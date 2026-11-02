<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Functional;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nowo\InstanceSettingsBundle\Command\SeedFromFallbacksCommand;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\MailKitBundle\Command\PurgeLegacyResendCommand;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\ResendApiMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;

use function bin2hex;
use function random_bytes;

/**
 * DB-only mode: `bootstrap.mailer_dsn` / `bootstrap.resend_api_key` (mapped to %env()%) seeded once through
 * InstanceSettings, encrypted, names only in the output; notices on the settings pages; 1.3 migration of the 1.2
 * `resend` rows into the mailer section.
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
        self::assertSame(0, $tester->execute(['--section' => ['mailer'], '--strict' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('mailer.dsn', $display);
        self::assertStringContainsString('mailer.resend_api_key', $display);
        self::assertStringNotContainsString($password, $display);
        self::assertStringNotContainsString($key, $display);

        self::assertSame('smtp://user:' . $password . '@smtp.example.com:587', $this->settings($kernel)->getSecret('mailer.dsn'));
        self::assertSame($key, $this->settings($kernel)->getSecret('mailer.resend_api_key'));
        self::assertFalse($this->settings($kernel)->isOverridden('mailer.provider'), 'the provider is not seeded without 1.2 rows');
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

        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        self::assertStringContainsString('Required settings are empty: dsn.', $crawler->filter('[data-testid="instance-settings-notice"]')->text());

        $this->settings($kernel)->save('mailer', ['provider' => 'resend']);
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        $notice  = $crawler->filter('[data-testid="instance-settings-notice"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString('Required settings are empty: resend_api_key.', $notice->text());
    }

    public function testThe12ResendRowsKeepWorkingAndAreSeededIntoTheMailerSection(): void
    {
        $key    = 're_' . bin2hex(random_bytes(12));
        $kernel = $this->bootKernel(['resend' => ['enabled' => true]]);
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'noreply@example.com']);
        $now = new DateTimeImmutable();
        $this->service($kernel, SettingValueRepositoryInterface::class)->apply([
            (new SettingValue('resend', 'enabled', $now))->storeValue(true, $now),
            (new SettingValue('resend', 'api_key', $now))->storeSecret($key, $now),
            (new SettingValue('resend', 'from', $now))->storeValue('mail@example.com', $now),
        ], []);
        $this->service($kernel, LegacyResendSettings::class)->reset();

        // Before the seed: 1.2 behaviour (Resend live) and a notice on the page.
        $settings = $this->service($kernel, MailSettings::class);
        self::assertTrue($settings->legacyResendPending());
        self::assertSame('resend+api://' . $key . '@default', $settings->dsn()?->dsn);
        self::assertSame('mail@example.com', $settings->from()?->getAddress());
        $crawler = $this->client($kernel)->request('GET', '/admin/settings/sections/mailer');
        self::assertStringContainsString('still read from the old resend section', $crawler->filter('[data-testid="instance-settings-notice"]')->text());

        $tester = new CommandTester((new Application($kernel))->find(SeedFromFallbacksCommand::NAME));
        self::assertSame(0, $tester->execute(['--section' => ['mailer'], '--strict' => true]));
        self::assertStringNotContainsString($key, $tester->getDisplay());
        $this->service($kernel, LegacyResendSettings::class)->reset();

        self::assertSame('resend', $this->settings($kernel)->getString('mailer.provider'));
        self::assertSame($key, $this->settings($kernel)->getSecret('mailer.resend_api_key'));
        self::assertSame('mail@example.com', $this->settings($kernel)->getString('mailer.resend_from'));
        self::assertFalse($settings->legacyResendPending());
        self::assertSame('resend+api://' . $key . '@default', $settings->dsn()?->dsn);
        $crawler = $this->client($kernel)->request('GET', '/admin/settings/sections/mailer');
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-notice"]'));
        self::assertSame('resend', $crawler->filter('select[name="settings_section[provider]"] option[selected]')->attr('value'));

        // Opt-in purge of the copied 1.2 rows (encrypted key included), idempotent.
        $purge = new CommandTester((new Application($kernel))->find(PurgeLegacyResendCommand::NAME));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('Deleted 3 legacy row(s).', $purge->getDisplay());
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne("SELECT COUNT(*) FROM instance_setting_value WHERE section = 'resend'"));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('nothing to purge', $purge->getDisplay());
        self::assertSame('resend+api://' . $key . '@default', $settings->dsn()?->dsn);
    }

    public function testSavingThePageBeforeTheSeedKeepsResendLive(): void
    {
        $key    = 're_' . bin2hex(random_bytes(12));
        $kernel = $this->bootKernel(['resend' => ['enabled' => true]], extra: static function (ContainerConfigurator $container): void {
            // Any Resend call fails the test: the live 1.2 key must not be checked again.
            $container->services()->set('http_client', MockHttpClient::class)->args([[]])->public();
        });
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'noreply@example.com']);
        $this->legacyRows($kernel, true, $key);

        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        self::assertSame('resend', $crawler->filter('select[name="settings_section[provider]"] option[selected]')->attr('value'), 'the live 1.2 provider is pre-selected');
        self::assertSame('legacy@example.com', $crawler->filter('input[name="settings_section[resend_from]"]')->attr('value'));
        self::assertStringNotContainsString($key, (string) $client->getResponse()->getContent());

        $client->submit($crawler->filter('form[name="settings_section"]')->form());
        self::assertSame(302, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $this->service($kernel, LegacyResendSettings::class)->reset();
        self::assertSame('resend', $this->settings($kernel)->getString('mailer.provider'));
        self::assertSame($key, $this->settings($kernel)->getSecret('mailer.resend_api_key'));
        self::assertSame('legacy@example.com', $this->settings($kernel)->getString('mailer.resend_from'));
        $settings = $this->service($kernel, MailSettings::class);
        self::assertFalse($settings->legacyResendPending());
        self::assertSame('resend+api://' . $key . '@default', $settings->dsn()?->dsn);
    }

    public function testAPending12KeyIsCheckedOnceWhenResendIsSelected(): void
    {
        $key    = 're_' . bin2hex(random_bytes(12));
        $kernel = $this->bootKernel(['resend' => ['enabled' => true]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set('http_client', MockHttpClient::class)->factory([ResendApiMock::class, 'rejectThenAccept'])->public();
        });
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'noreply@example.com']);
        $this->legacyRows($kernel, false, $key);
        $client = $this->client($kernel);

        // The 1.2 switch was off: the DSN provider stays selected; saving it keeps the DSN.
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        self::assertSame('dsn', $crawler->filter('select[name="settings_section[provider]"] option[selected]')->attr('value'));

        // A typed key wins over the 1.2 key (checked: rejected here).
        $form                                            = $crawler->filter('form[name="settings_section"]')->form();
        $form['settings_section[provider]']              = 'resend';
        $form['settings_section[resend_api_key][value]'] = 're_REDACTED-TYPED';
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Blank key: the 1.2 key is the one going live, so it is checked once (accepted) and copied.
        $form                               = $crawler->filter('form[name="settings_section"]')->form();
        $form['settings_section[provider]'] = 'resend';
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame($key, $this->settings($kernel)->getSecret('mailer.resend_api_key'));

        // Saving the DSN provider while pending does not copy the hidden key.
        $other = $this->bootKernel(['resend' => ['enabled' => true]]);
        $this->legacyRows($other, false, $key);
        $browser = $this->client($other);
        $browser->submit($browser->request('GET', '/admin/settings/sections/mailer')->filter('form[name="settings_section"]')->form());
        self::assertSame(302, $browser->getResponse()->getStatusCode());
        self::assertSame('dsn', $this->settings($other)->getString('mailer.provider'));
        self::assertFalse($this->settings($other)->hasSecret('mailer.resend_api_key'));
    }

    private function legacyRows(MailKitTestKernel $kernel, bool $enabled, string $key): void
    {
        $now = new DateTimeImmutable();
        $this->service($kernel, SettingValueRepositoryInterface::class)->apply([
            (new SettingValue('resend', 'enabled', $now))->storeValue($enabled, $now),
            (new SettingValue('resend', 'api_key', $now))->storeSecret($key, $now),
            (new SettingValue('resend', 'from', $now))->storeValue('legacy@example.com', $now),
        ], []);
        $this->service($kernel, LegacyResendSettings::class)->reset();
    }
}
