<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Validator;

use Attribute;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Admin form constraint of `mailer.resend_api_key` (since 1.3, audit MK-L1): when the submitted `provider` is
 * `resend` and the save would put a key live — a newly typed key, or the stored key when switching from another
 * provider — the key is checked against the Resend API first and the save is refused when Resend rejects it, so a
 * wrong key never replaces a working delivery. Applies only inside a Symfony form whose root has a `provider` field;
 * imports, the seed and {@see InstanceSettingsInterface::save()} are not checked.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class ResendApiKeyAccepted extends Constraint
{
    /**
     * Validated by the {@see ResendApiKeyAcceptedValidator} service.
     */
    public function validatedBy(): string
    {
        return ResendApiKeyAcceptedValidator::class;
    }
}
