<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use InvalidArgumentException;

/**
 * GDPR port of the open tracking (1.10, art. 15 / 17 / 20; service alias registered with `tracking.enabled`): every send
 * row stored for a host recipient id, exported or erased. Exact (binary) match of the id, re-checked in PHP; the
 * erasure is complete and idempotent (a second run returns 0). The UserKit bridge (`email_tracking`, with
 * `personal_data.enabled`) calls it with the ids of {@see TrackingRecipientResolverInterface}.
 *
 * Inside an open transaction on the tracking connection the erasure joins it; otherwise each statement commits on its
 * own. Sends tracked without a recipient id cannot be attributed and are only removed by the retention purge.
 */
interface EmailTrackingPersonalDataInterface
{
    /**
     * Deletes the recipient's send rows; returns how many.
     *
     * @throws InvalidArgumentException for an unacceptable id ({@see TrackingIds::recipient()})
     */
    public function eraseRecipient(string $recipientId): int;

    /**
     * The recipient's send rows, oldest first (dates in ISO 8601, UTC).
     *
     * @throws InvalidArgumentException for an unacceptable id ({@see TrackingIds::recipient()})
     *
     * @return list<array{send_id: string, template: string|null, tenant: string|null, sent_at: string, opens: int, first_open_at: string|null, last_open_at: string|null, machine_opens: int, machine_first_open_at: string|null, machine_last_open_at: string|null}>
     */
    public function exportRecipient(string $recipientId): array;
}
