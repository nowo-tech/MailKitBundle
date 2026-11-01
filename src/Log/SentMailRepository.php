<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;

use function array_keys;
use function implode;
use function in_array;
use function mb_strtolower;
use function sprintf;

/**
 * Read side of the sent-mail log (ORM, read-only entities): filtered count / page for the admin list, the streamed rows
 * of the CSV export, one row and its bodies for the detail page. The entity manager is resolved per call (a closed
 * manager is reset by Doctrine).
 *
 * Sorting is a server-side whitelist ({@see self::SORT_FIELDS}): the key validated by UiKit's `SortRequest` maps to a
 * DQL column here, with the id as a stable tie-breaker in the same direction; an unknown key or direction is refused.
 */
final readonly class SentMailRepository
{
    /** Sortable keys (the list column sort keys) => DQL column. */
    public const SORT_FIELDS = [
        'queuedAt'  => 'm.queuedAt',
        'status'    => 'm.status',
        'subject'   => 'm.subject',
        'tag'       => 'm.tag',
        'transport' => 'm.transport',
    ];

    /** Default sort: newest first. */
    public const DEFAULT_SORT = 'queuedAt';

    /** Default direction. */
    public const DEFAULT_DIRECTION = 'desc';

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
     * One page of rows matching the filters, in the given order (default newest first).
     *
     * @param string|null $sort key of {@see self::SORT_FIELDS} (null = {@see self::DEFAULT_SORT})
     * @param string $direction `asc` | `desc`
     *
     * @throws InvalidArgumentException on a key or direction outside the whitelist
     *
     * @return list<SentMail>
     */
    public function findFiltered(SentMailFilters $filters, int $limit, int $offset = 0, ?string $sort = null, string $direction = self::DEFAULT_DIRECTION): array
    {
        /** @var list<SentMail> $rows */
        $rows = $this->sorted($this->filtered($filters), $sort, $direction)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Rows matching the filters, in the given order, hydrated one at a time and detached once consumed (the CSV
     * export: memory stays flat whatever the row count; the host unit of work is never cleared).
     *
     * @param int $limit maximum rows
     * @param string|null $sort key of {@see self::SORT_FIELDS} (null = {@see self::DEFAULT_SORT})
     * @param string $direction `asc` | `desc`
     *
     * @throws InvalidArgumentException on a key or direction outside the whitelist
     *
     * @return iterable<SentMail>
     */
    public function iterateFiltered(SentMailFilters $filters, int $limit, ?string $sort = null, string $direction = self::DEFAULT_DIRECTION): iterable
    {
        $query   = $this->sorted($this->filtered($filters), $sort, $direction)->setMaxResults($limit)->getQuery();
        $manager = $this->manager();
        /** @var SentMail $row */
        foreach ($query->toIterable() as $row) {
            yield $row;
            $manager->detach($row);
        }
    }

    /**
     * Stored bodies of several rows, by id (rows without stored bodies are missing).
     *
     * @param list<string> $ids
     *
     * @return array<string, SentMailBody>
     */
    public function findBodies(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $manager = $this->manager();
        /** @var list<SentMailBody> $bodies */
        $bodies = $manager->createQueryBuilder()->select('b')->from(SentMailBody::class, 'b')
            ->where('b.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();
        $byId = [];
        foreach ($bodies as $body) {
            $byId[$body->getId()] = $body;
            $manager->detach($body);
        }

        return $byId;
    }

    /**
     * Approximate stored body size of several rows (text + HTML length; characters on some platforms, bytes on
     * MySQL / MariaDB), by id, without loading the bodies (rows without stored bodies are missing).
     *
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    public function bodySizes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<array{id: string, text: int|string|null, html: int|string|null}> $rows */
        $rows = $this->manager()->createQueryBuilder()
            ->select('b.id AS id', 'COALESCE(LENGTH(b.textBody), 0) AS text', 'COALESCE(LENGTH(b.htmlBody), 0) AS html')
            ->from(SentMailBody::class, 'b')
            ->where('b.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getArrayResult();
        $sizes = [];
        foreach ($rows as $row) {
            $sizes[$row['id']] = (int) $row['text'] + (int) $row['html'];
        }

        return $sizes;
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

    private function sorted(QueryBuilder $qb, ?string $sort, string $direction): QueryBuilder
    {
        $sort ??= self::DEFAULT_SORT;
        if (!isset(self::SORT_FIELDS[$sort])) {
            throw new InvalidArgumentException(sprintf('Unknown sent-mail log sort "%s" (allowed: %s).', $sort, implode(', ', array_keys(self::SORT_FIELDS))));
        }
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(sprintf('Invalid sort direction "%s" (asc|desc).', $direction));
        }
        $qb->orderBy(self::SORT_FIELDS[$sort], $direction);
        if ($sort !== self::DEFAULT_SORT) {
            $qb->addOrderBy('m.queuedAt', $direction);
        }

        return $qb->addOrderBy('m.id', $direction);
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
