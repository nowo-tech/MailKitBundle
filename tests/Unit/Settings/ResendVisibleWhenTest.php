<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\ResendSettingsSection;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * 1.3: the Resend API key and sender depend on the `enabled` switch (InstanceSettings `visible_when`); the mailer
 * section has no provider choice and stays unchanged.
 */
final class ResendVisibleWhenTest extends TestCase
{
    public function testResendFieldsDependOnTheSwitch(): void
    {
        $registry = new SettingsSectionRegistry([new MailerSettingsSection(), new ResendSettingsSection()]);
        $fields   = $registry->fields('resend');

        self::assertNull($fields['enabled']->visibleWhen);
        self::assertSame(['field' => 'enabled', 'in' => ['1']], $fields['api_key']->visibleWhen);
        self::assertSame(['field' => 'enabled', 'in' => ['1']], $fields['from']->visibleWhen);
        self::assertSame(['enabled'], array_keys($registry->visibleFields('resend', ['enabled' => false])));
        self::assertSame(['enabled', 'api_key', 'from'], array_keys($registry->visibleFields('resend', ['enabled' => true])));

        foreach ($registry->fields('mailer') as $name => $field) {
            self::assertNull($field->visibleWhen, $name);
        }
    }
}
