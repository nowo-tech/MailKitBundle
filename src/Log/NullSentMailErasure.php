<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;

/**
 * {@see SentMailErasureInterface}, {@see SentMailPersonalDataEraserInterface} (1.7) and
 * {@see SentMailTaggedPersonalDataEraserInterface} (2.8) on a host without Doctrine (the log requires it): the bundle cannot have stored
 * anything, so there is nothing to erase. With Doctrine registered the port is always {@see SentMailRecorder}.
 */
final readonly class NullSentMailErasure implements SentMailErasureInterface, SentMailPersonalDataEraserInterface, SentMailTaggedPersonalDataEraserInterface
{
    /**
     * Nothing stored.
     *
     * @return array{deleted: int, unlinked: int, redacted: int}
     */
    public function forgetAddress(string $email): array
    {
        return ['deleted' => 0, 'unlinked' => 0, 'redacted' => 0];
    }

    /**
     * Nothing stored.
     */
    public function erase(SentMailPersonalDataSubject $subject): SentMailErasureResult
    {
        return new SentMailErasureResult();
    }

    /**
     * Nothing stored; the tag is still validated like {@see SentMailRecorder::eraseTagged()}.
     */
    public function eraseTagged(SentMailPersonalDataSubject $subject, string $tag): SentMailErasureResult
    {
        SentMailRecorder::tagFilter($tag);

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
