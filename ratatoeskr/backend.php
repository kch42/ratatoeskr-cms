<?php
/*
 * File: ratatoeskr/backend.php
 * The backend.
 * 
 * License:
 * This file is part of Ratatöskr.
 * Ratatöskr is licensed unter the MIT / X11 License.
 * See "ratatoeskr/licenses/ratatoeskr" for more information.
 */

require_once(dirname(__FILE__) . "/sys/models.php");
require_once(dirname(__FILE__) . "/sys/pwhash.php");
require_once(dirname(__FILE__) . "/sys/textprocessors.php");
require_once(dirname(__FILE__) . "/languages.php");

$admin_grp = Group::by_name("admins");

/* Mass creation of tags. */
function maketags($tagnames, $lang)
{
	$rv = array();
	foreach($tagnames as $tagname)
	{
		if(empty($tagname))
			continue;
		try
		{
			$tag = Tag::by_name($tagname);
		}
		catch(DoesNotExistError $e)
		{
			$tag = Tag::create($tagname);
			$tag->title[$lang] = new Translation($tagname, "");
		}
		$rv[] = $tag;
	}
	return $rv;
}

/* Generates Yes/No form / checks it. */
function askyesno($ste, $callback, $question, $yes=NULL, $no=NULL, $moredetails="")
{
	if(isset($_POST["yes"]))
		return True;
	if(isset($_POST["no"]))
		return False;
	
	$ste->vars["callback"] = $callback;
	$ste->vars["question"] = $question;
	if($yes !== NULL)
		$ste->vars["yestext"] = $yes;
	if($no !== NULL)
		$ste->vars["notext"] = $no;
	if($moredetails !== NULL)
		$ste->vars["moredetails"] = $moredetails;
	return $ste->exectemplate("systemtemplates/areyousure.html");
}

