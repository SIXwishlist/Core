<?php
////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2007  Phorum Development Team                              //
//   http://www.phorum.org                                                    //
//                                                                            //
//   This program is free software. You can redistribute it and/or modify     //
//   it under the terms of either the current Phorum License (viewable at     //
//   phorum.org) or the Phorum License that was distributed with this file    //
//                                                                            //
//   This program is distributed in the hope that it will be useful,          //
//   but WITHOUT ANY WARRANTY, without even the implied warranty of           //
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.                     //
//                                                                            //
//   You should have received a copy of the Phorum License                    //
//   along with this program.                                                 //
//                                                                            //
////////////////////////////////////////////////////////////////////////////////

define('phorum_page','css');
include_once("./common.php");

// Set to FALSE to disable CSS compression.
define('PHORUM_COMPRESS_CSS', TRUE);

// Argument 1 should be the name of the css template to load.
if(isset($PHORUM["args"]["1"])){
    $css = basename((string)$PHORUM["args"]["1"]);
} else {
    trigger_error("Missing argument", E_USER_ERROR);
    exit(1);
}

/**
 * [hook]
 *     css_register
 *
 * [description]
 *     Modules can provide extra CSS data for CSS code that is retrieved
 *     through the css.php script. Extra CSS definitions can be added to
 *     the start and to the end of the base CSS code. Modules that make use
 *     of this facility should register the additional CSS code using
 *     this hook.
 *
 * [category]
 *     Templating
 *
 * [when]
 *     At the start of the css.php script.
 *
 * [input]
 *     An array, containing the following fields:
 *     <ul>
 *     <li><b>css</b><br>
 *         The name of the css file that was requested for the css.php
 *         script. Phorum requests either "css" or "css_print".
 *         The module can use this parameter to decide whether
 *         CSS code has to be registered or not.</li>
 *     <li><b>register</b><br>
 *         An array of registrations, filled by the modules. Modules
 *         can register their CSS code for inclusion in the base CSS
 *         file by adding a registration to this array. A registration
 *         is an array, containing the following fields:
 *         <ul>
 *         <li><b>module</b><br>
 *             The name of the module that adds the registration.
 *         </li>
 *         <li><b>where</b><br>
 *             This field determines whether the CSS data is added
 *             before or after the base CSS code. The value for this field
 *             is either "before" or "after".
 *         </li>
 *         <li><b>source</b><br>
 *             Specifies the source of the CSS data. This can be one of:
 *             <ul>
 *             <li><b>file(<path to filename>)</b><br>
 *                 For including a static CSS file. The path should be
 *                 absolute or relative to the Phorum install directory,
 *                 e.g. "file(mods/foobar/baz.css)". Because this file
 *                 is loaded using a PHP include() call, it is possible to
 *                 include PHP code in this file. Mind that this code
 *                 is stored interpreted in the cache.</li>
 *             <li><b>template(<template name>)</b><br>
 *                 For including a Phorum template,
 *                 e.g. "template(foobar::baz)"</li>
 *             <li><b>function(<function name>)</b><br>
 *                 For calling a function to retrieve the CSS code,
 *                 e.g. "function(mod_foobar_get_css)"</li>
 *             </ul>
 *         </li>
 *         <li><b>cache_key</b><br>
 *             To make caching of the generated CSS data
 *             possible, the module should provide the css.php script
 *             a cache key using this field. This cache key needs to
 *             change if the module will provide different CSS data.<br>
 *             <br>
 *             Note: in case "file" or "template" is used as the source,
 *             you are allowed to omit the cache_key. In that case, the
 *             modification time of the involved file(s) will be used as
 *             the cache key.<br>
 *             <br>
 *             It is okay for the module to provide multiple cache keys
 *             for different situations (e.g. if the CSS code depends on
 *             a group or so). Keep in mind though that for each different
 *             cache key, a separate cache file is generated. If you are
 *             generating different CSS code per user or so, then it might
 *             be better to add the CSS code differently (e.g. through a
 *             custom CSS generating script or by adding the CSS code to
 *             the $PHORUM['DATA']['HEAD_DATA'] variable. Also, do not use
 *             this to only add CSS code to certain phorum pages. Since
 *             the resulting CSS data is cached, it is no problem if you
 *             add the CSS data for your module to the CSS code for
 *             every page.
 *         </li>
 *         </ul>
 *     </li>
 *     </ul>
 *
 * [output]
 *     The same array as the one that was used for the hook call
 *     arguments, possibly with the "register" field updated.
 *     A module can add multiple registrations to the register array.
 */
$module_registrations = array();
if (isset($PHORUM['hooks']['css_register'])) {
    $res = phorum_hook('css_register', array(
        'css'      => $css,
        'register' => $module_registrations)
    );
    $module_registrations = $res['register'];
}

// Find the modification time for the css file and the settings file.
list ($css_php, $css_tpl) = phorum_get_template_file($css);
list ($settings_php, $settings_tpl) = phorum_get_template_file('settings');
$css_t = @filemtime($css_tpl);
$settings_t = @filemtime($settings_tpl);

