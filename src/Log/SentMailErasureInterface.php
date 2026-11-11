<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;

/**
 * Erasure and retention port of the sent-mail log (1.6, rule 1): the entry point of an account-deletion use case
 * (GDPR art. 17) and of a retention job. Always registered so an application service can depend on it whatever the
 * configuration: {@see SentMailRecorder} whenever Doctrine is registered — also with `log.enabled: false`, so rows
 * written while the log was on are really erased and purged — and {@see NullSentMailErasure} only on a host without
 * Doctrine, where the bundle cannot have stored anything.
 */
interface SentMailErasureInterface
{
    /**
     * Deletes every row (and body) the address sent or received and masks it in the remaining subjects and bodies.
     *
     * @throws SentMailLogTableMissingException when a log table is missing while the entities are mapped
     *
     * @return array{deleted: int, redacted: int}
     */
    public function forgetAddress(string $email): array;

    /**
     * Deletes rows queued before the date in keyset batches; returns the number of deleted rows.
     *
     * @throws SentMailLogTableMissingException when a log table is missing while the entities are mapped
     */
    public function purgeBefore(DateTimeImmutable $before, int $batch = 1000): int;
}
