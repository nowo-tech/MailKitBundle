<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph;

use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Nowo\MailKitBundle\Graph\Transport\GraphTransport;
use Nowo\MailKitBundle\Graph\Transport\GraphTransportFactory;
use Nowo\MailKitBundle\Graph\Transport\GraphUnrecoverableTransportException;
use Nowo\MailKitBundle\Mailer\DeliveryFailedException;
use Nowo\MailKitBundle\Mailer\UnrecoverableDeliveryFailedException;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Component\Mime\RawMessage;

use function fopen;
use function fwrite;
use function json_decode;
use function str_contains;

final class GraphTransportTest extends TestCase
{
    private GraphApiMock $mock;

    protected function setUp(): void
    {
        $this->mock = new GraphApiMock();
    }

    private function factory(): GraphTransportFactory
    {
        $client    = $this->mock->client();
        $mailboxes = new class implements GraphMailboxProviderInterface {
            public function mailbox(string $profile): GraphMailbox
            {
                return $profile === 'globex' ? GraphApiMock::mailboxB() : GraphApiMock::mailboxA();
            }

            public function defaultProfile(): string
            {
                return 'acme';
            }
        };

        return new GraphTransportFactory(new GraphMailSender($mailboxes, new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter()))));
    }

    public function testFactory(): void
    {
        $factory = $this->factory();
        self::assertTrue($factory->supports(new Dsn('microsoft+graph', 'default')));
        self::assertFalse($factory->supports(new Dsn('smtp', 'default')));
        self::assertSame('microsoft+graph://default', (string) $factory->create(Dsn::fromString('microsoft+graph://default')));
        self::assertSame('microsoft+graph://globex', (string) $factory->create(Dsn::fromString('microsoft+graph://globex')));

        foreach (['microsoft+graph://user:pass@default', 'microsoft+graph://bad%2Fname'] as $dsn) {
            try {
                $factory->create(Dsn::fromString($dsn));
                self::fail('Accepted ' . $dsn);
            } catch (InvalidArgumentException $e) {
                self::assertStringNotContainsString('pass', $e->getMessage());
            }
        }
        $this->expectException(UnsupportedSchemeException::class);
        $factory->create(new Dsn('smtp', 'default'));
    }

    public function testSendsAMailerEmail(): void
    {
        $html = fopen('php://memory', 'w+');
        self::assertIsResource($html);
        fwrite($html, '<p>Hi <img src="cid:logo.png"> <img src="cid:chart"></p>');
        $email = (new Email())
            ->from(new Address('someone-else@example.com', 'Projects Team'))
            ->to(new Address('client@example.net', 'Client'))
            ->cc('cc@example.net')
            ->bcc('hidden@example.net')
            ->replyTo('projects+t-7@example.com')
            ->subject('Re: Task 7')
            ->text('Hi')
            ->html($html)
            ->addPart((new DataPart('png-bytes', 'logo.png', 'image/png'))->asInline())
            ->addPart((new DataPart('chart-bytes', 'chart.png', 'image/png'))->asInline()->setContentId('chart@mk'))
            ->addPart(new DataPart('pdf-bytes', 'offer.pdf', 'application/pdf'));
        $email->getHeaders()->addIdHeader('Message-ID', 'task-7-2@example.com');
        $email->getHeaders()->addIdHeader('In-Reply-To', 'task-7-1@example.net');
        $email->getHeaders()->addTextHeader('References', '<task-7-0@example.com> <task-7-1@example.net>');

        $transport = $this->factory()->create(Dsn::fromString('microsoft+graph://default'));
        $sent      = $transport->send($email, new Envelope(new Address('bounce@example.com'), [
            new Address('client@example.net'),
            new Address('CC@example.net'),
            new Address('hidden@example.net'),
            new Address('hidden@example.net'),
        ]));

        self::assertNotNull($sent);
        self::assertSame('task-7-2@example.com', $sent->getMessageId());
        self::assertStringContainsString('Microsoft Graph message id: draft-projects@example.com-1', $sent->getDebug());

        $mime = $this->mock->draftMime();
        self::assertStringContainsString("From: Projects Team <projects@example.com>\r\n", $mime);
        self::assertStringContainsString("In-Reply-To: <task-7-1@example.net>\r\n", $mime);
        self::assertStringContainsString("References: <task-7-0@example.com> <task-7-1@example.net>\r\n", $mime);
        self::assertStringContainsString("Reply-To: projects+t-7@example.com\r\n", $mime);
        self::assertStringContainsString('cid:logo.png', $mime);

        $patch = json_decode($this->mock->matching('/messages/', 'PATCH')[0]['body'], true);
        self::assertSame(['bccRecipients' => [['emailAddress' => ['address' => 'hidden@example.net']]]], $patch);

        $attachments = [];
        foreach ($this->mock->matching('/attachments', 'POST') as $request) {
            $attachments[] = json_decode($request['body'], true);
        }
        self::assertCount(3, $attachments);
        self::assertSame(['logo.png', true, 'logo.png'], [$attachments[0]['name'], $attachments[0]['isInline'], $attachments[0]['contentId']]);
        self::assertSame(['chart.png', true, 'chart@mk'], [$attachments[1]['name'], $attachments[1]['isInline'], $attachments[1]['contentId']]);
        self::assertSame(['offer.pdf', false], [$attachments[2]['name'], $attachments[2]['isInline']]);
        self::assertArrayNotHasKey('contentId', $attachments[2]);
    }

    public function testMinimalEmailAndInvalidInlineName(): void
    {
        $email = (new Email())->from('app@example.org')->to('a@example.net')->text('plain')
            ->addPart((new DataPart('x', 'name with spaces.png', 'image/png'))->asInline());
        $sent = $this->factory()->create(Dsn::fromString('microsoft+graph://globex'))->send($email);
        self::assertNotNull($sent);
        self::assertStringEndsWith('@example.org', $sent->getMessageId());
        $attachment = json_decode($this->mock->matching('/attachments', 'POST')[0]['body'], true);
        self::assertFalse($attachment['isInline']);
        self::assertStringNotContainsString('In-Reply-To', $this->mock->draftMime());
    }

    public function testFailuresBecomeTransportExceptions(): void
    {
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, '/send')
            ? GraphApiMock::json(['error' => ['code' => 'ErrorSendAsDenied', 'message' => 'client@example.net']], 403)
            : null;
        $transport = $this->factory()->create(Dsn::fromString('microsoft+graph://default'));

        try {
            $transport->send((new Email())->from('app@example.com')->to('client@example.net')->text('x'));
            self::fail('A Graph failure must throw.');
        } catch (TransportException $e) {
            self::assertSame('Microsoft Graph send failed with HTTP 403 (ErrorSendAsDenied).', $e->getMessage());
            self::assertSame(403, $e->getCode());
            // The GraphException (safe message, reason, retryAfter, no previous of its own) is kept for Messenger retry logic.
            $previous = $e->getPrevious();
            self::assertInstanceOf(GraphException::class, $previous);
            self::assertNull($previous->getPrevious());
        }

        // Audit NK-1: an unknown send outcome must not be retried by Messenger, also through MailKit's runtime transport.
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_contains($url, '/send') || str_contains($url, '$select=isDraft')
            ? new MockResponse('', ['http_code' => 503])
            : null;
        try {
            $transport->send((new Email())->from('app@example.com')->to('client@example.net')->text('x'));
            self::fail('An ambiguous failure must throw.');
        } catch (GraphUnrecoverableTransportException $e) {
            $wrapped = DeliveryFailedException::from($e);
            self::assertInstanceOf(UnrecoverableDeliveryFailedException::class, $wrapped);
        }
        self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, DeliveryFailedException::from(new TransportException('x')));

        try {
            $transport->send(new RawMessage('raw'), new Envelope(new Address('a@example.com'), [new Address('b@example.com')]));
            self::fail('Raw messages are refused.');
        } catch (TransportException $e) {
            self::assertStringContainsString('MIME e-mails only', $e->getMessage());
        }

        $headers = (new Headers())->addMailboxListHeader('To', ['a@example.net'])->addMailboxListHeader('From', ['b@example.com']);
        try {
            $transport->send(new Message($headers, new TextPart('x', 'utf-8', 'calendar')));
            self::fail('Unsupported MIME structure.');
        } catch (TransportException $e) {
            self::assertStringContainsString('unsupported MIME structure', $e->getMessage());
        }
    }

    public function testDirectTransportInstance(): void
    {
        $transport = new GraphTransport($this->sender());
        self::assertSame('microsoft+graph://default', (string) $transport);
    }

    private function sender(): GraphMailSender
    {
        $client    = $this->mock->client();
        $mailboxes = new class implements GraphMailboxProviderInterface {
            public function mailbox(string $profile): GraphMailbox
            {
                return GraphApiMock::mailboxA();
            }

            public function defaultProfile(): string
            {
                return 'acme';
            }
        };

        return new GraphMailSender($mailboxes, new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter())));
    }
}
