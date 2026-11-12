<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

/**
 * One outgoing e-mail the host is asked about (1.10, {@see EmailTrackingPolicyInterface}): only messages with exactly
 * one envelope recipient, an HTML body and no `X-Mail-Kit-No-Track` header reach the policy, and never one whose
 * template matches `tracking.never_track_templates` (or that declares redacted secrets, with `tracking.skip_redacted`).
 *
 * Plain PHP value object (no Doctrine, Symfony or HTTP types).
 */
final readonly class TrackingCandidate
{
    /**
     * @param string|null $templateId `X-Mail-Kit-Template` (`MailHeaders::template()`),
     *                                else the log tag (`X-Mail-Kit-Tag`), else null
     * @param string $recipient the only envelope recipient address, lower-cased
     * @param string|null $recipientId host recipient id declared on the message
     *                                 (`MailHeaders::trackingRecipient()`), unvalidated hint
     * @param string|null $tenant tenant key declared on the message, unvalidated hint
     */
    public function __construct(
        public ?string $templateId,
        public string $recipient,
        public ?string $recipientId = null,
        public ?string $tenant = null,
    ) {
    }
}
