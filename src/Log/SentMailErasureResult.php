<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

/**
 * Outcome of the erasure of one address in the sent-mail log (1.7, GDPR art. 17): counts per kind. Plain PHP value
 * object (no Doctrine, Symfony or HTTP types).
 */
final readonly class SentMailErasureResult
{
    /**
     * @param int $deleted log rows deleted (with their bodies): rows of the address with no other recipient left
     * @param int $redacted subjects of other rows and stored bodies where the address was masked
     * @param int $unlinked rows kept for their other recipients, with the address removed from the recipients and
     *                      the sender and masked in the subject
     */
    public function __construct(
        public int $deleted = 0,
        public int $redacted = 0,
        public int $unlinked = 0,
    ) {
    }

    /**
     * Counts per kind, the shape of {@see SentMailErasureInterface::forgetAddress()}.
     *
     * @return array{deleted: int, unlinked: int, redacted: int}
     */
    public function toArray(): array
    {
        return ['deleted' => $this->deleted, 'unlinked' => $this->unlinked, 'redacted' => $this->redacted];
    }

    /**
     * Rows deleted, unlinked and masked.
     */
    public function total(): int
    {
        return $this->deleted + $this->unlinked + $this->redacted;
    }
}
