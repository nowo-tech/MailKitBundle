<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Validator;

use Attribute;
use Nowo\MailKitBundle\Sender\SenderAddress;
use Symfony\Component\Validator\Constraint;

/**
 * A sender address on a public domain ({@see SenderAddress::isDeliverable()}): providers
 * reject or spam-folder mail from `example.local`, single-label or `.localhost` domains. Blank values are valid.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class DeliverableSender extends Constraint
{
    /** Violation message (translation key, `NowoMailKitBundle` domain). */
    public string $message = 'sender.not_deliverable';
}
