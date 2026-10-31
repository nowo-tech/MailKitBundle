<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Integration\AuthKit;

use Nowo\AuthKitBundle\Mailer\OutboundMailReadyCheckerInterface;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;

/**
 * AuthKit adapter: AuthKit shows magic-login and password-reset only when MailKit can deliver mail. Registered as
 * `nowo_mail_kit.outbound_mail_ready_checker` when nowo-tech/auth-kit-bundle is installed and prepended as
 * `nowo_auth_kit.outbound_mail_ready_checker` (a host value wins).
 */
final readonly class MailKitOutboundMailReadyChecker implements OutboundMailReadyCheckerInterface
{
    /**
     * @param OutboundMailReadiness $readiness MailKit readiness
     */
    public function __construct(
        private OutboundMailReadiness $readiness,
    ) {
    }

    /**
     * True when a deliverable mailer is configured.
     */
    public function isReady(): bool
    {
        return $this->readiness->isOutboundMailReady();
    }
}