$backend_subactions = url_action_subactions(array(
	"_index" => url_action_alias(array("login")),
	"index" => url_action_alias(array("login")),
	/* _prelude guarantees that the user is logged in properly, so we do not have to care about that later, and sets some STE vars. */
	"_prelude" => function(&$data, $url_now, &$url_next)
	{
		global $ratatoeskr_settings, $admin_grp, $ste, $languages;
		
		$ste->vars["all_languages"] = array();
		$ste->vars["all_langcodes"] = array();
		foreach($languages as $code => $data)
		{
			$ste->vars["all_languages"][$code] = $data["language"];
			$ste->vars["all_langcodes"][]      = $code;
		}
		ksort($ste->vars["all_languages"]);
		sort($ste->vars["all_langcodes"]);
		
		
		/* Check authentification */
		if(isset($_SESSION["ratatoeskr_uid"]))
		{
			try
			{
				$user = User::by_id($_SESSION["ratatoeskr_uid"]);
				if(($user->pwhash == $_SESSION["ratatoeskr_pwhash"]) and $user->member_of($admin_grp))
				{
					if(empty($user->language))
					{
						$user->language = $ratatoeskr_settings["default_language"];
						$user->save();
					}
					load_language($user->language);
					
					if($url_next[0] == "login")
						$url_next = array("content", "write");
					$data["user"] = $user;
					$ste->vars["user"] = array("id" => $user->get_id(), "name" => $user->username, "lang" => $user->language);
					
					return; /* Authentification successful, continue  */
				}
				else
					unset($_SESSION["ratatoeskr_uid"]);
			}
			catch(DoesNotExistError $e)
			{
				unset($_SESSION["uid"]);
			}
		}
		load_language();
		/* If we are here, user is not logged in... */
		$url_next = array("login");
	},
	"login" => url_action_simple(function($data)
	{
		global $ste, $admin_grp;
		if(!empty($_POST["user"]))
		{
			try
			{
				$user = User::by_name($_POST["user"]);
				if(!PasswordHash::validate($_POST["password"], $user->pwhash))
					throw new Exception();
				if(!$user->member_of($admin_grp))
					throw new Exception();
				
				/* Login successful. */
				$_SESSION["ratatoeskr_uid"]    = $user->get_id();
				$_SESSION["ratatoeskr_pwhash"] = $user->pwhash;
				$data["user"] = $user;
				$ste->vars["user"] = array("name" => $user->username, "lang" => $user->language);
			}
			catch(Exception $e)
			{
				$ste->vars["login_failed"] = True;
			}
			
			if(isset($data["user"]))
				throw new Redirect(array("content", "write"));
		}
		
		echo $ste->exectemplate("systemtemplates/backend_login.html");
	}),
	"logout" => url_action_simple(function($data)
	{
		echo "foo";
		unset($_SESSION["ratatoeskr_uid"]);
		unset($_SESSION["ratatoeskr_pwhash"]);
		throw new Redirect(array("login"));
	}),
	"content" => url_action_subactions(array(
		"write" => function(&$data, $url_now, &$url_next)
		{
			global $ste, $translation, $textprocessors, $ratatoeskr_settings, $languages;
			
			list($article, $editlang) = array_slice($url_next, 0);
			if(!isset($editlang))
				$editlang = $data["user"]->language;
			if(isset($article))
				$ste->vars["article_editurl"] = urlencode($article) . "/" . urlencode($editlang);
			else
				$ste->vars["article_editurl"] = "";
			
			$url_next = array();
			
			$default_section = Section::by_id($ratatoeskr_settings["default_section"]);
			
			$ste->vars["section"] = "content";
			$ste->vars["submenu"] = isset($article) ? "articles" : "newarticle";
			
			$ste->vars["textprocessors"] = array();
			foreach($textprocessors as $txtproc => $properties)
				if($properties[1])
					$ste->vars["textprocessors"][] = $txtproc;
			
			$ste->vars["sections"] = array();
			foreach(Section::all() as $section)
				$ste->vars["sections"][] = $section->name;
			$ste->vars["article_section"] = $default_section->name;
			
			/* Check Form */
			$fail_reasons = array();
			
			$inputs = array(
				"date" => time(),
				"article_status" => ARTICLE_STATUS_LIVE
			);
			
			if(isset($_POST["save_article"]))
			{
				if(!preg_match('/^[a-zA-Z0-9-_]+$/', @$_POST["urlname"]))
					$fail_reasons[] = $translation["invalid_urlname"];
				else
					$inputs["urlname"] = $_POST["urlname"];
				if((@$_POST["article_status"] < 0) or (@$_POST["article_status"] > 3))
					$fail_reasons[] = $translation["invalid_article_status"];
				else
					$inputs["article_status"] = (int) $_POST["article_status"];
				if(!isset($textprocessors[@$_POST["content_txtproc"]]))
					$fail_reasons[] = $translation["unknown_txtproc"];
				else
					$inputs["content_txtproc"] = $_POST["content_txtproc"];
				if(!isset($textprocessors[@$_POST["excerpt_txtproc"]]))
					$fail_reasons[] = $translation["unknown_txtproc"];
				else
					$inputs["excerpt_txtproc"] = $_POST["excerpt_txtproc"];
				if(!empty($_POST["date"]))
				{
					if(($time_tmp = strptime(@$_POST["date"], "%Y-%m-%d %H:%M:%S")) === False)
						$fail_reasons[] = $translation["invalid_date"];
					else
						$inputs["date"] = @mktime($time_tmp["tm_sec"], $time_tmp["tm_min"], $time_tmp["tm_hour"], $time_tmp["tm_mon"] + 1, $time_tmp["tm_mday"], $time_tmp["tm_year"] + 1900);
				}
				else
					$inputs["date"] = time();
				$inputs["allow_comments"] = !(empty($_POST["allow_comments"]) or $_POST["allow_comments"] != "yes");
				
				try
				{
					$inputs["section"] = Section::by_name($_POST["section"]);
				}
				catch(DoesNotExistError $e)
				{
					$fail_reasons[] = $translation["unknown_section"];
				}
				
				$inputs["title"]      = $_POST["title"];
				$inputs["content"]    = $_POST["content"];
				$inputs["excerpt"]    = $_POST["excerpt"];
				$inputs["tags"]       = array_filter(array_map("trim", explode(",", $_POST["tags"])), function($t) { return !empty($t); });
				if(isset($_POST["saveaslang"]))
					$editlang = $_POST["saveaslang"];
			}
			
			function fill_article(&$article, $inputs, $editlang)
			{
				$article->urlname   = $inputs["urlname"];
				$article->status    = $inputs["article_status"];
				$article->timestamp = $inputs["date"];
				$article->section   = $inputs["section"];
				$article->tags      = maketags($inputs["tags"], $editlang);
				$article->title  [$editlang] = new Translation($inputs["title"],   ""       );
				$article->text   [$editlang] = new Translation($inputs["content"], $inputs["content_txtproc"]);
				$article->excerpt[$editlang] = new Translation($inputs["excerpt"], $inputs["excerpt_txtproc"]);
			}
			
			if(empty($article))
			{
				/* New Article */
				$ste->vars["pagetitle"] = $translation["new_article"];
				
				if(empty($fail_reasons) and isset($_POST["save_article"]))
				{
					$article = Article::create($inputs["urlname"]);
					fill_article($article, $inputs, $editlang);
					try
					{
						$article->save();
						$ste->vars["article_editurl"] = urlencode($article->urlname) . "/" . urlencode($editlang);
						$ste->vars["success"] = htmlesc($translation["article_save_success"]);
					}
					catch(AlreadyExistsError $e)
					{
						$fail_reasons[] = $translation["article_name_already_in_use"];
					}
				}
			}
			else
			{
				try
				{
					$article = Article::by_urlname($article);
				}
				catch(DoesNotExistError $e)
				{
					throw new NotFoundError();
				}
				
				if(empty($fail_reasons) and isset($_POST["save_article"]))
				{
					fill_article($article, $inputs, $editlang);
					try
					{
						$article->save();
						$ste->vars["article_editurl"] = urlencode($article->urlname) . "/" . urlencode($editlang);
						$ste->vars["success"] = htmlesc($translation["article_save_success"]);
					}
					catch(AlreadyExistsError $e)
					{
						$fail_reasons[] = $translation["article_name_already_in_use"];
					}
				}
				
				foreach(array(
					"urlname"        => "urlname",
					"section"        => "article_section",
					"status"         => "article_status",
					"timestamp"      => "date",
					"allow_comments" => "allow_comments"
				) as $prop => $k_out)
				{
					if(!isset($inputs[$k_out]))
						$inputs[$k_out] = $article->$prop;
				}
				if(!isset($inputs["title"]))
					$inputs["title"] = $article->title[$editlang]->text;
				if(!isset($inputs["content"]))
				{
					$translation_obj           = $article->text[$editlang];
					$inputs["content"]         = $translation_obj->text;
					$inputs["content_txtproc"] = $translation_obj->texttype;
				}
				if(!isset($inputs["excerpt"]))
				{
					$translation_obj           = $article->excerpt[$editlang];
					$inputs["excerpt"]         = $translation_obj->text;
					$inputs["excerpt_txtproc"] = $translation_obj->texttype;
				}
				if(!isset($inputs["tags"]))
					$inputs["tags"] = array_map(function($tag) use ($editlang) { return $tag->name; }, $article->tags);
				$ste->vars["morelangs"] = array();
				$ste->vars["pagetitle"] = $article->title[$editlang]->text;
				foreach($article->title as $lang => $_)
				{
					if($lang != $editlang)
						$ste->vars["morelangs"][] = array("url" => urlencode($article->urlname) . "/$lang", "full" => "($lang) " . $languages[$lang]["language"]);
				}
			}
			
			/* Push data back to template */
			if(isset($inputs["tags"]))
				$ste->vars["tags"] = implode(", ", $inputs["tags"]);
			if(isset($inputs["article_section"]))
				$ste->section["article_section"] = $inputs["article_section"]->name;
			$ste->vars["editlang"] = $editlang;
			foreach(array(
				"urlname"         => "urlname",
				"article_status"  => "article_status",
				"title"           => "title",
				"content_txtproc" => "content_txtproc",
				"content"         => "content",
				"excerpt_txtproc" => "excerpt_txtproc",
				"excerpt"         => "excerpt",
				"date"            => "date",
				"allow_comments"  => "allow_comments"
			) as $k_in => $k_out)
			{
				if(isset($inputs[$k_in]))
					$ste->vars[$k_out] = $inputs[$k_in];
			}
			
			if(!empty($fail_reasons))
				$ste->vars["failed"] = $fail_reasons;
			
			echo $ste->exectemplate("systemtemplates/content_write.html");
		},
		"tags" => function(&$data, $url_now, &$url_next)
		{
			global $translation, $languages, $ste, $rel_path_to_root;
			$ste->vars["section"] = "content";
			$ste->vars["submenu"] = "tags";
			
			list($tagname, $tagaction) = $url_next;
			$url_next = array();
			
			if(isset($tagname))
			{
				try
				{
					$tag = Tag::by_name($tagname);
				}
				catch(DoesNotExistError $e)
				{
					throw new NotFoundError();
				}
				
				if(isset($tagaction))
				{
					switch($tagaction)
					{
						case "delete": 
							$ste->vars["pagetitle"] = str_replace("[[TAGNAME]]", $tag->name, $translation["delete_tag_pagetitle"]);
							$yesnoresp = askyesno($ste, "$rel_path_to_root/backend/content/tags/{$tag->name}/delete", $translation["delete_comment_question"]);
							if(is_string($yesnoresp))
							{
								echo $yesnoresp;
								return;
							}
					
							if($yesnoresp)
							{
								$tag->delete();
								echo $ste->exectemplate("systemtemplates/tag_deleted.html");
							}
							else
								goto backend_content_tags_overview; /* Hopefully no dinosaur will attack me: http://xkcd.com/292/ :-) */
							break;
						case "addtranslation":
							$ste->vars["pagetitle"] = $translation["tag_add_lang"];
							$ste->vars["tagname"] = $tag->name;
							if(isset($_POST["addtranslation"]))
							{
								$errors = array();
								if(!isset($languages[@$_POST["language"]]))
									$errors[] = $translation["language_unknown"];
								if(empty($_POST["translation"]))
									$errors[] = $translation["no_translation_text_given"];
								if(empty($errors))
								{
									$tag->title[$_POST["language"]] = new Translation($_POST["translation"], "");
									$tag->save();
									$ste->vars["success"] = $translation["tag_translation_added"];
									goto backend_content_tags_overview;
								}
								else
									$ste->vars["errors"] = $errors;
							}
							echo $ste->exectemplate("systemtemplates/tag_addtranslation.html");
							break;
					}
				}
			}
			else
			{
				backend_content_tags_overview:
				
				if(isset($_POST["create_new_tag"]))
				{
					if((strpos(@$_POST["new_tag_name"], ",") !== False) or (strpos(@$_POST["new_tag_name"], " ") !== False) or (strlen(@$_POST["new_tag_name"]) == 0))
						$ste->vars["error"] = $translation["invalid_tag_name"];
					else
					{
						try
						{
							$tag = Tag::create($_POST["new_tag_name"]);
							$tag->title[$data["user"]->language] = new Translation($_POST["new_tag_name"], "");
							$tag->save();
							$ste->vars["success"] = $translation["tag_created_successfully"];
						}
						catch(AlreadyExistsError $e)
						{
							$ste->vars["error"] = $translation["tag_name_already_in_use"];
						}
					}
				}
				
				if(isset($_POST["edit_translations"]))
				{
					$tagbuffer = array();
					foreach($_POST as $k => $v)
					{
						if(preg_match("/^tagtrans_(.*?)_(.*)$/", $k, $matches))
						{
							if(!isset($languages[$matches[1]]))
								continue;
							
							if(!isset($tagbuffer[$matches[2]]))
							{
								try
								{
									$tagbuffer[$matches[2]] = Tag::by_name($matches[2]);
								}
								catch(DoesNotExistError $e)
								{
									continue;
								}
							}
							
							if(empty($v) and isset($tagbuffer[$matches[2]]->title[$matches[1]]))
								unset($tagbuffer[$matches[2]]->title[$matches[1]]);
							elseif(empty($v))
								continue;
							else
								$tagbuffer[$matches[2]]->title[$matches[1]] = new Translation($v, "");
						}
					}
					
					foreach($tagbuffer as $tag)
						$tag->save();
					
					$ste->vars["success"] = $translation["tag_titles_edited_successfully"];
				}
				
				$ste->vars["pagetitle"] = $translation["tags_overview"];
				
				$alltags = Tag::all();
				usort($alltags, function($a, $b) { return strcmp($a->name, $b->name); });
				$ste->vars["all_tag_langs"] = array();
				$ste->vars["alltags"] = array();
				foreach($alltags as $tag)
				{
					$tag_pre = array("name" => $tag->name, "translations" => array());
					foreach($tag->title as $langcode => $translation_obj)
					{
						$tag_pre["translations"][$langcode] = $translation_obj->text;
						if(!isset($ste->vars["all_tag_langs"][$langcode]))
							$ste->vars["all_tag_langs"][$langcode] = $languages[$langcode]["language"];
					}
					$ste->vars["alltags"][] = $tag_pre;
				}
				echo $ste->exectemplate("systemtemplates/tags_overview.html");
			}
		},
		"articles" => function(&$data, $url_now, &$url_next)
		{
			global $ste, $translation, $languages, $rel_path_to_root;
			
			$url_next = array();
			
			$ste->vars["section"]   = "content";
			$ste->vars["submenu"]   = "articles";
			$ste->vars["pagetitle"] = $translation["menu_articles"];
			
			if(isset($_POST["delete"]) and ($_POST["really_delete"] == "yes"))
			{
				foreach($_POST["article_multiselect"] as $article_urlname)
				{
					try
					{
						$article = Article::by_urlname($article_urlname);
						$article->delete();
					}
					catch(DoesNotExistError $e)
					{
						continue;
					}
				}
				
				$ste->vars["success"] = $translation["articles_deleted"];
			}
			
			$articles = Article::all();
			
			/* Filtering */
			$filterquery = array();
			if(!empty($_GET["filter_urlname"]))
			{
				$searchfor = strtolower($_GET["filter_urlname"]);
				$articles = array_filter($articles, function($a) use ($searchfor) { return strpos(strtolower($a->urlname), $searchfor) !== False; });
				$filterquery[] = "filter_urlname=" . urlencode($_GET["filter_urlname"]);
				$ste->vars["filter_urlname"] = $_GET["filter_urlname"];
			}
			if(!empty($_GET["filter_tag"]))
			{
				$searchfor = $_GET["filter_tag"];
				$articles = array_filter($articles, function($a) use ($searchfor) { foreach($a->tags as $t) { if($t->name==$searchfor) return True; } return False; });
				$filterquery[] = "filter_tag=" . urlencode($searchfor);
				$ste->vars["filter_tag"] = $searchfor;
			}
			if(!empty($_GET["filter_section"]))
			{
				$searchfor = $_GET["filter_section"];
				$articles = array_filter($articles, function($a) use ($searchfor) { return $a->section->name == $searchfor; });
				$filterquery[] = "filter_section=" . urlencode($searchfor);
				$ste->vars["filter_section"] = $searchfor;
			}
			$ste->vars["filterquery"] = implode("&", $filterquery);
			
			/* Sorting */
			if(isset($_GET["sort_asc"]))
			{
				switch($_GET["sort_asc"])
				{
					case "date":
						$ste->vars["sortquery"] = "sort_asc=date";
						$ste->vars["sort_asc_date"] = True;
						$ste->vars["sorting"] = array("dir" => "asc", "by" => "date");
						usort($articles, function($a, $b) { return intcmp($a->timestamp, $b->timestamp); });
						break;
					case "section":
						$ste->vars["sortquery"] = "sort_asc=section";
						$ste->vars["sort_asc_section"] = True;
						$ste->vars["sorting"] = array("dir" => "asc", "by" => "section");
						usort($articles, function($a, $b) { return strcmp($a->section->name, $b->section->name); });
						break;
					case "urlname":
						$ste->vars["sortquery"] = "sort_asc=urlname";
					default:
						$ste->vars["sort_asc_urlname"] = True;
						$ste->vars["sorting"] = array("dir" => "asc", "by" => "urlname");
						usort($articles, function($a, $b) { return strcmp($a->urlname, $b->urlname); });
						break;
				}
			}
			elseif(isset($_GET["sort_desc"]))
			{
				switch($_GET["sort_desc"])
				{
					case "date":
						$ste->vars["sortquery"] = "sort_desc=date";
						$ste->vars["sort_desc_date"] = True;
						$ste->vars["sorting"] = array("dir" => "desc", "by" => "date");
						usort($articles, function($a, $b) { return intcmp($b->timestamp, $a->timestamp); });
						break;
					case "section":
						$ste->vars["sortquery"] = "sort_desc=section";
						$ste->vars["sort_desc_section"] = True;
						$ste->vars["sorting"] = array("dir" => "desc", "by" => "section");
						usort($articles, function($a, $b) { return strcmp($b->section->name, $a->section->name); });
						break;
					case "urlname":
						$ste->vars["sortquery"] = "sort_desc=urlname";
						$ste->vars["sort_desc_urlname"] = True;
						$ste->vars["sorting"] = array("dir" => "desc", "by" => "urlname");
						usort($articles, function($a, $b) { return strcmp($b->urlname, $a->urlname); });
						break;
					default:
						$ste->vars["sort_asc_urlname"] = True;
						$ste->vars["sorting"] = array("dir" => "asc", "by" => "urlname");
						usort($articles, function($a, $b) { return strcmp($a->urlname, $b->urlname); });
						break;
				}
			}
			else
			{
				$ste->vars["sort_asc_urlname"] = True;
				$ste->vars["sorting"] = array("dir" => "asc", "by" => "urlname");
				usort($articles, function($a, $b) { return strcmp($a->urlname, $b->urlname); });
			}
			
			$ste->vars["articles"] = array_map(function($article) {
				$avail_langs = array();
				foreach($article->title as $lang => $_)
					$avail_langs[] = $lang;
				sort($avail_langs);
				return array(
					"urlname"   => $article->urlname,
					"languages" => $avail_langs,
					"date"      => $article->timestamp,
					"tags"      => array_map(function($tag) { return $tag->name; }, $article->tags),
					"section"   => array("id" => $article->section->get_id(), "name" => $article->section->name)
				);
			}, $articles);
			
			echo $ste->exectemplate("systemtemplates/articles.html");
		},
		"images" => function(&$data, $url_now, &$url_next)
		{
			global $ste, $translation, $languages, $rel_path_to_root;
			
			list($imgid, $imageaction) = $url_next;
			
			$url_next = array();
			
			$ste->vars["section"]   = "content";
			$ste->vars["submenu"]   = "images";
			$ste->vars["pagetitle"] = $translation["menu_images"];
			
			if(!empty($imgid) and is_numeric($imgid))
			{
				try
				{
					$image = Image::by_id($imgid);
				}
				catch(DoesNotExistError $e)
				{
					throw new NotFoundError();
				}
				
				if(empty($imageaction))
					throw new NotFoundError();
				else
				{
					if(($imageaction == "markdown") or ($imageaction == "html"))
					{
						$ste->vars["pagetitle"]      = $translations["generate_embed_code"];
						$ste->vars["image_id"]       = $image->get_id();
						$ste->vars["markup_variant"] = $imageaction;
						if(isset($_POST["img_alt"]))
						{
							if($imageaction == "markdown")
								$ste->vars["embed_code"] = "![" . str_replace("]", "\\]", $_POST["img_alt"]) . "](%root%/images/" . str_replace(")", "\\)", urlencode($image->get_filename())) . ")";
							elseif($imageaction == "html")
								$ste->vars["embed_code"] = "<img src=\"%root%/images/" . htmlesc(urlencode($image->get_filename())) . "\" alt=\"" . htmlesc($_POST["img_alt"]) . "\" />";
						}
						
						echo $ste->exectemplate("systemtemplates/image_embed.html");
					}
					else
						throw new NotFoundError();
				}
				return;
			}
			
			/* Upload Form */
			if(isset($_POST["upload"]))
			{
				try
				{
					$image = Image::create((!empty($_POST["upload_name"])) ? $_POST["upload_name"] : $_FILES["upload_img"]["name"], $_FILES["upload_img"]["tmp_name"]);
					$image->save();
					$ste->vars["success"] = $translation["upload_success"];
				}
				catch(IOError $e)
				{
					$ste->vars["error"] = $translation["upload_failed"];
				}
				catch(UnknownFileFormat $e)
				{
					$ste->vars["error"] = $translation["unknown_file_format"];
				}
			}
			
			/* Mass delete */
			if(isset($_POST["delete"]) and ($_POST["really_delete"] == "yes"))
			{
				foreach($_POST["image_multiselect"] as $image_id)
				{
					try
					{
						$image = Image::by_id($image_id);
						$image->delete();
					}
					catch(DoesNotExistError $e)
					{
						continue;
					}
				}
				
				$ste->vars["success"] = $translation["images_deleted"];
			}
			
			$images = Image::all();
			
			$ste->vars["images"] = array_map(function($img) { return array(
				"id"   => $img->get_id(),
				"name" => $img->name,
				"file" => $img->get_filename()
			); }, $images);
			
			echo $ste->exectemplate("systemtemplates/image_list.html");
		},
		"comments" => function(&$data, $url_now, &$url_next)
		{
			global $ste, $translation, $languages, $rel_path_to_root;
			
			list($comment_id) = $url_next;
			
			$url_next = array();
			
			$ste->vars["section"]   = "content";
			$ste->vars["submenu"]   = "comments";
			$ste->vars["pagetitle"] = $translation["menu_comments"];
			
			/* Single comment? */
			if(!empty($comment_id))
			{
				try
				{
					$comment = Comment::by_id($comment_id);
				}
				catch(DoesNotExistError $e)
				{
					throw new NotFoundError();
				}
				
				if(!$comment->read_by_admin)
				{
					$comment->read_by_admin = True;
					$comment->save();
				}
				
				if(isset($_POST["action_on_comment"]))
				{
					switch($_POST["action_on_comment"])
					{
						case "delete":
							$comment->delete();
							$ste->vars["success"] = $translation["comment_successfully_deleted"];
							goto backend_content_comments_overview;
							break;
						case "make_visible":
							$comment->visible = True;
							$comment->save();
							$ste->vars["success"] = $translation["comment_successfully_made_visible"];
							break;
						case "make_invisible":
							$comment->visible = False;
							$comment->save();
							$ste->vars["success"] = $translation["comment_successfully_made_invisible"];
							break;
					}
				}
				
				$ste->vars["id"] = $comment->get_id();
				$ste->vars["visible"] = $comment->visible;
				$ste->vars["article"] = $comment->get_article()->urlname;
				$ste->vars["language"] = $comment->get_language();
				$ste->vars["date"] = $comment->get_timestamp();
				$ste->vars["author"] = "\"{$comment->author_name}\" <{$comment->author_mail}>";
				$ste->vars["comment_text"] = $comment->create_html();
				$ste->vars["comment_raw"] = $comment->text;
				
				echo $ste->exectemplate("systemtemplates/single_comment.html");
				return;
			}
			
			backend_content_comments_overview:
			
			/* Perform an action on all selected comments */
			if(!empty($_POST["action_on_comments"]))
			{
				switch($_POST["action_on_comments"])
				{
					case "delete":
						$commentaction = function($c) { $c->delete(); };
						$ste->vars["success"] = $translation["comments_successfully_deleted"];
						break;
					case "mark_read":
						$commentaction = function($c) { $c->read_by_admin = True; $c->save(); };
						$ste->vars["success"] = $translation["comments_successfully_marked_read"];
						break;
					case "mark_unread":
						$commentaction = function($c) { $c->read_by_admin = False; $c->save(); };
						$ste->vars["success"] = $translation["comments_successfully_marked_unread"];
						break;
					case "make_visible":
						$commentaction = function($c) { $c->visible = True; $c->save(); };
						$ste->vars["success"] = $translation["comments_successfully_made_visible"];
						break;
					case "make_invisible":
						$commentaction = function($c) { $c->visible = False; $c->save(); };
						$ste->vars["success"] = $translation["comments_successfully_made_invisible"];
						break;
					default;
						$ste->vars["error"] = $translation["unknown_action"];
						break;
				}
				if(isset($commentaction))
				{
					foreach($_POST["comment_multiselect"] as $c_id)
					{
						try
						{
							$comment = Comment::by_id($c_id);
							$commentaction($comment);
						}
						catch(DoesNotExistError $e)
						{
							continue;
						}
					}
				}
			}
			
			$comments = Comment::all();
			
			/* Filtering */
			$filterquery = array();
			if(!empty($_GET["filter_article"]))
			{
				$searchfor = strtolower($_GET["filter_article"]);
				$comments = array_filter($comments, function($c) use ($searchfor) { return strpos(strtolower($c->get_article()->urlname), $searchfor) !== False; });
				$filterquery[] = "filter_article=" . urlencode($_GET["filter_article"]);
				$ste->vars["filter_article"] = $_GET["filter_article"];
			}
			$ste->vars["filterquery"] = implode("&", $filterquery);
			
			/* Sorting */
			if(isset($_GET["sort_asc"]))
			{
				$sort_dir = 1;
				$sort_by  = $_GET["sort_asc"];
			}
			elseif(isset($_GET["sort_desc"]))
			{
				$sort_dir = -1;
				$sort_by  = $_GET["sort_desc"];
			}
			else
			{
				$sort_dir = 1;
				$sort_by  = "was_read";
			}
			
			switch($sort_by)
			{
				case "language":
					usort($comments, function($a, $b) use ($sort_dir) { return strcmp($a->get_language(), $b->get_language()) * $sort_dir; });
					break;
				case "date":
					usort($comments, function($a, $b) use ($sort_dir) { return intcmp($a->get_timestamp(), $b->get_timestamp()) * $sort_dir; });
					break;
				case "was_read":
				default:
					usort($comments, function($a, $b) use ($sort_dir) { return intcmp((int) $a->read_by_admin, (int) $b->read_by_admin) * $sort_dir; });
					$sort_by = "was_read";
					break;
			}
			$ste->vars["sortquery"] = "sort_" . ($sort_dir == 1 ? "asc" : "desc") . "=$sort_by";
			$ste->vars["sorting"] = array("dir" => ($sort_dir == 1 ? "asc" : "desc"), "by" => $sort_by);
			$ste->vars["sort_" . ($sort_dir == 1 ? "asc" : "desc") . "_$sort_by"] = True;
			
			$ste->vars["comments"] = array_map(function($c) { return array(
				"id" => $c->get_id(),
				"visible" => $c->visible,
				"read_by_admin" => $c->read_by_admin,
				"article" => $c->get_article()->urlname,
				"excerpt" => substr(str_replace(array("\r\n", "\n", "\r"), " ", $c->text), 0, 50),
				"language" => $c->get_language(),
				"date" => $c->get_timestamp(),
				"author" => "\"{$c->author_name}\" <{$c->author_mail}>"
			); }, $comments);
			
			echo $ste->exectemplate("systemtemplates/comments_list.html");
		}
	)),
	"design" => url_action_subactions(array(
		"templates" => function(&$data, $url_now, &$url_next)
		{
			global $ste, $translation, $languages, $rel_path_to_root;
			
			list($template) = $url_next;
			
			$url_next = array();
			
			$ste->vars["section"]   = "design";
			$ste->vars["submenu"]   = "templates";
			$ste->vars["pagetitle"] = $translation["menu_templates"];
			
			if(isset($template))
			{
				if(preg_match("/^[a-zA-Z0-9\\-_\\.]+$/", $template) == 0) /* Prevent a possible LFI attack. */
					throw new NotFoundError();
				if(!is_file(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates/$template"))
					throw new NotFoundError();
				$ste->vars["template_name"] = $template;
				$ste->vars["template_code"] = file_get_contents(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates/$template");
			}
			
			/* Was there a delete request? */
			if(isset($_POST["delete"]) and ($_POST["really_delete"] == "yes"))
			{
				foreach($_POST["templates_multiselect"] as $tplname)
				{
					if(preg_match("/^[a-zA-Z0-9\\-_\\.]+$/", $tplname) == 0) /* Prevent a possible LFI attack. */
						continue;
					if(is_file(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates/$tplname"))
						@unlink(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates/$tplname");
				}
				$ste->vars["success"] = $translation["templates_successfully_deleted"];
			}
			
			/* A write request? */
			if(isset($_POST["save_template"]))
			{
				if(preg_match("/^[a-zA-Z0-9\\-_\\.]+$/", $_POST["template_name"]) == 1)
				{
					$ste->vars["template_name"] = $_POST["template_name"];
					$ste->vars["template_code"] = $_POST["template_code"];
					
					try
					{
						\ste\transcompile(\ste\parse(\ste\precompile($_POST["template_code"]), $_POST["template_name"]));
						file_put_contents(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates/" . $_POST["template_name"], $_POST["template_code"]);
						$ste->vars["success"] = $translation["template_successfully_saved"];
					}
					catch(\ste\ParseCompileError $e)
					{
						$e->rewrite($_POST["template_code"]);
						$ste->vars["error"] = $translation["could_not_compile_template"] . $e->getMessage();
					}
				}
				else
					$ste->vars["error"] = $translation["invalid_template_name"];
			}
			
			/* Get all templates */
			$ste->vars["templates"] = array();
			$tpldir = new DirectoryIterator(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates");
			foreach($tpldir as $fo)
			{
				if($fo->isFile())
					$ste->vars["templates"][] = $fo->getFilename();
			}
			sort($ste->vars["templates"]);
			
			echo $ste->exectemplate("systemtemplates/templates.html");
		},
		"styles" => function(&$data, $url_now, &$url_next)
		{
			global $ste, $translation, $languages, $rel_path_to_root;
			
			list($style) = $url_next;
			
			$url_next = array();
			
			$ste->vars["section"]   = "design";
			$ste->vars["submenu"]   = "styles";
			$ste->vars["pagetitle"] = $translation["menu_styles"];
			
			if(isset($style))
			{
				try
				{
					$style = Style::by_name($style);
					$ste->vars["style_name"] = $style->name;
					$ste->vars["style_code"] = $style->code;
				}
				catch(DoesNotExistError $e)
				{
					throw new NotFoundError();
				}
			}
			
			/* Was there a delete request? */
			if(isset($_POST["delete"]) and ($_POST["really_delete"] == "yes"))
			{
				foreach($_POST["styles_multiselect"] as $stylename)
				{
					try
					{
						$style = Style::by_name($stylename);
						$style->delete();
					}
					catch(DoesNotExistError $e)
					{
						continue;
					}
				}
				$ste->vars["success"] = $translation["styles_successfully_deleted"];
			}
			
			/* A write request? */
			if(isset($_POST["save_style"]))
			{
				if(preg_match("/^[a-zA-Z0-9\\-_\\.]+$/", $_POST["style_name"]) == 1)
				{
					$ste->vars["style_name"] = $_POST["style_name"];
					$ste->vars["style_code"] = $_POST["style_code"];
					
					try
					{
						$style = Style::by_name($_POST["style_name"]);
					}
					catch(DoesNotExistError $e)
					{
						$style = Style::create($_POST["style_name"]);
					}
					
					$style->code = $_POST["style_code"];
					$style->save();
					
					$ste->vars["success"] = $translation["style_successfully_saved"];
				}
				else
					$ste->vars["error"] = $translation["invalid_style_name"];
			}
			
			/* Get all styles */
			$ste->vars["styles"] = array_map(function($s) { return $s->name; }, Style::all());
			sort($ste->vars["styles"]);
			
			echo $ste->exectemplate("systemtemplates/styles.html");
		},
		"sections" => function(&$data, $url_now, &$url_next)
		{
			global $ste, $translation, $languages, $rel_path_to_root, $ratatoeskr_settings;
			
			list($style) = $url_next;
			
			$url_next = array();
			
			$ste->vars["section"]   = "design";
			$ste->vars["submenu"]   = "sections";
			$ste->vars["pagetitle"] = $translation["menu_pagesections"];
			
			/* New section? */
			if(isset($_POST["new_section"]))
			{
				try
				{
					Section::by_name($_POST["section_name"]);
					$ste->vars["error"] = $translation["section_already_exists"];
				}
				catch(DoesNotExistError $e)
				{
					if((preg_match("/^[a-zA-Z0-9\\-_\\.]+$/", $_POST["template"]) == 0) or (!is_file(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates/{$_POST['template']}")))
						$ste->vars["error"] = $translation["unknown_template"];
					else if(preg_match("/^[a-zA-Z0-9\\-_]+$/", $_POST["section_name"]) == 0)
						$ste->vars["error"] = $translation["invalid_section_name"];
					else
					{
						$section = Section::create($_POST["section_name"]);
						$section->template = $_POST["template"];
						$section->title[$data["user"]->language] = new Translation($_POST["section_name"], "");
						$section->save();
						$ste->vars["success"] = $translation["section_created_successfully"];
					}
				}
			}
			
			/* Remove a style? */
			if(isset($_GET["rmstyle"]) and isset($_GET["rmfrom"]))
			{
				try
				{
					$section = Section::by_name($_GET["rmfrom"]);
					$style   = $_GET["rmstyle"];
					$section->styles = array_filter($section->styles, function($s) use ($style) { return $s->name != $style; });
					$section->save();
					$ste->vars["success"] = $translation["style_removed"];
				}
				catch(DoesNotExistError $e)
				{
					continue;
				}
			}
			
			/* Delete a section? */
			if(isset($_POST["delete"]) and (@$_POST["really_delete"] == "yes") and isset($_POST["section_select"]))
			{
				try
				{
					$section = Section::by_name($_POST["section_select"]);
					if($section->get_id() == $ratatoeskr_settings["default_section"])
						$ste->vars["error"] = $translation["cannot_delete_default_section"];
					else
					{
						$default_section = Section::by_id($ratatoeskr_settings["default_section"]);
						foreach($section->get_articles() as $article)
						{
							$article->section = $default_section;
							$article->save();
						}
						$section->delete();
						$ste->vars["success"] = $translation["section_successfully_deleted"];
					}
				}
				catch(DoesNotExistError $e)
				{
					continue;
				}
			}
			
			/* Make section default? */
			if(isset($_POST["make_default"]) and isset($_POST["section_select"]))
			{
				try
				{
					$section = Section::by_name($_POST["section_select"]);
					$ratatoeskr_settings["default_section"] = $section->get_id();
					$ste->vars["success"] = $translation["default_section_changed_successfully"];
				}
				catch(DoesNotExistError $e)
				{
					continue;
				}
			}
			
			/* Set template? */
			if(isset($_POST["set_template"]) and isset($_POST["section_select"]))
			{
				try
				{
					$section = Section::by_name($_POST["section_select"]);
					if((preg_match("/^[a-zA-Z0-9\\-_\\.]+$/", $_POST["set_template_to"]) == 0) or (!is_file(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates/{$_POST['set_template_to']}")))
						$ste->vars["error"] = $translation["unknown_template"];
					else
					{
						$section->template = $_POST["set_template_to"];
						$section->save();
						$ste->vars["success"] = $translation["successfully_set_template"];
					}
				}
				catch(DoesNotExistError $e)
				{
					continue;
				}
			}
			
			/* Adding a style? */
			if(isset($_POST["add_style"]) and isset($_POST["section_select"]))
			{
				try
				{
					$section = Section::by_name($_POST["section_select"]);
					$style   = Style::by_name($_POST["style_to_add"]);
					if(!in_array($style, $section->styles))
					{
						$section->styles[] = $style;
						$section->save();
					}
					$ste->vars["success"] = $translation["successfully_added_style"];
				}
				catch(DoesNotExistError $e)
				{
					continue;
				}
			}
			
			/* Set/unset title? */
			if(isset($_POST["set_title"]) and isset($_POST["section_select"]))
			{
				if(!isset($languages[$_POST["set_title_lang"]]))
					$ste->vars["error"] = $translation["language_unknown"];
				else
				{
					try
					{
						$section = Section::by_name($_POST["section_select"]);
						if(!empty($_POST["set_title_text"]))
							$section->title[$_POST["set_title_lang"]] = new Translation($_POST["set_title_text"], "");
						else if(isset($section->title[$_POST["set_title_lang"]]))
							unset($section->title[$_POST["set_title_lang"]]);
						$section->save();
						$ste->vars["success"] = $translation["successfully_set_section_title"];
					}
					catch(DoesNotExistError $e)
					{
						continue;
					}
				}
			}
			
			/* Get all templates */
			$ste->vars["templates"] = array();
			$tpldir = new DirectoryIterator(SITE_BASE_PATH . "/ratatoeskr/templates/src/usertemplates");
			foreach($tpldir as $fo)
			{
				if($fo->isFile())
					$ste->vars["templates"][] = $fo->getFilename();
			}
			sort($ste->vars["templates"]);
			
			/* Get all styles */
			$ste->vars["styles"] = array_map(function($s) { return $s->name; }, Style::all());
			sort($ste->vars["styles"]);
			
			/* Get all sections */
			$sections = Section::all();
			$ste->vars["sections"] = array_map(function($section) use ($ratatoeskr_settings) {
				$titles = array();
				foreach($section->title as $l => $t)
					$titles[$l] = $t->text;
				return array(
					"name"     => $section->name,
					"title"    => $titles,
					"template" => $section->template,
					"styles"   => array_map(function($style) { return $style->name; }, $section->styles),
					"default"  => ($section->get_id() == $ratatoeskr_settings["default_section"])
				);
			}, $sections);
			
			echo $ste->exectemplate("systemtemplates/sections.html");
		}
	)),
	"admin" => url_action_subactions(array(
		"settings" => function(&$data, $url_now, &$url_next)
		{
			global $ste, $translation, $languages, $rel_path_to_root, $ratatoeskr_settings, $textprocessors;
			
			$url_next = array();
			
			$ste->vars["section"]   = "admin";
			$ste->vars["submenu"]   = "settings";
			$ste->vars["pagetitle"] = $translation["menu_settings"];
			
			$ste->vars["textprocessors"] = array();
			foreach($textprocessors as $txtproc => $properties)
				if($properties[1])
					$ste->vars["textprocessors"][] = $txtproc;
			
			/* Save comment settings? */
			if(isset($_POST["save_comment_settings"]))
			{
				if(!in_array(@$_POST["comment_textprocessor"], $ste->vars["textprocessors"]))
					$ste->vars["error"] = $translation["unknown_txtproc"];
				else
				{
					$ratatoeskr_settings["comment_textprocessor"]   = $_POST["comment_textprocessor"];
					$ratatoeskr_settings["comment_visible_default"] = (isset($_POST["comment_auto_visible"]) and ($_POST["comment_auto_visible"] == "yes"));
					$ste->vars["success"] = $translation["comment_settings_successfully_saved"];
				}
			}
			
			/* Delete language? */
			if(isset($_POST["delete"]) and ($_POST["really_delete"] == "yes") and isset($_POST["language_select"]))
			{
				if($ratatoeskr_settings["default_language"] == $_POST["language_select"])
					$ste->vars["error"] = $translation["cannot_delete_default_language"];
				else
				{
					$ratatoeskr_settings["languages"] = array_filter($ratatoeskr_settings["languages"], function($l) { return $l != $_POST["language_select"]; });
					$ste->vars["success"] = $translation["language_successfully_deleted"];
				}
			}
			
			/* Set default language */
			if(isset($_POST["make_default"]) and isset($_POST["language_select"]))
			{
				if(in_array($_POST["language_select"], $ratatoeskr_settings["languages"]))
				{
					$ratatoeskr_settings["default_language"] = $_POST["language_select"];
					$ste->vars["success"] = $translation["successfully_set_default_language"];
				}
			}
			
			/* Add a language */
			if(isset($_POST["add_language"]))
			{
				if(!isset($languages[$_POST["language_to_add"]]))
					$ste->vars["error"] = $translation["language_unknown"];
				else
				{
					if(!in_array($_POST["language_to_add"], $ratatoeskr_settings["languages"]))
					{
						$ls = $ratatoeskr_settings["languages"];
						$ls[] = $_POST["language_to_add"];
						$ratatoeskr_settings["languages"] = $ls;
					}
					$ste->vars["success"] = $translation["language_successfully_added"];
				}
			}
			
			$ste->vars["comment_auto_visible"]  = $ratatoeskr_settings["comment_visible_default"];
			$ste->vars["comment_textprocessor"] = $ratatoeskr_settings["comment_textprocessor"];
			$ste->vars["used_langs"] = array_map(function ($l) use ($ratatoeskr_settings, $languages) { return array(
				"code"    => $l,
				"name"    => $languages[$l]["language"],
				"default" => ($l == $ratatoeskr_settings["default_language"])
			);}, $ratatoeskr_settings["languages"]);
			
			echo $ste->exectemplate("systemtemplates/settings.html");
		}
	))
));

?>
