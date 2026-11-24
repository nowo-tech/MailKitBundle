<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

/**
 * The account behind a token (2.5.0): its stable provider id and its addresses (lower-case).
 *
 * @internal
 */
final readonly class ProviderAccount
{
    /**
     * @param list<string> $addresses
     */
    public function __construct(
        public string $id,
        public array $addresses,
    ) {
    }
}
