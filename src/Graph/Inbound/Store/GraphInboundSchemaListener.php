<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Store;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Adds the inbound tables to the schema the ORM generates for the configured entity manager (1.9), so
 * `doctrine:migrations:diff` / `doctrine:schema:update` create them and never propose to drop them.
 */
final class GraphInboundSchemaListener
{
    /**
     * @param string|null $entityManager entity manager whose schema gets the tables (null = default)
     */
    public function __construct(
        private readonly GraphInboundStore $store,
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
        $this->store->defineSchema($args->getSchema(), $args->getEntityManager()->getConnection());
    }
}
