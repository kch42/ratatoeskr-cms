<?php

/*
 * File: ratatoeskr/sys/pluginpackage.php
 * Handle plugin packages easily.
 *
 * License:
 * This file is part of Ratatöskr.
 * Unlike the other parts of Ratatöskr, *this* file ist *not* licensed under the
 * MIT / X11 License, but under the WTFPL, to make it even easier to use this
 * file in other projects.
 * See "ratatoeskr/licenses/wtfpl" for more information.
 */

/*
 * Function: dir2array
 * Pack a directory into an array.
 *
 * Parameters:
 *  $dir - The directory to pack.
 *
 * Returns:
 *  Associative array. Keys are filenames, values are either the file's content as a string or another array, if it's a directory.
 */
function dir2array($dir)
{
    $rv = [];
    foreach (scandir($dir) as $fn) {
        if (($fn == ".") or ($fn == "..")) {
            continue;
        }
        $fn_new = $dir . "/" . $fn;
        if (is_dir($fn_new)) {
            $rv[$fn] = dir2array($fn_new);
        } elseif (is_file($fn_new)) {
            $rv[$fn] = file_get_contents($fn_new);
        }
    }
    return $rv;
}

/*
 * Function: array2dir
 * Unpack an array into a directory.
 *
 * Parameters:
 *  $a   - Array to unpack.
 *  $dir - Directory to unpack to.
 */
function array2dir($a, $dir)
{
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach ($a as $k => $v) {
        $k = "$dir/$k";
        if (is_array($v)) {
            array2dir($v, $k);
        } else {
            file_put_contents($k, $v);
        }
    }
}

function validate_url($u)
{
    return preg_match("/^http[s]{0,1}:\\/\\/.*$/", $u) != 0;
}
function validate_arraydir($a)
{
    if (!is_array($a)) {
        return false;
    }
    foreach ($a as $k=>$v) {
        if (!is_string($k)) {
            return false;
        }
        if (is_array($v) and (!validate_arraydir($v))) {
            return false;
        } elseif (!is_string($v)) {
            return false;
        }
    }
    return true;
}

/*
 * Class: InvalidPackage
 * An Exception that <PluginPackage>'s function can throw, if the package is invalid.
 */
class InvalidPackage extends Exception
{
}

/*
 * Class: PluginPackage
 * A plugin package representation.
 */
class PluginPackage
{
    public static $magic = "R7RPLGPACKV001";

    /*
     * Variables: Mandatory values
     *
     * $code              - The plugin code
     * $classname         - The name of the plugins main class
     * $name              - Name of the plugin (must be at least one character, allowed chars: a-z A-Z 0-9 - _)
     * $author            - The author of the plugin (preferably in the format: Name<mail@address>)
     * $versiontext       - A text to describe the current version, something like "1.1 Beta"
     * $versioncount      - A number for this version, should be increased with every release
     * $api               - The used API version
     * $short_description - A short description.
     */
    public $code              = null;
    public $classname         = null;
    public $name              = null;
    public $author            = null;
    public $versiontext       = null;
    public $versioncount      = null;
    public $api               = null;
    public $short_description = null;

    /*
     * Variables: Optional values
     *
     * $updatepath - A URL that points to a update information resource (serialize'd array("current-version" => VERSIONCOUNT, "dl-path" => DOWNLOAD PATH); will get overwritten/set by the default repository software.
     * $web        - A URL to the webpage for the plugin. If left empty, the default repository software will set this to the description page of your plugin.
     * $license    - The license text of your plugin.
     * $help       - A help / manual (formatted in HTML) for your plugin.
     * $custompub  - <dir2array> 'd directory that contains custom public(i.e. can later be accessed from the web) data.
     * $custompriv - <dir2array> 'd directory that contains custom private data.
     * $tpls       - <dir2array> 'd directory containing custom STE templates.
     */
    public $updatepath = null;
    public $web        = null;
    public $license    = null;
    public $help       = null;
    public $custompub  = null;
    public $custompriv = null;
    public $tpls       = null;

