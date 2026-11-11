<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;

/**
 * {@see SentMailErasureInterface} and {@see SentMailPersonalDataEraserInterface} (1.7) on a host without Doctrine (the log requires it): the bundle cannot have stored
 * anything, so there is nothing to erase. With Doctrine registered the port is always {@see SentMailRecorder}.
 */
final readonly class NullSentMailErasure implements SentMailErasureInterface, SentMailPersonalDataEraserInterface
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
    public function erase(SentMailPersonalDataSubject $subject): SentMailErasureResult
    {
        return new SentMailErasureResult();
    }

    /**
     * Nothing stored.
     */
    public function purgeBefore(DateTimeImmutable $before, int $batch = 1000): int
    {
        return 0;
    }
}
