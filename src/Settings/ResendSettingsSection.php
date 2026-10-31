<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Settings;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Ui\SettingsActions;
use Nowo\MailKitBundle\Validator\DeliverableSender;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The optional `resend` settings section (`nowo_mail_kit.resend.enabled: true`): switch, API key (encrypted secret)
 * and sender. While switched on with a key, mail goes through `resend+api://…@default` (symfony/resend-mailer).
 * Actions: "Check the API key" and "Send a test e-mail with Resend".
 *
 * Keys: `resend.enabled`, `resend.api_key` (secret), `resend.from`.
 */
final class ResendSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface
{
    /** Section name. */
    public const NAME = 'resend';

    /**
     * @param bool $requirePublicDomain `sender.require_public_domain`
     * @param SettingsActions|null $actions null when the admin UI is off
     */
    public function __construct(
        private readonly bool $requirePublicDomain = true,
        private readonly ?SettingsActions $actions = null,
    ) {
    }

    /** `resend`. */
    public function getName(): string
    {
        return self::NAME;
    }

    /** `settings.resend.label`. */
    public function getLabel(): string
    {
        return 'settings.resend.label';
    }

    /** `NowoMailKitBundle`. */
    public function getTranslationDomain(): string
    {
        return NowoMailKitBundle::TRANSLATION_DOMAIN;
    }

    /** `settings.resend.intro`. */
    public function getIntro(): string
    {
        return 'settings.resend.intro';
    }

    /**
     * Enabled, API key, from.
     */
    public function getFields(): iterable
    {
        yield SettingField::bool('enabled', false, 'settings.resend.enabled', 'settings.resend.enabled_help');
        yield SettingField::secret('api_key', 'settings.resend.api_key', 'settings.resend.api_key_help', constraints: [new Length(min: 8, max: 200)]);
        yield new SettingField('from', SettingType::Email, null, 'settings.resend.from', 'settings.resend.from_help', constraints: $this->requirePublicDomain ? [new DeliverableSender()] : []);
    }

    /**
     * Key check and Resend test (when the admin UI is on).
     */
    public function getActions(): iterable
    {
        return $this->actions?->resend() ?? [];
    }
}
