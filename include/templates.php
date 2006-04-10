<?php

////////////////////////////////////////////////////////////////////////////////
//                                                                            //
//   Copyright (C) 2006  Phorum Development Team                              //
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
////////////////////////////////////////////////////////////////////////////////

// TODO:
// * Add an AND/OR feature to the {IF ..} statement.
// * Add syntax checks to warn template writers of problems in
//   a controlled way, instead of letting them bump hard into
//   PHP error messages.
// * Also add some generic simple syntax checks that for example
//   check the number of arguments for a template statement.
// * See if we can allow for {LOOP ARRAYVARIABLE} .. {/LOOP}
//   (so the loop ending statement without the name of the variable).

if(!defined("PHORUM")) return;

/**
 * Mainly used for cirular loop protection in template includes. The
 * default value should be more than sufficient for any template.
 */
define("PHORUM_TEMPLATES_MAX_INCLUDE_DEPTH", 50);

/**
 * The message to use for deprecated statements. The % character
 * is replaced with the statement that has been deprecated.
 */
define("PHORUM_DEPRECATED", "[Template statement \"%\" has been deprecated; Please read docs/upgrade_templates.txt]");

/**
 * Converts a Phorum template into PHP code and writes the resulting code
 * to disk. This is the only call from templates.php that is called from
 * outside this file. All other functions are used internally for the
 * template compiling process.
 *
 * @param $page - The template page name (as used for phorum_get_template())
 * @param $infile - The template input file to process
 * @param $outfile - The PHP file to write the resulting code to.
 */
