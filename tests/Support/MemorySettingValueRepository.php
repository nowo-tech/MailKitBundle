<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;

use function array_filter;
use function array_values;

/**
 * In-memory {@see SettingValueRepositoryInterface} for unit tests; counts the section queries.
 */
final class MemorySettingValueRepository implements SettingValueRepositoryInterface
{
    public int $queries = 0;

    /**
     * @param list<SettingValue> $rows
     */
    public function __construct(
        public array $rows = [],
    ) {
    }

    public function findAll(): array
    {
        return $this->rows;
    }

    public function findBySection(string $section): array
    {
        ++$this->queries;

        return array_values(array_filter($this->rows, static fn (SettingValue $row): bool => $row->getSection() === $section));
    }

    public function apply(array $save, array $remove): void
    {
    }

    public function transactional(callable $callback): mixed
    {
        return $callback();
    }
}
