<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Dsn;

/**
 * Source of the runtime mailer DSN. The default implementation reads the Instance Settings `mailer` / `resend`
 * sections ({@see SettingsMailerDsnProvider}); set `nowo_mail_kit.mailer.dsn_provider` to a service id of your own
 * (RuntimeEnvBundle, a vault…) to replace it.
 *
 * Called on every send: keep it cheap (read per call, no constructor caching — worker mode).
 */
interface MailerDsnProviderInterface
{
    /**
     * The DSN to send with, or null to use the framework transport (MAILER_DSN fallback).
     */
    public function getDsn(): ?RuntimeDsn;
}
