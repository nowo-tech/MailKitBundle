<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Form;

use Nowo\DoctrineEncryptBundle\Form\SecretFieldApplier;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Validator\ResendApiKeyAcceptedValidator;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

use function is_array;
use function is_string;
use function trim;

/**
 * Mailer settings page while the MailKit 1.2 `resend` rows are not migrated (since 1.3, user decision 2026-11-02):
 * the page shows what is live. With `resend.enabled = true` in the old rows the provider is pre-selected as `resend`;
 * the old key counts as stored ("leave blank to keep") and the old sender pre-fills `resend_from`.
 *
 * Saving with `provider = resend` and the key left blank submits the old key, so the save stores `mailer.provider`,
 * `mailer.resend_api_key` and `mailer.resend_from` and delivery stays on Resend. The key check treats it as the live
 * key ({@see ResendApiKeyAcceptedValidator}: no API call while Resend is live with it).
 * Nothing changes once `mailer.provider` is stored, or when `mailer.resend_api_key` is already stored.
 */
final class LegacyResendSettingsTypeExtension extends AbstractTypeExtension
{
    /** Placeholder handed to the secret field as `current_value` (only its presence counts). */
    private const STORED_MARKER = 'stored';

    /**
     * @param MailSettings $settings whether the 1.2 rows are still read
     * @param LegacyResendSettings $legacy the 1.2 values
     */
    public function __construct(
        private readonly MailSettings $settings,
        private readonly LegacyResendSettings $legacy,
    ) {
    }

    /**
     * The Instance Settings section form.
     */
    public static function getExtendedTypes(): iterable
    {
        return [SettingsSectionType::class];
    }

    /**
     * Pre-selects / pre-fills the mailer form from the 1.2 rows while they are pending.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $section = $options['section'] ?? null;
        if (!$section instanceof SettingsSectionInterface || $section->getName() !== MailerSettingsSection::NAME || !$builder->has('resend_api_key') || !$this->settings->legacyResendPending()) {
            return;
        }
        $legacy    = $this->legacy->values() ?? ['enabled' => false, 'api_key' => null, 'from' => null];
        $stored    = is_array($options['stored_secrets'] ?? null) && ($options['stored_secrets']['resend_api_key'] ?? false) === true;
        $legacyKey = $stored ? null : $legacy['api_key'];

        if ($legacyKey !== null) {
            $child = $builder->get('resend_api_key');
            $builder->add('resend_api_key', $child->getType()->getInnerType()::class, ['current_value' => self::STORED_MARKER] + $child->getOptions());
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) use ($legacy): void {
            // Instance Settings hands the effective values of the section (an array).
            $data = (array) $event->getData();
            if ($legacy['enabled']) {
                $data['provider'] = MailerSettingsSection::PROVIDER_RESEND;
            }
            $data['resend_from'] ??= $legacy['from'];
            $event->setData($data);
        });

        if ($legacyKey !== null) {
            $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event) use ($legacyKey): void {
                $data = $event->getData();
                if (!is_array($data) || ($data['provider'] ?? null) !== MailerSettingsSection::PROVIDER_RESEND) {
                    return;
                }
                $secret = is_array($data['resend_api_key'] ?? null) ? $data['resend_api_key'] : [];
                $value  = $secret[SecretFieldApplier::KEY_VALUE] ?? null;
                if (isset($secret[SecretFieldApplier::KEY_CLEAR]) || (is_string($value) && trim($value) !== '')) {
                    return;
                }
                // "Leave blank to keep" the 1.2 key: submit it so the save copies it into mailer.resend_api_key.
                $secret[SecretFieldApplier::KEY_VALUE] = $legacyKey;
                $data['resend_api_key']                = $secret;
                $event->setData($data);
            });
        }
    }
}
