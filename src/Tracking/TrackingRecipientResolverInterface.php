<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

/**
 * SPI (1.10, `nowo_mail_kit.tracking.recipient_resolver`): the tracking recipient ids of a host user — the ids your
 * {@see EmailTrackingPolicyInterface} stores — for the UserKit export provider and eraser (`email_tracking`). The
 * default {@see DefaultTrackingRecipientResolver} uses `getId()`. Must keep no per-user state (FrankenPHP worker mode).
 */
interface TrackingRecipientResolverInterface
{
    /**
     * Distinct recipient ids of the user; empty when it has none.
     *
     * @return list<string>
     */
    public function recipientIdsFor(object $user): array;
}
