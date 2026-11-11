<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;

/**
 * {@see SentMailErasureInterface} with `log.enabled: false`: the bundle stores nothing, so there is nothing to erase.
 */
final readonly class NullSentMailErasure implements SentMailErasureInterface
{
    /**
     * Nothing stored.
     *
     * @return array{deleted: int, redacted: int}
     */
    public function forgetAddress(string $email): array
    {
        return ['deleted' => 0, 'redacted' => 0];
    }

    /**
     * Nothing stored.
     */
    public function purgeBefore(DateTimeImmutable $before, int $batch = 1000): int
    {
        return 0;
    }
}
