<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

use DateTimeImmutable;

/**
 * One stored template or snippet (2.7.0) as the store returns it to an actor who may see it. `html` is the sanitised
 * HTML MailKit stored, `text` the text body as written (possibly empty: the renderer then derives it from the HTML).
 */
final readonly class StoredTemplate
{
    /**
     * @param string $id the template id (UUID)
     * @param string $tenant the tenant reference
     * @param string|null $owner the owner reference of a personal row, null for a shared one
     * @param int $version the optimistic version to pass to update / delete
     * @param string|null $createdBy the author (null after a GDPR erasure)
     * @param string|null $updatedBy the last author (null after a GDPR erasure)
     */
    public function __construct(
        public string $id,
        public TemplateKind $kind,
        public TemplateScope $scope,
        public string $tenant,
        public ?string $owner,
        public string $key,
        public string $name,
        public string $locale,
        public string $subject,
        public string $html,
        public string $text,
        public int $version,
        public ?string $createdBy,
        public ?string $updatedBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * The list view of this row.
     */
    public function summary(): TemplateSummary
    {
        return new TemplateSummary($this->id, $this->kind, $this->scope, $this->owner, $this->key, $this->name, $this->locale, $this->subject, $this->version, $this->updatedBy, $this->updatedAt);
    }
}
