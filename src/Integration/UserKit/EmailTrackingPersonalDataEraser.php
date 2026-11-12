<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Integration\UserKit;

use Nowo\MailKitBundle\Tracking\EmailTrackingPersonalDataInterface;
use Nowo\MailKitBundle\Tracking\TrackingRecipientResolverInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

/**
 * UserKit personal-data eraser `email_tracking` (1.10; registered with `tracking.enabled`, `personal_data.enabled` and
 * UserKit installed): deletes the open-tracking rows of every recipient id of the user
 * ({@see TrackingRecipientResolverInterface}). Runs before the host identity eraser (priority {@see self::PRIORITY}),
 * while the account still has its id; inside UserKit's transaction the DBAL statements join it.
 */
final readonly class EmailTrackingPersonalDataEraser implements PersonalDataEraserInterface
{
    /** Eraser key (stable). */
    public const KEY = 'email_tracking';

    /** Tag priority: before the host identity eraser (REQ-DATA-002). */
    public const PRIORITY = 110;

    /**
     * Wires the eraser.
     */
    public function __construct(
        private EmailTrackingPersonalDataInterface $tracking,
        private TrackingRecipientResolverInterface $recipients,
    ) {
    }

    /**
     * `email_tracking`.
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * Every user: one without a recipient id simply has nothing to erase.
     */
    public function supports(object $user): bool
    {
        return true;
    }

    /**
     * Erases the rows of every recipient id of the user (no flush).
     */
    public function erase(ErasureRequest $request): void
    {
        foreach ($this->recipients->recipientIdsFor($request->user) as $recipientId) {
            $this->tracking->eraseRecipient($recipientId);
        }
    }
}
