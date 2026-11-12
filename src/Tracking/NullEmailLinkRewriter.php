<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

/**
 * Default {@see EmailLinkRewriterInterface} (1.10): keeps every link (only the open pixel is added).
 */
final readonly class NullEmailLinkRewriter implements EmailLinkRewriterInterface
{
    /**
     * Keeps the link.
     */
    public function rewrite(TrackedLink $link): ?string
    {
        return null;
    }
}
