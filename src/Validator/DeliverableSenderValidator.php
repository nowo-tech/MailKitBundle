<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Validator;

use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Sender\SenderAddress;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

use function is_string;

/**
 * Validator of {@see DeliverableSender}.
 */
final class DeliverableSenderValidator extends ConstraintValidator
{
    /**
     * Adds one violation when the address is not on a public domain.
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DeliverableSender) {
            throw new UnexpectedTypeException($constraint, DeliverableSender::class);
        }
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }
        if (!SenderAddress::isDeliverable($value)) {
            $this->context->buildViolation($constraint->message)
                ->setTranslationDomain(NowoMailKitBundle::TRANSLATION_DOMAIN)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->addViolation();
        }
    }
}
