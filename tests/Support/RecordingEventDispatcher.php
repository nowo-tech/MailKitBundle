<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * PSR-14 dispatcher that records the events.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * Events of a class.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function of(string $class): array
    {
        $list = [];
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                $list[] = $event;
            }
        }

        return $list;
    }
}
