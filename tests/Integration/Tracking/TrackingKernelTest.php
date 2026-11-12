<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Tracking;

use ArrayObject;
use Nowo\MailKitBundle\Event\MailDeliveredEvent;
use Nowo\MailKitBundle\Event\MailMachineOpenedEvent;
use Nowo\MailKitBundle\Event\MailOpenedEvent;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Mailer\RuntimeTransport;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\HostTrackingPolicy;
use Nowo\MailKitBundle\Tests\Support\ShortLinkRewriter;
use Nowo\MailKitBundle\Tests\Support\StaticDsnProvider;
use Nowo\MailKitBundle\Tests\Support\StaticSenderProvider;
use Nowo\MailKitBundle\Tests\Support\TrackingKernelTrait;
use Nowo\MailKitBundle\Tracking\EmailTracker;
use Nowo\MailKitBundle\Tracking\EmailTrackingReaderInterface;
use Nowo\MailKitBundle\Tracking\Http\TrackingPixelController;
use Nowo\MailKitBundle\Tracking\Http\TrackingRouteLoader;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpenHandler;
use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function base64_decode;
use function count;
use function gc_collect_cycles;
use function hrtime;
use function memory_get_usage;
use function preg_match;
use function preg_replace;
use function sort;
use function str_repeat;

/**
 * 1.10 e-mail tracking in a booted kernel: off by default (nothing registered, 1.9 behaviour); a standard host
 * (Instance Settings, sent-mail log) whose mail gets the pixel and short links while security templates and opted-out
 * recipients get nothing; the stateless pixel (always the GIF, human / machine opens apart, events); a strict DDD host
 * (no Instance Settings, no log, host ports) serving 1 000 pixel requests for two tenants in one kept-alive kernel
 * without the services resetter (FrankenPHP worker mode) with flat memory and p95 under 20 ms; the optional bus;
 * lint:container and the commands.
 */
final class TrackingKernelTest extends KernelTestCase
{
    use TrackingKernelTrait;

    private const PIXEL = '~https://app\.example\.com/e/o/([A-Za-z0-9_-]{48})\.gif~';

    public function testOffByDefaultRegistersNothing(): void
    {
        $kernel    = $this->bootKernel();
        $container = $this->container($kernel);
        foreach ([EmailTracker::class, TrackingStore::class, TrackingPixelController::class, TrackingRouteLoader::class, TrackingTokenSigner::class, EmailTrackingReaderInterface::class, RecordMailOpenHandler::class] as $id) {
            self::assertFalse($container->has($id), $id);
        }
        $this->mailer($kernel)->send($this->email('user@example.com'));
        $message = $this->store($kernel)->sentVia('fallback')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame($this->email('user@example.com')->getHtmlBody(), $message->getHtmlBody(), 'Untouched, like 1.9.');
        self::assertFalse($message->getHeaders()->has(MailHeaders::TEMPLATE), 'The new internal headers are stripped anyway.');
        self::assertFalse($message->getHeaders()->has(MailHeaders::RECIPIENT));
    }

