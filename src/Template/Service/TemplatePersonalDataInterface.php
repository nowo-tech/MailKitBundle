<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Service;

use InvalidArgumentException;
use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;
use Nowo\MailKitBundle\Template\Port\TemplateOwnerResolverInterface;
use Nowo\MailKitBundle\Template\Value\TemplateErasureResult;

/**
 * GDPR port of the template store (2.7.0, art. 15 / 17 / 20), across every tenant. The UserKit bridge
 * (`email_templates`, with `personal_data.enabled`) calls it with the references of
 * {@see TemplateOwnerResolverInterface}; hosts without UserKit call it themselves.
 * Exact match of the reference, re-checked in PHP. The erasure is complete and idempotent (a second run returns 0 / 0).
 */
interface TemplatePersonalDataInterface
{
    /**
     * The owner's personal templates and snippets (with their content) and the shared rows the owner created or last
     * changed (key, name and dates only: the content belongs to the tenant). Dates in ISO 8601, UTC.
     *
     * @throws InvalidArgumentException for a malformed reference
     *
     * @return list<array{id: string, tenant: string, kind: string, scope: string, role: string, key: string, name: string, locale: string, subject: string|null, html: string|null, text: string|null, created_at: string, updated_at: string}>
     */
    public function exportOwner(string $owner): array;

    /**
     * Deletes the owner's personal templates and snippets (one {@see TemplateDeletedEvent}
     * each) and clears the owner as author / last author of shared rows, which stay for the tenant.
     *
     * @throws InvalidArgumentException for a malformed reference
     */
    public function eraseOwner(string $owner): TemplateErasureResult;
}
