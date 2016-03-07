<?php

use Nette\Utils\Html as NHtml;
use Nette\Utils\Strings as NStrings;

if (!defined('MEDIAWIKI')) {
    die('This is a mediawiki extensions and can\'t be run from the command line.');
}



class MathJax_Parser
{
    const MARKER_PREFIX = "\x7fMATH-";
    const MARKER_SUFFIX = "-HTAM\x7f";

    public static $defaultMathJaxPath = '/resources/lib/MathJax/MathJax.js';

    public static $defaultMathJaxConfig = 'TeX-AMS-MML_HTMLorMML-full';

    public static $defaultCustomConfig = '/resources/src/mathjax-config.js';

    private static $markersCounter = 0;
    private static $makers = [];



    public static function parserInit(Parser $parser)
    {
        Hooks::register('ParserBeforeStrip', self::class . '::markBlockMathTags');
        $parser->setHook('math', self::class . '::mathTag');
        $parser->setHook('nomathjax', self::class . '::noMathJaxTag');
        Hooks::register('ParserAfterTidy', self::class . '::afterTidy');
        Hooks::register('BeforePageDisplay', self::class . '::Inject_JS');

        return true;
    }



    public static function markBlockMathTags(Parser $parser, &$text)
    {
        $text = NStrings::replace($text, '~:\\<math\\>~', '<math display="block">');

        return true;
    }



    public static function mathTag($content, array $attrs, Parser $parser, PPFrame $frame)
    {
        $script = NHtml::el('script', ['type' => 'math/tex'])
            ->setHtml($content);
        if (!empty($attrs['display']) && $attrs['display'] === 'block') {
            $script->attrs['type'] .= '; mode=display';
        }

        if (self::containsXss($script)) {
            return '<span style="color:red">Invalid latex syntax</span>';
        }

        self::$makers[++self::$markersCounter] = (string) $script;

        return [
            Parser::MARKER_PREFIX . 'mathjax-' . self::$markersCounter . Parser::MARKER_PREFIX, // content
            'markerType' => 'nowiki'
        ];
    }



    public static function noMathJaxTag($text, array $args, Parser $parser, PPFrame $frame)
    {
        $output = $parser->recursiveTagParse($text, $frame);

        return '<span class="tex2jax_ignore">' . $output . '</span>';
    }



    public function afterTidy(Parser $parser, &$text)
    {
        $text = NStrings::replace($text, '~' . preg_quote(Parser::MARKER_PREFIX) . 'mathjax\\-(?P<id>\\d+)' . preg_quote(Parser::MARKER_PREFIX) . '~', function (array $match) {
            return self::$makers[$match['id']];
        });

        return true;
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



    private static function containsXss(NHtml $script)
    {
        if (!NStrings::checkEncoding($script->getHtml())) {
            return true;
        }

        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->preserveWhiteSpace = true;
        $dom->resolveExternals = false;
        $dom->strictErrorChecking = false;
        $dom->recover = true;

        $previousInternalErrors = libxml_use_internal_errors(true);
        $previousEntityLoader = libxml_disable_entity_loader();
        MediaWiki\suppressWarnings();
        $result = $dom->loadHTML(self::prepareHtmlDocument($script));
        MediaWiki\restoreWarnings();
        libxml_disable_entity_loader($previousEntityLoader);
        $htmlErrors = libxml_get_errors();
        libxml_use_internal_errors($previousInternalErrors);

        if (!$result || $htmlErrors) {
            return true;
        }

        $scripts = $dom->getElementsByTagName('script');
        if ($scripts->length > 1) {
            return true;
        }

        $filteredContent = $scripts->item(0)->textContent;
        if ($script->getHtml() !== $filteredContent) {
            return true;
        }

        return false;
    }

    private static function prepareHtmlDocument($script)
    {
        return '<!doctype html><html>'
            . '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>'
            . '<body>' . $script . '</body>'
            . '</html>';
    }

}
