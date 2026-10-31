<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Functional;

use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Support\ResendApiMock;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mime\Email;

/**
 * Admin UI: the mailer section of Instance Settings with the MailKit fields and actions, the test e-mail action
 * (CSRF, access, rate limit), the Resend actions and the sent-mail log pages.
 */
final class AdminUiTest extends KernelTestCase
{
    public function testTheMailerSectionShowsTheFieldsAndTheTestAction(): void
    {
        $kernel  = $this->bootKernel();
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');

        $this->assertResponseStatus($client, 200);
        self::assertCount(1, $crawler->filter('[data-testid="nowo_mail_kit_test"]'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Mailer DSN', $html);
        self::assertStringContainsString('Send a test e-mail', $html);
    }

    public function testSavingAPrivateHostDsnIsRefused(): void
    {
        $kernel                               = $this->bootKernel();
        $client                               = $this->client($kernel);
        $crawler                              = $client->request('GET', '/admin/settings/sections/mailer');
        $form                                 = $crawler->filter('form[name="settings_section"]')->form();
        $form['settings_section[dsn][value]'] = 'smtp://10.0.0.5:25';
        $form['settings_section[from]']       = 'noreply@example.com';
        $client->submit($form);

        $this->assertResponseStatus($client, 422);
        self::assertFalse($this->settings($kernel)->hasSecret('mailer.dsn'));

        $crawler                        = $client->request('GET', '/admin/settings/sections/mailer');
        $form                           = $crawler->filter('form[name="settings_section"]')->form();
        $form['settings_section[from]'] = 'root@localhost';
        $client->submit($form);
        $this->assertResponseStatus($client, 422);
        self::assertStringContainsString('would be rejected by mail providers', (string) $client->getResponse()->getContent());
        self::assertNull($this->settings($kernel)->getString('mailer.from'));
    }

    public function testTheTestActionSendsToTheTestRecipient(): void
    {
        $kernel = $this->bootKernel();
        $this->settings($kernel)->save('mailer', ['dsn' => 'memory://default', 'from' => 'noreply@example.com', 'test_recipient' => 'qa@example.com']);
        $client = $this->client($kernel);
        $client->followRedirects();
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        $client->submit($crawler->filter('[data-testid="nowo_mail_kit_test"]')->closest('form')?->form() ?? self::fail('No action form.'));

        $this->assertResponseStatus($client, 200);
        self::assertStringContainsString('Test e-mail sent to qa@example.com.', (string) $client->getResponse()->getContent());
        $sent = $this->store($kernel)->sentVia('default');
        self::assertCount(1, $sent);
        self::assertSame('mail_kit_test', $this->connection($kernel)->fetchOne('SELECT tag FROM mail_kit_sent_mail'));
    }

    public function testTheTestActionUsesTheUserAddressAndReportsMissingSenders(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel, 'ops@example.com');
        $client->followRedirects();
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        $client->submit($crawler->filter('[data-testid="nowo_mail_kit_test"]')->closest('form')?->form() ?? self::fail('No action form.'));
        self::assertStringContainsString('No sender address', (string) $client->getResponse()->getContent());

        $this->settings($kernel)->save('mailer', ['from' => 'noreply@example.com']);
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        $client->submit($crawler->filter('[data-testid="nowo_mail_kit_test"]')->closest('form')?->form() ?? self::fail('No action form.'));
        self::assertStringContainsString('Test e-mail sent to ops@example.com.', (string) $client->getResponse()->getContent());
        self::assertCount(1, $this->store($kernel)->sentVia('fallback'));
    }

    public function testTheTestActionIsRateLimited(): void
    {
        $kernel = $this->bootKernel(['test_email' => ['rate_limit' => 1]]);
        $this->settings($kernel)->save('mailer', ['from' => 'noreply@example.com', 'test_recipient' => 'qa@example.com']);
        $client = $this->client($kernel);
        $client->followRedirects();
        for ($i = 0; $i < 2; ++$i) {
            $crawler = $client->request('GET', '/admin/settings/sections/mailer');
            $client->submit($crawler->filter('[data-testid="nowo_mail_kit_test"]')->closest('form')?->form() ?? self::fail('No action form.'));
        }

        self::assertStringContainsString('Too many test e-mails', (string) $client->getResponse()->getContent());
        self::assertCount(1, $this->store($kernel)->sent);
    }

    public function testTheActionsRefuseAMissingTokenAndUnauthorisedUsers(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $client->request('POST', '/admin/mail/actions/test', ['_token' => 'forged']);
        $this->assertResponseStatus($client, 403);

        $viewer = $this->client($kernel, 'viewer');
        $viewer->request('POST', '/admin/mail/actions/test');
        $this->assertResponseStatus($viewer, 403);

        $anonymous = $this->client($kernel, null);
        $anonymous->request('POST', '/admin/mail/actions/test');
        $this->assertResponseStatus($anonymous, 401);
        self::assertSame([], $this->store($kernel)->sent);
    }

    public function testTheActionsNeedTheInstanceSettingsSectionAccessToo(): void
    {
        $kernel = $this->bootKernel(['security' => ['access_roles' => ['ROLE_MAIL_LOG']]]);
        $client = $this->client($kernel, 'logreader');
        $client->request('GET', '/admin/mail/log');
        $this->assertResponseStatus($client, 200);
        $client->request('POST', '/admin/mail/actions/test');
        $this->assertResponseStatus($client, 403);
    }

    public function testTheLogListFiltersAndTheDetailPage(): void
    {
        $kernel = $this->bootKernel();
        $mailer = $this->mailer($kernel);
        $mailer->send((new Email())->from('app@example.com')->to('alice@example.com')->subject('Invoice 42')->text('Hi'));
        $mailer->send((new Email())->from('app@example.com')->to('bob@example.com')->cc('carol@example.com')->subject('Welcome')->text('Hi'));
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/mail/log');
        $this->assertResponseStatus($client, 200);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(2, $crawler->filter('[data-testid="mail-kit-status"]'));

        $crawler = $client->request('GET', '/admin/mail/log?q=alice&status=sent&period=30d');
        self::assertCount(1, $crawler->filter('[data-testid="mail-kit-status"]'));
        self::assertStringContainsString('Invoice 42', (string) $client->getResponse()->getContent());

        $client->request('GET', '/admin/mail/log?status=failed');
        self::assertStringContainsString('No e-mail matches these filters.', (string) $client->getResponse()->getContent());

        $id = (string) $this->connection($kernel)->fetchOne("SELECT id FROM mail_kit_sent_mail WHERE subject = 'Welcome'");
        $client->request('GET', '/admin/mail/log/' . $id);
        $this->assertResponseStatus($client, 200);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('carol@example.com', $html);
        self::assertStringNotContainsString('Text body', $html);

        $client->request('GET', '/admin/mail/log/018f0000-0000-7000-8000-000000000000');
        $this->assertResponseStatus($client, 404);
        $client->request('GET', '/admin/mail/log/' . $id . '/body');
        $this->assertResponseStatus($client, 404);
    }

    public function testStoredBodiesAreShownEscapedAndSandboxed(): void
    {
        $kernel = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $this->mailer($kernel)->send((new Email())->from('app@example.com')->to('a@example.com')->subject('Body')->text('<b>text</b>')->html('<p onclick="x()">html</p>'));
        $id     = (string) $this->connection($kernel)->fetchOne('SELECT id FROM mail_kit_sent_mail');
        $client = $this->client($kernel);

        $client->request('GET', '/admin/mail/log/' . $id);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('&lt;b&gt;text&lt;/b&gt;', $html);
        self::assertStringContainsString('/admin/mail/log/' . $id . '/body', $html);

        $client->request('GET', '/admin/mail/log/' . $id . '/body');
        $this->assertResponseStatus($client, 200);
        self::assertStringContainsString('sandbox', (string) $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

        $this->connection($kernel)->executeStatement('UPDATE mail_kit_sent_mail_body SET html_body = NULL');
        $client->request('GET', '/admin/mail/log/' . $id . '/body');
        $this->assertResponseStatus($client, 404);
    }

    public function testTheLogNeedsTheAccessRole(): void
    {
        $kernel = $this->bootKernel();
        $viewer = $this->client($kernel, 'viewer');
        $viewer->request('GET', '/admin/mail/log');
        $this->assertResponseStatus($viewer, 403);
        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', '/admin/mail/log');
        $this->assertResponseStatus($anonymous, 401);
    }

    public function testTheResendActions(): void
    {
        $kernel = $this->bootKernel(['resend' => ['enabled' => true]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set('http_client', MockHttpClient::class)->factory([ResendApiMock::class, 'create'])->public();
        });
        $client = $this->client($kernel);
        $client->followRedirects();

        $crawler = $client->request('GET', '/admin/settings/sections/resend');
        $client->submit($crawler->filter('[data-testid="nowo_mail_kit_resend_check"]')->closest('form')?->form() ?? self::fail('No check form.'));
        self::assertStringContainsString('Store a Resend API key first.', (string) $client->getResponse()->getContent());

        $this->settings($kernel)->save('resend', ['api_key' => 're_REDACTED-TEST', 'from' => 'mail@example.com']);
        $this->settings($kernel)->save('mailer', ['test_recipient' => 'qa@example.com']);
        $expected = ['example.com (verified), x (unknown)', 'sending-only key', 'Domains: —', 'API key is invalid'];
        foreach ($expected as $text) {
            $crawler = $client->request('GET', '/admin/settings/sections/resend');
            $client->submit($crawler->filter('[data-testid="nowo_mail_kit_resend_check"]')->closest('form')?->form() ?? self::fail('No check form.'));
            self::assertStringContainsString($text, (string) $client->getResponse()->getContent());
        }

        $crawler = $client->request('GET', '/admin/settings/sections/resend');
        $client->submit($crawler->filter('[data-testid="nowo_mail_kit_resend_test"]')->closest('form')?->form() ?? self::fail('No test form.'));
        self::assertStringContainsString('The test e-mail to qa@example.com failed', (string) $client->getResponse()->getContent());
    }

    public function testTheRouteGroupsFollowTheConfiguration(): void
    {
        $kernel = $this->bootKernel(['log' => ['enabled' => false]]);
        $client = $this->client($kernel);
        $client->request('GET', '/admin/mail/log');
        $this->assertResponseStatus($client, 404);
        $client->request('POST', '/admin/mail/actions/resend/check');
        $this->assertResponseStatus($client, 404);
        self::assertFalse($this->container($kernel)->has(SentMailRecorder::class));
    }

    private function assertResponseStatus(KernelBrowser $client, int $status): void
    {
        self::assertSame($status, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }
}
