<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Settings;

use Closure;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SectionFallbackProviderInterface;
use Nowo\InstanceSettingsBundle\Section\SettingFallback;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionActionsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionNoticesInterface;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Ui\SettingsActions;
use Nowo\MailKitBundle\Validator\DeliverableSender;
use SensitiveParameter;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints\Length;

use function implode;

/**
 * The optional `resend` settings section (`nowo_mail_kit.resend.enabled: true`): switch, API key (encrypted secret)
 * and sender. While switched on with a key, mail goes through `resend+api://…@default` (symfony/resend-mailer).
 * Actions: "Check the API key" and "Send a test e-mail with Resend".
 *
 * Keys: `resend.enabled`, `resend.api_key` (secret), `resend.from`.
 */
final class ResendSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface, SectionFallbackProviderInterface, SettingsSectionNoticesInterface
{
    /** Section name. */
    public const NAME = 'resend';

    /**
     * @param bool $requirePublicDomain `sender.require_public_domain`
     * @param SettingsActions|null $actions null when the admin UI is off
     * @param string|null $bootstrapApiKey `bootstrap.resend_api_key`: copied once into `resend.api_key` by the seed (since 1.2)
     * @param (Closure(): OutboundMailReadiness)|null $readiness lazy readiness (page notice)
     */
    public function __construct(
        private readonly bool $requirePublicDomain = true,
        private readonly ?SettingsActions $actions = null,
        #[SensitiveParameter]
        private readonly ?string $bootstrapApiKey = null,
        private readonly ?Closure $readiness = null,
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
        yield SettingField::email('from', null, 'settings.resend.from', 'settings.resend.from_help', constraints: $this->requirePublicDomain ? [new DeliverableSender()] : []);
    }

    /**
     * Key check and Resend test (when the admin UI is on).
     */
    public function getActions(SectionActionContext $context): iterable
    {
        return $this->actions?->resend() ?? [];
    }

    /**
     * `bootstrap.resend_api_key` as the `api_key` fallback (secret). The `enabled` switch is not seeded: switching
     * the delivery to Resend stays an explicit admin decision.
     *
     * @return iterable<SettingFallback>
     */
    public function getFallbacks(): iterable
    {
        return [SettingFallback::secret('api_key', $this->bootstrapApiKey)];
    }

    /**
     * Warning naming the required fields of this section that are empty ({@see OutboundMailReadiness::missingFields()}).
     *
     * @param SectionActionContext $context page being rendered (unused)
     *
     * @return iterable<TranslatableMessage>
     */
    public function getNotices(SectionActionContext $context): iterable
    {
        $readiness = $this->readiness instanceof Closure ? ($this->readiness)() : null;
        $missing   = $readiness instanceof OutboundMailReadiness ? $readiness->missingFieldsOf(self::NAME) : [];

        return $missing === [] ? [] : [new TranslatableMessage('settings.notice_missing', ['%fields%' => implode(', ', $missing)], NowoMailKitBundle::TRANSLATION_DOMAIN)];
    }
}
