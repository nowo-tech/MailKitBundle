<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Messenger bus that keeps the dispatched messages (no handler).
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    /** @var list<array<StampInterface>> */
    public array $stamps = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message instanceof Envelope ? $message->getMessage() : $message;
        $this->stamps[]   = $stamps;

        return Envelope::wrap($message, $stamps);
    }
}
