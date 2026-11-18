<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Throwable;

use function count;

/**
 * Messenger bus that keeps the dispatched messages (no handler).
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    /** @var list<array<StampInterface>> */
    public array $stamps = [];

    /** Thrown by the dispatch that would be number `$failAt` (0-based); null = never. */
    public ?Throwable $failure = null;

    public int $failAt = 0;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($this->failure instanceof Throwable && count($this->messages) === $this->failAt) {
            throw $this->failure;
        }
        $this->messages[] = $message instanceof Envelope ? $message->getMessage() : $message;
        $this->stamps[]   = $stamps;

        return Envelope::wrap($message, $stamps);
    }
}
