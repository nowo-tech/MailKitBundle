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
use Nowo\MailKitBundle\Validator\MailerDsn;
use Nowo\MailKitBundle\Validator\ResendApiKeyAccepted;
use SensitiveParameter;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints\Length;

use function implode;

/**
 * The `mailer` settings section contributed to Instance Settings: DSN (encrypted secret, validated), sender address
 * and name, Reply-To and the recipient of the test e-mails, plus the "Send a test e-mail" action.
 *
 * With `nowo_mail_kit.resend.enabled` (since 1.3) the section also carries the provider choice and the Resend fields,
 * provider-dependent through InstanceSettings `visible_when`: `provider` (`dsn` / `resend`), `dsn` only for `dsn`,
 * `resend_api_key` and `resend_from` only for `resend`; the sender, Reply-To and test recipient always. Saving
 * `provider = resend` with a new key, or switching to Resend, checks the key against the Resend API first and refuses
 * the save when it is rejected ({@see ResendApiKeyAccepted}). The Resend actions ("Check the API key", "Send a test
 * e-mail with Resend") are added next to the mailer test.
 *
 * Keys: `mailer.provider`, `mailer.dsn` (secret), `mailer.resend_api_key` (secret), `mailer.resend_from`,
 * `mailer.from`, `mailer.from_name`, `mailer.reply_to`, `mailer.test_recipient`. Never rename them: stored rows are
 * keyed by name.
 */
final class MailerSettingsSection extends AbstractSettingsSection implements SettingsSectionActionsInterface, SectionFallbackProviderInterface, SettingsSectionNoticesInterface
{
    /** Section name (key prefix, URL segment, export key). */
    public const NAME = 'mailer';

    /** `mailer.provider`: SMTP or any Symfony mailer DSN (`mailer.dsn`). Since 1.3. */
    public const PROVIDER_DSN = 'dsn';

    /** `mailer.provider`: the Resend API (`mailer.resend_api_key`). Since 1.3. */
    public const PROVIDER_RESEND = 'resend';

