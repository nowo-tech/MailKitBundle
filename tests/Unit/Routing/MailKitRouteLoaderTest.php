<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Routing;

use Nowo\MailKitBundle\Routing\MailKitRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_keys;
use function sort;

final class MailKitRouteLoaderTest extends TestCase
{
    public function testGroups(): void
    {
        self::assertSame(['nowo_mail_kit_log', 'nowo_mail_kit_log_export', 'nowo_mail_kit_log_show', 'nowo_mail_kit_test'], $this->names(new MailKitRouteLoader(true, '/admin/mail/')));
        self::assertSame(['nowo_mail_kit_log', 'nowo_mail_kit_log_show', 'nowo_mail_kit_test'], $this->names(new MailKitRouteLoader(true, '/admin/mail/', exportEnabled: false)));
        self::assertSame(['nowo_mail_kit_test'], $this->names(new MailKitRouteLoader(true, '/admin/mail', false, true, false)));
        self::assertSame(
            ['nowo_mail_kit_log', 'nowo_mail_kit_log_body', 'nowo_mail_kit_log_export', 'nowo_mail_kit_log_show', 'nowo_mail_kit_resend_check', 'nowo_mail_kit_resend_test', 'nowo_mail_kit_test'],
            $this->names(new MailKitRouteLoader(true, '/admin/mail', true, true, true)),
        );
        self::assertSame([], $this->names(new MailKitRouteLoader(false, '/admin/mail')));
    }

    public function testSupportsAndLoadsOnce(): void
    {
        $loader = new MailKitRouteLoader(true, '/admin/mail');
        self::assertTrue($loader->supports('.', 'nowo_mail_kit'));
        self::assertFalse($loader->supports('.', 'attribute'));
        $loader->load('.');

        $this->expectException(RuntimeException::class);
        $loader->load('.');
    }

    /**
     * @return list<string>
     */
    private function names(MailKitRouteLoader $loader): array
    {
        $names = array_keys($loader->load('.')->all());
        sort($names);

        return $names;
    }
}
