<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

/**
 * A subscription the provider created (2.5.0).
 *
 * @internal
 */
final readonly class RemoteSubscription
{
    /**
     * @param int $expiresAt UNIX seconds
     */
    public function __construct(
        public string $id,
        public int $expiresAt,
    ) {
    }
}
