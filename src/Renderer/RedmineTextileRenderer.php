<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Renderer;

use Netcarver\Textile\Parser as TextileParser;
use TYPO3\HtmlSanitizer\Sanitizer;

/**
 * Renders Redmine issue/journal text written in Textile, mirroring Redmine's
 * RedCloth3 pipeline (lib/redmine/wiki_formatting/textile/*).
 *
 * PHP-Textile is not RedCloth3, and the differences bite hardest around code:
 *
 *  1. PHP-Textile escapes the inner <code> tag of `<pre><code class="php">`, so the
 *     tag shows up as literal text. RedCloth3 keeps it (and keeps its class).
 *  2. PHP-Textile applies glyph substitution inside <pre>, injecting
 *     <span class="caps"> into code. Redmine disables that via no_span_caps.
 *  3. PHP-Textile leaves raw markup inside a plain <pre> live, so the sanitizer
 *     later mangles it; RedCloth3 always escapes offtag content.
 *  4. PHP-Textile supports block signatures Redmine's RedCloth3 has no handler
 *     for (bc., pre., notextile., ###.), so those render as code here and as
 *     literal text in Redmine.
 *
 * Verbatim blocks are therefore lifted out before PHP-Textile runs and put back
 * after the sanitizer, so neither ever sees the code as markup.
 */
final class RedmineTextileRenderer
{
    /**
     * RedCloth3 OFFTAGS, minus notextile (which is a raw-HTML escape hatch, not code).
     */
    private const array OFFTAGS = ['code', 'kbd'];

    /**
     * Block signatures PHP-Textile implements but Redmine's RedCloth3 does not:
     * it only defines textile_p, textile_bq, textile_h1..h6 and textile_fn_.
     */
    private const array UNSUPPORTED_BLOCKS = ['bc', 'pre', 'notextile', '###'];

    /**
     * RedCloth3 SIMPLE_HTML_TAGS: blocks opening with anything else are left alone.
     */
    private const array SIMPLE_HTML_TAGS = [
        'tt', 'b', 'i', 'big', 'small', 'em', 'strong', 'dfn', 'code', 'samp', 'kbd',
        'var', 'cite', 'abbr', 'acronym', 'a', 'img', 'br', 'map', 'q', 'sub', 'sup',
        'span', 'bdo',
    ];

    /**
     * Token prefix. Deliberately contains no "x": Textile rewrites the x in
     * "2x3" to a multiplication sign, which would corrupt any token following a digit.
     */
    private const string TOKEN_PREFIX = 'rmtok';

    /**
     * Rouge lexer aliases Redmine ships with; drives the "supported language" branch.
     */
    private const array DEFAULT_LANGUAGES = [
        'apache', 'bash', 'c', 'clojure', 'cpp', 'csharp', 'css', 'diff', 'django',
        'elixir', 'erb', 'go', 'groovy', 'haml', 'html', 'http', 'ini', 'java',
        'javascript', 'js', 'json', 'kotlin', 'less', 'lua', 'make', 'markdown',
        'nginx', 'objective_c', 'perl', 'php', 'plaintext', 'powershell', 'properties',
        'python', 'ruby', 'rust', 'sass', 'scala', 'scss', 'shell', 'sql', 'swift',
        'toml', 'twig', 'typescript', 'vim', 'xml', 'yaml',
    ];

    /** @var array<int, array{tag: string, lang: ?string, code: string, block: bool}> */
    private array $stash = [];
    /** @var array<int, string> */
    private array $literals = [];
    private string $nonce = '';

    public function __construct(
        private readonly bool $strictRedmineBlocks,
        private readonly bool $indentedTextIsCode,
        private readonly TextileParser $textile,
        private readonly Sanitizer $sanitizer,
    ) {
    }

    public function render(string $text): string
    {
        $this->nonce = bin2hex(random_bytes(8));
        $this->stash = [];
        $this->literals = [];

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // RedCloth3 order: clean_white_space, then rip_offtags, then blocks.
        $text = $this->cleanWhiteSpace($text);
        $text = $this->extractPreBlocks($text);
        $text = $this->extractInlineOfftags($text);
        // RedCloth runs hard_break after rip_offtags and before blocks.
        $text = $this->hardBreak($text);
        if ($this->indentedTextIsCode) {
            $text = $this->extractIndentedBlocks($text);
        }
        if ($this->strictRedmineBlocks) {
            $text = $this->neutralizeUnsupportedBlocks($text);
        }

        $html = $this->inlineRules($this->textile->parse($text));
        $html = $this->sanitizer->sanitize($html);

        return $this->restore($html);
    }

