<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use Closure;

use function html_entity_decode;
use function htmlspecialchars;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function strip_tags;
use function stripos;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;
use function substr_replace;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const PREG_OFFSET_CAPTURE;

/**
 * Text-level rewriting of a rendered e-mail (1.10): the `href` of `<a>`, `<area>` and VML `<v:roundrect>` buttons, the
 * matching URLs of the text part, and the open pixel. A tolerant scanner, not a DOM round trip: the rest of the HTML
 * (Outlook conditional comments, malformed markup, inline styles) is kept byte for byte.
 *
 * Skipped: HTML comments (except conditional `<!--[if …]>` blocks, whose VML buttons are rewritten), `<script>` and
 * `<style>` contents, links marked `data-mail-kit-track="off"`, tags whose attributes do not parse. Pure and
 * stateless (FrankenPHP worker mode).
 */
final readonly class HtmlLinkTracker
{
    /** Longest label passed to the rewriter. */
    public const LABEL_MAX = 100;

    /** Characters scanned after an `<a>` tag for its label. */
    private const LABEL_SCAN = 4000;

    private const PATTERN = '~<!--(?!\[if).*?-->|<(script|style)\b[^>]*>.*?</\1\s*>|<(a|area|v:roundrect)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~is';

    private const HREF = '~(\shref\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))~i';

    /**
     * @param int $maxLinks links rewritten in one e-mail at most (the following ones are kept)
     */
    public function __construct(
        private int $maxLinks = 100,
    ) {
    }

    /**
     * Rewrites the links of an HTML body.
     *
     * @param Closure(string, string|null, int): (string|null) $rewrite (decoded URL, label, 1-based position among the
     *                                                                  http(s) links) => new URL or null to keep it
     *
     * @return array{html: string, map: array<string, string>} the new HTML and original URL => rewritten URL (first
     *                                                         rewrite of each URL)
     */
    public function rewriteHtml(string $html, Closure $rewrite): array
    {
        $position  = 0;
        $rewritten = 0;
        $map       = [];
        $result    = preg_replace_callback(self::PATTERN, function (array $match) use ($html, $rewrite, &$position, &$rewritten, &$map): string {
            // Groups: 1 = script / style (skipped), 2 = link tag name, 3 = its attributes.
            $tag = $match[2][0] ?? '';
            if ($tag === '') {
                return $match[0][0];
            }
            $attributes = $match[3][0] ?? '';
            if (preg_match(self::HREF, $attributes, $href) !== 1) {
                return $match[0][0];
            }
            $url = trim(html_entity_decode(($href[2] ?? '') !== '' ? $href[2] : (($href[3] ?? '') !== '' ? $href[3] : ($href[4] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('~^https?://~i', $url) !== 1) {
                return $match[0][0];
            }
            ++$position;
            if ($rewritten >= $this->maxLinks || preg_match('~\sdata-mail-kit-track\s*=\s*["\']?off\b~i', $attributes) === 1) {
                return $match[0][0];
            }
            $label = $this->label(strtolower($tag) === 'a' ? $this->inner($html, $match[0][1] + strlen($match[0][0])) : '', $attributes);
            $new   = $rewrite($url, $label, $position);
            if ($new === null) {
                return $match[0][0];
            }
            ++$rewritten;
            $map[$url] ??= $new;
            $replaced = (string) preg_replace(self::HREF, '${1}"' . $this->escapeReplacement(htmlspecialchars($new, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8')) . '"', $attributes, 1);

            return '<' . $tag . $replaced . '>';
        }, $html, flags: PREG_OFFSET_CAPTURE);

        // A PCRE failure (backtracking limit) keeps the body as it was.
        return $result === null ? ['html' => $html, 'map' => []] : ['html' => $result, 'map' => $map];
    }

    /**
     * Replaces, in a text part, the URLs rewritten in the HTML part (same tracking URL); other URLs are kept.
     *
     * @param array<string, string> $map original URL => rewritten URL
     */
    public function rewriteText(string $text, array $map): string
    {
        if ($map === []) {
            return $text;
        }

        return preg_replace_callback('~https?://[^\s<>"\']+~i', static function (array $match) use ($map): string {
            $url = $match[0];
            if (isset($map[$url])) {
                return $map[$url];
            }
            $trimmed = rtrim($url, '.,;:!?)]}');
            if ($trimmed !== $url && isset($map[$trimmed])) {
                return $map[$trimmed] . substr($url, strlen($trimmed));
            }

            return $url;
        }, $text) ?? $text;
    }

    /**
     * Adds the 1×1 pixel before the last `</body>` (or at the end).
     */
    public function injectPixel(string $html, string $pixelUrl): string
    {
        $img      = '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" width="1" height="1" alt="" border="0" style="display:block;width:1px;height:1px;border:0;margin:0;padding:0;" />';
        $position = strrpos(strtolower($html), '</body');

        return $position === false ? $html . $img : substr_replace($html, $img, $position, 0);
    }

    /**
     * Inner HTML of an `<a>` up to its closing tag (bounded), or '' when it is not closed.
     */
    private function inner(string $html, int $offset): string
    {
        $chunk = substr($html, $offset, self::LABEL_SCAN);
        $end   = stripos($chunk, '</a');

        return $end === false ? '' : substr($chunk, 0, $end);
    }

    /**
     * `aria-label`, then the visible text, then the first image `alt` (or the `alt` of an `<area>`), then `title`; white space collapsed, bounded.
     */
    private function label(string $inner, string $attributes): ?string
    {
        foreach ([$this->attribute($attributes, 'aria-label'), strip_tags($inner), $this->attribute($inner, 'alt'), $this->attribute($attributes, 'alt'), $this->attribute($attributes, 'title')] as $candidate) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($candidate ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text !== '') {
                return mb_substr($text, 0, self::LABEL_MAX);
            }
        }

        return null;
    }

    private function attribute(string $html, string $name): ?string
    {
        if (preg_match('~\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))~i', $html, $m) !== 1) {
            return null;
        }

        return $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
    }

    private function escapeReplacement(string $value): string
    {
        return (string) preg_replace('/[\\\\$]/', '\\\\$0', $value);
    }
}
