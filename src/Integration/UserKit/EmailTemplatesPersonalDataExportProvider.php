<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Integration\UserKit;

use Nowo\MailKitBundle\Template\Port\TemplateOwnerResolverInterface;
use Nowo\MailKitBundle\Template\Service\TemplatePersonalDataInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExportProviderInterface;

/**
 * UserKit personal-data export section `email_templates` (2.7.0; registered like
 * {@see EmailTemplatesPersonalDataEraser}): the user's personal templates and snippets, and the shared ones the user
 * created or last changed.
 */
final readonly class EmailTemplatesPersonalDataExportProvider implements PersonalDataExportProviderInterface
{
    /** Section key (stable). */
    public const SECTION = 'email_templates';

    /** Tag priority (UserKit's built-in sections use negative priorities). */
    public const PRIORITY = 0;

    /**
     * Wires the provider.
     */
    public function __construct(
        private TemplatePersonalDataInterface $templates,
        private TemplateOwnerResolverInterface $owners,
    ) {
    }

    /**
     * `email_templates`.
     */
    public function getSectionKey(): string
    {
        return self::SECTION;
    }

    /**
     * Every user: one without an owner reference gets an empty section.
     */
    public function supports(object $user): bool
    {
        return true;
    }

    /**
     * Rows of every owner reference of the user.
     *
     * @return list<array<string, mixed>>
     */
    public function export(object $user): array
    {
        $rows = [];
        foreach ($this->owners->ownerIdsFor($user) as $owner) {
            foreach ($this->templates->exportOwner($owner) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
