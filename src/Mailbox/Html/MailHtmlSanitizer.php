<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Html;

use Nowo\MailKitBundle\Graph\Send\GraphAttachment;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

use function html_entity_decode;
use function htmlspecialchars;
use function is_array;
use function mb_strcut;
use function parse_url;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function rawurldecode;
use function rawurlencode;
use function str_starts_with;
use function strip_tags;
use function strlen;
use function strtolower;
use function substr_count;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Sanitises the HTML of received mail with symfony/html-sanitizer (1.9 inbound rules, provider-neutral since 2.5.0):
 * the W3C safe elements and attributes only (no script, style, iframe, form, event handler or `style` attribute),
 * links `http`, `https` and `mailto` only with `rel="noopener noreferrer nofollow"`, no relative URLs, `cid:` images
 * of the message's own inline parts. Remote images follow {@see RemoteImages}; unless they are allowed, every other
 * URL-bearing media attribute and the `source`, `track`, `link` and `style` elements are removed (2.2.2, ZAT-L4). The input is cut to `$maxBytes` first.
 * Also derives the plain text of an HTML-only message.
 *
 * The output has no inline script, event handler, `style` attribute or element and, in `blocked` mode, no URL that
 * loads without a click, so it renders under a strict CSP (`style-src` without `'unsafe-inline'`, `img-src 'self'`).
 * Stateless after construction (immutable configuration): safe to share in worker mode.
 */
class MailHtmlSanitizer
{
    /** Attribute that carries a blocked remote image URL. */
    public const REMOTE_SRC_ATTRIBUTE = 'data-mail-kit-remote-src';

    /** Longest blocked image URL kept. */
    public const MAX_REMOTE_URL = 2048;

    /**
     * URL-bearing attributes removed from every element unless remote images are allowed (2.2.2, audit ZAT-L4): older
     * symfony/html-sanitizer releases keep `srcset` / `imagesrcset` without a URL check (before 7.4.23 and 8.1.11),
     * and `poster` / `longdesc` too in early 7.4 releases; `style` can hold `url()`.
     */
    public const DROPPED_URL_ATTRIBUTES = ['srcset', 'imagesrcset', 'lowsrc', 'dynsrc', 'poster', 'background', 'longdesc', 'style'];

    /** Elements removed (with their content) unless remote images are allowed: they only carry media sources or CSS. */
    public const DROPPED_MEDIA_ELEMENTS = ['source', 'track', 'link', 'style'];

    /** Placeholder host that carries `cid:` references through the URL sanitizer. */
    private const CID_HOST = 'cid.mail-kit.invalid';

    private readonly HtmlSanitizer $sanitizer;

    /**
     * @param int $maxBytes bytes of HTML read
     */
    public function __construct(
        private readonly RemoteImages $remoteImages = RemoteImages::Dropped,
        private readonly int $maxBytes = 1_048_576,
    ) {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength($maxBytes * 2);
        $config = match ($remoteImages) {
            RemoteImages::Allowed => $config->allowMediaSchemes(['http', 'https']),
            RemoteImages::Dropped => $config->allowMediaSchemes(['https'])->allowMediaHosts([self::CID_HOST]),
            RemoteImages::Blocked => $config->allowMediaSchemes(['https'])->dropElement('audio')->dropElement('video'),
        };
        if ($remoteImages !== RemoteImages::Allowed) {
            // 2.2.2 (audit ZAT-L4): whatever the installed sanitizer version checks, no other URL-bearing media
            // attribute or media-only element survives, so no remote image loads in `dropped` or `blocked` mode.
            foreach (self::DROPPED_URL_ATTRIBUTES as $attribute) {
                $config = $config->dropAttribute($attribute, '*');
            }
            foreach (self::DROPPED_MEDIA_ELEMENTS as $element) {
                $config = $config->dropElement($element);
            }
        }
        $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * Safe HTML.
     */
    public function sanitize(string $html): string
    {
        if (strlen($html) > $this->maxBytes) {
            $html = mb_strcut($html, 0, $this->maxBytes);
        }
        // cid:<id> → https://<placeholder>/<encoded id>, so the URL sanitizer keeps inline images of the message.
        $html = (string) preg_replace_callback(
            '/\bsrc\s*=\s*(["\'])cid:([^"\']{1,256})\1/i',
            static fn (array $m): string => preg_match(GraphAttachment::CONTENT_ID_PATTERN, $m[2]) === 1 ? 'src="https://' . self::CID_HOST . '/' . rawurlencode($m[2]) . '"' : 'src=""',
            $html,
        );
        $safe = $this->sanitizer->sanitize($html);
        if ($this->remoteImages === RemoteImages::Blocked) {
            $safe = $this->block($safe);
        }

        return (string) preg_replace_callback(
            '#src="https://' . preg_quote(self::CID_HOST, '#') . '/([^"]*)"#',
            static function (array $m): string {
                $id = rawurldecode($m[1]);

                return preg_match(GraphAttachment::CONTENT_ID_PATTERN, $id) === 1 ? 'src="cid:' . htmlspecialchars($id, ENT_QUOTES | ENT_HTML5) . '"' : '';
            },
            $safe,
        );
    }

    /**
     * Remote images blocked in sanitised HTML (`data-mail-kit-remote-src` attributes).
     */
    public static function blockedImages(string $html): int
    {
        return substr_count($html, ' ' . self::REMOTE_SRC_ATTRIBUTE . '="');
    }

    /**
     * Plain text of (already sanitised) HTML: block ends become line breaks, entities decoded, blank runs collapsed.
     */
    public function toText(string $html): string
    {
        $html = (string) preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6]|/blockquote)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t\u{00A0}]+/u", ' ', $text);
        $text = (string) preg_replace("/ *\n */", "\n", $text);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * `blocked` mode on the sanitiser's output (well-formed: attribute values double-quoted and entity-encoded, text
     * never holds a raw `<`): an `img` remote `src` moves to the data attribute when its URL passes the re-validation,
     * else it is removed; a remote `src` on any other element is removed. `cid:` images stay.
     */
    private function block(string $html): string
    {
        $cid = 'https://' . self::CID_HOST . '/';

        return (string) preg_replace_callback(
            '#<([a-z][a-z0-9]*)\b([^>]*)>#i',
            static function (array $tag) use ($cid): string {
                return '<' . $tag[1] . preg_replace_callback(
                    '#\ssrc="([^"]*)"#',
                    static function (array $src) use ($tag, $cid): string {
                        if (str_starts_with($src[1], $cid)) {
                            return $src[0];
                        }
                        $url = html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                        return strtolower($tag[1]) === 'img' && self::remoteUrl($url)
                            ? ' ' . self::REMOTE_SRC_ATTRIBUTE . '="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) . '"'
                            : '';
                    },
                    $tag[2],
                ) . '>';
            },
            $html,
        );
    }

    /**
     * An absolute https URL with a host, no user info, no whitespace or control character, at most
     * {@see MAX_REMOTE_URL} characters.
     */
    private static function remoteUrl(string $url): bool
    {
        if (strlen($url) > self::MAX_REMOTE_URL || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && trim($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
