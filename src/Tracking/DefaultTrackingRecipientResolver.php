<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking;

use InvalidArgumentException;
use Stringable;

use function is_int;
use function is_string;
use function method_exists;

/**
 * Default {@see TrackingRecipientResolverInterface} (1.10): the user's `getId()` as a string (int, string or
 * Stringable, e.g. a Uid) when it is an acceptable recipient id ({@see TrackingIds::recipient()}); otherwise none.
 */
final readonly class DefaultTrackingRecipientResolver implements TrackingRecipientResolverInterface
{
    /**
     * `[(string) $user->getId()]`, or `[]`.
     *
     * @return list<string>
     */
    public function recipientIdsFor(object $user): array
    {
        if (!method_exists($user, 'getId')) {
            return [];
        }
        $id = $user->getId();
        $id = is_int($id) || $id instanceof Stringable ? (string) $id : $id;
        if (!is_string($id)) {
            return [];
        }
        try {
            return [(string) TrackingIds::recipient($id)];
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}