    /*
     * Function: validate
     * Validate, if the variables are set correctly.
     * Will throw an <InvalidPackage> exception if invalid.
     */
    public function validate()
    {
        if (!is_string($this->code)) {
            throw new InvalidPackage("Invalid code value.");
        }
        if (!is_string($this->classname)) {
            throw new InvalidPackage("Invalid classname value.");
        }
        if (preg_match("/^[a-zA-Z0-9_\\-]+$/", $this->name) == 0) {
            throw new InvalidPackage("Invalid name value (must be at least 1 character, accepted chars: a-z A-Z 0-9 - _).");
        }
        if (!is_string($this->author)) {
            throw new InvalidPackage("Invalid author value.");
        }
        if (!is_string($this->versiontext)) {
            throw new InvalidPackage("Invalid versiontext value.");
        }
        if (!is_numeric($this->versioncount)) {
            throw new InvalidPackage("Invalid versioncount value. Must be a number.");
        }
        if (!is_numeric($this->api)) {
            throw new InvalidPackage("Invalid api value. Must be a number.");
        }
        if (!is_string($this->short_description)) {
            throw new InvalidPackage("Invalid short_description value.");
        }

        if ((!empty($this->updatepath)) and (!validate_url($this->updatepath))) {
            throw new InvalidPackage("Invalid updatepath value. Must be an URL. " .$this->updatepath);
        }
        if ((!empty($this->web)) and (!validate_url($this->web))) {
            throw new InvalidPackage("Invalid web value. Must be an URL.");
        }
        if (($this->license !== null) and (!is_string($this->license))) {
            throw new InvalidPackage("Invalid license value.");
        }
        if (($this->help !== null) and (!is_string($this->help))) {
            throw new InvalidPackage("Invalid help value.");
        }
        if (($this->custompub !== null) and (!validate_arraydir($this->custompub))) {
            throw new InvalidPackage("Invalid custompub value.");
        }
        if (($this->custompriv !== null) and (!validate_arraydir($this->custompriv))) {
            throw new InvalidPackage("Invalid custompriv value.");
        }
        if (($this->tpls !== null) and (!validate_arraydir($this->tpls))) {
            throw new InvalidPackage("Invalid tpls value.");
        }
        return true;
    }

    /*
     * Function: load
     * Load a plugin package from binary data.
     *
     * Parameters:
     *  $plugin_raw - The raw package to load.
     *
     * Returns:
     *  The <PluginPackage> object.
     *
     * Throws:
     *  <InvalidPackage> if package is invalid.
     */
    public static function load($plugin_raw)
    {
        /* Read and compare magic number */
        $magic = substr($plugin_raw, 0, strlen(self::$magic));
        if ($magic != self::$magic) {
            throw new InvalidPackage("Wrong magic number");
        }

        /* Read sha1sum and uncompress serialized plugin, then compare the hash */
        $sha1sum   = substr($plugin_raw, strlen(self::$magic), 20);
        $pluginser = gzuncompress(substr($plugin_raw, strlen(self::$magic) + 20));
        if (sha1($pluginser, true) != $sha1sum) {
            throw new InvalidPackage("Wrong SHA1 hash");
        }

        $plugin = @unserialize($pluginser);
        if (!($plugin instanceof self)) {
            throw new InvalidPackage("Not the correct class or not unserializeable.");
        }

        $plugin->validate();

        return $plugin;
    }

    /*
     * Function: save
     * Save the plugin.
     *
     * Returns:
     *  A binary plugin package.
     *
     * Throws:
     *  <InvalidPackage> if package is invalid.
     */
    public function save()
    {
        $this->validate();
        $ser = serialize($this);
        return self::$magic . sha1($ser, true) . gzcompress($ser, 9);
    }

    /*
     * Function: extract_meta
     * Get just the metadata of this package.
     *
     * Returns:
     *  A <PluginPackageMeta> object.
     */
    public function extract_meta()
    {
        $meta = new PluginPackageMeta();

        $meta->name              = $this->name;
        $meta->author            = $this->author;
        $meta->versiontext       = $this->versiontext;
        $meta->versioncount      = $this->versioncount;
        $meta->api               = $this->api;
        $meta->short_description = $this->short_description;
        $meta->updatepath        = $this->updatepath;
        $meta->web               = $this->web;
        $meta->license           = $this->license;

        return $meta;
    }
}

/*
 * Class: PluginPackageMeta
 * Only the metadata of a <PluginPackage>.
 */
class PluginPackageMeta
{
    /*
     * Variables: Mandatory values
     *
     * $name              - Name of the plugin (must be at least one character, allowed chars: a-z A-Z 0-9 - _)
     * $author            - The author of the plugin (preferably in the format: Name<mail@address>)
     * $versiontext       - A text to describe the current version, something like "1.1 Beta"
     * $versioncount      - A number for this version, should be increased with every release
     * $api               - The used API version
     * $short_description - A short description.
     */
    public $name              = null;
    public $author            = null;
    public $versiontext       = null;
    public $versioncount      = null;
    public $api               = null;
    public $short_description = null;

    /*
     * Variables: Optional values
     *
     * $updatepath - A URL that points to a update information resource (serialize'd array("current-version" => VERSIONCOUNT, "dl-path" => DOWNLOAD PATH); will get overwritten/set by the default repository software.
     * $web        - A URL to the webpage for the plugin. If left empty, the default repository software will set this to the description page of your plugin.
     * $license    - The license text of your plugin.
     */
    public $updatepath = null;
    public $web        = null;
    public $license    = null;
}
