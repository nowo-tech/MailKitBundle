<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Integration\UserKit;

use Nowo\MailKitBundle\Template\Port\TemplateOwnerResolverInterface;
use Nowo\MailKitBundle\Template\Service\TemplatePersonalDataInterface;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface;

/**
 * UserKit personal-data eraser `email_templates` (2.7.0; registered with `templates.enabled`, `personal_data.enabled`
 * and UserKit installed): deletes the user's personal templates and snippets and clears the user as author of shared
 * ones, for every owner reference of {@see TemplateOwnerResolverInterface}. Runs before the host identity eraser.
 */
final readonly class EmailTemplatesPersonalDataEraser implements PersonalDataEraserInterface
{
    /** Eraser key (stable). */
    public const KEY = 'email_templates';

    /** Tag priority: before the host identity eraser (REQ-DATA-002). */
    public const PRIORITY = 110;

    /**
     * Wires the eraser.
     */
    public function __construct(
        private TemplatePersonalDataInterface $templates,
        private TemplateOwnerResolverInterface $owners,
    ) {
    }

    /**
     * `email_templates`.
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * Every user: one without an owner reference has nothing to erase.
     */
    public function supports(object $user): bool
    {
        return true;
    }

    /**
     * Erases every owner reference of the user.
     */
    public function erase(ErasureRequest $request): void
    {
        foreach ($this->owners->ownerIdsFor($request->user) as $owner) {
            $this->templates->eraseOwner($owner);
        }
    }
}
