<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailbox\Exception;

use RuntimeException;

/**
 * Base of the delegated-mailbox failures (2.5.0). Messages are fixed text plus ids, statuses and provider error codes:
 * never a token, a URL, an address or message content; no previous exception is kept.
 */
abstract class MailboxException extends RuntimeException
{
}
