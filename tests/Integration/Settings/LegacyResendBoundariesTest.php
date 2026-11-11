<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Settings;

use ArrayObject;
use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\MailKitBundle\EventListener\LegacyResendSettingsResetListener;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\ResetServicesListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

use function gc_collect_cycles;
use function is_array;
use function memory_get_usage;
use function sprintf;

/**
 * Kept-alive kernel, services resetter never called (a host that removes or bypasses services_resetter,
 * `messenger:consume --no-reset`, long-running console processes): one booted kernel, the same
 * {@see LegacyResendSettings} memo, many units of work.
 *
 * Before each unit "another worker" edits the MailKit 1.2 `resend.from` row, alternating tenant A's and tenant B's
 * sender. Each unit must read the sender stored now, not the memo of the previous unit (which would send tenant B's
 * mail from tenant A's address).
 *
 * N = 500 per path: HTTP through the http_kernel service (not Kernel::handle(), so no resetter; with a sub-request),
 * Messenger through a real Worker without ResetServicesListener (every 7th handler fails), and console Application
 * runs. Memory margin: 1 MiB after a 50-unit warm-up.
 */
final class LegacyResendBoundariesTest extends KernelTestCase
{
    private const ITERATIONS    = 500;
    private const WARMUP        = 50;
    private const MEMORY_MARGIN = 1024 * 1024;
    private const SENDERS       = ['tenant-a@example.com', 'tenant-b@example.com'];

    /** @var list<string> */
    private array $leaks = [];

    private int $memoryAtWarmup = 0;

    private int $memoryAtEnd = 0;

    public function testManyRequestsReadTheCurrentLegacyRows(): void
    {
        [$kernel, $legacy] = $this->boot();
        $http              = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $this->edit($kernel, $i);
            $request = Request::create('/send');
            $request->attributes->set('_controller', function () use ($http, $legacy, $i): Response {
                $this->unitOfWork($legacy, $i);
                // A sub-request (fragment) keeps the memo of its main request.
                $fragment = Request::create('/_fragment');
                $fragment->attributes->set('_controller', function () use ($legacy, $i): Response {
                    $this->unitOfWork($legacy, $i);

                    return new Response('fragment');
                });

                return $http->handle($fragment, HttpKernelInterface::SUB_REQUEST);
            });
            $response = $http->handle($request);
            self::assertSame(200, $response->getStatusCode());
            $http->terminate($request, $response);
        }

