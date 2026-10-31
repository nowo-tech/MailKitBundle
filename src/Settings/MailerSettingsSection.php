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
use Nowo\MailKitBundle\Validator\MailerDsn;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The `mailer` settings section contributed to Instance Settings: DSN (encrypted secret, validated), sender address
 * and name, Reply-To and the recipient of the test e-mails, plus the "Send a test e-mail" action.
 *
 * Keys: `mailer.dsn` (secret), `mailer.from`, `mailer.from_name`, `mailer.reply_to`, `mailer.test_recipient`.
 * Never rename them: stored rows are keyed by name.
 */
final class MailerSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface
{
    /** Section name (key prefix, URL segment, export key). */
    public const NAME = 'mailer';

    /**
     * @param string|null $defaultFrom `sender.default_from`
     * @param string|null $defaultFromName `sender.default_from_name`
     * @param bool $requirePublicDomain `sender.require_public_domain`
     * @param SettingsActions|null $actions null when the admin UI is off (no action endpoints)
     */
    public function __construct(
        private readonly ?string $defaultFrom = null,
        private readonly ?string $defaultFromName = null,
        private readonly bool $requirePublicDomain = true,
        private readonly ?SettingsActions $actions = null,
    ) {
    }

    /** `mailer`. */
    public function getName(): string
    {
        return self::NAME;
    }

    /** `settings.mailer.label`. */
    public function getLabel(): string
    {
        return 'settings.mailer.label';
    }

    /** `NowoMailKitBundle`. */
    public function getTranslationDomain(): string
    {
        return NowoMailKitBundle::TRANSLATION_DOMAIN;
    }

    /** `settings.mailer.intro`. */
    public function getIntro(): string
    {
        return 'settings.mailer.intro';
    }

    /**
     * DSN, from, from name, reply-to, test recipient.
     */
    public function getFields(): iterable
    {
        $sender = $this->requirePublicDomain ? [new DeliverableSender()] : [];

        yield SettingField::secret('dsn', 'settings.mailer.dsn', 'settings.mailer.dsn_help', constraints: [new MailerDsn()]);
        yield new SettingField('from', SettingType::Email, $this->defaultFrom, 'settings.mailer.from', 'settings.mailer.from_help', constraints: $sender);
        yield SettingField::string('from_name', $this->defaultFromName, 'settings.mailer.from_name', constraints: [new Length(max: 120)]);
        yield new SettingField('reply_to', SettingType::Email, null, 'settings.mailer.reply_to', 'settings.mailer.reply_to_help');
        yield new SettingField('test_recipient', SettingType::Email, null, 'settings.mailer.test_recipient', 'settings.mailer.test_recipient_help');
    }

    /**
     * "Send a test e-mail" (when the admin UI is on).
     */
    public function getActions(): iterable
    {
        return $this->actions?->mailer() ?? [];
    }
}