    public function testStandardHostTracksMailAndCountsOpens(): void
    {
        $kernel = $this->bootStandard();
        /** @var ArrayObject<int, object> $events */
        $events     = new ArrayObject();
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach ([MailDeliveredEvent::class, MailOpenedEvent::class, MailMachineOpenedEvent::class] as $event) {
            $dispatcher->addListener($event, static function (object $e) use ($events): void {
                $events[] = $e;
            });
        }

        $this->mailer($kernel)->send($this->email('user@example.com', 'issue_alert'));
        $message = $this->store($kernel)->sentVia('fallback')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        $html = (string) $message->getHtmlBody();
        self::assertSame(1, preg_match(self::PIXEL, $html, $m));
        $token = $m[1];
        self::assertStringContainsString('href="https://app.example.com/s/', $html);
        self::assertStringContainsString('href="https://app.example.com/reset-password?token=abc"', $html);
        foreach ([MailHeaders::TEMPLATE, MailHeaders::RECIPIENT, MailHeaders::TENANT, MailHeaders::LOG_ID] as $header) {
            self::assertFalse($message->getHeaders()->has($header), $header);
        }
        $delivered = $events[0];
        self::assertInstanceOf(MailDeliveredEvent::class, $delivered);
        self::assertNotNull($delivered->trackingId);
        $reader = $this->service($kernel, EmailTrackingReaderInterface::class);
        $stats  = $reader->find($delivered->trackingId);
        self::assertNotNull($stats);
        self::assertSame([$delivered->logId, 'issue_alert', 'user-user', 'acme'], [$stats->logId, $stats->templateId, $stats->recipientId, $stats->tenant]);
        self::assertNotNull($stats->logId, 'Linked to the sent-mail log row.');

        $client = $this->client($kernel, null);
        $client->request('GET', '/e/o/' . $token . '.gif', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'REMOTE_ADDR' => '203.0.113.77']);
        $this->assertPixel($client->getResponse());
        $client->request('GET', '/e/o/' . $token . '.gif', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 (KHTML, like Gecko)', 'REMOTE_ADDR' => '17.58.1.2']);
        $this->assertPixel($client->getResponse());
        $client->request('HEAD', '/e/o/' . $token . '.gif');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        foreach (['forged' . str_repeat('A', 42), 'x', 'abc.def', (new TrackingTokenSigner(str_repeat('o', 32)))->issue(TrackingTokenSigner::newSendId()), (new TrackingTokenSigner('test-tracking-secret-REDACTED-0000000000'))->issue(TrackingTokenSigner::newSendId())] as $bad) {
            $client->request('GET', '/e/o/' . $bad . '.gif');
            $this->assertPixel($client->getResponse());
        }

        $stats = $reader->find($delivered->trackingId);
        self::assertNotNull($stats);
        self::assertSame([1, 1, '17.58.1.0', 'apple_mail'], [$stats->opens, $stats->machineOpens, $stats->lastOpenIp, $stats->lastOpenFamily]);
        self::assertInstanceOf(MailOpenedEvent::class, $events[1]);
        self::assertInstanceOf(MailMachineOpenedEvent::class, $events[2]);
        self::assertSame($delivered->trackingId, $events[1]->sendId);
        self::assertCount(3, $events);

        // Sent straight through the transport (no queued row): the send is linked to the log row written after it.
        $this->service($kernel, RuntimeTransport::class)->send($this->email('direct@example.com', 'digest'));
        $direct = $events[3];
        self::assertInstanceOf(MailDeliveredEvent::class, $direct);
        self::assertNotNull($direct->trackingId);
        self::assertNotNull($direct->logId);
        self::assertSame($direct->logId, $reader->find($direct->trackingId)?->logId);

        $router = $this->container($kernel)->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $route = $router->getRouteCollection()->get(TrackingRouteLoader::ROUTE);
        self::assertNotNull($route);
        self::assertSame('/e/o/{token}.gif', $route->getPath());
        self::assertTrue($route->getDefault('_stateless'));
    }

    public function testSecurityTemplatesOptedOutRecipientsAndMultipleRecipientsStayUntracked(): void
    {
        $kernel = $this->bootStandard();
        $mailer = $this->mailer($kernel);
        foreach (HostTrackingPolicy::SECURITY as $template) {
            $mailer->send($this->email('user@example.com', $template));
        }
        $mailer->send($this->email('optout@example.com'));
        $mailer->send($this->email('user@example.com')->addCc('cc@example.com'));
        $noTrack = $this->email('user@example.com');
        MailHeaders::noTracking($noTrack);
        $mailer->send($noTrack);

        $sent = $this->store($kernel)->sentVia('fallback');
        self::assertCount(count(HostTrackingPolicy::SECURITY) + 3, $sent);
        foreach ($sent as $entry) {
            $message = $entry->getOriginalMessage();
            self::assertInstanceOf(Email::class, $message);
            self::assertSame(0, preg_match(self::PIXEL, (string) $message->getHtmlBody()));
            self::assertStringNotContainsString('/s/', (string) $message->getHtmlBody());
        }
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM mail_kit_tracking_send'));
        self::assertSame([], $this->service($kernel, ShortLinkRewriter::class)->links);
    }

