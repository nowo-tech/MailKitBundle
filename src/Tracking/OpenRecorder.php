<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use DateTimeImmutable;
use Nowo\MailKitBundle\Event\MailMachineOpenedEvent;
use Nowo\MailKitBundle\Event\MailOpenedEvent;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpen;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Counts one open (1.10) and, when the send exists, dispatches {@see MailOpenedEvent} or {@see MailMachineOpenedEvent}
 * after the write (autocommit: the write is committed). A failing listener is logged. Stateless.
 */
final readonly class OpenRecorder
{
    /**
     * Wires the recorder.
     */
    public function __construct(
        private TrackingStore $store,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Writes the open; true when the send exists.
     */
    public function record(RecordMailOpen $open): bool
    {
        if (!$this->store->recordOpen($open->sendId, $open->machine, $open->ip, $open->family, $open->at)) {
            return false;
        }
        if ($this->dispatcher instanceof EventDispatcherInterface) {
            $at = new DateTimeImmutable('@' . $open->at);
            try {
                $this->dispatcher->dispatch($open->machine ? new MailMachineOpenedEvent($open->sendId, $at) : new MailOpenedEvent($open->sendId, $at));
            } catch (Throwable $exception) {
                $this->logger?->error('Mail open listener failed.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);
            }
        }

        return true;
    }
}
