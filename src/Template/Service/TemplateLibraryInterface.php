<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Service;

use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Exception\TemplateAccessDenied;
use Nowo\MailKitBundle\Template\Exception\TemplateKeyTaken;
use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Exception\TemplateVersionConflict;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Nowo\MailKitBundle\Template\Value\TemplateSummary;

/**
 * The template and snippet store (2.7.0, `templates.enabled`). Every call takes the acting {@see TemplateActor}: rows
 * of another tenant never appear, personal rows only to their owner, and the host's permission port decides the rest
 * (deny by default). A row the actor may not see reads as {@see TemplateNotFound}; a visible row the actor may not
 * change gives {@see TemplateAccessDenied}.
 */
interface TemplateLibraryInterface
{
    /**
     * Summaries the actor may see, personal first, then shared, each by key and locale; `$scope` / `$locale` narrow
     * the list. At most `templates.max_per_scope` rows per scope. Empty when no permission allows anything.
     *
     * @return list<TemplateSummary>
     */
    public function list(TemplateActor $actor, TemplateKind $kind, ?TemplateScope $scope = null, ?string $locale = null): array;

    /**
     * One row the actor may see.
     *
     * @throws TemplateNotFound
     */
    public function get(TemplateActor $actor, string $id): StoredTemplate;

    /**
     * The row of a key in `$locale`, else in its language (`es_ES` → `es`).
     *
     * @throws TemplateNotFound
     */
    public function findByKey(TemplateActor $actor, TemplateKind $kind, TemplateScope $scope, string $key, string $locale): StoredTemplate;

    /**
     * Stores a new row (personal rows belong to the actor; the actor is the author of both scopes).
     *
     * @throws TemplateAccessDenied when the actor may not manage that scope
     * @throws InvalidTemplate for a size, placeholder-count or per-scope cap breach
     * @throws TemplateKeyTaken when the key and locale exist already
     */
    public function create(TemplateActor $actor, TemplateDraft $draft): StoredTemplate;

    /**
     * Replaces key, locale, name, subject and bodies of a row that still has `$expectedVersion`. The draft's kind and
     * scope must be the row's.
     *
     * @throws TemplateNotFound
     * @throws TemplateAccessDenied
     * @throws InvalidTemplate
     * @throws TemplateKeyTaken
     * @throws TemplateVersionConflict when the row changed since `$expectedVersion`
     */
    public function update(TemplateActor $actor, string $id, int $expectedVersion, TemplateDraft $draft): StoredTemplate;

    /**
     * Deletes a row that still has `$expectedVersion` and dispatches
     * {@see TemplateDeletedEvent}.
     *
     * @throws TemplateNotFound
     * @throws TemplateAccessDenied
     * @throws TemplateVersionConflict
     */
    public function delete(TemplateActor $actor, string $id, int $expectedVersion): void;
}
