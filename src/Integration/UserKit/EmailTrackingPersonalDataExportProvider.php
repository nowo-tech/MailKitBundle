<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Integration\UserKit;

use Nowo\MailKitBundle\Tracking\EmailTrackingPersonalDataInterface;
use Nowo\MailKitBundle\Tracking\TrackingRecipientResolverInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

/**
 * UserKit personal-data export section `email_tracking` (1.10; registered like {@see EmailTrackingPersonalDataEraser}):
 * the open-tracking rows of every recipient id of the user (send id, template, tenant, sent / open times and counts).
 */
final readonly class EmailTrackingPersonalDataExportProvider implements PersonalDataExportProviderInterface
{
    /** Section key (stable). */
    public const SECTION = 'email_tracking';

    /** Tag priority (UserKit's built-in sections use negative priorities). */
    public const PRIORITY = 0;

    /**
     * Wires the provider.
     */
    public function __construct(
        private EmailTrackingPersonalDataInterface $tracking,
        private TrackingRecipientResolverInterface $recipients,
    ) {
    }

    /**
     * `email_tracking`.
     */
    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    /**
     * Every user: one without a recipient id gets an empty section.
     */
    public function supports(object $user): bool
    {
        return true;
    }

    /**
     * Rows of every recipient id of the user.
     *
     * @return list<array<string, mixed>>
     */
    public function export(object $user): array
    {
        $rows = [];
        foreach ($this->recipients->recipientIdsFor($user) as $recipientId) {
            foreach ($this->tracking->exportRecipient($recipientId) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
