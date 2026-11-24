<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Graph;

use Closure;
use Nowo\MailKitBundle\Graph\GraphApiClient;
use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\GraphMailbox;
use Nowo\MailKitBundle\Graph\GraphMailboxProviderInterface;
use Nowo\MailKitBundle\Graph\GraphTokenProvider;
use Nowo\MailKitBundle\Graph\Inbound\GraphMessageNormalizer;
use Nowo\MailKitBundle\Graph\Send\GraphMailSender;
use Nowo\MailKitBundle\Graph\Transport\GraphTransport;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSession;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxException;
use Nowo\MailKitBundle\Mailbox\Graph\DelegatedGraphCredential;
use Nowo\MailKitBundle\Mailbox\Graph\GraphMailboxGateway;
use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Mailbox\Html\RemoteImages;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Tests\Support\GraphApiMock;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Mime\Email;
use Throwable;

use function fopen;
use function implode;
use function ini_get;
use function ini_set;
use function is_array;
use function is_string;
use function json_encode;
use function serialize;
use function str_contains;
use function str_repeat;
use function str_starts_with;

/**
 * Audit ZAT-L5: on the app-only path, no Graph bearer token, upload session URL or client secret reaches the trace
 * arguments, the message or the logs of an exception chain that Messenger stores in its retry / failure transport.
 * The tokens, secrets and URLs below are fake fixture strings of {@see GraphApiMock}.
 */
final class GraphTokenTraceTest extends TestCase
{
    /** Fake upload session URL (pre-authenticated in real life). */
    private const UPLOAD_URL = 'https://outlook.office.com/upload/REDACTED-TEST-upload-capability';

    private GraphApiMock $mock;

    private string|false $ignoreArgs = false;

    /** @var list<string> log lines (message + JSON context) of the client and the token provider */
    private array $logged = [];

