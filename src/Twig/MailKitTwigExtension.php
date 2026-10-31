<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Twig;

use Nowo\MailKitBundle\Readiness\MailStatus;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions: `nowo_mail_kit_outbound_mail_ready()` (bool) and `nowo_mail_kit_status()` ({@see MailStatus}:
 * source, redacted label, ready, error key) — never the DSN.
 */
final class MailKitTwigExtension extends AbstractExtension
{
    /**
     * @param OutboundMailReadiness $readiness MailKit readiness
     */
    public function __construct(
        private readonly OutboundMailReadiness $readiness,
    ) {
    }

    /**
     * The two functions.
     *
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('nowo_mail_kit_outbound_mail_ready', $this->readiness->isOutboundMailReady(...)),
            new TwigFunction('nowo_mail_kit_status', $this->status(...)),
        ];
    }

    /**
     * Status of the outbound mail configuration.
     */
    public function status(): MailStatus
    {
        return $this->readiness->status();
    }
}
