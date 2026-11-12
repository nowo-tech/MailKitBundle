<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use Nowo\MailKitBundle\Event\MailDeliveredEvent;

/**
 * Read port of the open statistics (1.10, service alias registered with `tracking.enabled`): for the host's e-mail log
 * (send id from {@see MailDeliveredEvent::$trackingId}). Clicks are recorded by the host's
 * link rewriter (short links), not here.
 */
interface EmailTrackingReaderInterface
{
    /**
     * Statistics of one send, or null when it is unknown (never tracked, purged or erased).
     */
    public function find(string $sendId): ?OpenStats;

    /**
     * Statistics of several sends (unknown ids are left out), keyed by send id.
     *
     * @param list<string> $sendIds
     *
     * @return array<string, OpenStats>
     */
    public function findMany(array $sendIds): array;
}
