<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Settings;

/**
 * Name of the `resend` settings section of MailKit 1.0–1.2 (`resend.enabled`, `resend.api_key`, `resend.from`).
 *
 * Since 1.3 the section is no longer registered: the Resend fields are provider-dependent fields of the `mailer`
 * section ({@see MailerSettingsSection::PROVIDER_RESEND}) and the stored 1.2 rows are read by
 * {@see LegacyResendSettings} until they are seeded into it. Only the constant is kept for host code that referenced
 * it.
 *
 * @deprecated since 1.3, use {@see MailerSettingsSection::NAME} (the Resend fields moved there) or
 *             {@see LegacyResendSettings::SECTION}
 */
final class ResendSettingsSection
{
    /** Name of the 1.0–1.2 section. */
    public const NAME = LegacyResendSettings::SECTION;
}
