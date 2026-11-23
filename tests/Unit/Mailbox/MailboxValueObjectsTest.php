<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Graph\Inbound\AuthenticationResults;
use Nowo\MailKitBundle\Mailbox\Event\MailboxGrantFailedEvent;
use Nowo\MailKitBundle\Mailbox\Event\MailboxResyncedEvent;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxNotActive;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Port\KeepAllExclusionPolicy;
use Nowo\MailKitBundle\Mailbox\Port\NullMailboxCallObserver;
use Nowo\MailKitBundle\Mailbox\Value\AttachmentKind;
use Nowo\MailKitBundle\Mailbox\Value\AttachmentRef;
use Nowo\MailKitBundle\Mailbox\Value\DisconnectResult;
use Nowo\MailKitBundle\Mailbox\Value\ExclusionDecision;
use Nowo\MailKitBundle\Mailbox\Value\FetchedAttachment;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\FolderSyncState;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSyncStatus;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\ProviderAttachmentId;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\StoreOutcome;
use Nowo\MailKitBundle\Mailbox\Value\SyncedMessage;
use Nowo\MailKitBundle\Mailbox\Value\ThreadKey;
use Nowo\MailKitBundle\Sender\MailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function fclose;
use function fopen;
use function hash;
use function is_resource;
use function print_r;
use function serialize;
use function str_repeat;
use function substr;

/**
 * 2.5.0 delegated-mailbox value objects: every constructor validates and bounds its input; the access token never
 * shows or serialises its value; the public surface is framework-free (REQ-ARCH-001 rule 3, SC-004).
 */
final class MailboxValueObjectsTest extends TestCase
{
    public function testIdsAcceptTheirFormAndCompareExactly(): void
    {
        $id = new MailboxId('org-1:user.ana_2');
        self::assertTrue($id->equals(new MailboxId('org-1:user.ana_2')));
        self::assertFalse($id->equals(new MailboxId('ORG-1:user.ana_2')));
        self::assertSame('org-1:user.ana_2', (string) $id);

        $message = new ProviderMessageId('AAMkAGI2+/=_-');
        self::assertTrue($message->equals(new ProviderMessageId('AAMkAGI2+/=_-')));
        self::assertFalse($message->equals(new ProviderMessageId('aamkagi2')));
        self::assertSame('att-1', (new ProviderAttachmentId('att-1'))->value);
    }

