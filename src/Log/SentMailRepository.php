<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;

use function mb_strtolower;

/**
 * Read side of the sent-mail log (ORM, read-only entities): filtered count / page for the admin list, one row and
 * its bodies for the detail page. The entity manager is resolved per call (a closed manager is reset by Doctrine).
 */
final readonly class SentMailRepository
{
    /**
     * @param string|null $entityManager entity manager name (null = default)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManager = null,
    ) {
    }

    /**
     * Number of rows matching the filters.
     */
    public function countFiltered(SentMailFilters $filters): int
    {
        return (int) $this->filtered($filters)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * One page of rows matching the filters, newest first.
     *
     * @return list<SentMail>
     */
    public function findFiltered(SentMailFilters $filters, int $limit, int $offset = 0): array
    {
        /** @var list<SentMail> $rows */
        $rows = $this->filtered($filters)
            ->orderBy('m.queuedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * One row, or null.
     */
    public function find(string $id): ?SentMail
    {
        return $this->manager()->find(SentMail::class, $id);
    }

    /**
     * Stored bodies of one row, or null.
     */
    public function findBody(string $id): ?SentMailBody
    {
        return $this->manager()->find(SentMailBody::class, $id);
    }

    private function filtered(SentMailFilters $filters): QueryBuilder
    {
        $qb = $this->manager()->createQueryBuilder()->select('m')->from(SentMail::class, 'm');
        if ($filters->status instanceof SentMailStatus) {
            $qb->andWhere('m.status = :status')->setParameter('status', $filters->status->value);
        }
        if ($filters->since instanceof DateTimeImmutable) {
            $qb->andWhere('m.queuedAt >= :since')->setParameter('since', $filters->since);
        }
        if ($filters->query !== null) {
            $qb->andWhere("(LOWER(m.subject) LIKE :q ESCAPE '!' OR m.recipientIndex LIKE :q ESCAPE '!')")
                ->setParameter('q', '%' . SentMailRecorder::likeEscape(mb_strtolower($filters->query)) . '%');
        }

        return $qb;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager($this->entityManager);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoMailKitBundle: the sent-mail log needs a Doctrine ORM entity manager.');
        }

        return $manager;
    }
}
