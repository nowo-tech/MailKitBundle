<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Event;

use Nowo\MailKitBundle\Log\SentMailErasureInterface;

/**
 * Dispatched (PSR-14, FQCN as event name) after {@see SentMailErasureInterface::forgetAddress()} committed the erasure
 * of one address (1.7): for the host's erasure audit trail. Not dispatched when the caller already had a transaction
 * open on the log connection (it may still roll back): there the returned counts are the outcome. Dispatched also when
 * nothing matched (counts 0), so an audit trail records every completed request.
 *
 * Carries the erased address (normalised, the value of the request): keep it out of long-lived logs.
 */
final readonly class SentMailPersonalDataErasedEvent
{
    /**
     * @param string $email normalised address that was erased
     * @param int $deleted log rows deleted (with their bodies)
     * @param int $redacted subjects of other rows and stored bodies where the address was masked
     * @param int $unlinked rows kept for their other recipients, with the address removed
     */
    public function __construct(
        public string $email,
        public int $deleted,
        public int $redacted,
        public int $unlinked = 0,
    ) {
    }

    /**
     * Counts per kind.
     *
     * @return array{deleted: int, unlinked: int, redacted: int}
     */
    public function toArray(): array
    {
        return ['deleted' => $this->deleted, 'unlinked' => $this->unlinked, 'redacted' => $this->redacted];
    }
}
