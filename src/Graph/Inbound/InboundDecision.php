<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use function mb_substr;
use function preg_replace;
use function trim;

/**
 * The host's decision about one inbound message (1.9): an {@see InboundAction}, an optional host reference (e.g. the
 * id of the stored thread message, at most 128 characters) and an optional short reason code (at most 64 characters,
 * no control characters). MailKit stores only the action with the idempotency record.
 */
final class InboundDecision
{
    /** Host reference, or null. */
    public readonly ?string $reference;

    /** Short reason code, or null. */
    public readonly ?string $reason;

    /**
     * @param string|null $reference host id of what the message became
     * @param string|null $reason short reason code (`unknown_sender`, `spam`…)
     */
    public function __construct(
        public readonly InboundAction $action,
        ?string $reference = null,
        ?string $reason = null,
    ) {
        $this->reference = self::clean($reference, 128);
        $this->reason    = self::clean($reason, 64);
    }

    /**
     * Routed to the host's model.
     */
    public static function route(?string $reference = null): self
    {
        return new self(InboundAction::Route, $reference);
    }

    /**
     * Quarantined.
     */
    public static function quarantine(?string $reason = null, ?string $reference = null): self
    {
        return new self(InboundAction::Quarantine, $reference, $reason);
    }

    /**
     * Discarded.
     */
    public static function discard(?string $reason = null): self
    {
        return new self(InboundAction::Discard, null, $reason);
    }

    private static function clean(?string $value, int $max): ?string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
