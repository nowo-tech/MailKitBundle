<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking\Store;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Adds the tracking table to the schema the ORM generates for the configured entity manager (1.10), so
 * `doctrine:migrations:diff` / `doctrine:schema:update` create it and never propose to drop it.
 */
final class TrackingSchemaListener
{
    /**
     * @param string|null $entityManager entity manager whose schema gets the table (null = default)
     */
    public function __construct(
        private readonly TrackingStore $store,
        private readonly ManagerRegistry $doctrine,
        private readonly ?string $entityManager = null,
    ) {
    }

    /**
     * `postGenerateSchema`.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        if ($args->getEntityManager() !== $this->doctrine->getManager($this->entityManager)) {
            return;
        }
        $this->store->defineSchema($args->getSchema(), $args->getEntityManager()->getConnection()->getDatabasePlatform());
    }
}
