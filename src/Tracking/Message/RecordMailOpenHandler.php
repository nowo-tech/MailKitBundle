<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking\Message;

use Nowo\MailKitBundle\Tracking\OpenRecorder;

/**
 * Handles {@see RecordMailOpen} (1.10, with `tracking.bus`): the same write and event as a synchronous open.
 */
final readonly class RecordMailOpenHandler
{
    /**
     * Wires the handler.
     */
    public function __construct(
        private OpenRecorder $recorder,
    ) {
    }

    /**
     * Records the open.
     */
    public function __invoke(RecordMailOpen $message): void
    {
        $this->recorder->record($message);
    }
}
