<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Validator;

use Attribute;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use Symfony\Component\Validator\Constraint;

/**
 * A mailer DSN accepted by {@see MailerDsnValidator} (allow-listed scheme, SSRF-safe host,
 * buildable transport). Blank values are valid (use NotBlank to require one).
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class MailerDsn extends Constraint
{
    /**
     * Validated by the {@see MailerDsnConstraintValidator} service.
     */
    public function validatedBy(): string
    {
        return MailerDsnConstraintValidator::class;
    }
}