    /**
     * A <pre> block runs to its closing </pre>; blank lines do not end it.
     */
    private function extractPreBlocks(string $text): string
    {
        $lines = explode("\n", $text);
        $total = count($lines);
        $out = [];

        for ($i = 0; $i < $total; ++$i) {
            if (!preg_match('/^([ ]{0,3})<pre(?=[\s>]|$)/i', $lines[$i], $m)) {
                $out[] = $lines[$i];
                continue;
            }

            $buffer = [];
            $end = $total - 1;
            for ($j = $i; $j < $total; ++$j) {
                $buffer[] = $lines[$j];
                if (false !== stripos($lines[$j], '</pre>')) {
                    $end = $j;
                    break;
                }
            }

            $token = $this->stashPre(implode("\n", $buffer));
            // Blank lines keep the token a block of its own, so the restored
            // <pre> never ends up nested inside a <p>.
            $out[] = '';
            $out[] = $m[1] . $token;
            $out[] = '';
            $i = $end;
        }

        return implode("\n", $out);
    }

    private function stashPre(string $raw): string
    {
        if (preg_match('/^[ ]{0,3}<pre\b[^>]*>/i', $raw, $m)) {
            $raw = substr($raw, strlen($m[0]));
        }
        $raw = preg_replace('#</pre\s*>.*$#is', '', $raw) ?? $raw;

        $lang = null;
        $tag = 'pre';
        if (preg_match('/^\s*<code\b([^>]*)>/i', $raw, $m)) {
            $tag = 'pre>code';
            $lang = $this->languageFromAttributes($m[1]);
            $raw = substr($raw, strlen($m[0]));
            $raw = preg_replace('#</code\s*>\s*$#is', '', $raw) ?? $raw;
        }

        return $this->push($tag, $lang, $raw, true);
    }

    /**
     * Standalone <code>/<kbd> spans are RedCloth offtags too: content escaped,
     * and on <code> the class attribute survives and drives highlighting.
     */
    private function extractInlineOfftags(string $text): string
    {
        foreach (self::OFFTAGS as $tag) {
            $text = preg_replace_callback(
                '#<' . $tag . '\b([^>]*)>(.*?)</' . $tag . '\s*>#is',
                fn (array $m): string => $this->push(
                    $tag,
                    'code' === $tag ? $this->languageFromAttributes($m[1]) : null,
                    $m[2],
                    false
                ),
                $text
            ) ?? $text;
        }

        return $text;
    }

    /**
     * Redmine has no handler for these prefixes, so it prints them verbatim.
     * Only the marker is shelved; the rest of the line stays ordinary Textile.
     */
    private function neutralizeUnsupportedBlocks(string $text): string
    {
        $tags = implode('|', array_map('preg_quote', self::UNSUPPORTED_BLOCKS));

        return preg_replace_callback(
            '/^(' . $tags . ')([^\s.]*)\.(?=\s)/mi',
            function (array $m): string {
                $index = count($this->literals);
                $this->literals[$index] = $m[0];

                return self::TOKEN_PREFIX . $this->nonce . 'l' . $index;
            },
            $text
        ) ?? $text;
    }

    private function push(string $tag, ?string $lang, string $code, bool $block): string
    {
        $index = count($this->stash);
        $this->stash[$index] = ['tag' => $tag, 'lang' => $lang, 'code' => $code, 'block' => $block];

        return self::TOKEN_PREFIX . $this->nonce . 'c' . $index;
    }

    private function languageFromAttributes(string $attributes): ?string
    {
        if (!preg_match('/\bclass\s*=\s*(["\'])(.*?)\1/i', $attributes, $m)) {
            return null;
        }
        $first = strtok(trim($m[2]), " \t");
        if (false === $first || '' === $first) {
            return null;
        }

        // Anything outside a lexer-name charset is not a language; drop it rather
        // than echoing attacker-controlled text into an attribute.
        return 1 === preg_match('/^[A-Za-z0-9_+#.-]{1,32}$/', $first) ? $first : null;
    }

