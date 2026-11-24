<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Engine;

use RuntimeException;

/**
 * The provider no longer knows a folder's cursor (Graph 410, 2.5.0): the sweep restarts from the resync window.
 *
 * @internal
 */
final class CursorExpired extends RuntimeException
{
    /**
     * Fixed message.
     */
    public function __construct()
    {
        parent::__construct('The mailbox folder cursor expired.');
    }
}
