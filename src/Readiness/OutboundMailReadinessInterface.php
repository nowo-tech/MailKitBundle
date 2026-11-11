<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Readiness;

/**
 * Port of "can this instance send e-mail?" (1.6, rule 1): readiness of the runtime DSN for dashboards, setup
 * checklists and the AuthKit adapter. The default implementation is {@see OutboundMailReadiness}; inject this interface
 * (aliased to it) instead of the final class. Answers per call, no memo across requests (worker mode).
 */
interface OutboundMailReadinessInterface
{
    /**
     * True when outgoing mail can be delivered.
     */
    public function isOutboundMailReady(): bool;

    /**
     * Source, redacted label, readiness and error of the current configuration (scalars only).
     */
    public function status(): MailStatus;

    /**
     * Required settings that are empty (`<section>.<field>`); empty when the DSN comes from a host provider.
     *
     * @return list<string>
     */
    public function missingFields(): array;
}
