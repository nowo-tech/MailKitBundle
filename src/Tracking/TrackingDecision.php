<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use InvalidArgumentException;

/**
 * Answer of the host {@see EmailTrackingPolicyInterface} for one e-mail (1.10): track nothing, or the open pixel and / or
 * the link rewriting, with the host recipient id and tenant key stored with the send (GDPR erasure / export by
 * recipient id; never the address).
 *
 * Plain PHP value object (no Doctrine, Symfony or HTTP types).
 */
final readonly class TrackingDecision
{
    private function __construct(
        public bool $pixel,
        public bool $links,
        public ?string $recipientId,
        public ?string $tenant,
    ) {
    }

    /**
     * No pixel, links untouched, nothing stored (security e-mails, opted-out recipients).
     */
    public static function none(): self
    {
        return new self(false, false, null, null);
    }

    /**
     * Track the e-mail.
     *
     * @param string|null $recipientId host recipient id ({@see TrackingIds::recipient()}: exact, at most 128 characters)
     * @param string|null $tenant tenant key ({@see TrackingIds::tenant()}: exact, at most 64 characters)
     * @param bool $pixel add the open pixel
     * @param bool $links rewrite the allowed links through the {@see EmailLinkRewriterInterface}
     *
     * @throws InvalidArgumentException for an unacceptable id
     */
    public static function track(?string $recipientId = null, ?string $tenant = null, bool $pixel = true, bool $links = true): self
    {
        return new self($pixel, $links, TrackingIds::recipient($recipientId), TrackingIds::tenant($tenant));
    }

    /**
     * True when something is tracked (a send row is stored).
     */
    public function tracks(): bool
    {
        return $this->pixel || $this->links;
    }
}
