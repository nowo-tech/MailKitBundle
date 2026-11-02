<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Functional;

use Doctrine\DBAL\Connection;
use Nowo\InstanceSettingsBundle\Command\SeedFromFallbacksCommand;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function bin2hex;
use function random_bytes;

/**
 * DB-only mode: `bootstrap.mailer_dsn` / `bootstrap.resend_api_key` (mapped to %env()%) seeded once through
 * InstanceSettings 1.2, encrypted, names only in the output; notices on the settings pages.
 */
final class DbOnlySeedTest extends KernelTestCase
{
    public function testBootstrapValuesAreSeededOnce(): void
    {
        $password = bin2hex(random_bytes(10));
        $key      = 're_' . bin2hex(random_bytes(12));
        $kernel   = $this->bootKernel([
            'resend'    => ['enabled' => true],
            'bootstrap' => ['mailer_dsn' => '%env(default:mail_kit_test_dsn:MAIL_KIT_TEST_UNDEFINED)%', 'resend_api_key' => '%env(default:mail_kit_test_key:MAIL_KIT_TEST_UNDEFINED)%'],
        ], extra: static function (ContainerConfigurator $container) use ($password, $key): void {
            $container->parameters()->set('mail_kit_test_dsn', 'smtp://user:' . $password . '@smtp.example.com:587');
            $container->parameters()->set('mail_kit_test_key', $key);
        });

        $tester = new CommandTester((new Application($kernel))->find(SeedFromFallbacksCommand::NAME));
        self::assertSame(0, $tester->execute(['--section' => ['mailer', 'resend'], '--strict' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('mailer.dsn', $display);
        self::assertStringContainsString('resend.api_key', $display);
        self::assertStringNotContainsString($password, $display);
        self::assertStringNotContainsString($key, $display);

        self::assertSame('smtp://user:' . $password . '@smtp.example.com:587', $this->settings($kernel)->getSecret('mailer.dsn'));
        self::assertSame($key, $this->settings($kernel)->getSecret('resend.api_key'));
        self::assertFalse($this->settings($kernel)->getBool('resend.enabled'), 'the Resend switch is not seeded');
        $connection = $this->container($kernel)->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertStringNotContainsString($password, (string) $connection->fetchOne("SELECT secret_value FROM instance_setting_value WHERE section = 'mailer' AND name = 'dsn'"));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Nothing to seed', $tester->getDisplay());

        $readiness = $this->container($kernel)->get(OutboundMailReadiness::class);
        self::assertInstanceOf(OutboundMailReadiness::class, $readiness);
        self::assertSame([], $readiness->status()->missingFields);
    }

    public function testAnInvalidBootstrapDsnIsRefused(): void
    {
        $kernel = $this->bootKernel(['bootstrap' => ['mailer_dsn' => 'smtp://10.0.0.5:25']]);
        $tester = new CommandTester((new Application($kernel))->find(SeedFromFallbacksCommand::NAME));

        self::assertSame(1, $tester->execute(['--strict' => true]));
        self::assertStringContainsString('mailer.dsn (invalid)', $tester->getDisplay());
        self::assertStringNotContainsString('10.0.0.5', $tester->getDisplay());
        self::assertFalse($this->settings($kernel)->hasSecret('mailer.dsn'));
    }

    public function testThePagesWarnAboutMissingSettings(): void
    {
        $kernel = $this->bootKernel(['resend' => ['enabled' => true]]);
        $client = $this->client($kernel);
        $this->settings($kernel)->save('resend', ['enabled' => true]);

        $crawler = $client->request('GET', '/admin/settings/sections/resend');
        $notice  = $crawler->filter('[data-testid="instance-settings-notice"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('Required settings are empty: api_key.', $notice->text());

        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        self::assertStringContainsString('Required settings are empty: dsn.', $crawler->filter('[data-testid="instance-settings-notice"]')->text());
    }
}