// Generate the cache key. While adding cache keys for the module
// registrations, we also check the validity of the registration data.
$cache_key = $PHORUM['template'] .'|'.
             $css_t              .'|'.
             $settings_t;
foreach ($module_registrations as $id => $r)
{
    if (!isset($r['module'])) trigger_error(
        "css_register hook: module registration error: " .
        "the \"module\" field was not set."
    );
    if (!isset($r['source'])) trigger_error(
        "css_register hook: module registration error: " .
        "the \"source\" field was not set."
    );
    if (!isset($r['where'])) trigger_error(
        "css_register hook: module registration error: " .
        "the \"where\" field was not set."
    );
    if ($r['where'] != 'before' && $r['where'] != 'after') trigger_error(
        "css_register hook: module registration error: " .
        "illegal \"where\" field value\"{$r['where']}\"."
    );
    if (preg_match('/^(file|template|function)\((.+)\)$/', $r['source'], $m))
    {
        $module_registrations[$id]['type']   = $m[1];
        $module_registrations[$id]['source'] = $m[2];

        switch ($m[1])
        {
            case "file":

                if (!isset($r['cache_key'])) {
                    $mtime = @filemtime($m[2]);
                    $r['cache_key'] = $mtime;
                    $module_registrations[$id]['cache_key'] = $mtime;
                }
                break;

            case "template":

                // We load the parsed template into memory. This will refresh
                // the cached template file if required. This is the easiest
                // way to make this work correctly for nested template files.
                ob_start();
                include(phorum_get_template($m[2]));
                $module_registrations[$id]['content'] = ob_get_contents();
                ob_end_clean();

                // We use the mtime of the compiled template as the cache
                // key if no specific cache key was set.
                if (!isset($r['cache_key'])) {
                    list ($php, $tpl) = phorum_get_template_file($m[2]);
                    $mtime = @filemtime($php);
                    $r['cache_key'] = $mtime;
                    $module_registrations[$id]['cache_key'] = $mtime;
                }
                break;

            case "function":

                if (!isset($r['cache_key'])) trigger_error(
                    "css_register hook: module registration error: " .
                    "\"cache_key\" field missing for source " .
                    "\"{$r['source']}\" in module \"{$r['module']}\"."
                );

                break;
        }
    } else trigger_error(
        "css_register hook: module registration error: " .
        "illegal format for source definition \"{$r['source']}\" " .
        "in module \"{$r['module']}\"."
    );

    $cache_key .= '|' . $r['module'] . ':' . $r['cache_key'];
}

// Generate the final cache key.
$cache_key = md5($cache_key . __FILE__);

// Generate the cache file name.
$cache_file = "{$PHORUM['cache']}/tpl-{$PHORUM['template']}-css-$css-" .
              md5($cache_key . __FILE__);

// Create the cache file if it does not exist or if caching is disabled.
if (empty($PHORUM['cache_css']) || !file_exists($cache_file))
{
    ob_start();
    include(phorum_get_template($css));
    $base = ob_get_contents();
    ob_end_clean();

    $before = '';
    $after  = '';

    foreach ($module_registrations as $id => $r)
    {
        $add = "/* Added by module \"{$r['module']}\", " .
               "{$r['type']} \"{$r['source']}\" */\n";
        switch ($r['type'])
        {
            case "file":
                ob_start();
                include($r['source']);
                $add .= ob_get_contents();
                ob_end_clean();
                break;

            case "template":
                $add .= $r['content'];
                break;

            case "function":
                $add .= call_user_func($r['source']);
                break;
        }
        if ($r['where'] == 'before') {
            $before .= ($before == '') ? $add : "\n\n$add";
        } else {
            $after .= "\n\n$add";
        }
    }

    $content = "$before\n$base\n$after";

    // Compress the CSS code.
    if (PHORUM_COMPRESS_CSS)
    {
        $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);
        $content = str_replace(
            array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '),
            '', $content
        );
    }

    if (!empty($PHORUM['cache_css'])) {
        include_once "./include/templates.php";
        phorum_write_file($cache_file, $content);
    }

    // Send the RSS to the browser.
    header("Content-Type: text/css");
    print $content;

    // Exit here explicitly for not giving back control to portable and
    // embedded Phorum setups.
    exit(0);
}

// Find the modification time for the cache file.
$last_modified = @filemtime($cache_file);

// Check if a If-Modified-Since header is in the request. If yes, then
// check if the CSS code has changed, based on the filemtime() data from
// above. If nothing changed, then we can return a 304 header, to tell the
// browser to use the cached data.
if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
    $header = preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]);
    $if_modified_since = strtotime($header);

    if ($if_modified_since >= $last_modified) {
        header("HTTP/1.0 304 Not Modified");
        exit(0);
    }
}

// Send the RSS to the browser.
header("Content-Type: text/css");
header("Last-Modified: " . date("r", $last_modified));

include($cache_file);

// Exit here explicitly for not giving back control to portable and
// embedded Phorum setups.
exit(0);

?>