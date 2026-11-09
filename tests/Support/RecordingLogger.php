<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps the log records of a test.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function has(string $fragment): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $fragment)) {
                return true;
            }
        }

        return false;
    }
}
