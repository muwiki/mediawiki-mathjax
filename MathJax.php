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

    private static $jsInserted = false;



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
        $text = NStrings::replace($text, '~:\\<math([^\\>]*)\\>~', '<math display="block"$1>');

        return true;
    }



    public static function mathTag($content, array $attrs, Parser $parser, PPFrame $frame)
    {
        $math = self::preProcessLinks($content, $parser, $frame);

        $script = NHtml::el('script', ['type' => 'math/tex'])
            ->setHtml($math);

        $wrapper = 'span';
        if (!empty($attrs['display']) && $attrs['display'] === 'block') {
            $script->attrs['type'] .= '; mode=display';
            $wrapper = 'div';
        }

        if (self::containsXss($script)) {
            return '<span style="color:red">Invalid latex syntax</span>';
        }

        if (!empty($attrs['id'])) {
            $script = NHtml::el($wrapper, ['id' => 'equation-' . $attrs['id'], 'class' => 'math-equation'])
                ->add($script);
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



    public static function afterTidy(Parser $parser, &$text)
    {
        $text = NStrings::replace($text, '~' . preg_quote(Parser::MARKER_PREFIX) . 'mathjax\\-(?P<id>\\d+)' . preg_quote(Parser::MARKER_PREFIX) . '~', function (array $match) {
            return self::$makers[$match['id']];
        });

        return true;
    }



    public static function Inject_JS(OutputPage $out)
    {
        global $wgMathJaxJS, $wgMathJaxProcConf, $wgMathJaxLocConf;

        if (self::$jsInserted === true) {
            return true;
        }

        $userConfig = ($wgMathJaxLocConf ?: self::$defaultCustomConfig);
        $configContents = file_get_contents(__DIR__ . '/../../public' . $userConfig);
        $out->addScript(\Html::rawElement(
            'script',
            ['type' => 'text/x-mathjax-config'],
            "\n" . $configContents . "\n"
        ));

        $file = $wgMathJaxJS ?: self::$defaultMathJaxPath;
        $config = ($wgMathJaxProcConf ?: self::$defaultMathJaxConfig);
        $url = $file . '?' . http_build_query(['config' => $config], NULL, '&');
        $out->addScript(Html::linkedScript($url));

        self::$jsInserted = true;

        return true;
    }

    /**
     * @param string $content
     * @param \Parser $parser
     * @param \PPFrame $frame
     * @return string
     */
    public static function preProcessLinks($content, Parser $parser, PPFrame $frame)
    {
        return NStrings::replace($content, '~(?:(?<!\\\\)|^)\\\\(eq)?ref\\{(?P<link>[^\\}]+)\\}~', function (array $m) use ($parser) {
            $html = $parser->recursiveTagParseFully(sprintf('{{canonicalurl:%s}}', ltrim($m['link'], ':')));
            if (!$doc = self::createDomFromHtml($html)) {
                return $m[0];
            }
            $links = (new DOMXpath($doc))->query('//a[@href]/@href');
            return $links->length !== 1
                ? $m[0]
                : sprintf('\\href{%s}{\\text{#}}', $links->item(0)->value);
        });
    }



    private static function containsXss(NHtml $script)
    {
        if (!NStrings::checkEncoding($script->getHtml())) {
            return true;
        }

        if (!$dom = self::createDomFromHtml($script)) {
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

    /**
     * @param string $html
     * @return \DOMDocument|null
     */
    private static function createDomFromHtml($html)
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $dom->resolveExternals = false;
        $dom->strictErrorChecking = false;
        $dom->recover = true;

        libxml_clear_errors();
        $previousInternalErrors = libxml_use_internal_errors(true);
        $previousEntityLoader = libxml_disable_entity_loader();
        $result = $dom->loadHTML(self::prepareHtmlDocument($html));
        libxml_disable_entity_loader($previousEntityLoader);
        $htmlErrors = libxml_get_errors();
        libxml_use_internal_errors($previousInternalErrors);
        libxml_clear_errors();

        return $result && !$htmlErrors ? $dom : null;
    }

    private static function prepareHtmlDocument($script)
    {
        return '<!doctype html><html>'
            . '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>'
            . '<body>' . $script . '</body>'
            . '</html>';
    }

}
