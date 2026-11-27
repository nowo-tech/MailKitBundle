<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Value;

use DateTimeImmutable;

/**
 * One row of a template list (2.7.0): everything but the bodies, so a list never loads them.
 */
final readonly class TemplateSummary
{
    /**
     * @param string $id the template id (UUID)
     * @param string|null $owner the owner reference of a personal row, null for a shared one
     * @param int $version the optimistic version to pass to update / delete
     * @param string|null $updatedBy the last author (null after a GDPR erasure)
     */
    public function __construct(
        public string $id,
        public TemplateKind $kind,
        public TemplateScope $scope,
        public ?string $owner,
        public string $key,
        public string $name,
        public string $locale,
        public string $subject,
        public int $version,
        public ?string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
