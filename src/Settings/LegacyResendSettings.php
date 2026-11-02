<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Settings;

use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Symfony\Contracts\Service\ResetInterface;

use function in_array;
use function is_string;
use function trim;

/**
 * Read-only view of the rows of the `resend` settings section of MailKit < 1.3 (`resend.enabled`, `resend.api_key`,
 * `resend.from`). Since 1.3 the Resend fields live in the `mailer` section (`mailer.provider`,
 * `mailer.resend_api_key`, `mailer.resend_from`); until `nowo:instance-settings:seed-from-fallbacks --section=mailer`
 * has copied them, {@see MailSettings} keeps reading these rows so an upgraded instance keeps sending through Resend.
 *
 * The rows are read straight from the Instance Settings repository (the section is no longer registered), at most
 * once per request (memo cleared by `kernel.reset`). Values are never logged.
 */
final class LegacyResendSettings implements ResetInterface
{
    /** Name of the 1.0–1.2 section. */
    public const SECTION = 'resend';

    /** @var array{enabled: bool, api_key: string|null, from: string|null}|false|null memo: false = no legacy row */
    private array|false|null $values = null;

    /**
     * @param SettingValueRepositoryInterface $repository Instance Settings storage
     */
    public function __construct(
        private readonly SettingValueRepositoryInterface $repository,
    ) {
    }

    /**
     * The legacy values, or null when no `resend` row is stored (fresh install or already cleaned up).
     *
     * @return array{enabled: bool, api_key: string|null, from: string|null}|null
     */
    public function values(): ?array
    {
        $this->values ??= $this->load();

        return $this->values === false ? null : $this->values;
    }

    /**
     * Clears the memo (`kernel.reset`).
     */
    public function reset(): void
    {
        $this->values = null;
    }

    /**
     * @return array{enabled: bool, api_key: string|null, from: string|null}|false
     */
    private function load(): array|false
    {
        $rows = $this->repository->findBySection(self::SECTION);
        if ($rows === []) {
            return false;
        }

        $values = ['enabled' => false, 'api_key' => null, 'from' => null];
        foreach ($rows as $row) {
            $value = $row->getValue();
            match ($row->getName()) {
                'enabled' => $values['enabled'] = in_array($value, [true, 1, '1'], true),
                'api_key' => $values['api_key'] = $this->nonEmpty($row->getSecret()),
                'from'    => $values['from']    = is_string($value) ? $this->nonEmpty($value) : null,
                default   => null,
            };
        }

        return $values;
    }

    private function nonEmpty(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
