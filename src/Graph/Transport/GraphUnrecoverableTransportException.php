<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Transport;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A Graph send whose outcome is unknown (audit NK-1): Messenger must not retry it (the message may have been sent).
 * Thrown by {@see GraphTransport} only when symfony/messenger is installed.
 */
final class GraphUnrecoverableTransportException extends TransportException implements UnrecoverableExceptionInterface
{
}
