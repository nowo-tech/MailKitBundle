<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;

/**
 * Sanitises the HTML of inbound messages (1.9) with symfony/html-sanitizer: the W3C safe elements and attributes only
 * (no script, style, iframe, form, event handler or `style` attribute), links `http`, `https` and `mailto` only with
 * `rel="noopener noreferrer nofollow"`, no relative URLs. Images: `cid:` references to the message's own inline
 * parts only, unless remote images are allowed (`http` / `https`, which lets the sender track the reader). The input is
 * cut to `max_html_bytes` first. Also derives the plain text of an HTML-only message.
 *
 * 2.5.0: a thin subclass of the provider-neutral {@see MailHtmlSanitizer} (modes `dropped` / `allowed`); no BC break.
 */
final class InboundHtmlSanitizer extends MailHtmlSanitizer
{
    /**
     * @param bool $allowRemoteImages keep `http(s)` image sources (tracking pixels)
     * @param int $maxBytes bytes of HTML read
     */
    public function __construct(
        bool $allowRemoteImages = false,
        int $maxBytes = 1_048_576,
    ) {
        parent::__construct($allowRemoteImages ? RemoteImages::Allowed : RemoteImages::Dropped, $maxBytes);
    }
}