    public function testTemplatedMailIsRenderedThenTrackedAndAFailedDeliveryForgetsTheSend(): void
    {
        $kernel = $this->bootDdd('memory://default');
        $email  = (new TemplatedEmail())->to('user@example.com')->subject('Welcome')
            ->htmlTemplate('email/welcome.html.twig')->textTemplate('email/welcome.txt.twig')->context(['user_name' => 'Ada']);
        MailHeaders::template($email, 'welcome');
        $this->mailer($kernel)->send($email);
        $message = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(1, preg_match(self::PIXEL, (string) $message->getHtmlBody()));
        self::assertSame(1, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM mail_kit_tracking_send'));

        $this->service($kernel, StaticDsnProvider::class)->dsn = 'memory://fail';
        try {
            $this->mailer($kernel)->send($this->email('user@example.com'));
            self::fail('The delivery must fail.');
        } catch (TransportExceptionInterface) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM mail_kit_tracking_send'), 'The failed send was forgotten.');
    }

    public function testDddHostServesOneThousandPixelsInOneWorker(): void
    {
        $kernel = $this->bootDdd('memory://default');
        $http   = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $mails    = $this->store($kernel);
        $rewriter = $this->service($kernel, ShortLinkRewriter::class);
        $reader   = $this->service($kernel, EmailTrackingReaderInterface::class);
        $mailer   = $this->mailer($kernel);
        // framework.test keeps every sent message for assertions (reset only by kernel.reset): forget them per send.
        $logger = $this->container($kernel)->get('mailer.message_logger_listener');
        self::assertInstanceOf(MessageLoggerListener::class, $logger);
        $before = 0;
        $times  = [];

        for ($i = 0; $i < 1000; ++$i) {
            $tenant = $i % 2 === 0 ? 'acme' : 'globex';
            $email  = $this->email('user' . $i % 7 . '@example.com', 'alert');
            MailHeaders::trackingRecipient($email, 'u-' . $tenant . '-' . $i % 7, $tenant);
            $mailer->send($email);
            $message = $mails->sent[0]['message']->getOriginalMessage();
            self::assertInstanceOf(Email::class, $message);
            self::assertSame(1, preg_match(self::PIXEL, (string) $message->getHtmlBody(), $m));
            $mails->sent     = [];
            $rewriter->links = [];
            $logger->reset();

            $request  = Request::create('/e/o/' . $m[1] . '.gif', 'GET', server: ['REMOTE_ADDR' => $i % 3 === 0 ? '17.1.2.3' : '198.51.100.' . $i % 250, 'HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox/120.0']);
            $start    = hrtime(true);
            $response = $http->handle($request);
            $times[]  = (hrtime(true) - $start) / 1e6;
            $this->assertPixel($response);
            $http->terminate($request, $response);

            if ($i === 199) {
                gc_collect_cycles();
                $before = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(2 * 1024 * 1024, memory_get_usage() - $before, 'Memory must stay flat over 800 sends and pixel requests.');
        sort($times);
        $p95 = $times[(int) (count($times) * 0.95)];
        self::assertLessThan(20.0, $p95, 'Pixel p95 ' . $p95 . ' ms.');

        $db = $this->connection($kernel);
        self::assertSame(1000, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_tracking_send'));
        self::assertSame(1000, (int) $db->fetchOne('SELECT SUM(open_count) + SUM(machine_open_count) FROM mail_kit_tracking_send'));
        self::assertSame(334, (int) $db->fetchOne('SELECT SUM(machine_open_count) FROM mail_kit_tracking_send'));
        self::assertSame(500, (int) $db->fetchOne("SELECT COUNT(*) FROM mail_kit_tracking_send WHERE tenant = 'acme'"));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM mail_kit_tracking_send WHERE tenant = 'acme' AND recipient_id NOT LIKE 'u-acme-%'"), 'No tenant data crossed over.');
        self::assertCount(1000, $reader->findMany($db->fetchFirstColumn('SELECT send_id FROM mail_kit_tracking_send')));
    }

    public function testTheOptionalBusRecordsOpensThroughTheHandler(): void
    {
        $kernel = $this->bootDdd('memory://default', ['bus' => 'messenger.default_bus']);
        self::assertTrue($this->container($kernel)->has(RecordMailOpenHandler::class));
        $this->mailer($kernel)->send($this->email('user@example.com'));
        $message = $this->store($kernel)->sentVia('default')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(1, preg_match(self::PIXEL, (string) $message->getHtmlBody(), $m));

        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $response = $http->handle(Request::create('/e/o/' . $m[1] . '.gif', server: ['HTTP_USER_AGENT' => 'Thunderbird/115']));
        $this->assertPixel($response);
        self::assertSame(1, (int) $this->connection($kernel)->fetchOne('SELECT SUM(open_count) FROM mail_kit_tracking_send'));
    }

    public function testLintContainerAndCommands(): void
    {
        $kernel      = $this->bootDdd('memory://default', debug: true);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'lint:container']);
        self::assertSame(0, $tester->getStatusCode(), (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        $tester->run(['command' => 'nowo:mail-kit:tracking:schema']);
        self::assertSame("The tracking table exists.\n", $tester->getDisplay());
        $this->connection($kernel)->executeStatement('DROP TABLE mail_kit_tracking_send');
        $tester->run(['command' => 'nowo:mail-kit:tracking:schema']);
        self::assertStringContainsString('CREATE TABLE mail_kit_tracking_send', $tester->getDisplay());
        $tester->run(['command' => 'nowo:mail-kit:tracking:schema', '--force' => true]);
        self::assertSame("Created the tracking table.\n", $tester->getDisplay());

        $this->connection($kernel)->insert('mail_kit_tracking_send', ['send_id' => str_repeat('a', 32), 'sent_at' => 1, 'open_count' => 0, 'machine_open_count' => 0]);
        $this->connection($kernel)->insert('mail_kit_tracking_send', ['send_id' => str_repeat('b', 32), 'sent_at' => 4_000_000_000, 'open_count' => 0, 'machine_open_count' => 0]);
        $tester->run(['command' => 'nowo:mail-kit:tracking:purge']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 send row(s) older than the retention deleted', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    private function assertPixel(Response $response): void
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/gif', $response->headers->get('Content-Type'));
        self::assertSame(base64_decode(TrackingPixelController::GIF, true), $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies(), 'Stateless: no session cookie.');
    }

    private function email(string $to, ?string $template = null): Email
    {
        $email = (new Email())->from('app@example.com')->to($to)->subject('Alert')
            ->html('<html><body><p>Hi</p><a href="https://app.example.com/issues/1">View issue</a> <a href="https://app.example.com/reset-password?token=abc">Reset</a></body></html>')
            ->text('View issue: https://app.example.com/issues/1');
        if ($template !== null) {
            MailHeaders::template($email, $template);
        }
        MailHeaders::trackingRecipient($email, 'user-' . preg_replace('/@.*/', '', $to), 'acme');

        return $email;
    }

    private function bootStandard(): MailKitTestKernel
    {
        return $this->bootKernel(['tracking' => self::tracking()], routes: static function (RoutingConfigurator $routes): void {
            $routes->import('.', 'nowo_instance_settings');
            $routes->import('.', 'nowo_mail_kit');
            $routes->import('.', 'nowo_mail_kit_tracking');
        }, extra: self::trackingServices());
    }

    /**
     * @param array<string, mixed> $tracking
     */
    private function bootDdd(string $dsn, array $tracking = [], bool $debug = false): MailKitTestKernel
    {
        return $this->bootKernel(
            [
                'mailer'   => ['dsn_provider' => StaticDsnProvider::class, 'sender_provider' => StaticSenderProvider::class],
                'log'      => ['enabled' => false],
                'web_ui'   => ['enabled' => false],
                'tracking' => self::tracking($tracking),
            ],
            without: ['instance_settings'],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_mail_kit_tracking');
            },
            debug: $debug,
            extra: self::trackingServices(static function (ContainerConfigurator $container) use ($dsn): void {
                $services = $container->services();
                $services->set(StaticDsnProvider::class)->args([$dsn])->public();
                $services->set(StaticSenderProvider::class);
                $container->extension('twig', ['paths' => ['%kernel.project_dir%/tests/Fixtures/templates']]);
            }),
        );
    }
}
