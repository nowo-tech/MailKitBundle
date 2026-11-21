<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;
use Nowo\MailKitBundle\Log\SentMailErasureInterface;
use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\Tests\Fixtures\HostEntity\HostMailUser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mime\Email;

use function dirname;
use function iterator_to_array;

/**
 * Host entities next to MailKit's log entities (the class of failure seen in another 2.0 major: "A new entity was found
 * through the relationship …" when a host-managed entity reaches an entity flushed on a bundle-private manager).
 *
 * MailKit has no private entity manager: `SentMail` / `SentMailBody` are read-only mapped entities without associations,
 * every write (log, tracking, Graph inbound, erasure) goes through DBAL with scalar columns, and nothing in MailKit
 * persists, flushes, clears, resets or closes the host manager. A host entity may point at a log row on its own manager,
 * and MailKit's reads must not detach a log row the host is holding.
 */
final class HostEntityRelationKernelTest extends KernelTestCase
{
    public function testTheLogEntitiesHaveNoAssociationToHostClasses(): void
    {
        $manager = $this->manager($this->hostKernel());
        foreach ([SentMail::class, SentMailBody::class] as $class) {
            self::assertSame([], $manager->getClassMetadata($class)->getAssociationMappings(), $class);
        }
    }

    public function testSendingAndErasingLeaveTheHostManagerAndItsPendingEntitiesAlone(): void
    {
        $kernel  = $this->hostKernel();
        $manager = $this->manager($kernel);
        $pending = new HostMailUser('pending@example.net');
        $manager->persist($pending);

        $this->mailer($kernel)->send((new Email())->from('ops@example.com')->to('pending@example.net')->subject('Hi')->text('Hi'));
        $this->mailer($kernel)->send((new Email())->from('ops@example.com')->to('other@example.net')->subject('Hi')->text('Hi'));
        $erasure = $this->service($kernel, SentMailErasureInterface::class);
        self::assertSame(1, $erasure->forgetAddress('other@example.net')['deleted']);

        // MailKit wrote through DBAL: the host's insert is still pending, nothing of the host was flushed.
        self::assertTrue($manager->getUnitOfWork()->isScheduledForInsert($pending));
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM host_mail_user'));
        self::assertSame(1, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
        self::assertTrue($manager->isOpen());

        // The host links its user to the log row on its own manager and flushes: no "new entity found" error.
        $logId = (string) $this->connection($kernel)->fetchOne('SELECT id FROM mail_kit_sent_mail');
        $pending->setLastMail($manager->getReference(SentMail::class, $logId));
        $manager->flush();
        self::assertSame($logId, (string) $this->connection($kernel)->fetchOne('SELECT last_mail_id FROM host_mail_user'));
    }

    public function testTheLogPagesAndTheExportDoNotDetachALogRowTheHostHolds(): void
    {
        $kernel   = $this->hostKernel();
        $manager  = $this->manager($kernel);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $logId    = (string) $recorder->sent(null, (new Email())->from('ops@example.com')->to('held@example.net')->subject('Hi')->html('<p>Hi</p>')->text('Hi'), 'memory', null, 10);
        $otherId  = (string) $recorder->sent(null, (new Email())->from('ops@example.com')->to('other@example.net')->subject('Hi')->text('Hi'), 'memory', null, 10);

        $repository = $this->service($kernel, SentMailRepository::class);
        $held       = $repository->find($logId);
        self::assertInstanceOf(SentMail::class, $held);
        $heldBody = $repository->findBody($logId);
        self::assertInstanceOf(SentMailBody::class, $heldBody);
        $user = new HostMailUser('held@example.net');
        $user->setLastMail($held);
        $manager->persist($user);

        // The CSV export streams and detaches the rows it loaded itself, never the one the host holds.
        $rows = iterator_to_array($repository->iterateFiltered(new SentMailFilters(period: 'all'), 10), false);
        self::assertCount(2, $rows);
        self::assertTrue($manager->contains($held));
        $repository->findBodies([$logId, $otherId]);
        self::assertTrue($manager->contains($heldBody));
        foreach ($rows as $row) {
            self::assertSame($row->getId() === $logId, $manager->contains($row), $row->getId());
        }

        $manager->flush();
        self::assertSame($logId, (string) $this->connection($kernel)->fetchOne('SELECT last_mail_id FROM host_mail_user'));
    }

    private function hostKernel(): MailKitTestKernel
    {
        return $this->bootKernel(['log' => ['store_bodies' => true]], extra: static function (ContainerConfigurator $container): void {
            $container->extension('doctrine', ['orm' => ['mappings' => ['HostEntity' => [
                'type'      => 'attribute',
                'dir'       => dirname(__DIR__, 2) . '/Fixtures/HostEntity',
                'prefix'    => 'Nowo\MailKitBundle\Tests\Fixtures\HostEntity',
                'is_bundle' => false,
            ]]]]);
        });
    }

    private function manager(MailKitTestKernel $kernel): EntityManagerInterface
    {
        $manager = $this->container($kernel)->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