        $this->assertNoLeakAndFlatMemory('HTTP requests');
    }

    public function testManyMessengerMessagesReadTheCurrentLegacyRows(): void
    {
        [$kernel, $legacy, $dispatcher] = $this->boot();

        foreach ($dispatcher->getListeners(WorkerRunningEvent::class) as $listener) {
            self::assertFalse(
                is_array($listener) && $listener[0] instanceof ResetServicesListener,
                'The worker must run without ResetServicesListener (messenger:consume --no-reset).',
            );
        }

        $receiver = new class(self::ITERATIONS) implements ReceiverInterface {
            public int $handled = 0;
            private int $next   = 0;

            public function __construct(private readonly int $count)
            {
            }

            public function get(): iterable
            {
                return $this->next < $this->count ? [new Envelope(new ArrayObject(['index' => $this->next++]))] : [];
            }

            public function ack(Envelope $envelope): void
            {
                ++$this->handled;
            }

            public function reject(Envelope $envelope): void
            {
                ++$this->handled;
            }
        };

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            ArrayObject::class => [function (ArrayObject $message) use ($kernel, $legacy): void {
                $index = (int) $message['index'];
                $this->unitOfWork($legacy, $index);
                // The next message runs after another worker's edit.
                $this->edit($kernel, $index + 1);
                if ($index % 7 === 3) {
                    throw new RuntimeException('Mailer down');
                }
            }],
        ]))]);

        $this->edit($kernel, 0);
        $worker     = new Worker(['async' => $receiver], $bus, $dispatcher);
        $stopOnIdle = static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerRunningEvent::class, $stopOnIdle);

        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $dispatcher->removeListener(WorkerRunningEvent::class, $stopOnIdle);
        }

        self::assertSame(self::ITERATIONS, $receiver->handled);
        $this->assertNoLeakAndFlatMemory('Messenger messages');
    }

    public function testManyConsoleRunsReadTheCurrentLegacyRows(): void
    {
        [$kernel, $legacy, $dispatcher] = $this->boot();

        $command = (new Command('app:send-tenant'))
            ->addArgument('index', InputArgument::REQUIRED)
            ->setCode(function (InputInterface $input, OutputInterface $output) use ($legacy): int {
                $this->unitOfWork($legacy, (int) $input->getArgument('index'));

                return Command::SUCCESS;
            });

        $application = new Application('worker-test');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->setDispatcher($dispatcher);
        $application->setCommandLoader(new FactoryCommandLoader(['app:send-tenant' => static fn (): Command => $command]));

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $this->edit($kernel, $i);
            self::assertSame(Command::SUCCESS, $application->run(new ArrayInput(['command' => 'app:send-tenant', 'index' => (string) $i]), new NullOutput()));
        }

        $this->assertNoLeakAndFlatMemory('console command runs');
    }

    public function testTheListenerIsWiredOnlyWithResendAndOnEveryBoundary(): void
    {
        $kernel = $this->bootKernel(['resend' => ['enabled' => true]]);
        self::assertInstanceOf(LegacyResendSettingsResetListener::class, $this->container($kernel)->get(LegacyResendSettingsResetListener::class));
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        foreach (['kernel.request', 'kernel.terminate', 'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent', 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent', 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent', 'console.command', 'console.terminate'] as $event) {
            $found = false;
            foreach ($dispatcher->getListeners($event) as $listener) {
                $found = $found || (is_array($listener) && $listener[0] instanceof LegacyResendSettingsResetListener);
            }
            self::assertTrue($found, $event);
        }

        $off = $this->bootKernel();
        self::assertFalse($this->container($off)->has(LegacyResendSettingsResetListener::class));
    }

    /**
     * @return array{0: MailKitTestKernel, 1: LegacyResendSettings, 2: EventDispatcherInterface}
     */
    private function boot(): array
    {
        $kernel     = $this->bootKernel(['resend' => ['enabled' => true]]);
        $repository = $this->repository($kernel);
        $now        = new DateTimeImmutable();
        $repository->apply([
            (new SettingValue(LegacyResendSettings::SECTION, 'enabled', $now))->storeValue(true, $now),
            (new SettingValue(LegacyResendSettings::SECTION, 'api_key', $now))->storeSecret('re_REDACTED-TEST-x', $now),
            (new SettingValue(LegacyResendSettings::SECTION, 'from', $now))->storeValue(self::SENDERS[0], $now),
        ], []);
        $legacy = $this->container($kernel)->get(LegacyResendSettings::class);
        self::assertInstanceOf(LegacyResendSettings::class, $legacy);
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->leaks          = [];
        $this->memoryAtWarmup = 0;
        $this->memoryAtEnd    = 0;

        return [$kernel, $legacy, $dispatcher];
    }

    /**
     * Another worker stores tenant A's sender for even units and tenant B's for odd units.
     */
    private function edit(MailKitTestKernel $kernel, int $index): void
    {
        $repository = $this->repository($kernel);
        foreach ($repository->findBySection(LegacyResendSettings::SECTION) as $row) {
            if ($row->getName() === 'from') {
                $repository->apply([$row->storeValue(self::SENDERS[$index % 2], new DateTimeImmutable())], []);
            }
        }
    }

    private function unitOfWork(LegacyResendSettings $legacy, int $index): void
    {
        if ($index === self::WARMUP) {
            gc_collect_cycles();
            $this->memoryAtWarmup = memory_get_usage();
        }

        $from = $legacy->values()['from'] ?? null;
        if ($from !== self::SENDERS[$index % 2]) {
            $this->leaks[] = sprintf('unit %d read sender %s instead of %s', $index, (string) $from, self::SENDERS[$index % 2]);
        }

        if ($index === self::ITERATIONS - 1) {
            gc_collect_cycles();
            $this->memoryAtEnd = memory_get_usage();
        }
    }

    private function repository(MailKitTestKernel $kernel): SettingValueRepositoryInterface
    {
        $repository = $this->container($kernel)->get(SettingValueRepositoryInterface::class);
        self::assertInstanceOf(SettingValueRepositoryInterface::class, $repository);

        return $repository;
    }

    private function assertNoLeakAndFlatMemory(string $path): void
    {
        self::assertSame([], $this->leaks, sprintf('Stale legacy Resend rows between %s.', $path));
        self::assertGreaterThan(0, $this->memoryAtWarmup);
        self::assertLessThan(self::MEMORY_MARGIN, $this->memoryAtEnd - $this->memoryAtWarmup, sprintf('Memory grew across %s.', $path));
    }
}
