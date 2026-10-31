<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use LogicException;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * In-memory {@see InstanceSettingsInterface} for unit tests: `values` (plain) and `secrets` keyed `<section>.<field>`.
 */
final class FakeSettings implements InstanceSettingsInterface
{
    /**
     * @param array<string, bool|float|int|string|null> $values
     * @param array<string, string|null> $secrets
     */
    public function __construct(
        public array $values = [],
        public array $secrets = [],
    ) {
    }

    public function get(string $key): bool|float|int|string|null
    {
        return $this->values[$key] ?? null;
    }

    public function getString(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) ? $value : null;
    }

    public function getInt(string $key): ?int
    {
        $value = $this->get($key);

        return is_int($value) ? $value : null;
    }

    public function getFloat(string $key): ?float
    {
        $value = $this->get($key);

        return is_float($value) ? $value : null;
    }

    public function getBool(string $key): bool
    {
        $value = $this->get($key);

        return is_bool($value) && $value;
    }

    public function getSecret(string $key): ?string
    {
        return $this->secrets[$key] ?? null;
    }

    public function hasSecret(string $key): bool
    {
        return $this->getSecret($key) !== null;
    }

    public function isOverridden(string $key): bool
    {
        return isset($this->values[$key]) || isset($this->secrets[$key]);
    }

    public function getSection(string $section): array
    {
        throw new LogicException('Not used.');
    }

    public function save(string $section, array $values, string $origin = SettingsChangedEvent::ORIGIN_API): array
    {
        throw new LogicException('Not used.');
    }
}
