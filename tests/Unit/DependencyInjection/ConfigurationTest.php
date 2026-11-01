<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\DependencyInjection;

use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\Dsn\MailerDsnValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testSafeDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        self::assertTrue($config['mailer']['decorate']);
        self::assertFalse($config['mailer']['fallback_counts_as_ready']);
        self::assertSame(MailerDsnValidator::DEFAULT_SCHEMES, $config['dsn']['allowed_schemes']);
        self::assertFalse($config['dsn']['allow_private_hosts']);
        self::assertTrue($config['dsn']['resolve_dns']);
        self::assertTrue($config['sender']['require_public_domain']);
        self::assertFalse($config['resend']['enabled']);
        self::assertSame(5, $config['test_email']['rate_limit']);
        self::assertTrue($config['log']['enabled']);
        self::assertFalse($config['log']['store_bodies']);
        self::assertSame(30, $config['log']['retention_days']);
        self::assertSame('/admin/mail', $config['web_ui']['path_prefix']);
        self::assertSame(['enabled' => true, 'max_rows' => 10_000, 'access_roles' => [], 'include_bodies' => false], $config['web_ui']['export']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertFalse($config['security']['allow_unauthenticated']);
    }

    public function testRoleAndListHelpers(): void
    {
        self::assertSame(['ROLE_A'], Configuration::roles([' ROLE_A ', '', 3, null]));
        self::assertSame([], Configuration::roles('ROLE_A'));
        self::assertSame(['smtp', 'ses'], Configuration::lower([' SMTP', 'ses']));

        $config = (new Processor())->processConfiguration(new Configuration(), [['security' => ['access_roles' => [], 'access_checker' => 'app.checker']]]);
        self::assertSame([], $config['security']['access_roles']);
        $config = (new Processor())->processConfiguration(new Configuration(), [['security' => ['access_roles' => [], 'allow_unauthenticated' => true]]]);
        self::assertTrue($config['security']['allow_unauthenticated']);
    }
}