    /**
     * @return iterable<string, array{0: callable(): object}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty mailbox id' => [static fn (): object => new MailboxId('')];
        yield 'mailbox id with space' => [static fn (): object => new MailboxId('a b')];
        yield 'mailbox id too long' => [static fn (): object => new MailboxId(str_repeat('a', 129))];
        yield 'folder with quote' => [static fn (): object => new FolderRef("inbox'")];
        yield 'folder with dot' => [static fn (): object => new FolderRef('../inbox')];
        yield 'message id with space' => [static fn (): object => new ProviderMessageId('a b')];
        yield 'message id too long' => [static fn (): object => new ProviderMessageId(str_repeat('a', 513))];
        yield 'attachment id with quote' => [static fn (): object => new ProviderAttachmentId("a'b")];
        yield 'thread key' => [static fn (): object => new ThreadKey('other:x')];
        yield 'rule id' => [static fn (): object => ExclusionDecision::exclude('has space')];
        yield 'operation' => [static fn (): object => new ProviderCall(MailboxProvider::Graph, new MailboxId('m'), 'download', 200, 1, 1, null, 0, 1)];
        yield 'token with newline' => [static fn (): object => new MailboxAccessToken("abc\r\nX-Evil: 1", new DateTimeImmutable())];
        yield 'token too long' => [static fn (): object => new MailboxAccessToken(str_repeat('a', MailboxAccessToken::MAX_LENGTH + 1), new DateTimeImmutable())];
        yield 'empty token' => [static fn (): object => new MailboxAccessToken('', new DateTimeImmutable())];
        yield 'address' => [static fn (): object => self::settings('not-an-address')];
        yield 'address with angle' => [static fn (): object => self::settings('<ana@example.com>')];
        yield 'address too long' => [static fn (): object => self::settings(str_repeat('a', 250) . '@example.com')];
        yield 'no folder' => [static fn (): object => new MailboxSettings(new MailboxId('m'), MailboxProvider::Graph, 'ana@example.com', [])];
        yield 'too many folders' => [static fn (): object => new MailboxSettings(new MailboxId('m'), MailboxProvider::Graph, 'ana@example.com', array_map(static fn (int $i): FolderRef => new FolderRef('f' . $i), range(1, 101)))];
        yield 'duplicate folder' => [static fn (): object => new MailboxSettings(new MailboxId('m'), MailboxProvider::Graph, 'ana@example.com', [FolderRef::inbox(), FolderRef::inbox()])];
        yield 'window in minutes' => [static fn (): object => self::settings('ana@example.com', 'PT30M')];
        yield 'zero window' => [static fn (): object => self::settings('ana@example.com', 'P0D')];
        yield 'closed stream' => [static fn (): object => new FetchedAttachment(self::closedStream(), 0, 'text/plain', 'a.txt')];
    }

    /**
     * @param callable(): object $build
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRefused(callable $build): void
    {
        $this->expectException(InvalidArgumentException::class);
        $build();
    }

    public function testSettingsNormaliseTheAddressAndFindFolders(): void
    {
        $settings = new MailboxSettings(new MailboxId('m'), MailboxProvider::Graph, '  Ana@Example.COM ', [FolderRef::inbox(), FolderRef::sentItems()], true, 'P1DT6H');
        self::assertSame('ana@example.com', $settings->address);
        self::assertTrue($settings->paused);
        self::assertSame('P1DT6H', $settings->initialWindow);
        self::assertSame('sentitems', $settings->folder('sentitems')?->key);
        self::assertNull($settings->folder('archive'));
        self::assertTrue(FolderRef::inbox()->isWellKnown());
        self::assertFalse((new FolderRef('AAMkAGFolder=='))->isWellKnown());
        self::assertTrue(FolderRef::inbox()->equals(new FolderRef('inbox')));
        self::assertSame('ana@example.com', self::settings('ana@example.com', 'PT12H')->address);
    }

    public function testTheAccessTokenNeverShowsOrSerialisesItsValue(): void
    {
        $value = 'eyJ0eXAiOiJKV1QifQ.fake-payload.sig_-~+/==';
        $token = new MailboxAccessToken($value, new DateTimeImmutable('2026-11-23T13:00:00Z'), ['Mail.ReadWrite']);
        self::assertSame($value, $token->reveal());
        self::assertSame(substr(hash('sha256', $value), 0, 12), $token->fingerprint());
        self::assertSame(['Mail.ReadWrite'], $token->scopes);
        self::assertStringNotContainsString('fake-payload', print_r($token, true));
        self::assertSame('[redacted]', $token->__debugInfo()['value']);

        try {
            serialize($token);
            self::fail('Serialisation must be refused.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $token->__unserialize([]);
    }

    public function testExclusionDecisions(): void
    {
        self::assertFalse(ExclusionDecision::keep()->isExcluded());
        $excluded = ExclusionDecision::exclude('rule:newsletters-1');
        self::assertTrue($excluded->isExcluded());
        self::assertSame('rule:newsletters-1', $excluded->ruleId);
        self::assertEquals(ExclusionDecision::keep(), ExclusionDecision::keep());
    }

    public function testThreadKeyOrder(): void
    {
        $id = new ProviderMessageId('m1');
        self::assertSame('provider:graph:conv-1', ThreadKey::for(MailboxProvider::Graph, 'conv-1', ['root@x'], 'parent@x', 'own@x', $id)->value);
        self::assertSame('msgid:root@x', ThreadKey::for(MailboxProvider::Graph, 'bad id with space', ['root@x', 'b@x'], 'parent@x', 'own@x', $id)->value);
        self::assertSame('msgid:parent@x', ThreadKey::for(MailboxProvider::Graph, null, [], 'parent@x', 'own@x', $id)->value);
        self::assertSame('msgid:own@x', ThreadKey::for(MailboxProvider::Graph, null, [], null, 'own@x', $id)->value);
        self::assertSame('provider:graph:message:m1', ThreadKey::for(MailboxProvider::Graph, null, [], null, '', $id)->value);
        self::assertSame('provider:gmail:t1', ThreadKey::for(MailboxProvider::Gmail, 't1', [], null, '', $id)->value);
    }

    public function testRecordsCarryTheirFields(): void
    {
        $mailbox = new MailboxId('m');
        $call    = new ProviderCall(MailboxProvider::Graph, $mailbox, 'get_message', 200, 12, 345, 7, 0, 2);
        self::assertSame([200, 12, 345, 7, 0, 2], [$call->status, $call->durationMs, $call->bytes, $call->retryAfter, $call->quotaUnits, $call->attempt]);

        $ref = new AttachmentRef(new ProviderAttachmentId('a'), AttachmentKind::File, 'a.pdf', 'application/pdf', 10, true, 'cid1@x');
        self::assertTrue($ref->isDownloadable());
        self::assertFalse((new AttachmentRef(new ProviderAttachmentId('b'), AttachmentKind::Item, 'mail', 'message/rfc822', 0, false, null))->isDownloadable());

        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        $fetched = new FetchedAttachment($stream, 0, 'text/plain', 'a.txt');
        $fetched->close();
        $fetched->close();
        self::assertFalse(is_resource($stream));

        $status = new MailboxSyncStatus($mailbox, MailboxStatus::PausedGrant, GrantFailureReason::Revoked, [new FolderSyncState(FolderRef::inbox(), null, null)]);
        self::assertFalse($status->isActive());
        self::assertTrue((new MailboxSyncStatus($mailbox, MailboxStatus::Active))->isActive());
        self::assertSame([2, true, 5], [(new DisconnectResult(2, true, 5))->subscriptionsDeleted, (new DisconnectResult(2, true, 5))->remoteCleanupFailed, (new DisconnectResult(2, true, 5))->rowsDeleted]);

        $envelope = new MessageEnvelope($mailbox, FolderRef::inbox(), new ProviderMessageId('x'), new MailAddress('a@x.example'), null, null, null, null, false);
        self::assertFalse($envelope->complete);
        self::assertFalse((new KeepAllExclusionPolicy())->decide($envelope)->isExcluded());
        (new NullMailboxCallObserver())->called($call);

        $synced = new SyncedMessage($mailbox, MailboxProvider::Graph, FolderRef::inbox(), new ProviderMessageId('x'), null, new ThreadKey('msgid:a@b'), 'a@b', null, [], null, null, [], [], [], [], '', '', '', null, 0, null, null, false, null, [], new AuthenticationResults(), false, MessageOrigin::Sync);
        self::assertSame(MessageOrigin::Sync, $synced->origin);
        self::assertSame('duplicate', StoreOutcome::Duplicate->value);
    }

    public function testExceptionsAndEvents(): void
    {
        $failure = new MailboxGrantFailure(GrantFailureReason::ConsentMissing);
        self::assertSame(GrantFailureReason::ConsentMissing, $failure->reason);
        self::assertSame('The mailbox grant is gone.', $failure->getMessage());
        self::assertSame(42, (new MailboxTokenUnavailable(retryAfter: 42))->retryAfter);
        self::assertSame('The mailbox is not active (paused_host).', (new MailboxNotActive(MailboxStatus::PausedByHost))->getMessage());
        $provider = new MailboxProviderFailure('x', MailboxProviderFailure::TRANSIENT, 429, 3);
        self::assertTrue($provider->isRetryable());
        self::assertFalse((new MailboxProviderFailure('x', MailboxProviderFailure::REFUSED))->isRetryable());

        $event = new MailboxGrantFailedEvent(new MailboxId('m'), MailboxProvider::Graph, GrantFailureReason::Revoked);
        self::assertSame(GrantFailureReason::Revoked, $event->reason);
        self::assertSame(MailboxResyncedEvent::CURSOR_EXPIRED, (new MailboxResyncedEvent(new MailboxId('m'), FolderRef::inbox()))->reason);
    }

    public function testThePublicSurfaceIsFrameworkFree(): void
    {
        $dirs = ['Port', 'Value', 'Event', 'Service', 'Exception'];
        foreach ($dirs as $dir) {
            foreach (glob(__DIR__ . '/../../../src/Mailbox/' . $dir . '/*.php') ?: [] as $file) {
                $source = (string) file_get_contents($file);
                self::assertDoesNotMatchRegularExpression('/^use (Symfony|Doctrine|Psr\\\\Http)\\\\/m', $source, $file);
            }
        }
        self::assertTrue((new ReflectionClass(MailboxAccessToken::class))->isFinal());
    }

    /**
     * @return resource
     */
    private static function closedStream(): mixed
    {
        $stream = fopen('php://temp', 'r');
        self::assertIsResource($stream);
        fclose($stream);

        return $stream;
    }

    private static function settings(string $address, ?string $window = null): MailboxSettings
    {
        return new MailboxSettings(new MailboxId('m'), MailboxProvider::Graph, $address, [FolderRef::inbox()], false, $window);
    }
}
