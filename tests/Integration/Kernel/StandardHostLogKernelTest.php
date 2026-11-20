<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Kernel;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\MailKitBundle\DependencyInjection\NowoMailKitExtension;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Log\SentMailErasureInterface;
use Nowo\MailKitBundle\Log\SentMailLogTableMissingException;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\TestMail\TestMailOutcome;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Tests\Support\StaticSenderIdentityProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mime\Email;

/**
 * The sent-mail log of a standard host (Instance Settings and the ORM installed): the mapping follows
 * `log.map_entities` (unset: `log.enabled`, 2.0), erasure and retention through the port, and missing tables.
 */
final class StandardHostLogKernelTest extends KernelTestCase
{
    /**
     * LL-MK-M1: a host that turns the log off after it was on and sets `map_entities: true` keeps its tables (no
     * schema diff) and its erasure / retention really delete the rows written while the log was on.
     */
    public function testStandardHostWithTheLogOffKeepsTheMappingAndReallyErases(): void
    {
        $kernel  = $this->bootKernel(['log' => ['enabled' => false, 'map_entities' => true]]);
        $manager = $this->entityManager($kernel);
        self::assertContains(SentMail::class, $this->mappedClasses($manager));
        self::assertSame([], (new SchemaTool($manager))->getUpdateSchemaSql($manager->getMetadataFactory()->getAllMetadata()));
        self::assertTrue($this->container($kernel)->getParameter(NowoMailKitExtension::INSTANCE_SETTINGS_PARAMETER));

        // Rows from when the log was on.
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $recorder->sent(null, (new Email())->from('ops@example.com')->to('victim@example.net')->subject('Hi')->text('Hi'), 'memory', null, 10);
        $recorder->sent(null, (new Email())->from('ops@example.com')->to('other@example.net')->subject('Hi')->text('Hi'), 'memory', null, 10);
        $connection = $this->connection($kernel);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));

        // The log is off: a send records nothing.
        $this->mailer($kernel)->send((new Email())->from('ops@example.com')->to('victim@example.net')->subject('Hi')->text('Hi'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));

        $erasure = $this->service($kernel, SentMailErasureInterface::class);
        self::assertSame($recorder, $erasure);
        self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 0], $erasure->forgetAddress('victim@example.net'));
        self::assertSame(1, $erasure->purgeBefore(new DateTimeImmutable('+1 day')));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
    }

    /**
     * @return iterable<string, array{array<string, bool>}>
     */
    public static function unmappedLogs(): iterable
    {
        yield 'map_entities false' => [['enabled' => false, 'map_entities' => false]];
        yield 'map_entities unset (2.0: follows enabled)' => [['enabled' => false]];
    }

    /**
     * @param array<string, bool> $log
     */
    #[DataProvider('unmappedLogs')]
    public function testAnUnmappedLogStaysOutOfTheEntityManager(array $log): void
    {
        $kernel  = $this->bootKernel(['log' => $log]);
        $manager = $this->entityManager($kernel);
        self::assertNotContains(SentMail::class, $this->mappedClasses($manager));

        // No log tables: nothing stored, nothing to erase (no SQL error).
        $erasure = $this->service($kernel, SentMailErasureInterface::class);
        self::assertInstanceOf(SentMailRecorder::class, $erasure);
        self::assertSame(['deleted' => 0, 'unlinked' => 0, 'redacted' => 0], $erasure->forgetAddress('victim@example.net'));
        self::assertSame(0, $erasure->purgeBefore(new DateTimeImmutable('+1 day')));
    }

    /**
     * LL-MK-L3: with the entities mapped, a missing log table never reads as a successful erasure.
     */
    public function testMissingLogTablesFailClearlyWhenTheEntitiesAreMapped(): void
    {
        $kernel     = $this->bootKernel();
        $connection = $this->connection($kernel);
        $connection->executeStatement('DROP TABLE mail_kit_sent_mail_body');
        $connection->executeStatement('DROP TABLE mail_kit_sent_mail');
        $erasure = $this->service($kernel, SentMailErasureInterface::class);

        try {
            $erasure->forgetAddress('victim@example.net');
            self::fail('A missing log table must not read as success.');
        } catch (SentMailLogTableMissingException $missing) {
            self::assertSame(['mail_kit_sent_mail', 'mail_kit_sent_mail_body'], $missing->tables);
            self::assertStringContainsString('table_prefix', $missing->getMessage());
        }
        try {
            $erasure->purgeBefore(new DateTimeImmutable('+1 day'));
            self::fail('A missing log table must not read as success.');
        } catch (SentMailLogTableMissingException $missing) {
            self::assertSame(0, $missing->deleted);
        }

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        self::assertNotSame(0, $tester->run(['command' => 'nowo:mail-kit:log:forget', 'email' => 'victim@example.net']));
        self::assertStringContainsString('do not exist', $tester->getDisplay(true));
    }

    public function testAMissingBodyTableStillErasesTheMainRowsThenFails(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        foreach (['victim@example.net', 'other@example.net', 'third@example.net'] as $to) {
            $recorder->sent(null, (new Email())->from('ops@example.com')->to($to)->subject('Hi')->text('Hi'), 'memory', null, 10);
        }
        $connection = $this->connection($kernel);
        $connection->executeStatement('DROP TABLE mail_kit_sent_mail_body');

        try {
            $recorder->forgetAddress('victim@example.net');
            self::fail('A missing body table must be reported.');
        } catch (SentMailLogTableMissingException $missing) {
            self::assertSame(['mail_kit_sent_mail_body'], $missing->tables);
            self::assertSame(1, $missing->deleted);
        }
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));

        try {
            $recorder->purgeBefore(new DateTimeImmutable('+1 day'), 1);
            self::fail('A missing body table must be reported.');
        } catch (SentMailLogTableMissingException $missing) {
            self::assertSame(2, $missing->deleted);
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));
    }

    /**
     * LL-MK-L2: with Instance Settings and a host sender port, the test e-mail uses the port, like real mail.
     */
    public function testTheTestEmailUsesTheHostSenderPortWithInstanceSettings(): void
    {
        $kernel = $this->bootKernel(['mailer' => ['sender_provider' => StaticSenderIdentityProvider::class]], extra: static function (ContainerConfigurator $container): void {
            $container->services()->set(StaticSenderIdentityProvider::class);
        });

        self::assertSame(TestMailOutcome::Sent, $this->service($kernel, TestMailSender::class)->sendMailer('ops@example.net')->outcome);
        $message = $this->store($kernel)->sentVia('fallback')[0]->getOriginalMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('tenant@example.com', $message->getFrom()[0]->getAddress());
    }

    public function testStandardHostWithTheLogOnErasesThroughThePort(): void
    {
        $kernel = $this->bootKernel();
        self::assertInstanceOf(SentMailRecorder::class, $this->service($kernel, SentMailErasureInterface::class));
        $this->mailer($kernel)->send((new Email())->from('ops@example.com')->to('a@example.net')->subject('Hi')->text('Hi'));
        self::assertSame(['deleted' => 1, 'unlinked' => 0, 'redacted' => 0], $this->service($kernel, SentMailErasureInterface::class)->forgetAddress('a@example.net'));
    }

    private function entityManager(MailKitTestKernel $kernel): EntityManagerInterface
    {
        $manager = $this->container($kernel)->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * @return list<string>
     */
    private function mappedClasses(EntityManagerInterface $manager): array
    {
        $classes = [];
        foreach ($manager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $classes[] = $metadata->getName();
        }

        return $classes;
    }
}
