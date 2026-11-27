<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Template\Engine\PlaceholderResolver;
use Nowo\MailKitBundle\Template\Engine\TemplateLibrary;
use Nowo\MailKitBundle\Template\Engine\TemplatePersonalData;
use Nowo\MailKitBundle\Template\Engine\TemplateRenderer;
use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateStore;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The template services on an SQLite (or given) connection with test ports.
 */
final class TemplateHarness
{
    public readonly MemoryTemplatePermission $permission;

    public readonly MePlaceholderProvider $me;

    public readonly RecordingEventDispatcher $events;

    public readonly MockClock $clock;

    public readonly TemplateStore $store;

    public readonly PlaceholderResolver $resolver;

    public readonly TemplateLibrary $library;

    public readonly TemplateRenderer $renderer;

    public readonly TemplatePersonalData $personalData;

    public readonly Connection $connection;

    public function __construct(
        TestCase $test,
        ?Connection $connection = null,
        string $prefix = '',
        int $maxBodyBytes = 4096,
        int $maxOutputBytes = 8192,
        int $maxPlaceholders = 20,
        int $maxPerScope = 5,
    ) {
        $this->connection = $connection ?? self::sqlite();
        // createStub() is protected: called in the test's scope.
        $registry = Closure::bind(static fn (TestCase $case): Stub => $case->createStub(ManagerRegistry::class), null, TestCase::class)($test);
        $this->assertRegistry($registry);
        $registry->method('getConnection')->willReturn($this->connection);
        $this->permission = new MemoryTemplatePermission();
        $this->me         = new MePlaceholderProvider();
        $this->events     = new RecordingEventDispatcher();
        $this->clock      = new MockClock('2026-11-27 10:00:00 UTC');
        $this->store      = new TemplateStore($registry, null, $prefix);
        $this->resolver   = new PlaceholderResolver(new ServiceLocator([
            'me'      => fn (): MePlaceholderProvider => $this->me,
            'contact' => static fn (): ContactPlaceholderProvider => new ContactPlaceholderProvider(),
        ]), $maxPlaceholders);
        $sanitizer          = new MailHtmlSanitizer(RemoteImages::Allowed, $maxOutputBytes);
        $this->library      = new TemplateLibrary($this->store, $this->permission, $this->resolver, $sanitizer, $maxBodyBytes, $maxPerScope, $this->events, $this->clock);
        $this->renderer     = new TemplateRenderer($this->library, $this->resolver, $sanitizer, $maxOutputBytes);
        $this->personalData = new TemplatePersonalData($this->store, $this->events);
        $this->store->execute($this->store->missingTablesSql());
    }

    /**
     * @phpstan-assert ManagerRegistry&Stub $registry
     */
    private function assertRegistry(Stub $registry): void
    {
        if (!$registry instanceof ManagerRegistry) {
            throw new LogicException('stub');
        }
    }

    public static function sqlite(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
