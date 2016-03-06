<?php

use Nette\Utils\Html as NHtml;

if (!defined('MEDIAWIKI')) {
    die('This is a mediawiki extensions and can\'t be run from the command line.');
}



class MathJax_Parser
{

    public static $mark_n = 0;

    public static $defaultMathJaxPath = '/resources/lib/MathJax/MathJax.js';

    public static $defaultMathJaxConfig = 'TeX-AMS-MML_HTMLorMML-full';

    public static $defaultCustomConfig = '/resources/src/mathjax-config.js';



    public static function parserInit(Parser $parser)
    {
        Hooks::register('ParserBeforeStrip', self::class . '::markBlockMathTags');
        $parser->setHook('math', self::class . '::mathTag');
        $parser->setHook('nomathjax', self::class . '::noMathJaxTag');
        Hooks::register('BeforePageDisplay', self::class . '::Inject_JS');

        return true;
    }



    public static function markBlockMathTags(Parser $parser, &$text)
    {
        $text = preg_replace('~:\\<math\\>~', '<math display="block">', $text);

        return true;
    }



    public static function mathTag($text, array $args, Parser $parser, PPFrame $frame)
    {
        $script = NHtml::el('script', ['type' => 'math/tex']);
        if (!empty($args['display']) && $args['display'] === 'block') {
            $script->attrs['type'] .= '; mode=display';
        }

        $script->setHtml($text);

        return (string) $script;
    }



    public static function noMathJaxTag($text, array $args, Parser $parser, PPFrame $frame)
    {
        $output = $parser->recursiveTagParse($text, $frame);

        return '<span class="tex2jax_ignore">' . $output . '</span>';
    }



    public static function Inject_JS(OutputPage $out)
    {
        global $wgMathJaxJS, $wgMathJaxProcConf, $wgMathJaxLocConf;

        $file = $wgMathJaxJS ?: self::$defaultMathJaxPath;
        $config = ($wgMathJaxProcConf ?: self::$defaultMathJaxConfig);
        $userConfig = ($wgMathJaxLocConf ?: self::$defaultCustomConfig);

        $url = $file . '?' . http_build_query(['config' => $config . ',' . $userConfig], NULL, '&');
        $out->addScript(Html::linkedScript($url));

        return true;
    }

}
