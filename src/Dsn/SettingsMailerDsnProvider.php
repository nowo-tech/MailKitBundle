<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Dsn;

use Nowo\MailKitBundle\Settings\MailSettings;

/**
 * Default {@see MailerDsnProviderInterface}: the Resend key when the Resend section is active, else the stored
 * `mailer.dsn` secret, else null (framework transport).
 */
final readonly class SettingsMailerDsnProvider implements MailerDsnProviderInterface
{
    /**
     * @param MailSettings $settings typed view of the mail settings
     */
    public function __construct(
        private MailSettings $settings,
    ) {
    }

    /**
     * Resend DSN, mailer DSN or null.
     */
    public function getDsn(): ?RuntimeDsn
    {
        return $this->settings->dsn();
    }
}
