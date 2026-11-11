<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;

/**
 * Erasure and retention port of the sent-mail log (1.6, rule 1): the entry point of an account-deletion use case
 * (GDPR art. 17) and of a retention job. Always registered: {@see SentMailRecorder} with `log.enabled: true`,
 * {@see NullSentMailErasure} (nothing stored, nothing to erase) with the log off, so an application service can depend
 * on it whatever the configuration.
 */
interface SentMailErasureInterface
{
    /**
     * Deletes every row (and body) the address sent or received and masks it in the remaining subjects and bodies.
     *
     * @return array{deleted: int, redacted: int}
     */
    public function forgetAddress(string $email): array;

    /**
     * Deletes rows queued before the date in keyset batches; returns the number of deleted rows.
     */
    public function purgeBefore(DateTimeImmutable $before, int $batch = 1000): int;
}
