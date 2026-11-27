<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Log;

use ArrayObject;
use InvalidArgumentException;
use Nowo\MailKitBundle\Event\SentMailPersonalDataErasedEvent;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubject;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailTaggedPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailTaggedPersonalDataExporterInterface;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Email;

use function array_column;
use function sort;
use function str_repeat;

/**
 * Tag-scoped GDPR export and erasure (2.8) on SQLite, and on MySQL / MariaDB / PostgreSQL in CI
 * (MAIL_KIT_TEST_DATABASE_URL): only rows with exactly the tag are read, unlinked, deleted or masked; rows with
 * another tag, a case variant of the tag or no tag stay untouched, also their subjects and bodies.
 */
final class TaggedPersonalDataTest extends KernelTestCase
{
    public function testExportReturnsOnlyRowsWithTheExactTag(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $this->send($recorder, 'ana@example.com', 'Tenant A', 'crm:aaa');
        $this->send($recorder, 'ana@example.com', 'Tenant B', 'crm:bbb');
        $this->send($recorder, 'ana@example.com', 'Host', null);
        $this->send($recorder, 'ana@example.com', 'Case variant', 'CRM:AAA');
        $this->send($recorder, 'other@example.com', 'Tenant A other', 'crm:aaa');

        $exporter = $this->service($kernel, SentMailTaggedPersonalDataExporterInterface::class);
        self::assertSame($recorder, $exporter);
        $subject = SentMailPersonalDataSubject::fromEmail('Ana@Example.com');

        $rows = $exporter->exportTagged($subject, ' crm:aaa ');
        self::assertSame(['Tenant A'], array_column($rows, 'subject'));
        self::assertSame('crm:aaa', $rows[0]['template']);
        self::assertSame(['recipient'], $rows[0]['roles']);
        self::assertSame(['Tenant B'], array_column($exporter->exportTagged($subject, 'crm:bbb'), 'subject'));
        self::assertSame([], $exporter->exportTagged($subject, 'crm:ccc'));
        self::assertCount(4, $recorder->export($subject));
    }

    public function testErasureTouchesOnlyRowsWithTheTag(): void
    {
        $kernel   = $this->bootKernel(['log' => ['store_bodies' => true]]);
        $recorder = $this->service($kernel, SentMailRecorder::class);
        // Sole recipient: deleted. Shared: unlinked. Masking in another row of the same tag.
        $this->send($recorder, 'ana@example.com', 'A sole', 'crm:aaa');
        $this->send($recorder, ['ana@example.com', 'bob@example.com'], 'A shared', 'crm:aaa');
        $this->send($recorder, 'ops@example.com', 'A about ana@example.com', 'crm:aaa', 'Body ana@example.com');
        // Other tenant, case variant and host: untouched, also where they mention the address.
        $this->send($recorder, 'ana@example.com', 'B sole', 'crm:bbb');
        $this->send($recorder, ['ana@example.com', 'bob@example.com'], 'B shared', 'crm:bbb');
        $this->send($recorder, 'ops@example.com', 'B about ana@example.com', 'crm:bbb', 'Body ana@example.com');
        $this->send($recorder, 'ana@example.com', 'Variant', 'CRM:AAA');
        $this->send($recorder, 'ana@example.com', 'Host', null, 'Host body ana@example.com');

        $eraser = $this->service($kernel, SentMailTaggedPersonalDataEraserInterface::class);
        self::assertSame($recorder, $eraser);
        $subject = SentMailPersonalDataSubject::fromEmail('ana@example.com');
        self::assertSame(['deleted' => 1, 'unlinked' => 1, 'redacted' => 2], $eraser->eraseTagged($subject, 'crm:aaa')->toArray());
        // Idempotent.
        self::assertSame(0, $eraser->eraseTagged($subject, 'crm:aaa')->total());

        $db       = $this->connection($kernel);
        $subjects = $db->fetchFirstColumn('SELECT subject FROM mail_kit_sent_mail ORDER BY subject');
        sort($subjects);
        self::assertSame(['A about [redacted]', 'A shared', 'B about ana@example.com', 'B shared', 'B sole', 'Host', 'Variant'], $subjects);
        self::assertSame([], $recorder->exportTagged($subject, 'crm:aaa'));
        $tenantB = array_column($recorder->exportTagged($subject, 'crm:bbb'), 'subject');
        sort($tenantB);
        self::assertSame(['B shared', 'B sole'], $tenantB);
        self::assertSame(['Variant'], array_column($recorder->exportTagged($subject, 'CRM:AAA'), 'subject'));
        self::assertCount(4, $recorder->export($subject));

        $bodies = $db->fetchFirstColumn('SELECT text_body FROM mail_kit_sent_mail_body WHERE text_body LIKE ?', ['%ody%']);
        sort($bodies);
        self::assertSame(['Body [redacted]', 'Body ana@example.com', 'Host body ana@example.com'], $bodies);
    }

    public function testEventCarriesTheTagAndCallerTransactionDispatchesNothing(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $db       = $this->connection($kernel);
        /** @var ArrayObject<int, mixed> $events */
        $events     = new ArrayObject();
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(SentMailPersonalDataErasedEvent::class, static function (SentMailPersonalDataErasedEvent $event) use ($events, $db): void {
            $events[] = [$event->email, $event->tag, $event->deleted, $db->isTransactionActive()];
        });
        $this->send($recorder, 'ana@example.com', 'A', 'crm:aaa');
        $this->send($recorder, 'ana@example.com', 'B', 'crm:bbb');
        $subject = SentMailPersonalDataSubject::fromEmail('ana@example.com');

        $db->beginTransaction();
        self::assertSame(1, $recorder->eraseTagged($subject, 'crm:aaa')->deleted);
        $db->rollBack();
        self::assertSame([], $events->getArrayCopy());
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM mail_kit_sent_mail'));

        self::assertSame(1, $recorder->eraseTagged($subject, 'crm:aaa')->deleted);
        self::assertSame([['ana@example.com', 'crm:aaa', 1, false]], $events->getArrayCopy());
        self::assertSame(['B'], $db->fetchFirstColumn('SELECT subject FROM mail_kit_sent_mail'));
    }

    public function testInvalidTagsAreRefused(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $subject  = SentMailPersonalDataSubject::fromEmail('ana@example.com');
        foreach (['', '   ', "crm:\x00", str_repeat('x', MailHeaders::TAG_MAX + 1), "\xC3"] as $tag) {
            try {
                $recorder->eraseTagged($subject, $tag);
                self::fail('Tag accepted: ' . $tag);
            } catch (InvalidArgumentException) {
            }
            try {
                $recorder->exportTagged($subject, $tag);
                self::fail('Tag accepted: ' . $tag);
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(str_repeat('x', MailHeaders::TAG_MAX), SentMailRecorder::tagFilter(str_repeat('x', MailHeaders::TAG_MAX)));
    }

    /**
     * @param list<string>|string $to
     */
    private function send(SentMailRecorder $recorder, string|array $to, string $subject, ?string $tag, string $text = 't'): void
    {
        $email = (new Email())->from('app@example.com')->to(...(array) $to)->subject($subject)->text($text);
        if ($tag !== null) {
            MailHeaders::tag($email, $tag);
        }
        $recorder->sent(null, $email, 'smtp', null, null);
    }
}
