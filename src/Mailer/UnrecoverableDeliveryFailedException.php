<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Mailer;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A {@see DeliveryFailedException} whose cause must not be retried by Messenger (1.8, audit NK-1: a Graph send with an
 * unknown outcome). Created by {@see DeliveryFailedException::from()} only for an unrecoverable cause, so the class is
 * loaded only when symfony/messenger is installed.
 */
final class UnrecoverableDeliveryFailedException extends DeliveryFailedException implements UnrecoverableExceptionInterface
{
}
