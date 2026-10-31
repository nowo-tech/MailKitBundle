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
 * Typed view of the `mailer` (and optional `resend`) Instance Settings sections. Every getter reads the store on
 * call (memoized per request by Instance Settings), so a change applies on the next send without a deploy.
 */
final readonly class MailSettings
{
    /**
     * @param bool $resendEnabled `nowo_mail_kit.resend.enabled` (the `resend` section exists)
     */
    public function __construct(
        private InstanceSettingsInterface $settings,
        private bool $resendEnabled = false,
    ) {
    }

    /**
     * Effective runtime DSN: the Resend key when the Resend section is active, else `mailer.dsn`, else null.
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
     * The Resend section exists, is switched on and holds an API key.
     */
    public function resendActive(): bool
    {
        return $this->resendEnabled
            && $this->settings->getBool(ResendSettingsSection::NAME . '.enabled')
            && $this->resendApiKey() !== null;
    }

    /**
     * Stored Resend API key (whether or not the section is switched on), null without the Resend section.
     */
    public function resendApiKey(): ?string
    {
        return $this->resendEnabled ? $this->settings->getSecret(ResendSettingsSection::NAME . '.api_key') : null;
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
     * Default From of outgoing messages: `resend.from` when Resend is active and has one, else `mailer.from`
     * (with `mailer.from_name`); null when nothing is configured.
     */
    public function from(): ?Address
    {
        return $this->resendActive() ? $this->resendFrom() : $this->mailerFrom();
    }

    /**
     * Sender of the Resend test: `resend.from`, else `mailer.from`.
     */
    public function resendFrom(): ?Address
    {
        $from = $this->resendEnabled ? $this->settings->getString(ResendSettingsSection::NAME . '.from') : null;

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

    private function address(string $email, ?string $name = null): ?Address
    {
        try {
            return new Address(trim($email), trim((string) $name));
        } catch (Throwable) {
            return null;
        }
    }
}