    /**
     * @param string|null $defaultFrom `sender.default_from`
     * @param string|null $defaultFromName `sender.default_from_name`
     * @param bool $requirePublicDomain `sender.require_public_domain`
     * @param SettingsActions|null $actions null when the admin UI is off (no action endpoints)
     * @param string|null $bootstrapDsn `bootstrap.mailer_dsn`: copied once into `mailer.dsn` by the seed (since 1.2)
     * @param (Closure(): OutboundMailReadiness)|null $readiness lazy readiness (page notice); lazy because it reads the
     *                                                           settings store this section belongs to
     * @param bool $resendEnabled `resend.enabled`: provider choice and Resend fields (since 1.3)
     * @param string|null $bootstrapResendApiKey `bootstrap.resend_api_key`: copied once into `mailer.resend_api_key`
     * @param LegacyResendSettings|null $legacy 1.2 `resend` rows, seeded into the Resend fields (since 1.3)
     * @param (Closure(): MailSettings)|null $settings lazy settings view (notice while the 1.2 rows are not migrated)
     */
    public function __construct(
        private readonly ?string $defaultFrom = null,
        private readonly ?string $defaultFromName = null,
        private readonly bool $requirePublicDomain = true,
        private readonly ?SettingsActions $actions = null,
        #[SensitiveParameter]
        private readonly ?string $bootstrapDsn = null,
        private readonly ?Closure $readiness = null,
        private readonly bool $resendEnabled = false,
        #[SensitiveParameter]
        private readonly ?string $bootstrapResendApiKey = null,
        private readonly ?LegacyResendSettings $legacy = null,
        private readonly ?Closure $settings = null,
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

    /** `settings.mailer.intro` (`settings.mailer.intro_providers` with the Resend provider). */
    public function getIntro(): string
    {
        return $this->resendEnabled ? 'settings.mailer.intro_providers' : 'settings.mailer.intro';
    }

    /** `tabler:mail-forward`. */
    public function getIcon(): ?string
    {
        return 'tabler:mail-forward';
    }

    /**
     * Provider choice and Resend fields (with the Resend provider), DSN, from, from name, reply-to, test recipient.
     */
    public function getFields(): iterable
    {
        $sender = $this->requirePublicDomain ? [new DeliverableSender()] : [];
        $dsn    = SettingField::secret('dsn', 'settings.mailer.dsn', 'settings.mailer.dsn_help', constraints: [new MailerDsn()]);

        if (!$this->resendEnabled) {
            yield $dsn;
        } else {
            yield SettingField::choice('provider', [
                'settings.mailer.provider_dsn'    => self::PROVIDER_DSN,
                'settings.mailer.provider_resend' => self::PROVIDER_RESEND,
            ], self::PROVIDER_DSN, 'settings.mailer.provider', 'settings.mailer.provider_help', required: true);
            yield $dsn->withVisibleWhen('provider', [self::PROVIDER_DSN]);
            // The Resend key is checked against the Resend API before it can go live (MK-L1): admin form constraint.
            yield SettingField::secret('resend_api_key', 'settings.mailer.resend_api_key', 'settings.mailer.resend_api_key_help', constraints: [new Length(min: 8, max: 200)])
                ->withVisibleWhen('provider', [self::PROVIDER_RESEND])
                ->withFormOptions(['constraints' => [new ResendApiKeyAccepted()]]);
            yield SettingField::email('resend_from', null, 'settings.mailer.resend_from', 'settings.mailer.resend_from_help', constraints: $sender)
                ->withVisibleWhen('provider', [self::PROVIDER_RESEND]);
        }
        yield SettingField::email('from', $this->defaultFrom, 'settings.mailer.from', 'settings.mailer.from_help', constraints: $sender);
        yield SettingField::string('from_name', $this->defaultFromName, 'settings.mailer.from_name', constraints: [new Length(max: 120)]);
        yield SettingField::email('reply_to', null, 'settings.mailer.reply_to', 'settings.mailer.reply_to_help');
        yield SettingField::email('test_recipient', null, 'settings.mailer.test_recipient', 'settings.mailer.test_recipient_help');
    }

    /**
     * "Send a test e-mail", plus "Check the API key" and "Send a test e-mail with Resend" with the Resend provider
     * (when the admin UI is on).
     */
    public function getActions(SectionActionContext $context): iterable
    {
        if (!$this->actions instanceof SettingsActions) {
            return [];
        }

        return $this->resendEnabled ? [...$this->actions->mailer(), ...$this->actions->resend()] : $this->actions->mailer();
    }

    /**
     * `bootstrap.mailer_dsn` as the `dsn` fallback (secret). With the Resend provider: the 1.2 `resend` rows as the
     * `provider` (`resend.enabled` → `resend`, else `dsn`), `resend_api_key` and `resend_from` fallbacks, then
     * `bootstrap.resend_api_key` for the key. Without 1.2 rows the provider is not seeded: switching the delivery to
     * Resend stays an explicit admin decision.
     *
     * @return iterable<SettingFallback>
     */
    public function getFallbacks(): iterable
    {
        $fallbacks = [SettingFallback::secret('dsn', $this->bootstrapDsn)];
        if (!$this->resendEnabled) {
            return $fallbacks;
        }

        $legacy = $this->legacy?->values();
        if ($legacy !== null) {
            $fallbacks[] = SettingFallback::plain('provider', $legacy['enabled'] ? self::PROVIDER_RESEND : self::PROVIDER_DSN);
        }
        $fallbacks[] = SettingFallback::secret('resend_api_key', $legacy['api_key'] ?? $this->bootstrapResendApiKey);
        $fallbacks[] = SettingFallback::plain('resend_from', $legacy['from'] ?? null);

        return $fallbacks;
    }

    /**
     * Warning naming the required fields of the selected provider that are empty
     * ({@see OutboundMailReadiness::missingFields()}), and one while the 1.2 `resend` rows are not migrated.
     *
     * @param SectionActionContext $context page being rendered (unused)
     *
     * @return iterable<TranslatableMessage>
     */
    public function getNotices(SectionActionContext $context): iterable
    {
        $notices  = [];
        $settings = $this->settings instanceof Closure ? ($this->settings)() : null;
        if ($settings instanceof MailSettings && $settings->legacyResendPending()) {
            $notices[] = new TranslatableMessage('settings.mailer.notice_legacy_resend', [], NowoMailKitBundle::TRANSLATION_DOMAIN);
        }
        $readiness = $this->readiness instanceof Closure ? ($this->readiness)() : null;
        $missing   = $readiness instanceof OutboundMailReadiness ? $readiness->missingFieldsOf(self::NAME) : [];
        if ($missing !== []) {
            $notices[] = new TranslatableMessage('settings.notice_missing', ['%fields%' => implode(', ', $missing)], NowoMailKitBundle::TRANSLATION_DOMAIN);
        }

        return $notices;
    }
}
