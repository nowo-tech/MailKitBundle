<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Settings;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\RuntimeDsn;
use Symfony\Component\Mime\Address;
use Throwable;

use function rawurlencode;
use function trim;

/**
 * Typed view of the `mailer` Instance Settings section. Every getter reads the store on call (memoized per request
 * by Instance Settings), so a change applies on the next send without a deploy.
 *
 * Since 1.3 the delivery is chosen explicitly with `mailer.provider` (`dsn` or `resend`, only with
 * `nowo_mail_kit.resend.enabled`); the Resend key and sender are `mailer.resend_api_key` / `mailer.resend_from`.
 * Until the 1.2 `resend` rows are migrated ({@see LegacyResendSettings}), an instance without a stored
 * `mailer.provider` keeps the 1.2 behaviour: `resend.enabled` selects Resend, `resend.api_key` / `resend.from` apply.
 */
final readonly class MailSettings
{
    /**
     * @param bool $resendEnabled `nowo_mail_kit.resend.enabled` (the `mailer` section offers the Resend provider)
     * @param LegacyResendSettings|null $legacy rows of the 1.2 `resend` section (read until they are migrated)
     */
    public function __construct(
        private InstanceSettingsInterface $settings,
        private bool $resendEnabled = false,
        private ?LegacyResendSettings $legacy = null,
    ) {
    }

    /**
     * Selected delivery: {@see MailerSettingsSection::PROVIDER_RESEND} or {@see MailerSettingsSection::PROVIDER_DSN}
     * (always `dsn` without the Resend provider). Since 1.3.
     */
    public function provider(): string
    {
        if (!$this->resendEnabled) {
            return MailerSettingsSection::PROVIDER_DSN;
        }
        if ($this->settings->isOverridden(MailerSettingsSection::NAME . '.provider')) {
            return $this->settings->getString(MailerSettingsSection::NAME . '.provider') === MailerSettingsSection::PROVIDER_RESEND
                ? MailerSettingsSection::PROVIDER_RESEND
                : MailerSettingsSection::PROVIDER_DSN;
        }

        return ($this->legacyValues()['enabled'] ?? false) ? MailerSettingsSection::PROVIDER_RESEND : MailerSettingsSection::PROVIDER_DSN;
    }

    /**
     * Whether 1.2 `resend` rows are still read because `mailer.provider` is not stored yet (run
     * `nowo:instance-settings:seed-from-fallbacks --section=mailer`). Since 1.3.
     */
    public function legacyResendPending(): bool
    {
        return $this->legacyValues() !== null;
    }

    /**
     * Effective runtime DSN: the Resend key when Resend is the selected provider and holds a key, else `mailer.dsn`,
     * else null.
     */
    public function dsn(): ?RuntimeDsn
    {
        if ($this->resendActive()) {
            return $this->resendDsn();
        }
        $dsn = $this->settings->getSecret(MailerSettingsSection::NAME . '.dsn');

        return $dsn === null ? null : new RuntimeDsn($dsn, DsnSource::Settings);
    }

    /**
     * Resend is the selected provider and holds an API key.
     */
    public function resendActive(): bool
    {
        return $this->provider() === MailerSettingsSection::PROVIDER_RESEND && $this->resendApiKey() !== null;
    }

    /**
     * Required settings that are empty for the selected provider (`<section>.<field>` names, never values):
     * `mailer.resend_api_key` when Resend is selected without a key, `mailer.dsn` when the DSN provider is selected
     * and no runtime DSN is stored (mail then goes through the framework transport). Since 1.2.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        if ($this->provider() === MailerSettingsSection::PROVIDER_RESEND) {
            return $this->resendApiKey() === null ? [MailerSettingsSection::NAME . '.resend_api_key'] : [];
        }

        return $this->dsn() instanceof RuntimeDsn ? [] : [MailerSettingsSection::NAME . '.dsn'];
    }

    /**
     * Stored Resend API key (whichever provider is selected), null without the Resend provider.
     */
    public function resendApiKey(): ?string
    {
        if (!$this->resendEnabled) {
            return null;
        }

        return $this->settings->getSecret(MailerSettingsSection::NAME . '.resend_api_key') ?? $this->legacyValues()['api_key'] ?? null;
    }

    /**
     * `resend+api://<key>@default` for the stored key (needs symfony/resend-mailer to send), or null.
     */
    public function resendDsn(): ?RuntimeDsn
    {
        $key = $this->resendApiKey();

        return $key === null ? null : new RuntimeDsn('resend+api://' . rawurlencode($key) . '@default', DsnSource::Resend);
    }

    /**
     * Default From of outgoing messages: the Resend sender when Resend is active, else `mailer.from` (with
     * `mailer.from_name`); null when nothing is configured.
     */
    public function from(): ?Address
    {
        return $this->resendActive() ? $this->resendFrom() : $this->mailerFrom();
    }

    /**
     * Sender of the Resend delivery and test: `mailer.resend_from`, else `mailer.from`.
     */
    public function resendFrom(): ?Address
    {
        $from = $this->resendEnabled
            ? $this->settings->getString(MailerSettingsSection::NAME . '.resend_from') ?? $this->legacyValues()['from'] ?? null
            : null;

        return $from === null ? $this->mailerFrom() : $this->address($from, $this->settings->getString(MailerSettingsSection::NAME . '.from_name'));
    }

    /**
     * `mailer.from` with `mailer.from_name`.
     */
    public function mailerFrom(): ?Address
    {
        $from = $this->settings->getString(MailerSettingsSection::NAME . '.from');

        return $from === null ? null : $this->address($from, $this->settings->getString(MailerSettingsSection::NAME . '.from_name'));
    }

    /**
     * Default Reply-To (`mailer.reply_to`), or null.
     */
    public function replyTo(): ?Address
    {
        $replyTo = $this->settings->getString(MailerSettingsSection::NAME . '.reply_to');

        return $replyTo === null ? null : $this->address($replyTo);
    }

    /**
     * Recipient of the test e-mails (`mailer.test_recipient`), or null.
     */
    public function testRecipient(): ?string
    {
        return $this->settings->getString(MailerSettingsSection::NAME . '.test_recipient');
    }

    /**
     * The 1.2 `resend` values while they still apply: Resend provider on, `mailer.provider` not stored, legacy rows
     * present.
     *
     * @return array{enabled: bool, api_key: string|null, from: string|null}|null
     */
    private function legacyValues(): ?array
    {
        if (!$this->resendEnabled || !$this->legacy instanceof LegacyResendSettings || $this->settings->isOverridden(MailerSettingsSection::NAME . '.provider')) {
            return null;
        }

        return $this->legacy->values();
    }

    private function address(string $email, ?string $name = null): ?Address
    {
        try {
            return new Address(trim($email), trim((string) $name));
        } catch (Throwable) {
            return null;
        }
    }
}
