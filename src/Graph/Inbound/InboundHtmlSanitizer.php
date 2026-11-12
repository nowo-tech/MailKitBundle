<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use Nowo\MailKitBundle\Graph\Send\GraphAttachment;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

use function html_entity_decode;
use function htmlspecialchars;
use function mb_strcut;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function rawurldecode;
use function rawurlencode;
use function strip_tags;
use function strlen;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Sanitises the HTML of inbound messages (1.9) with symfony/html-sanitizer: the W3C safe elements and attributes only
 * (no script, style, iframe, form, event handler or `style` attribute), links `http`, `https` and `mailto` only with
 * `rel="noopener noreferrer nofollow"`, no relative URLs. Images: `cid:` references to the message's own inline
 * parts only, unless remote images are allowed (`http` / `https`, which lets the sender track the reader). The input is
 * cut to `max_html_bytes` first. Also derives the plain text of an HTML-only message.
 */
final class InboundHtmlSanitizer
{
    /** Placeholder host that carries `cid:` references through the URL sanitizer. */
    private const CID_HOST = 'cid.mail-kit.invalid';

    private readonly HtmlSanitizer $sanitizer;

    /**
     * @param bool $allowRemoteImages keep `http(s)` image sources (tracking pixels)
     * @param int $maxBytes bytes of HTML read
     */
    public function __construct(
        bool $allowRemoteImages = false,
        private readonly int $maxBytes = 1_048_576,
    ) {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength($maxBytes * 2);
        $config = $allowRemoteImages
            ? $config->allowMediaSchemes(['http', 'https'])
            : $config->allowMediaSchemes(['https'])->allowMediaHosts([self::CID_HOST]);
        // Stateless after construction (immutable config): safe to share across requests in worker mode.
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
}
