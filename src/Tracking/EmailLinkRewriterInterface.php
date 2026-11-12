<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

/**
 * Port of the click tracking (1.10, `nowo_mail_kit.tracking.link_rewriter`): turns one link of a tracked e-mail into a
 * tracking URL — typically a short link of kind "email" that records the click (send id, label, position) and
 * redirects (302) to the original URL. The default {@see NullEmailLinkRewriter} keeps every link.
 *
 * Return an absolute `http(s)` URL (at most 2048 characters), or null to keep the original link. Anything else, or an
 * exception, keeps the original link (logged). An `essential` link must always redirect (never blocked, expired or
 * rate-limited). Must keep no per-send state (FrankenPHP worker mode).
 */
interface EmailLinkRewriterInterface
{
    /**
     * Tracking URL of the link, or null to keep it.
     */
    public function rewrite(TrackedLink $link): ?string;
}
