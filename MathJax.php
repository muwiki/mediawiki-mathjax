<?php

if (!defined('MEDIAWIKI')) {
    die('This is a mediawiki extensions and can\'t be run from the command line.');
}



//Hooks::register('ParserFirstCallInit', 'MathJax_Parser::RunMathJax');


class MathJax_Parser
{

    public static $Markers;

    public static $mark_n = 0;

    public static $defaultMathJaxPath = '/resources/lib/MathJax/MathJax.js';

    public static $defaultMathJaxConfig = 'TeX-AMS-MML_HTMLorMML-full';

    public static $defaultCustomConfig = '/resources/src/mathjax-config.js';



    public static function RunMathJax(Parser $parser)
    {
        $parser->setHook('nomathjax', 'MathJax_Parser::NoMathJax');

        Hooks::register('ParserBeforeStrip', 'MathJax_Parser::RemoveMathTags');
        Hooks::register('InternalParseBeforeLinks', 'MathJax_Parser::ReplaceByMarkers');
        Hooks::register('ParserAfterTidy', 'MathJax_Parser::RemoveMarkers');
        Hooks::register('BeforePageDisplay', 'MathJax_Parser::Inject_JS');

        return true;
    }



    public static function RemoveMathTags(&$parser, &$text)
    {
        $text = preg_replace('|:\<math>(.*?)</math>|s', '\\[$1\\]', $text);
        $text = preg_replace('|<math>(.*?)</math>|s', '\\($1\\)', $text);

        return true;
    }



    public static function Inject_JS(OutputPage $out)
    {
        global $wgMathJaxJS, $wgMathJaxProcConf, $wgMathJaxLocConf;

        if (self::$mark_n == 0) {
//            return true;
        } // there was no math detected

        $file = $wgMathJaxJS ?: self::$defaultMathJaxPath;
        $config = ($wgMathJaxProcConf ?: self::$defaultMathJaxConfig);
        $userConfig = ($wgMathJaxLocConf ?: self::$defaultCustomConfig);

        $url = $file . '?' . http_build_query(['config' => $config . ',' . $userConfig], NULL, '&');
        $out->addScript(Html::linkedScript($url));

        return true;
    }



    public static function ReplaceByMarkers(Parser $parser, &$text)
    {
        $text = preg_replace_callback('/(\$\$)(.*?)(\$\$)/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('|(?<![\{\/\:\\\\])(\$)(.*?)(?<![\\\\])(\$)|s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\\\\[)(.*?)(\\\\\])/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\\\\()(.*?)(\\\\\))/s', 'MathJax_Parser::Marker', $text);
        $text = preg_replace_callback('/(\\\begin{(?:.*?)})(.*?)(\\\end{(?:.*?)})/s', 'MathJax_Parser::Marker', $text);

        return true;
    }



    public static function NoMathJax($text, array $args, Parser $parser, PPFrame $frame)
    {
        $output = $parser->recursiveTagParse($text, $frame);

        return '<span class="tex2jax_ignore">' . $output . '</span>';
    }



    public static function RemoveMarkers(Parser &$parser, &$text)
    {
        /** <!-- array_values seems to return a string with "..." instead of '...' so that double backslashes (\\)
         *  becomes a single backslash (\) so that I do need the MarkerVal function since modifying a code
         *  in math mode to match these backslashes may cause unexpected behaviors. -->
         **/
        #$text = preg_replace(array_keys(self::$Markers), array_values(self::$Markers), $text);
        $text = preg_replace_callback('/' . Parser::MARKER_PREFIX . 'MathJax(?:.*?)' . Parser::MARKER_SUFFIX . '/s', 'MathJax_Parser::MarkerVal', $text);

        return true;
    }



    public static function MarkerVal($matches)
    {
        return self::$Markers[$matches[0]];
    }



    public static function Marker($matches)
    {
        $marker = Parser::MARKER_PREFIX . 'MathJax' . ++self::$mark_n . Parser::MARKER_SUFFIX;

        $matches[2] = str_replace('<', '&#60;', $matches[2]);
        $matches[2] = str_replace('>', '&#62;', $matches[2]);
        self::$Markers[$marker] = $matches[1] . $matches[2] . $matches[3];

        return $marker;
    }
}