function phorum_import_template($page, $infile, $outfile)
{
    // Template pass 1:
    // Recursively process all template {include ...} statements, to
    // construct a single template data block.
    list ($template, $dependancies) = phorum_import_template_pass1($infile);

    // Template pass 2:
    // Translate all other template statements into PHP code.
    $template = phorum_import_template_pass2($template);

    // Write the compiled template to disk.
    //
    // For storing the compiled template, we use two files. The first one
    // has some code for checking if one of the dependant files has been
    // updated and for rebuilding the template if this is the case.
    // This one loads the second file, which is the compiled template itself.
    //
    // This two-stage loading is needed to make sure that syntax
    // errors in a template file won't break the depancy checking process.
    // If both were in the same file, the complete file would not be run
    // at all and the user would have to clean out the template cache to
    // reload the template once it was fixed. This way user intervention
    // is never needed.

    $stage1file = $outfile;
    $stage2file = $outfile . "-stage2";

    // Output file for stage 1. This file contains code to check the file
    // dependancies. If one of the files that the template depends on is
    // changed, the template has to be rebuilt.
    $checks = array();
    foreach ($dependancies as $file => $mtime) {
        $qfile = addslashes($file);
        $checks[] = "@filemtime(\"$qfile\") > $mtime";
    }
    $qstage1file = addslashes($stage1file);
    $qstage2file = addslashes($stage2file);
    $qpage = addslashes($page);
    $stage1 = "<?php
      if (" . implode(" || ", $checks) . ") {
          @unlink (\"$qstage1file\");
          include(phorum_get_template(\"$qpage\"));
          return;
      } else {
          include(\"$qstage2file\");
      }
      ?>";
    phorum_write_file($stage1file, $stage1);

    // Output file for stage 2. This file contains the compiled template.
    phorum_write_file($stage2file, $template);
}

/**
 * Runs the first stage of the Phorum template processing. In this stage,
 * all (static) {include <template>} statements are recursively resolved.
 * After resolving all includes, a complete single template is constructed.
 * During this process, the function will keep track of all file
 * dependancies for the constructed template.
 *
 * @param $infile - The template file to process.
 * @param $include_depth - Current include depth (only for recursive call).
 * @param $deps - File dependancies (only for recursive call)
 * @param $include_once - Already include pages (only for recursive call)
 * @return $template - The constructed template data.
 * @return $dependancies - An array containing file dependancies for the
 *     created template data. The keys are filenames and the values are
 *     file modification times.
 */
function phorum_import_template_pass1($infile, $include_depth = 0, $deps = array(), $include_once = array())
{
    $include_depth++;

    if ($include_depth > PHORUM_TEMPLATES_MAX_INCLUDE_DEPTH) die(
        "phorum_import_template_pass1: the include depth has passed " .
        "the maximum allowed include depth of " .
        PHORUM_TEMPLATES_MAX_INCLUDE_DEPTH . ". Maybe some circular " .
        "include loop was introduced? If not, then you can raise the " .
        "value for the PHORUM_TEMPLATES_MAX_INCLUDE_DEPTH definition " .
        "in " . htmlspecialchars(__FILE__) . ".");

    $deps[$infile] = filemtime($infile);

    $template = phorum_read_file($infile);

    // Process {include [once] "page"} statements in the template.
    preg_match_all("/\{include\s+(.+?)\}/is", $template, $matches);
    for ($i=0; $i<count($matches[0]); $i++)
    {
        $tokens = phorum_tokenize_statement($matches[1][$i]);

        // Find out if we have a static value for the include statement.
        // Dynamic values are handled in pass 2.
        $only_once = false;
        if (strtolower($tokens[0]) == "once" && isset($tokens[1])) {
            $only_once = true;
            array_shift($tokens);
        }
        list ($page, $type) = phorum_templatevalue_to_php(NULL,$tokens[0]);
        if ($type == "variable" || $type == "definition") continue;

        // Since $value contains PHP code now, we have to resolve that
        // code into a real value.
        eval("\$page = $page;");

        if ($only_once && isset($include_once[$page])) {
            $replace = '';
        } else {
            list ($subout, $subin) = phorum_get_template_file($page);
            if ($subout == NULL) {
                $replace = phorum_read_file($subin);
            } else {
                list ($replace, $deps) =
                    phorum_import_template_pass1($subin, $include_depth, $deps, $include_once);
            }
            $include_once[$page] = true;
        }

        $template = str_replace($matches[0][$i], $replace, $template);
    }

    return array($template, $deps);
}

/**
 * Runs the second stage of Phorum template processing. In this stage,
 * all template statements are translated into PHP code.
 *
 * @param $template - The template data to process.
 * @return $template - The processed template data.
 */
function phorum_import_template_pass2($template)
{
    // This array is used for keeping track of loop variables.
    $loopvars = array();

    // Find and process all template statements in the code.
    preg_match_all("/\{[\"\'\!\/A-Za-z0-9].+?\}/s", $template, $matches);
    foreach ($matches[0] as $match)
    {
        // Strip surrounding { .. } from the statement.
        $string=substr($match, 1, -1);
        $string = trim($string);

        // Pre-parse pointer variables.
        if (strstr($string, "->")){
            $string = str_replace("->", "']['", $string);
        }

        // Process the template statement by creating replacement code for it.
        $tokens = phorum_tokenize_statement($string);
        switch (strtolower($tokens[0]))
        {
            // COMMENTS ------------------------------------------------------

            // Syntax:
            //    {! <comment string>}
            // Function:
            //    Adding comments to templates
            //
            // These are only used for commenting template code and they are
            // fully removed from the template.
            //
            case "!":
                $repl = "";
                break;

            // INCLUDE -------------------------------------------------------

            // Syntax:
            //     {include [once] <value>}
            // Function:
            //     Include a template. The name of the template to
            //     include is in the <value>. If the keyword "once"
            //     is used, the specified template is only included
            //     once on the page.
            //
            // {include ..} statements that use a string are handled in
            // template pass 1 already. There static includes are fully
            // resolved into one single template. So here, only PHP
            // defined values and template variables are handled.
            //
            case "include":
                $include = "include";
                $statement = array_shift($tokens); 
                if (strtolower($tokens[0]) == "once" && isset($tokens[1])) {
                    $include = "include_once";
                    array_shift($tokens);
                }
                $variable = array_shift($tokens);

                list ($value,$type) = phorum_templatevalue_to_php($loopvars, $variable);
                $repl = "<?php $include phorum_get_template($value); ?>";
                break;

            case "include_var":
                $repl = str_replace("%", "include_once", PHORUM_DEPRECATED);
                break;

            case "include_once":
                $repl = str_replace("%", "include_once", PHORUM_DEPRECATED);
                break;

            // VAR, DEFINE and ASSIGN ----------------------------------------

            // Syntax:
            //     {var <variable> <value>}
            //     {assign <variable> <value>}
            // Function:
            //     Set variables that are used in the templates.
            //
            // This will set $PHORUM["DATA"][<variable>] = <value>;
            // After this, the variable is usable in template statements like
            // {<variable>} and {IF <variable>}...{/IF}.
            //
            // Syntax:
            //    {define <variable> <value>}
            // Function:
            //    Set definitions that are used by the Phorum core.
            //
            // This will set $PHORUM["TMP"][<variable>] = <value>
            // This data is not accessible through templating statements (and
            // it's not supposed to be). The data should only be accessed
            // from Phorum core and module code.
            //
            case "var":
            case "define":
                $statement = strtolower(array_shift($tokens));
                $index = $statement == "define" ? "TMP" : "DATA";
                $variable = phorum_templatevariable_to_php($index, array_shift($tokens));
                list ($value, $type) = phorum_templatevalue_to_php($loopvars, array_shift($tokens));
                $repl = "<?php $variable = $value; ?>";
                break;

            // Assign has been deprecated. Use {var ..} instead.
            case "assign":
                $repl = str_replace("%", "assign", PHORUM_DEPRECATED);
                break;

            // LOOP ----------------------------------------------------------

            // Syntax:
            //     {loop <array variable>}
            //         .. loop code ..
            //     {/loop <array variable>}
            // Function:
            //     Loop through all elements of an array variable.
            // Example:
            //     {loop arrayvar}
            //         Element is: {arrayvar}
            //     {/loop arrayvar}
            //
            // The array variable to loop through has to be set in variable
            // $PHORUM["DATA"][<array variable>]. While looping through this
            // array, elements are put in $PHORUM["TMP"][<array variable>].
            // If constructions like {<array variable>} are used inside the
            // loop, the element in $PHORUM["TMP"] will be used.
            //
            // $PHORUM['LOOPSTACK'] is used to be able to nest {LOOP ..}
            // statements. If a loopvar already is in use when entering
            // the loop, that loopvar is pushed on the stack. After the
            // loop has ended, it can be popped and restored.
            //
            case "loop":
                $statement = array_shift($tokens);
                $varname = array_shift($tokens);
                $variable = phorum_templatevariable_to_php($loopvars, $varname);
                $loopvariable = "\$PHORUM['TMP']['$varname']";
                $loopvars[$varname] = true;
                $repl =
                    "<?php " .
                    "\$PHORUM['LOOPSTACK'][] = isset($loopvariable) ? $loopvariable : NULL;" .
                    "if (isset($variable) && is_array($variable))" .
                    "  foreach ($variable as $loopvariable) { " .
                    "?>";
                break;

            case "/loop":
                if (!isset($tokens[1])) {
                    print "[Template warning: Missing argument for /loop statement]";
                }
                $statement = array_shift($tokens);
                $varname = array_shift($tokens);
                $loopvariable = "\$PHORUM['TMP']['$varname']";
                $repl =
                    "<?php " .
                    "  }" .
                    "  if (isset(\$PHORUM['TMP']) && isset($loopvariable))" .
                    "    unset($loopvariable);" .
                    "  \$PHORUM['LOOPSTACK_ITEM'] = array_pop(\$PHORUM['LOOPSTACK']);" .
                    "  if (isset(\$PHORUM['LOOPSTACK_ITEM']))" .
                    "    $loopvariable = \$PHORUM['LOOPSTACK_ITEM']; " .
                    "?>";
                unset($loopvars[$varname]);
                break;

            // IF/ELSEIF/ELSE ------------------------------------------------

            // Syntax:
            //     {if [not] <condition>}
            //         .. conditional code ..
            //     [{elseif [not] <condition>}
            //         .. conditional code ..]
            //     [{else}
            //         .. conditional code ..]
            //     {/if}
            //
            //     The <condition> can be:
            //     <variable>
            //         True if the variable (template variable or loop
            //         variable) is set and not empty.
            //     <variable> <number|"string"|php defined constant>
            //         Compares the variable to a number, string or constant.
            //     <variable> <othervariable>
            //         Compares the variale to another variable.
            // Function:
            //     Run conditional code.
            // Example:
            //     {if somevariable}somevariable is true{/if}
            //     {if not somevariable 1}somevariable is not 1{/if}
            //     {if thevar "somevalue"}thevar contains "somevalue"{/if}
            //     {if thevar phpdefine}thevar and phpdefine are equal{/if}
            //     {if thevar othervar}thevar and othervar are equal{/if}
            //
            case "if":
            case "elseif":
                // Determine if we're handling "if" or "elseif".
                $statement = strtolower(array_shift($tokens));
                $prefix = $statement=="if" ? "if" : "} elseif";

                // Determine if we need "==" or "!=" for the condition.
                if (strtolower($tokens[0]) == "not") {
                    $operator = "!=";
                    array_shift($tokens);
                } else {
                    $operator="==";
                }

                // Determine what variable we are comparing to in the condition.
                $variable = phorum_templatevariable_to_php($loopvars, array_shift($tokens));

                // If there is no value to compare to, then check if
                // the value for the variable is set and not empty.
                if (!isset($tokens[0])) {
                    if ($operator == "=="){
                        $repl = "<?php $prefix (isset($variable) && !empty($variable)) { ?>";
                    } else {
                        $repl = "<?php $prefix (!isset($variable) || empty($variable)) { ?>";
                    }
                }
                // There is a value. Make a comparison to that value.
                else {
                    list ($value, $type) = phorum_templatevalue_to_php($loopvars, array_shift($tokens));
                    if ($type == "variable") {
                        $repl = "<?php $prefix (isset($variable) && isset($value) && $variable $operator $value) { ?>";
                    } else {
                        $repl = "<?php $prefix (isset($variable) && $variable $operator $value) { ?>";
                    }
                }
                break;

            case "else":
                $repl="<?php } else { ?>";
                break;

            case "/if":
                $repl="<?php } ?>";
                break;

            // HOOK ----------------------------------------------------------

            // Syntax:
            //     {hook <hook name> [<param 1> <param 2> .. <param n>]}
            // Function:
            //     Run a Phorum hook. The first parameter is the name of the
            //     hook. Other parameters will be passed on as arguments for
            //     the hook function. One argument will be passed directly to
            //     the hook. Multiple arguments will be passed in an array.
            // Example:
            //     {hook "my_hook" USER->username}
            //
            case "hook":
                $statement = array_shift($tokens);

                // Find the hook to run.
                list ($hook, $type) = phorum_templatevalue_to_php($loopvars, array_shift($tokens));

                // Setup hook arguments.
                $hookargs = array();
                while ($token = array_shift($tokens)) {
                    list ($value, $type) = phorum_templatevalue_to_php($loopvars, $token);
                    $hookargs[] = $value;
                }

                // Build the replacement string.
                $repl = "<?php if(isset(\$PHORUM['hooks'][$hook])) phorum_hook($hook";
                if (count($hookargs) == 1) {
                    $repl .= "," . $hookargs[0];
                } elseif (count($hookargs) > 1) {
                    $repl .= ",array(" . implode(",", $hookargs) . ")";
                }
                $repl .= ") ?>";
                break;

            // ECHO A VARIABLE -----------------------------------------------

            // Syntax:
            //     {<variable>}
            // Function:
            //     Echo the value for the <variable> on screen. The <variable>
            //     can be (in order of importance) a PHP constant definition
            //     value, a template loop variable or a template variable.
            //
            default:
                list ($value, $type) = phorum_templatevalue_to_php($loopvars, $tokens[0]);
                $repl = "<?php echo $value ?>";

        }

        // Replace all occurances of the template statement in the template.
        $template = str_replace($match, $repl, $template);
    }

    // Add some initialization code to the template.
    $template = 
        "<?php if(!defined(\"PHORUM\")) return; ?>\n" .
        "<?php \$PHORUM['LOOPSTACK'] = array() ?>\n" .
        $template;

    return $template;
}

/**
 * Splits a template statement into separate tokens. This will split the
 * statement on whitespace, except for string tokens that look like 
 * "a string" or 'a string'. Inside the string tokens, quotes can be
 * escaped using \" and \' (just like in PHP).
 *
 * @param $statement - The statement to tokenize.
 * @return $tokens - An array of tokens.
 */
function phorum_tokenize_statement($statement)
{
    $tokens = array();    

    $quote = NULL;
    $escaped = false;
    $token = '';

    for ($i=0; $i<strlen($statement); $i++)
    {
        $ch = substr($statement, $i, 1);

        // Handle characters inside a quoted piece?
        if ($quote != NULL)
        {
            // Simply add escaped characters.
            if ($escaped) {
                $token .= $ch; 
                $escaped = false;
                continue;
            }

            // The start of an escaped character.
            if ($ch == '\\') {
                $token .= $ch;
                $escaped = true;
                continue;
            }

            // The end of the quoted string reached?
            if ($ch == $quote) {
                $token .= $ch;
                $quote = NULL;
                continue;
            }

            // All other characters add to the current token.
            $token .= $ch;
            continue;
        }

        // " and ' start a new quoted string.
        if ($token == "" && ($ch == '"' || $ch == "'")) {
            $quote = $ch;
            $token .= $ch;
            continue;
        }

        // Whitespace starts a new token.
        if ($ch == "\n" || $ch == " " || $ch == "\t") {
            if ($token != "") {
                $tokens[] = $token;
                $token = "";
            }
            continue;
        }

        // All other characters add to the current token.
        $token .= $ch;
    }

    // Add the last token to the array.
    if ($token != "") {
        $tokens[] = $token;
    }

    return $tokens;
}

/**
 * Determines wheter a template variable should be used from
 * $PHORUM["DATA"] (the default location) or $PHORUM["TMP"]
 * (for loop variables).
 *
 * @param $loopvars - The current array of loop variables.
 * @param $varname - The name of the variable for which to do the lookup.
 * @return The index for the $PHORUM array; either "DATA" or "TMP".
 */
function phorum_determine_index($loopvars, $varname)
{
    if (isset($loopvars) && count($loopvars)) {
        for(;;) {
            if (isset($loopvars[$varname])) {
                return "TMP";
            }
            if (strstr($varname, "]")){
                $varname = substr($varname, 0, strrpos($varname, "]")-1);
            } else {
                break;
            }
        }

    }

    return "DATA";
}

/**
 * Translates a template variable name into a PHP string.
 *
 * @param $index - Determines if TMP or DATA is used 
 *     as the index. If the $index is an array of loopvars,
 *     it's determined automatically. If it's set to a scalar
 *     value of "TMP" or "DATA", then that index is used.
 * @param $varname - The name of the variable to translate.
 * @return $phpcode - The PHP representation of the $varname.
 */
function phorum_templatevariable_to_php($index, $varname)
{
    if (is_array($index)) {
        $index = phorum_determine_index($index, $varname);
    }
    if ($index != "DATA" && $index != "TMP") {
        die ("phorum_templatevariable_to_php: illegal \$index \"$index\"");
    }
    return "\$PHORUM['$index']['$varname']";
}

/**
 * Translates a template statement value into a PHP string.
 * This supports the following structures:
 * - integer (e.g. 1)
 * - string (e.g. "string value")
 * - definition (e.g. mydef when set by define("mydef","myval")
 * - variable (e.g. USER->username)
 *
 * @param $loopvars - The current array of loop variables.
 * @param $value - The value to translate.
 * @return $phpcode - The PHP representation of the $value. 
 * @return $type - The type of value.
 */
function phorum_templatevalue_to_php($loopvars, $value)
{
    // Integers
    if (is_numeric($value)) { 
        $type = "integer";
    }
    // Strings
    elseif (preg_match('!^(".*"|\'.*\')$!', $value)) {
        $type = "string";
    }
    // PHP definitions
    elseif (defined($value)) {
        $type = "definition";
    }
    // Template variables
    else {
        $type = "variable";
        $index = phorum_determine_index($loopvars, $value);
        $value = "\$PHORUM['$index']['$value']";
    }

    return array($value, $type);
}

/**
 * Reads a file from disk and returns the contents of that file.
 *
 * @param $file - The filename of the file to read.
 * @return $data - The contents of the file.
 */
function phorum_read_file($file)
{
    // Check if the file exists.
    if (! file_exists($file)) die(
        "phorum_get_file_contents: file \"" . htmlspecialchars($file) . "\" " .
        "does not exist");

    // In case we're handling a zero byte large file, we don't read it in.
    // Running fread($fp, 0) gives a PHP warning.
    $size = filesize($file);
    if ($size == 0) return "";

    // Read in the file contents.
    if (! $fp = fopen($file, "r")) die(
        "phorum_get_file_contents: failed to read file " .
        "\"" . htmlspecialchars($file) . "\"");
    $data = fread($fp, $size);
    fclose($fp);

    return $data;
}

/**
 * Writes a file do disk, with thorough error checking.
 *
 * @param $file - The filename of the file to write the data to.
 * @param $data - The data to put in the file.
 */
function phorum_write_file($file, $data)
{
    // Write the data to the file.
    if (! $fp = fopen($file, "w")) die(
        "phorum_write_file: failed to write to file " .
        "\"" . htmlspecialchars($file) . "\". This is probably caused by " .
        "the file permissions on your Phorum cache directory"); 
    fputs($fp, $data);
    if (! fclose($fp)) die(
        "phorum_write_file: error on closing the file " .
        "\"" . htmlspecialchars($file) . "\". Is your disk full?");

    // A special check on the created outputfile. We have seen strange
    // things happen on Windows2000 where the webserver could not read
    // the file it just had written :-/
    if (! $fp = fopen($file, "r")) die(
        "Failed to write a usable compiled template to the file " .
        "\"" . htmlspecialchars($outfile) . "\". The file was created " .
        "successfully, but it could not be read by the webserver " .
        "afterwards. This is probably caused by the filepermissions " .
        "on your cache directory."
    );
    fclose($fp);
}


?>
