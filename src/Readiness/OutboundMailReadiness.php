<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Readiness;

use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Dsn\MailerDsnProviderInterface;
use Nowo\MailKitBundle\Dsn\RuntimeDsn;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;

/**
 * Answers "can this instance send e-mail?" — the gate for magic-login / password-reset links (AuthKit adapter),
 * dashboards and setup checklists. Ready means a runtime DSN is stored and passes validation; with nothing stored,
 * the framework transport counts only when `mailer.fallback_counts_as_ready` is true (MAILER_DSN cannot be inspected
 * through the framework transports).
 */
final readonly class OutboundMailReadiness
{
    /**
     * @param bool $fallbackCountsAsReady `nowo_mail_kit.mailer.fallback_counts_as_ready`
     */
    public function __construct(
        private MailerDsnProviderInterface $provider,
        private RuntimeTransport $transport,
        private bool $fallbackCountsAsReady = false,
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
        $dsn = $this->provider->getDsn();
        if (!$dsn instanceof RuntimeDsn) {
            return new MailStatus(DsnSource::Fallback, RuntimeTransport::FALLBACK_LABEL, $this->fallbackCountsAsReady);
        }
        $error = $this->transport->check($dsn);

        return new MailStatus($dsn->source, $dsn->label(), $error === null, $error);
    }
}
