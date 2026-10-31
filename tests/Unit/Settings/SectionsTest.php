<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Section\SectionActionContext;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\ResendSettingsSection;
use Nowo\MailKitBundle\Validator\DeliverableSender;
use Nowo\MailKitBundle\Validator\MailerDsn;
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
        self::assertNull($section->getIcon());

        $fields = $this->fields($section->getFields());
        self::assertSame(['dsn', 'from', 'from_name', 'reply_to', 'test_recipient'], array_keys($fields));
        self::assertTrue($fields['dsn']->secret);
        self::assertInstanceOf(MailerDsn::class, $fields['dsn']->constraints[0]);
        self::assertSame('noreply@example.com', $fields['from']->default);
        self::assertInstanceOf(DeliverableSender::class, $fields['from']->constraints[0]);
        self::assertSame('Acme', $fields['from_name']->default);

        self::assertSame([], iterator_to_array($section->getActions($this->context()), false));

        $plain = new MailerSettingsSection(requirePublicDomain: false);
        self::assertSame([], $this->fields($plain->getFields())['from']->constraints);
        self::assertSame([], iterator_to_array($plain->getActions($this->context()), false));
    }

    public function testResendSection(): void
    {
        $section = new ResendSettingsSection(true);
        self::assertSame('resend', $section->getName());
        self::assertSame('settings.resend.label', $section->getLabel());
        self::assertSame('NowoMailKitBundle', $section->getTranslationDomain());
        self::assertSame('settings.resend.intro', $section->getIntro());
        $fields = $this->fields($section->getFields());
        self::assertSame(['enabled', 'api_key', 'from'], array_keys($fields));
        self::assertTrue($fields['api_key']->secret);
        self::assertSame([], iterator_to_array($section->getActions($this->context()), false));

        $plain = new ResendSettingsSection(false);
        self::assertSame([], $this->fields($plain->getFields())['from']->constraints);
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
