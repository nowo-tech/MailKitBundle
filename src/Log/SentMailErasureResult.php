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
     * @param int $deleted log rows deleted (with their bodies): rows the address received (to, cc, bcc) or sent
     * @param int $redacted subjects and stored bodies of other rows where the address was masked
     */
    public function __construct(
        public int $deleted = 0,
        public int $redacted = 0,
    ) {
    }

    /**
     * Counts per kind, the shape of {@see SentMailErasureInterface::forgetAddress()}.
     *
     * @return array{deleted: int, redacted: int}
     */
    public function toArray(): array
    {
        return ['deleted' => $this->deleted, 'redacted' => $this->redacted];
    }

    /**
     * Rows deleted plus rows masked.
     */
    public function total(): int
    {
        return $this->deleted + $this->redacted;
    }
}