    /**
     * RedCloth turns the break marker into real markup before running the inline
     * rules, so an auto-linked URL terminates at the <br /> instead of absorbing it.
     * Redmine appends the link rules after the Textile ones so they cannot break
     * !image! tags: RULES = [:textile, ..., :inline_auto_link, :inline_auto_mailto].
     */
    private function inlineRules(string $html): string
    {
        $html = str_replace(self::TOKEN_PREFIX . $this->nonce . 'br', '<br />', $html);

        return $this->autoLink($html);
    }

    /**
     * Port of Redmine::WikiFormatting::LinksHelper#auto_link!. Operates on the
     * HTML string exactly as Redmine does; the leading-context group is what keeps
     * it out of attribute values, and code has already been shelved by this point.
     */
    private function autoLink(string $html): string
    {
        $pattern = '{
            (<\w+[^>]*?>|[\s\(\[,;]|^)          # leading tag, punctuation or line start
            ((?:https?://)|(?:s?ftps?://)|(?:www\.))
            (([^<]\S*?)(/)?)
            ((?:&gt;)?|[^[:alnum:]_=/;\(\)\-]*?)  # trailing punctuation
            (?=<|\s|$)
        }xmi';

        return preg_replace_callback($pattern, static function (array $m): string {
            [$all, $leading, $proto, $url] = [$m[0], $m[1], $m[2], $m[3]];
            $post = $m[6] ?? '';

            // Already inside a link, or a Textile image modifier (! !> !< !=).
            if (1 === preg_match('/<a\s/i', $leading) || 1 === preg_match('/!$/', $leading)) {
                return $all;
            }
            // An URL ending in ")" with unbalanced parens: push it out of the link.
            if (str_ends_with($url, ')') && substr_count($url, '(') - substr_count($url, ')') < 0) {
                $url = substr($url, 0, -1);
                $post = ')' . $post;
            }

            // $proto/$url come out of Textile's own output, so they are ALREADY
            // HTML-escaped. Re-escaping here would turn &amp; into &amp;amp;;
            // only the quote characters still need neutralising for the attribute.
            $href = ('www.' === strtolower($proto) ? 'http://www.' : $proto) . $url;
            $href = str_replace(['"', "'"], ['&quot;', '&#039;'], $href);

            return $leading
                . '<a href="' . $href . '" target="_blank" rel="noreferrer">'
                . $proto . $url
                . '</a>' . $post;
        }, $html) ?? $html;
    }

    /**
     * RedCloth3#clean_white_space. Note this also normalizes tabs inside code.
     */
    private function cleanWhiteSpace(string $text): string
    {
        $text = str_replace("\t", '    ', $text);
        $text = preg_replace('/^ +$/m', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return $this->flushLeft($text);
    }

    /**
     * RedCloth3#flush_left: strip the smallest common indent.
     */
    private function flushLeft(string $text): string
    {
        if (1 !== preg_match('/^ +\S/m', $text)) {
            return $text;
        }
        $indent = 0;
        while (1 !== preg_match('/^ {' . $indent . '}\S/m', $text)) {
            if (++$indent > 1000) {
                return $text;
            }
        }
        if (0 === $indent) {
            return $text;
        }

        return preg_replace('/^ {' . $indent . '}/m', '', $text) ?? $text;
    }

    /**
     * Redmine's Filter#hard_break override (formatter.rb): the leading \n| in the
     * lookahead is what keeps blank lines — and therefore paragraphs — intact.
     * A token is emitted rather than <br /> so later escaping cannot touch it.
     */
    private function hardBreak(string $text): string
    {
        return preg_replace(
            '/(.)\n(?!\n|\z| *([#*=]+(\s|$)|[{|]))/m',
            '$1' . self::TOKEN_PREFIX . $this->nonce . 'br',
            $text
        ) ?? $text;
    }

    /**
     * RedCloth3#blocks "search for indentation levels": inside a block that does not
     * itself start with #, *, > or a space, a run of indented lines following a blank
     * line is flushed left, split into paragraphs, and each becomes <pre><code>.
     * One leading space is enough — this is not Markdown's four.
     */
    private function extractIndentedBlocks(string $text): string
    {
        $out = [];
        foreach (preg_split('/\n{2,}(?! )/', $text) ?: [] as $block) {
            $plain = 1 !== preg_match('/\A[#*> ]/', $block);

            // RedCloth skips blocks that open with complex HTML.
            if (1 === preg_match('/^<\/?(\w+).*>/', $block, $m)
                && !in_array(strtolower($m[1]), self::SIMPLE_HTML_TAGS, true)) {
                $out[] = $block;
                continue;
            }

            $block = trim($block);
            if ('' === $block) {
                $out[] = $block;
                continue;
            }

            if (!$plain) {
                // RedCloth keeps the run in place and wraps it in <p>, nested inside
                // the list item or quote. PHP-Textile cannot nest it, so it is emitted
                // as a following paragraph instead of a bare text node.
                $out[] = preg_replace_callback(
                    '/((?:\n(?:\n^ +[^\n]*)+)+)/m',
                    fn (array $m): string => "\n\n" . trim($this->flushLeft($m[1])),
                    $block
                ) ?? $block;
                continue;
            }

            $tail = '';
            $block = preg_replace_callback(
                '/((?:\n(?:\n^ +[^\n]*)+)+)/m',
                function (array $m) use (&$tail): string {
                    // hard_break already ran, so continuation lines keep the
                    // indentation that flush_left can no longer see.
                    $run = $this->flushLeft($m[1]);
                    $tokens = [];
                    foreach (preg_split('/\n{2,}/', trim($run)) ?: [] as $paragraph) {
                        if ('' !== trim($paragraph)) {
                            $tokens[] = $this->push('indent', null, $paragraph, true);
                        }
                    }
                    $tail = implode("\n\n", $tokens);

                    return '';
                },
                $block
            ) ?? $block;

            $block = rtrim($block);
            $out[] = '' === $tail ? $block : ('' === $block ? $tail : $block . "\n\n" . $tail);
        }

        return implode("\n\n", $out);
    }

    /**
     * These blocks are NOT offtags: RedCloth runs the inline rules over them, so
     * *bold* becomes markup. Only the tags are escaped — once, unlike RedCloth,
     * which escapes them twice and shows a literal &lt;b&gt; on screen.
     */
    private function renderIndented(string $source): string
    {
        $escaped = str_replace(['<', '>'], ['&lt;', '&gt;'], $source);
        $html = $this->inlineRules($this->textile->parse($escaped));
        if (1 === preg_match('#\A\s*<p[^>]*>(.*)</p>\s*\z#s', $html, $m)) {
            $html = $m[1];
        }

        return $this->sanitizer->sanitize($html);
    }

    private function restore(string $html): string
    {
        // Unwrap the paragraph a block token was parsed into.
        $html = preg_replace(
            '#<p>\s*(' . self::TOKEN_PREFIX . $this->nonce . 'c\d+)\s*</p>#',
            '$1',
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/' . self::TOKEN_PREFIX . $this->nonce . 'c(\d+)/',
            function (array $m): string {
                $item = $this->stash[(int) $m[1]] ?? null;

                return null === $item ? '' : $this->buildCodeBlock($item);
            },
            $html
        ) ?? $html;

        return preg_replace_callback(
            '/' . self::TOKEN_PREFIX . $this->nonce . 'l(\d+)/',
            fn (array $m): string => htmlspecialchars(
                $this->literals[(int) $m[1]] ?? '',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            $html
        ) ?? $html;
    }

    /**
     * RedCloth3 escapes offtag content with htmlesc(:NoQuotes) — ampersands and
     * angle brackets only, quotes untouched — and keeps `class` on <code> alone.
     * Redmine's SyntaxHighlightScrubber then rewrites that class.
     *
     * @param array{tag: string, lang: ?string, code: string, block: bool} $item
     */
    private function buildCodeBlock(array $item): string
    {
        $code = $item['code'];
        if ($item['block']) {
            // HTML drops one newline after <pre>; the scrubber strips one before highlighting.
            $code = preg_replace('/^\n/', '', $code) ?? $code;
            $code = rtrim($code, "\n");
        }

        $escaped = htmlspecialchars($code, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lang = $item['lang'];
        $body = $escaped;
        $attributes = '';

        if (null !== $lang && $this->isSupported($lang)) {
            $attributes = ' class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"';
        }

        return match ($item['tag']) {
            'indent' => '<pre><code>' . $this->renderIndented($item['code']) . '</code></pre>',
            'pre' => '<pre>' . $escaped . '</pre>',
            'pre>code' => '<pre><code' . $attributes . '>' . $body . '</code></pre>',
            'kbd' => '<kbd>' . $escaped . '</kbd>',
            default => '<code' . $attributes . '>' . $body . '</code>',
        };
    }

    private function isSupported(string $lang): bool
    {
        return in_array(strtolower($lang), self::DEFAULT_LANGUAGES, true);
    }
}
