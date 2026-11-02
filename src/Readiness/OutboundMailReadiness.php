<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Readiness;

use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\Dsn\RuntimeDsn;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;

use function str_starts_with;
use function strlen;
use function substr;

/**
 * Answers "can this instance send e-mail?" — the gate for magic-login / password-reset links (AuthKit adapter),
 * dashboards and setup checklists. Ready means a runtime DSN is stored and passes validation; with nothing stored,
 * the framework transport counts only when `mailer.fallback_counts_as_ready` is true (MAILER_DSN cannot be inspected
 * through the framework transports). No DNS lookup here (see {@see self::status()}).
 */
final readonly class OutboundMailReadiness
{
    /**
     * @param bool $fallbackCountsAsReady `nowo_mail_kit.mailer.fallback_counts_as_ready`
     * @param MailSettings|null $settings the settings view behind the default DSN provider (missing-field report);
     *                                    null with a custom `mailer.dsn_provider`
     */
    public function __construct(
        private MailerDsnProviderInterface $provider,
        private MailerDsnValidator $validator,
        private bool $fallbackCountsAsReady = false,
        private ?MailSettings $settings = null,
    ) {
    }

    /**
     * True when outgoing mail can be delivered.
     */
    public function isOutboundMailReady(): bool
    {
        return $this->status()->ready;
    }

    /**
     * Source, redacted label, readiness and error of the current configuration.
     */
    public function status(): MailStatus
    {
        $missing = $this->missingFields();
        $dsn     = $this->provider->getDsn();
        if (!$dsn instanceof RuntimeDsn) {
            return new MailStatus(DsnSource::Fallback, RuntimeTransport::FALLBACK_LABEL, $this->fallbackCountsAsReady, missingFields: $missing);
        }
        // Syntax and configuration only, no DNS: readiness runs on anonymous pages (AuthKit login, CA L1); the full
        // check (DNS included) runs on save and before every send.
        $error = $this->validator->validate($dsn->dsn, false);

        return new MailStatus($dsn->source, $dsn->label(), $error === null, $error, $missing);
    }

    /**
     * Required settings that are empty (`<section>.<field>`), for the settings page notices and health pages;
     * `mailer.dsn` is not reported when the framework transport counts as ready.
     *
     * @return list<string>
     */
    public function missingFields(): array
    {
        $missing = [];
        foreach ($this->settings?->missingFields() ?? [] as $field) {
            if ($field !== MailerSettingsSection::NAME . '.dsn' || !$this->fallbackCountsAsReady) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Field names (without the section prefix) of {@see self::missingFields()} that belong to `$section`.
     *
     * @return list<string>
     */
    public function missingFieldsOf(string $section): array
    {
        $fields = [];
        foreach ($this->missingFields() as $key) {
            if (str_starts_with($key, $section . '.')) {
                $fields[] = substr($key, strlen($section) + 1);
            }
        }

        return $fields;
    }
}
