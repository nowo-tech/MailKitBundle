<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Log;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use PHPUnit\Framework\TestCase;

final class NonOrmManagerTest extends TestCase
{
    public function testTheRecorderNeedsAnOrmManager(): void
    {
        $this->expectException(LogicException::class);
        (new SentMailRecorder($this->registry()))->purgeBefore(new DateTimeImmutable());
    }

    public function testTheRepositoryNeedsAnOrmManager(): void
    {
        $this->expectException(LogicException::class);
        (new SentMailRepository($this->registry()))->find('x');
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));

        return $registry;
    }
}
