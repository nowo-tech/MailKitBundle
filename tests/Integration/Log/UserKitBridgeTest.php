<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Log;

use ArrayObject;
use Nowo\MailKitBundle\Event\SentMailPersonalDataErasedEvent;
use Nowo\MailKitBundle\Integration\UserKit\EmailsReceivedPersonalDataEraser;
use Nowo\MailKitBundle\Integration\UserKit\EmailsReceivedPersonalDataExportProvider;
use Nowo\MailKitBundle\Log\SentMailPersonalDataEraserInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataExporterInterface;
use Nowo\MailKitBundle\Log\SentMailPersonalDataSubjectResolverInterface;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\MailKitBundle\Tests\Integration\Kernel\MailKitTestKernel;
use Nowo\MailKitBundle\Tests\Support\HostUser;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporter;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_column;

/**
 * UserKit bridge (1.7): the `emails_received` eraser and export section on the real log, run like UserKit runs them.
 */
final class UserKitBridgeTest extends KernelTestCase
{
    public function testTheEraserCoversTheOriginalIdentifierTheCurrentAndThePreviousAddress(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        foreach (['login@example.com', 'current@example.com', 'old@example.com', 'other@example.com', 'Login@Example.co'] as $to) {
            $recorder->sent(null, (new Email())->from('app@example.com')->to($to)->subject('Hi ' . $to)->text('t'), 'smtp', null, null);
        }
        /** @var ArrayObject<int, mixed> $events */
        $events     = new ArrayObject();
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(SentMailPersonalDataErasedEvent::class, static function (SentMailPersonalDataErasedEvent $event) use ($events): void {
            $events[] = $event->email;
        });

        $eraser = $this->eraser($kernel);
        self::assertSame('emails_received', $eraser->getKey());
        self::assertTrue($eraser->supports(new stdClass()));

        // UserKit runs every eraser inside one transaction: the erasure joins it and dispatches nothing.
        $db = $this->connection($kernel);
        $db->beginTransaction();
        $eraser->erase(new ErasureRequest(new HostUser('anonymised-123', 'Current@Example.com'), null, ['previous_email' => 'old@example.com'], 'Login@Example.com'));
        $db->commit();
        self::assertSame([], $events->getArrayCopy());
        self::assertSame(['Hi Login@Example.co', 'Hi other@example.com'], $db->fetchFirstColumn('SELECT subject FROM mail_kit_sent_mail ORDER BY subject'));

        // Own transaction (MailKit on another connection than the user's): one event per address.
        $recorder->sent(null, (new Email())->from('app@example.com')->to('other@example.com')->subject('Again')->text('t'), 'smtp', null, null);
        $eraser->erase(new ErasureRequest(new HostUser('other@example.com', null), null, ['previous_email' => '']));
        $eraser->erase(new ErasureRequest(new stdClass(), null, ['previous_email' => ['not a string']]));
        self::assertSame(['other@example.com'], $events->getArrayCopy());
        self::assertSame(['Hi Login@Example.co'], $db->fetchFirstColumn('SELECT subject FROM mail_kit_sent_mail'));
    }

    public function testTheExportSectionThroughUserKit(): void
    {
        $kernel   = $this->bootKernel();
        $recorder = $this->service($kernel, SentMailRecorder::class);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('login@example.com', 'current@example.com')->subject('Both')->text('t'), 'smtp', null, null);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('current@example.com')->subject('Current')->text('t'), 'smtp', null, null);
        $recorder->sent(null, (new Email())->from('app@example.com')->to('other@example.com')->subject('Other')->text('t'), 'smtp', null, null);

        $provider = new EmailsReceivedPersonalDataExportProvider(
            $this->service($kernel, SentMailPersonalDataExporterInterface::class),
            $this->service($kernel, SentMailPersonalDataSubjectResolverInterface::class),
        );
        self::assertSame('emails_received', $provider->getSectionKey());
        self::assertTrue($provider->supports(new stdClass()));

        $document = (new PersonalDataExporter([$provider], new MockClock()))->export(new HostUser('login@example.com', 'current@example.com'));
        $section  = $document['sections']['emails_received'];
        self::assertIsArray($section);
        self::assertSame(['Both', 'Current'], array_column($section, 'subject'));
        self::assertSame([], $provider->export(new InMemoryUser('jdoe', null)));
    }

    private function eraser(MailKitTestKernel $kernel): EmailsReceivedPersonalDataEraser
    {
        return new EmailsReceivedPersonalDataEraser(
            $this->service($kernel, SentMailPersonalDataEraserInterface::class),
            $this->service($kernel, SentMailPersonalDataSubjectResolverInterface::class),
        );
    }
}
