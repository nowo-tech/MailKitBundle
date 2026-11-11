<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Integration\UserKit;

use Nowo\MailKitBundle\Log\SentMailPersonalDataExporterInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubjectResolverInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;

/**
 * UserKit personal-data export section `emails_received` (1.7; registered like {@see EmailsReceivedPersonalDataEraser}):
 * the sent-mail log rows of every address of the user ({@see SentMailPersonalDataExporterInterface}, envelope only),
 * each row once even when it involves several of the user's addresses.
 */
final readonly class EmailsReceivedPersonalDataExportProvider implements PersonalDataExportProviderInterface
{
    /** Section key (stable). */
    public const SECTION = 'emails_received';

    /** Tag priority (UserKit's built-in sections use negative priorities). */
    public const PRIORITY = 0;

    /**
     * @param SentMailPersonalDataExporterInterface $exporter the log export port
     * @param SentMailPersonalDataSubjectResolverInterface $subjects addresses of the user
     */
    public function __construct(
        private SentMailPersonalDataExporterInterface $exporter,
        private SentMailPersonalDataSubjectResolverInterface $subjects,
    ) {
    }

    /**
     * `emails_received`.
     */
    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    /**
     * Every user: one without an address gets an empty section.
     */
    public function supports(object $user): bool
    {
        return true;
    }

    /**
     * Rows of every address of the user, oldest id first per address.
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null}>
     */
    public function export(object $user): array
    {
        $rows = [];
        foreach ($this->subjects->subjectsFor($user, $user instanceof UserInterface ? $user->getUserIdentifier() : null) as $subject) {
            foreach ($this->exporter->export($subject) as $row) {
                $rows[$row['id']] ??= $row;
            }
        }

        return array_values($rows);
    }
}
