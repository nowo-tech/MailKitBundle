<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;

/**
 * An application section that happens to be named `resend` (not MailKit's 1.2 section).
 */
final class HostResendSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'resend';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('webhook');
    }

    public static function registry(): SettingsSectionRegistry
    {
        return new SettingsSectionRegistry([new self()]);
    }
}