    private AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->mock   = new GraphApiMock();
        $this->logger = new class(function (string $line): void {
            $this->logged[] = $line;
        }) extends AbstractLogger {

            /** @param Closure(string): void $record */
            public function __construct(private readonly Closure $record)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                ($this->record)($message . ' ' . json_encode($context));
            }
        };
        // The PHP built-in default (and php.ini-development): trace frames keep their arguments.
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function failures(): iterable
    {
        yield 'transport error on the draft' => ['transport'];
        yield 'client decorator throws on the draft' => ['throw'];
        yield 'client decorator throws on the token request' => ['throw-token'];
        yield 'error status on the draft' => ['status'];
        yield 'transport error on an upload chunk' => ['upload'];
    }

    #[DataProvider('failures')]
    public function testFailingSendKeepsSecretsOutOfTheStoredChain(string $failure): void
    {
        $this->mock->override = static function (string $method, string $url, array $headers, string $body) use ($failure): ?MockResponse {
            if ($failure === 'throw-token' && str_starts_with($url, 'https://login.microsoftonline.com/')) {
                throw new RuntimeException('Outbound guard refused the request.');
            }
            if ($failure === 'upload' && str_contains($url, 'createUploadSession')) {
                return GraphApiMock::json(['uploadUrl' => self::UPLOAD_URL], 201);
            }
            if ($failure === 'upload' && str_starts_with($url, 'https://outlook.office.com/upload/')) {
                return new MockResponse('', ['error' => 'Connection reset by peer.']);
            }
            if ($failure === 'upload' || !str_starts_with($url, 'https://graph.microsoft.com/') || $method !== 'POST') {
                return null;
            }

            return match ($failure) {
                'transport' => new MockResponse('', ['error' => 'Connection reset by peer.']),
                'throw'     => throw new RuntimeException('Outbound guard refused the request.'),
                default     => GraphApiMock::json(['error' => ['code' => 'ErrorAccessDenied']], 403),
            };
        };

        $email = (new Email())->from('app@example.com')->to('client@example.net')->subject('Hi')->text('x');
        if ($failure === 'upload') {
            // Above the 3 MiB inline limit: sent through an upload session.
            $email->attach(str_repeat('a', 3_200_000), 'big.bin', 'application/octet-stream');
        }

        try {
            (new GraphTransport($this->sender()))->send($email);
            self::fail('The send did not fail.');
        } catch (Throwable $exception) {
            $this->assertNoSecret($exception);
        }
        self::assertSame($failure === 'throw-token' ? 0 : 1, $this->mock->tokensIssued);
        self::assertNotEmpty($this->logged);
        $this->assertNoSecretIn(implode("\n", $this->logged));
    }

    public function testOversizedAnswerKeepsTheTokenOutOfTheTrace(): void
    {
        $this->mock->override = static fn (string $method, string $url): ?MockResponse => str_starts_with($url, 'https://graph.microsoft.com/')
            ? GraphApiMock::json(['value' => str_repeat('x', 256)])
            : null;
        $client = $this->mock->client();
        $api    = new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter()));

        try {
            $api->call(GraphApiMock::mailboxA(), 'GET', '/users/projects%40example.com/messages', ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'read message', 64);
            self::fail('The oversized answer was accepted.');
        } catch (GraphException $exception) {
            self::assertSame(GraphException::REASON_TOO_LARGE, $exception->reason);
            $this->assertNoSecret($exception);
        }

        $sink = fopen('php://memory', 'w+');
        self::assertNotFalse($sink);

        try {
            $api->download(GraphApiMock::mailboxA(), '/users/projects%40example.com/messages/1/attachments/2/$value', $sink, 64, 'download attachment');
            self::fail('The oversized download was accepted.');
        } catch (GraphException $exception) {
            $this->assertNoSecret($exception);
        }
    }

    public function testRefusedUploadUrlStaysOutOfTheTrace(): void
    {
        $client = $this->mock->client();
        $api    = new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter()));

        try {
            $api->assertUploadUrl('https://evil.example/upload/REDACTED-TEST-upload-capability');
            self::fail('The upload URL was accepted.');
        } catch (GraphException $exception) {
            $this->assertNoSecret($exception);
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function delegatedFailures(): iterable
    {
        yield 'transport error on the delta' => ['transport'];
        yield 'client decorator throws' => ['throw'];
        yield 'oversized delta page' => ['large'];
        yield 'token refused twice' => ['revoked'];
        yield 'link outside the mailbox' => ['link'];
    }

    /**
     * 2.5.0: the delegated credential path keeps the host's token and the delta token out of every stored chain too
     * (the GraphApiClient exception and the gateway's mailbox failure that replaces it).
     */
    #[DataProvider('delegatedFailures')]
    public function testDelegatedFailuresKeepTheTokenOutOfTheStoredChain(string $failure): void
    {
        $graph           = new MailboxGraphFake();
        $ports           = new MemoryMailboxPorts();
        $graph->override = static function (string $method, string $path) use ($failure): ?MockResponse {
            if (!str_contains($path, '/delta')) {
                return null;
            }

            return match ($failure) {
                'transport' => new MockResponse('', ['error' => 'Connection reset by peer.']),
                'throw'     => throw new RuntimeException('Outbound guard refused the request.'),
                'large'     => MailboxGraphFake::json(['value' => str_repeat('x', 5_000_000)]),
                'revoked'   => MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401),
                default     => MailboxGraphFake::json(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/other@example.org/mailFolders/inbox/messages/delta?$deltatoken=REDACTED-TEST-delta-token']),
            };
        };
        $client  = $graph->client();
        $session = new MailboxSession(new MailboxId('mbx-ana'), MailboxProvider::Graph, 'ana@example.com', null, $ports, $ports, $this->logger);
        $cursor  = MailboxGraphFake::BASE . "/me/mailFolders('inbox')/messages/delta?\$deltatoken=REDACTED-TEST-delta-token";
        $api     = new GraphApiClient($client, null, logger: $this->logger);

        // The client itself, with the delegated credential.
        if ($failure !== 'link') {
            try {
                $api->callUrl(new DelegatedGraphCredential($session), 'GET', $cursor, ['headers' => ['Prefer' => 'IdType="ImmutableId"']], [200], 'delta', 4_194_304);
                self::fail('The delta call did not fail.');
            } catch (GraphException $exception) {
                $this->assertNoDelegatedSecret($exception);
            }
        }

        // The gateway, which replaces the Graph exception by a mailbox failure.
        $gateway = new GraphMailboxGateway($api, new GraphMessageNormalizer(new MailHtmlSanitizer(RemoteImages::Blocked)), 65_536, $this->logger);

        try {
            $gateway->changes($session, FolderRef::inbox(), $cursor, 0, 25);
            self::fail('The sweep page did not fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(MailboxException::class, $exception);
            $this->assertNoDelegatedSecret($exception);
        }
        $this->assertNoDelegatedSecretIn(implode("\n", $this->logged));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function delegatedSendFailures(): iterable
    {
        yield 'transport error on the draft' => ['draft'];
        yield 'client decorator throws on the update' => ['update'];
        yield 'transport error on an upload chunk' => ['chunk'];
        yield 'error status on an upload chunk' => ['chunk-status'];
        yield 'token refused twice on the send' => ['send'];
        yield 'oversized sent copy' => ['sent-copy'];
    }

    /**
     * 2.5.0 part 2: the send operations (draft, update, upload chunk, send, sent-copy fetch) keep the host's token and
     * the pre-authenticated upload URL out of every stored chain and log.
     */
    #[DataProvider('delegatedSendFailures')]
    public function testDelegatedSendFailuresKeepTheTokenOutOfTheStoredChain(string $failure): void
    {
        $graph           = new MailboxGraphFake();
        $ports           = new MemoryMailboxPorts();
        $graph->override = static function (string $method, string $path) use ($failure): ?MockResponse {
            return match (true) {
                $failure === 'draft' && $method === 'POST' && $path === '/v1.0/me/messages'                  => new MockResponse('', ['error' => 'Connection reset by peer.']),
                $failure === 'update' && $method === 'PATCH'                                                 => throw new RuntimeException('Outbound guard refused the request.'),
                $failure === 'chunk' && $method === 'PUT'                                                    => new MockResponse('', ['error' => 'Connection reset by peer.']),
                $failure === 'chunk-status' && $method === 'PUT'                                             => MailboxGraphFake::json(['error' => ['code' => 'InvalidRange']], 416),
                $failure === 'send' && $method === 'POST' && str_contains($path, '/send')                    => MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401),
                $failure === 'sent-copy' && $method === 'GET' && str_contains($path, '/me/messages/DRAFT-1') => MailboxGraphFake::json(['value' => str_repeat('x', 300_000)]),
                default                                                                                      => null,
            };
        };
        $session = new MailboxSession(new MailboxId('mbx-ana'), MailboxProvider::Graph, 'ana@example.com', null, $ports, $ports, $this->logger);
        $gateway = new GraphMailboxGateway(new GraphApiClient($graph->client(), null, logger: $this->logger), new GraphMessageNormalizer(new MailHtmlSanitizer(RemoteImages::Blocked)), 65_536, $this->logger);
        $mail    = new OutgoingMailboxMail(OutgoingKind::New, null, [new MailAddress('pedro@example.org')], [], [new MailAddress('boss@example.org')], 'Plan', 'Hello', null, [], 'trace-key-000001');

        try {
            $draft = $gateway->createDraft($session, $mail, 'x@example.com');
            $gateway->updateDraft($session, $draft, $mail);
            $gateway->addAttachment($session, $draft, OutgoingAttachment::fromString(str_repeat('a', 3_000_000), 'a.bin'), static function (): void {
            });
            $gateway->sendDraft($session, $draft->id);
            $gateway->fetch($session, FolderRef::sentItems(), $draft->id);
            self::fail('The send path did not fail.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(MailboxException::class, $exception);
            $this->assertNoDelegatedSecret($exception);
            $this->assertNoDelegatedSecretIn('REDACTED-TEST-upload-capability', serialize(FlattenException::createFromThrowable($exception)));
        }
        $this->assertNoDelegatedSecretIn(implode("\n", $this->logged));
        $this->assertNoDelegatedSecretIn('REDACTED-TEST-upload-capability', implode("\n", $this->logged));
    }

    private function assertNoDelegatedSecret(Throwable $exception): void
    {
        $this->assertNoDelegatedSecretIn(serialize((new Envelope(new stdClass()))->with(ErrorDetailsStamp::create($exception))));
        $this->assertNoDelegatedSecretIn(serialize(FlattenException::createFromThrowable($exception)));
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $this->assertNoDelegatedSecretIn($current->getMessage());
            $this->assertNoDelegatedSecretIn(implode("\n", self::strings($current->getTrace())));
        }
    }

    private function assertNoDelegatedSecretIn(string $haystack, ?string $extra = null): void
    {
        if ($extra !== null) {
            [$secret, $haystack] = [$haystack, $extra];
            self::assertStringNotContainsString($secret, $haystack, 'Leaked: ' . $secret);

            return;
        }
        foreach (['fake-mbx-ana', 'Bearer ', 'REDACTED-TEST-delta-token', 'REDACTED-TEST-upload-capability'] as $secret) {
            self::assertStringNotContainsString($secret, $haystack, 'Leaked: ' . $secret);
        }
    }

    private function assertNoSecret(Throwable $exception): void
    {
        // What Messenger writes to the retry / failure transport: the envelope with the flattened chain.
        $stored = serialize((new Envelope(new stdClass()))->with(ErrorDetailsStamp::create($exception)));
        $this->assertNoSecretIn($stored);
        $this->assertNoSecretIn(serialize(FlattenException::createFromThrowable($exception)));

        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $this->assertNoSecretIn($current->getMessage());
            $this->assertNoSecretIn(implode("\n", self::strings($current->getTrace())));
        }
    }

    private function assertNoSecretIn(string $haystack): void
    {
        foreach (['tok-' . GraphApiMock::TENANT_A, 'Bearer ', GraphApiMock::secret(), 'REDACTED-TEST-upload-capability'] as $secret) {
            self::assertStringNotContainsString($secret, $haystack, 'Leaked: ' . $secret);
        }
    }

    /**
     * Every string in trace arguments (arrays walked; objects are stored by class only).
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        $strings = [];
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $strings[] = $key;
                }
                foreach (self::strings($item) as $string) {
                    $strings[] = $string;
                }
            }
        }

        return $strings;
    }

    private function sender(): GraphMailSender
    {
        $logger    = $this->logger;
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

        return new GraphMailSender($mailboxes, new GraphApiClient($client, new GraphTokenProvider($client, new ArrayAdapter(), logger: $logger), logger: $logger));
    }
}
