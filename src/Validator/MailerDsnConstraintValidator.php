<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Validator;

use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

use function is_string;

/**
 * Validator of {@see MailerDsn}: the violation message is the {@see MailerDsnValidator} error key, translated in the
 * `NowoMailKitBundle` domain. The DSN is never part of the message.
 */
final class MailerDsnConstraintValidator extends ConstraintValidator
{
    /**
     * @param MailerDsnValidator $dsnValidator configured scheme / host rules
     */
    public function __construct(
        private readonly MailerDsnValidator $dsnValidator,
    ) {
    }

    /**
     * Adds one violation when the DSN is refused.
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MailerDsn) {
            throw new UnexpectedTypeException($constraint, MailerDsn::class);
        }
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $error = $this->dsnValidator->validate($value);
        if ($error !== null) {
            $this->context->buildViolation($error)
                ->setTranslationDomain(NowoMailKitBundle::TRANSLATION_DOMAIN)
                ->setInvalidValue('[redacted]')
                ->addViolation();
        }
    }
}
