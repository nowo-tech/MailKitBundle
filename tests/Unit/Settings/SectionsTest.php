<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Validator\DeliverableSender;
use Nowo\MailKitBundle\Validator\MailerDsn;
use Nowo\MailKitBundle\Validator\ResendApiKeyAccepted;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function iterator_to_array;

final class SectionsTest extends TestCase
{
    public function testMailerSection(): void
    {
        $section = new MailerSettingsSection('noreply@example.com', 'Acme', true);
        self::assertSame('mailer', $section->getName());
        self::assertSame('settings.mailer.label', $section->getLabel());
        self::assertSame('NowoMailKitBundle', $section->getTranslationDomain());
        self::assertSame('settings.mailer.intro', $section->getIntro());
        self::assertSame('tabler:mail-forward', $section->getIcon());

        $fields = $this->fields($section->getFields());
        self::assertSame(['dsn', 'from', 'from_name', 'reply_to', 'test_recipient'], array_keys($fields));
        self::assertTrue($fields['dsn']->secret);
        self::assertNull($fields['dsn']->visibleWhen, 'no provider choice without the Resend provider (1.2 page)');
        self::assertInstanceOf(MailerDsn::class, $fields['dsn']->constraints[0]);
        self::assertSame('noreply@example.com', $fields['from']->default);
        self::assertInstanceOf(DeliverableSender::class, $fields['from']->constraints[0]);
        self::assertSame('Acme', $fields['from_name']->default);

        self::assertSame([], iterator_to_array($section->getActions($this->context()), false));

        $plain = new MailerSettingsSection(requirePublicDomain: false);
        self::assertSame([], $this->fields($plain->getFields())['from']->constraints);
        self::assertSame([], iterator_to_array($plain->getActions($this->context()), false));
    }

    public function testMailerSectionWithTheResendProvider(): void
    {
        $section = new MailerSettingsSection('noreply@example.com', 'Acme', true, resendEnabled: true);
        self::assertSame('settings.mailer.intro_providers', $section->getIntro());

        $fields = $this->fields($section->getFields());
        self::assertSame(['provider', 'dsn', 'resend_api_key', 'resend_from', 'from', 'from_name', 'reply_to', 'test_recipient'], array_keys($fields));
        self::assertSame(['settings.mailer.provider_dsn' => 'dsn', 'settings.mailer.provider_resend' => 'resend'], $fields['provider']->choices);
        self::assertSame('dsn', $fields['provider']->default);
        self::assertTrue($fields['provider']->required);
        self::assertNull($fields['provider']->visibleWhen);
        self::assertSame(['field' => 'provider', 'in' => ['dsn']], $fields['dsn']->visibleWhen);
        self::assertSame(['field' => 'provider', 'in' => ['resend']], $fields['resend_api_key']->visibleWhen);
        self::assertSame(['field' => 'provider', 'in' => ['resend']], $fields['resend_from']->visibleWhen);
        self::assertTrue($fields['resend_api_key']->secret);
        self::assertInstanceOf(ResendApiKeyAccepted::class, $fields['resend_api_key']->formOptions['constraints'][0] ?? null);
        self::assertInstanceOf(DeliverableSender::class, $fields['resend_from']->constraints[0]);
        foreach (['from', 'from_name', 'reply_to', 'test_recipient'] as $shared) {
            self::assertNull($fields[$shared]->visibleWhen, $shared . ' is shown for every provider');
        }

        $plain = new MailerSettingsSection(requirePublicDomain: false, resendEnabled: true);
        self::assertSame([], $this->fields($plain->getFields())['resend_from']->constraints);
        self::assertSame([], iterator_to_array($plain->getActions($this->context()), false));
    }

    private function context(): SectionActionContext
    {
        return new SectionActionContext('mailer', new Request());
    }

    /**
     * @param iterable<SettingField> $fields
     *
     * @return array<string, SettingField>
     */
    private function fields(iterable $fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            $map[$field->name] = $field;
        }

        return $map;
    }
}
