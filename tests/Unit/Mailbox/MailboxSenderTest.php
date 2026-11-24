<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Mailbox;

use ArrayObject;
use InvalidArgumentException;
use LogicException;
use Nowo\MailKitBundle\Mailbox\Engine\MailboxSendOptions;
use Nowo\MailKitBundle\Mailbox\Event\MailboxGrantFailedEvent;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxNotActive;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxProviderFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendFailed;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendInProgress;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendKeyConflict;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendOutcomeUnknown;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\DeliverSentCopy;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\ReconcileMailboxSend;
use Nowo\MailKitBundle\Mailbox\Value\ExclusionDecision;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxStatus;
use Nowo\MailKitBundle\Mailbox\Value\MessageOrigin;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Sender\MailAddress;
use Nowo\MailKitBundle\Tests\Support\MailboxGraphFake;
use Nowo\MailKitBundle\Tests\Support\MailboxHarness;
use Nowo\MailKitBundle\Tests\Support\MemoryMailboxPorts;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;

/**
 * 2.5.0 part 2: sending from a delegated mailbox end to end on SQLite with the fake Graph (draft + send, Q-MB-1), the
 * idempotency state machine (claim, takeover, ambiguous submit reconciled by the draft state, never resent blindly),
 * the account check before every send, limits, the sent copy and the background reconcile. Fake tokens only.
 */
final class MailboxSenderTest extends TestCase
{
    use MailboxHarness;

    private const KEY = 'beacon-draft-0001';

    private MailboxId $ana;

    protected function setUp(): void
    {
        $this->harness(sendOptions: new MailboxSendOptions(staleClaimSeconds: 120, maxAttempts: 3));
        $this->ana = new MailboxId('mbx-ana');
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox']));
        $this->mailboxStore->insertMailbox($this->ana, MailboxProvider::Graph);
        $this->graph->messages['orig-1'] = MailboxGraphFake::message('orig-1');
    }

    public function testANewMessageIsSentOnceWithItsSentCopy(): void
    {
        $small = OutgoingAttachment::fromString('hello', 'note.txt', 'text/plain');
        $large = OutgoingAttachment::fromString(str_repeat('x', 4_000_000), 'big.bin', 'application/octet-stream');
        $mail  = $this->mail(attachments: [$small, $large], bcc: [new MailAddress('boss@example.org')], html: '<p onclick="x()">Hi <img src="https://tracker.example.net/p.gif"></p><script>alert(1)</script>');

        $sent = $this->sender->send($this->ana, $mail);
        self::assertSame('DRAFT-1', $sent->id->value);
        self::assertSame('DRAFT-1@ana.example.com', $sent->internetMessageId);
        self::assertSame('conv-DRAFT-1', $sent->providerThreadId);
        self::assertSame('provider:graph:conv-DRAFT-1', $sent->threadKey->value);
        self::assertFalse($sent->replayed);
        self::assertSame(['DRAFT-1'], $this->graph->delivered);

        $mime = $this->graph->mime['DRAFT-1'];
        self::assertStringContainsString('From: ana@example.com', $mime);
        self::assertStringContainsString('To: Pedro <pedro@example.org>', $mime);
        self::assertStringNotContainsString('boss@example.org', $mime, 'Bcc never in the MIME');
        self::assertStringNotContainsString('<script', $mime);
        self::assertStringNotContainsString('onclick', $mime);
        self::assertStringNotContainsString('tracker.example.net', $mime, 'remote images are not sent');
        self::assertMatchesRegularExpression('/Message-ID: <[0-9a-f]{32}\.mailkit@example\.com>/', $mime);
        self::assertSame([['bccRecipients' => [['emailAddress' => ['address' => 'boss@example.org']]]]], $this->graph->patches['DRAFT-1']);
        self::assertCount(2, $this->graph->added['DRAFT-1']);
        self::assertSame('aGVsbG8=', $this->graph->added['DRAFT-1'][0]['contentBytes']);
        self::assertSame(4_000_000, $this->graph->added['DRAFT-1'][1]['AttachmentItem']['size']);
        foreach ($this->graph->matching('outlook.office.com/upload/') as $chunk) {
            self::assertSame('', $chunk['authorization'], 'the upload host never gets the token');
        }
        self::assertCount(2, $this->graph->matching('outlook.office.com/upload/', 'PUT'));
        self::assertContains('upload', array_values(array_map(static fn (ProviderCall $c): string => $c->operation, $this->ports->calls)));

        // The sent copy reaches the sink although Sent Items is not followed, without the exclusion policy.
        $this->ports->policy = static fn (): ExclusionDecision => ExclusionDecision::exclude('all');
        self::assertInstanceOf(DeliverSentCopy::class, $this->bus->messages[0]);
        self::assertEquals([new DelayStamp(5000)], $this->bus->stamps[0]);
        self::assertSame([], $this->drain());
        $copy = $this->ports->stored['mbx-ana' . "\n" . 'DRAFT-1'];
        self::assertSame(MessageOrigin::SentCopy, $copy->origin);
        self::assertSame('sentitems', $copy->folder->key);
        self::assertTrue($copy->fromMailbox);
        self::assertSame([], $this->ports->envelopes);

        // A retry with the same key sends nothing and answers the same ids.
        $again = $this->sender->send($this->ana, $mail);
        self::assertTrue($again->replayed);
        self::assertSame('DRAFT-1', $again->id->value);
        self::assertSame($sent->sentAt->getTimestamp(), $again->sentAt->getTimestamp());
        self::assertSame(['DRAFT-1'], $this->graph->drafts);
        self::assertSame(['DRAFT-1'], $this->graph->sendCalls);

        // The same key for another message is refused.
        try {
            $this->sender->send($this->ana, $this->mail(subject: 'Another'));
            self::fail('The key names another message.');
        } catch (MailboxSendKeyConflict) {
        }
        self::assertSame(['DRAFT-1'], $this->graph->drafts);
    }

    public function testRepliesAndForwardsPutTheHostsRecipientsAndBodyOnTheDraft(): void
    {
        $reply = $this->sender->send($this->ana, $this->mail(OutgoingKind::Reply, key: 'reply-key-000001', subject: null, html: '<p>Thanks</p><img src="https://x.example/a.png">'));
        self::assertSame('conv-orig-1', $reply->providerThreadId);
        self::assertCount(1, $this->graph->matching('/me/messages/orig-1/createReply', 'POST'));
        $patch = $this->graph->patches[$reply->id->value][0];
        self::assertSame([['emailAddress' => ['address' => 'pedro@example.org', 'name' => 'Pedro']]], $patch['toRecipients']);
        self::assertSame([], $patch['ccRecipients']);
        self::assertSame('html', $patch['body']['contentType']);
        self::assertStringNotContainsString('x.example', $patch['body']['content']);
        self::assertArrayNotHasKey('subject', $patch);

        $all = $this->sender->send($this->ana, $this->mail(OutgoingKind::ReplyAll, key: 'reply-key-000002', subject: 'Re: plan'));
        self::assertCount(1, $this->graph->matching('/me/messages/orig-1/createReplyAll', 'POST'));
        self::assertSame('Re: plan', $this->graph->patches[$all->id->value][0]['subject']);
        self::assertSame(['contentType' => 'text', 'content' => 'Hello'], $this->graph->patches[$all->id->value][0]['body']);

        $this->sender->send($this->ana, $this->mail(OutgoingKind::Forward, key: 'forward-key-0001', subject: null));
        self::assertCount(1, $this->graph->matching('/me/messages/orig-1/createForward', 'POST'));
        self::assertCount(3, $this->graph->delivered);

        // A reply to a message that no longer exists is refused for good.
        try {
            $this->sender->send($this->ana, $this->mail(OutgoingKind::Reply, key: 'reply-key-000003', inResponseTo: 'gone-1'));
            self::fail('The original is gone.');
        } catch (MailboxSendFailed $failure) {
            self::assertSame('not_found:ErrorItemNotFound', $failure->reason);
        }
        $this->expectException(MailboxSendFailed::class);
        $this->sender->send($this->ana, $this->mail(OutgoingKind::Reply, key: 'reply-key-000003', inResponseTo: 'gone-1'));
    }

    public function testAnotherAccountsTokenPausesTheMailboxAndSendsNothing(): void
    {
        $this->graph->account = ['id' => 'other-account', 'mail' => 'eve@example.org', 'userPrincipalName' => 'eve@example.org'];

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('The token belongs to another account.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::AccountMismatch, $failure->reason);
        }
        self::assertSame([], $this->graph->drafts);
        self::assertSame(MailboxStatus::PausedGrant, $this->engine->status($this->ana)->status);
        self::assertCount(1, array_filter($this->events->events, static fn (object $e): bool => $e instanceof MailboxGrantFailedEvent));
        self::assertSame('released', $this->row()['status']);

        $this->expectException(MailboxNotActive::class);
        $this->sender->send($this->ana, $this->mail());
    }

    public function testASecondWorkerWithTheSameKeySendsNothingWhileTheFirstIsInFlight(): void
    {
        $inner                 = null;
        $this->graph->override = function (string $method, string $path) use (&$inner): ?MockResponse {
            if ($method === 'POST' && str_contains($path, '/send') && !$inner instanceof Throwable) {
                try {
                    $this->sender->send($this->ana, $this->mail());
                } catch (Throwable $failure) {
                    $inner = $failure;
                }
            }

            return null;
        };
        self::assertFalse($this->sender->send($this->ana, $this->mail())->replayed);
        self::assertInstanceOf(MailboxSendInProgress::class, $inner);
        self::assertSame(['DRAFT-1'], $this->graph->delivered);
        self::assertTrue($this->sender->send($this->ana, $this->mail())->replayed);
    }

    public function testATransientFailureBeforeTheSubmitReleasesTheKeyForTheNextCall(): void
    {
        $switch                = new ArrayObject(['fail' => true]);
        $this->graph->override = static function (string $method, string $path) use ($switch): ?MockResponse {
            if ($switch['fail'] === true && $method === 'POST' && str_contains($path, '/attachments')) {
                return MailboxGraphFake::json(['error' => ['code' => 'ServiceUnavailable']], 503, ['retry-after' => '5']);
            }

            return null;
        };
        $mail = $this->mail(attachments: [OutgoingAttachment::fromString('a', 'a.txt')]);

        try {
            $this->sender->send($this->ana, $mail);
            self::fail('The attachment upload is throttled.');
        } catch (MailboxProviderFailure $failure) {
            self::assertTrue($failure->isRetryable());
        }
        self::assertSame(['DRAFT-1'], $this->graph->deleted, 'the unsent draft is deleted');
        self::assertSame('released', $this->row()['status']);
        self::assertNull($this->row()['provider_message_id']);

        $switch['fail'] = false;
        self::assertSame('DRAFT-2', $this->sender->send($this->ana, $mail)->id->value);
        self::assertSame(['DRAFT-2'], $this->graph->delivered);
    }

    public function testTheTokenPortAndGrantFailuresBeforeTheSubmitReleaseTheKey(): void
    {
        $this->ports->tokenOverride = static fn (): never => throw new MailboxTokenUnavailable();

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('No token.');
        } catch (MailboxTokenUnavailable) {
        }
        self::assertSame('released', $this->row()['status']);

        $this->ports->tokenOverride      = null;
        $this->ports->revoked['mbx-ana'] = GrantFailureReason::Revoked;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Revoked.');
        } catch (MailboxGrantFailure) {
        }
        self::assertSame(MailboxStatus::PausedGrant, $this->engine->status($this->ana)->status);
        self::assertSame('released', $this->row()['status']);
        self::assertSame([], $this->graph->drafts);
    }

    public function testADefiniteRefusalOfTheDraftFailsTheKeyForGood(): void
    {
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && $path === '/v1.0/me/messages'
            ? MailboxGraphFake::json(['error' => ['code' => 'ErrorInvalidRecipients']], 400)
            : null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Refused.');
        } catch (MailboxSendFailed $failure) {
            self::assertSame('refused:ErrorInvalidRecipients', $failure->reason);
        }
        self::assertSame('failed', $this->row()['status']);
        $this->graph->override = null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('The key failed for good.');
        } catch (MailboxSendFailed $failure) {
            self::assertSame('refused:ErrorInvalidRecipients', $failure->reason);
        }
        self::assertSame([], $this->graph->drafts);
    }

    public function testAnAmbiguousSubmitIsReconciledByTheDraftStateAndNeverDuplicated(): void
    {
        $calls                 = 0;
        $this->graph->override = static function (string $method, string $path) use (&$calls): ?MockResponse {
            if ($method === 'POST' && str_contains($path, '/send') && ++$calls === 1) {
                return MailboxGraphFake::json(['error' => ['code' => 'GatewayTimeout']], 504);
            }

            return null;
        };

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('The submit timed out.');
        } catch (MailboxSendInProgress $pending) {
            self::assertTrue($pending->reconciling);
        }
        self::assertSame('sending', $this->row()['status']);
        self::assertInstanceOf(ReconcileMailboxSend::class, $this->bus->messages[0]);
        self::assertEquals([new DelayStamp(121_000)], $this->bus->stamps[0]);

        // Within the stale delay: nothing is submitted again.
        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Still reconciling.');
        } catch (MailboxSendInProgress $pending) {
            self::assertTrue($pending->reconciling);
        }
        self::assertSame(1, $this->submits());

        // The background reconcile, once stale: still a draft → that same draft is submitted again (one delivery).
        $this->clock->modify('+122 seconds');
        self::assertSame([], $this->drain());
        self::assertSame(2, $this->submits());
        self::assertSame(['DRAFT-1'], $this->graph->delivered);
        self::assertSame(['DRAFT-1'], $this->graph->drafts, 'no second draft');
        self::assertSame('sent', $this->row()['status']);
        self::assertTrue($this->sender->send($this->ana, $this->mail())->replayed);
    }

    public function testAnAmbiguousSubmitThatWasDeliveredIsMarkedSent(): void
    {
        $this->graph->override = function (string $method, string $path): ?MockResponse {
            if ($method === 'POST' && str_contains($path, '/send')) {
                $this->graph->deliver('DRAFT-1');

                return new MockResponse('', ['error' => 'Connection reset by peer.']);
            }

            return null;
        };
        $sent = $this->sender->send($this->ana, $this->mail());
        self::assertFalse($sent->replayed);
        self::assertSame(['DRAFT-1'], $this->graph->delivered);
        self::assertInstanceOf(DeliverSentCopy::class, $this->bus->messages[0]);
    }

    public function testADefinitelyRefusedSubmitFailsAndDeletesTheDraft(): void
    {
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && str_contains($path, '/send')
            ? MailboxGraphFake::json(['error' => ['code' => 'ErrorMessageSizeExceeded']], 400)
            : null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Refused.');
        } catch (MailboxSendFailed $failure) {
            self::assertSame('refused:ErrorMessageSizeExceeded', $failure->reason);
        }
        self::assertSame(['DRAFT-1'], $this->graph->deleted);
        self::assertSame('failed', $this->row()['status']);
        self::assertSame([], $this->graph->delivered);
    }

    public function testASubmittedDraftThatIsGoneIsUnknownAndNeverSentAgain(): void
    {
        $this->graph->override = function (string $method, string $path): ?MockResponse {
            if ($method === 'POST' && str_contains($path, '/send')) {
                unset($this->graph->messages['DRAFT-1']);

                return MailboxGraphFake::json(['error' => ['code' => 'InternalServerError']], 500);
            }

            return null;
        };

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Unknown.');
        } catch (MailboxSendOutcomeUnknown) {
        }
        self::assertSame('unknown', $this->row()['status']);
        $this->graph->override = null;
        $this->expectException(MailboxSendOutcomeUnknown::class);
        $this->sender->send($this->ana, $this->mail());
    }

    public function testAnUnreadableDraftStateStaysPendingUntilTheAttemptsRunOut(): void
    {
        $this->graph->override = static function (string $method, string $path): ?MockResponse {
            if (($method === 'POST' && str_contains($path, '/send')) || ($method === 'GET' && str_contains($path, 'isDraft'))) {
                return MailboxGraphFake::json(['error' => ['code' => 'ServiceUnavailable']], 503);
            }

            return null;
        };
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            try {
                $this->sender->send($this->ana, $this->mail());
                self::fail('Pending.');
            } catch (MailboxSendInProgress $pending) {
                self::assertTrue($pending->reconciling);
            }
            $this->clock->modify('+121 seconds');
        }
        self::assertSame(3, $this->row()['attempts']);

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Out of attempts.');
        } catch (MailboxSendOutcomeUnknown) {
        }
        self::assertSame('unknown', $this->row()['status']);
        self::assertSame(1, $this->submits(), 'an unreadable draft is never resubmitted');
        self::assertSame([], $this->graph->delivered);
    }

    public function testAStaleDraftingClaimIsTakenOverAndItsDraftReplaced(): void
    {
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'PATCH'
            ? throw new RuntimeException('worker killed') : null;

        try {
            $this->sender->send($this->ana, $this->mail(bcc: [new MailAddress('boss@example.org')]));
            self::fail('Crashed.');
        } catch (Throwable) {
        }
        // The crash left the row `drafting` (as if the worker died); simulate it by restoring the state.
        $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_send SET status = 'drafting', provider_message_id = 'DRAFT-1'");
        $this->graph->messages['DRAFT-1'] = MailboxGraphFake::message('DRAFT-1', ['isDraft' => true]);
        $this->graph->override            = null;

        try {
            $this->sender->send($this->ana, $this->mail(bcc: [new MailAddress('boss@example.org')]));
            self::fail('Not stale yet.');
        } catch (MailboxSendInProgress $busy) {
            self::assertFalse($busy->reconciling);
        }
        $this->clock->modify('+121 seconds');
        $sent = $this->sender->send($this->ana, $this->mail(bcc: [new MailAddress('boss@example.org')]));
        self::assertSame('DRAFT-2', $sent->id->value);
        self::assertContains('DRAFT-1', $this->graph->deleted);
        self::assertSame(['DRAFT-2'], $this->graph->delivered);
    }

    public function testAPreviousDraftSentByHandOrUnreadableIsNeverSentAgain(): void
    {
        $this->sender->send($this->ana, $this->mail(key: 'other-key-000001'));
        $this->graph->delivered = [];
        $this->mailboxConnection->executeStatement("INSERT INTO mail_kit_mailbox_send (mailbox_id, idempotency_key, payload_hash, status, claim_token, provider_message_id, attempts, row_version, claimed_at, updated_at) VALUES ('mbx-ana', ?, ?, 'released', 't0', 'DRAFT-1', 1, 0, 0, 0)", [self::KEY, $this->mail()->fingerprint()]);

        // DRAFT-1 reads as sent: the key's message may have left; nothing is sent again.
        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Sent elsewhere.');
        } catch (MailboxSendOutcomeUnknown) {
        }
        self::assertSame('unknown', $this->row()['status']);

        $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_send SET status = 'released', provider_message_id = 'DRAFT-9' WHERE idempotency_key = ?", [self::KEY]);
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => str_contains($path, 'DRAFT-9') ? MailboxGraphFake::json([], 500) : null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Unreadable previous draft.');
        } catch (MailboxProviderFailure $failure) {
            self::assertTrue($failure->isRetryable());
        }
        self::assertSame('released', $this->row()['status']);
        self::assertSame('DRAFT-9', $this->row()['provider_message_id']);

        // Gone: a new draft is sent.
        $this->graph->override = null;
        self::assertSame('DRAFT-2', $this->sender->send($this->ana, $this->mail())->id->value);
    }

    public function testALostClaimDuringTheUploadStopsWithoutSending(): void
    {
        $this->graph->override = function (string $method, string $path): ?MockResponse {
            if ($method === 'PUT') {
                $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_send SET claim_token = 'another-worker'");
            }

            return null;
        };

        try {
            $this->sender->send($this->ana, $this->mail(attachments: [OutgoingAttachment::fromString(str_repeat('y', 3_000_000), 'big.bin')]));
            self::fail('The claim was taken over.');
        } catch (MailboxSendInProgress $busy) {
            self::assertFalse($busy->reconciling);
        }
        self::assertSame([], $this->graph->sendCalls);
        self::assertSame(['DRAFT-1'], $this->graph->deleted);
        self::assertCount(1, $this->graph->matching('outlook.office.com/upload/', 'DELETE'), 'the upload session is cancelled');
        self::assertSame('drafting', $this->row()['status'], 'the row is the new holder\'s');
    }

    public function testATakenOverSubmitAnswersWhatTheNewHolderRecorded(): void
    {
        $this->graph->override = function (string $method, string $path): ?MockResponse {
            if ($method === 'POST' && str_contains($path, '/send')) {
                $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_send SET claim_token = 'another-worker'");
            }

            return null;
        };

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Taken over.');
        } catch (MailboxSendInProgress $busy) {
            self::assertTrue($busy->reconciling);
        }

        $this->graph->override = function (string $method, string $path): ?MockResponse {
            if ($method === 'POST' && str_contains($path, '/send')) {
                $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_send SET claim_token = 'another-worker', status = 'sent', sent_at = 1");
            }

            return null;
        };
        self::assertTrue($this->sender->send($this->ana, $this->mail(key: 'second-key-00001'))->replayed);
    }

    public function testGrantFailuresAroundTheSubmitKeepTheKeySending(): void
    {
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && str_contains($path, '/send')
            ? MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401)
            : null;

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Revoked.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::Revoked, $failure->reason);
        }
        self::assertSame('sending', $this->row()['status']);
        self::assertSame(MailboxStatus::PausedGrant, $this->engine->status($this->ana)->status);

        // After resume, the next call reconciles: still a draft → submitted once.
        $this->mailboxStore->activate($this->ana);
        $this->graph->override = null;
        $this->clock->modify('+121 seconds');
        self::assertTrue($this->sender->send($this->ana, $this->mail())->replayed);
        self::assertSame(['DRAFT-1'], $this->graph->delivered);

        // A refused state read after an ambiguous submit pauses too.
        $this->graph->override = static function (string $method, string $path): ?MockResponse {
            if ($method === 'POST' && str_contains($path, '/send')) {
                return MailboxGraphFake::json([], 503);
            }
            if ($method === 'GET' && str_contains($path, 'isDraft')) {
                return MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
            }

            return null;
        };

        try {
            $this->sender->send($this->ana, $this->mail(key: 'second-key-00001'));
            self::fail('Revoked.');
        } catch (MailboxGrantFailure) {
        }
        self::assertSame('sending', $this->row('second-key-00001')['status']);

        // The reconcile of that key pauses again when the account changed meanwhile.
        $this->mailboxStore->activate($this->ana);
        $this->clock->modify('+121 seconds');
        $this->graph->account = ['id' => 'other-account', 'mail' => 'eve@example.org'];

        try {
            $this->sender->send($this->ana, $this->mail(key: 'second-key-00001'));
            self::fail('Another account.');
        } catch (MailboxGrantFailure $failure) {
            self::assertSame(GrantFailureReason::AccountMismatch, $failure->reason);
        }
        self::assertSame('sending', $this->row('second-key-00001')['status']);
    }

    public function testTheBackgroundReconcile(): void
    {
        self::assertTrue($this->sender->reconcile($this->ana, 'missing-key-0001'));
        $this->sender->send($this->ana, $this->mail());
        self::assertTrue($this->sender->reconcile($this->ana, self::KEY), 'already sent');

        $this->graph->override = static fn (string $method, string $path): ?MockResponse => ($method === 'POST' && str_contains($path, '/send')) || ($method === 'GET' && str_contains($path, 'isDraft'))
            ? MailboxGraphFake::json([], 503)
            : null;

        try {
            $this->sender->send($this->ana, $this->mail(key: 'second-key-00001'));
        } catch (MailboxSendInProgress) {
        }
        self::assertFalse($this->sender->reconcile($this->ana, 'second-key-00001'), 'not stale yet');
        $this->clock->modify('+121 seconds');
        self::assertFalse($this->sender->reconcile($this->ana, 'second-key-00001'), 'still unreadable');
        $this->clock->modify('+121 seconds');
        $this->mailboxStore->pauseByHost($this->ana);
        self::assertTrue($this->sender->reconcile($this->ana, 'second-key-00001'), 'paused: the next send() reconciles');
        $this->mailboxStore->activate($this->ana);

        // A gone draft settles as unknown.
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'GET' && str_contains($path, 'isDraft') ? MailboxGraphFake::json(['error' => ['code' => 'ErrorItemNotFound']], 404) : null;
        self::assertTrue($this->sender->reconcile($this->ana, 'second-key-00001'));
        self::assertSame('unknown', $this->row('second-key-00001')['status']);
    }

    public function testTheHandlerRetriesTheSentCopyAndTheReconcileABoundedNumberOfTimes(): void
    {
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'GET' && str_contains($path, '/me/messages/DRAFT-1?')
            ? MailboxGraphFake::json(['error' => ['code' => 'ErrorItemNotFound']], 404)
            : null;
        $this->sender->send($this->ana, $this->mail());
        self::assertSame([], $this->drain());
        self::assertSame([], $this->ports->stored);
        self::assertCount(3, $this->graph->matching('/me/messages/DRAFT-1?', 'GET'));
        self::assertTrue($this->log->has('Mailbox sent copy not found after the last attempt; a sweep of a followed Sent Items delivers it.'));

        $this->handler->sentCopy(new DeliverSentCopy('bad id', 'DRAFT-1'));
        $this->handler->sentCopy(new DeliverSentCopy('mbx-ana', 'bad id'));
        $this->handler->reconcile(new ReconcileMailboxSend('mbx-ana', 'short'));
        self::assertSame([], $this->bus->messages);

        // Not settled → tried again later, at most MAX_DELAYED_ATTEMPTS times.
        $this->handler->reconcile(new ReconcileMailboxSend('mbx-ana', 'no-such-key-0001', 0));
        self::assertSame([], $this->bus->messages, 'nothing to reconcile');
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && str_contains($path, '/send') ? MailboxGraphFake::json([], 503) : null;

        try {
            $this->sender->send($this->ana, $this->mail(key: 'second-key-00001'));
        } catch (MailboxSendInProgress) {
        }
        $this->bus->messages = [];
        $this->bus->stamps   = [];
        $this->handler->reconcile(new ReconcileMailboxSend('mbx-ana', 'second-key-00001', 0));
        self::assertEquals([new ReconcileMailboxSend('mbx-ana', 'second-key-00001', 1)], $this->bus->messages);
        $this->handler->reconcile(new ReconcileMailboxSend('mbx-ana', 'second-key-00001', 9));
        self::assertCount(1, $this->bus->messages);
        self::assertTrue($this->log->has('Mailbox send still unsettled after the last background reconcile; the next send() with the key reconciles it.'));
    }

    public function testGuardsAndLimits(): void
    {
        $this->mailboxConnection->beginTransaction();

        try {
            $this->sender->send($this->ana, $this->mail());
            self::fail('Inside a transaction.');
        } catch (LogicException) {
        } finally {
            $this->mailboxConnection->rollBack();
        }

        $this->harness(sendOptions: new MailboxSendOptions(maxRecipients: 1, maxAttachments: 1, maxAttachmentBytes: 10, maxMessageBytes: 30, maxHtmlBytes: 1024, maxTextBytes: 1024));
        $this->ports->add(MemoryMailboxPorts::settings('mbx-ana', 'ana@example.com', ['inbox']));
        $this->mailboxStore->insertMailbox($this->ana, MailboxProvider::Graph);
        $cases = [
            $this->mail(cc: [new MailAddress('cc@example.org')]),
            $this->mail(text: str_repeat('t', 1025)),
            $this->mail(html: str_repeat('h', 1025)),
            $this->mail(attachments: [OutgoingAttachment::fromString('a', 'a'), OutgoingAttachment::fromString('b', 'b')]),
            $this->mail(attachments: [OutgoingAttachment::fromString(str_repeat('a', 11), 'a')]),
            $this->mail(attachments: [OutgoingAttachment::fromString(str_repeat('a', 10), 'a')], text: str_repeat('t', 25)),
        ];
        foreach ($cases as $i => $mail) {
            try {
                $this->sender->send($this->ana, $mail);
                self::fail('Limit ' . $i);
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(0, (int) $this->mailboxConnection->fetchOne('SELECT COUNT(*) FROM mail_kit_mailbox_send'));

        $this->mailboxStore->pauseByHost($this->ana);
        $this->expectException(MailboxNotActive::class);
        $this->sender->send($this->ana, $this->mail());
    }

    public function testFailuresAroundTheStoreAndTheBusNeverHideTheOutcome(): void
    {
        // The sent copy cannot be enqueued: the send still succeeds.
        $this->bus->failure = new RuntimeException('bus down');
        self::assertFalse($this->sender->send($this->ana, $this->mail())->replayed);
        self::assertTrue($this->log->has('Mailbox sent copy could not be enqueued.'));

        // The reconcile cannot be enqueued: pending all the same.
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && str_contains($path, '/send') ? MailboxGraphFake::json([], 503) : null;

        try {
            $this->sender->send($this->ana, $this->mail(key: 'second-key-00001'));
            self::fail('Pending.');
        } catch (MailboxSendInProgress) {
        }
        self::assertTrue($this->log->has('Mailbox send reconcile could not be enqueued; the next send() with the key reconciles it.'));
        $this->bus->failure = null;

        // A row deleted between the claim and the read (purge, disconnect): the caller retries.
        $this->graph->override = null;
        $this->mailboxConnection->executeStatement("CREATE TRIGGER ghost_claim BEFORE INSERT ON mail_kit_mailbox_send WHEN NEW.idempotency_key = 'ghost-key-000001' BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: mail_kit_mailbox_send.idempotency_key'); END");

        try {
            $this->sender->send($this->ana, $this->mail(key: 'ghost-key-000001'));
            self::fail('The row vanished.');
        } catch (MailboxSendInProgress $busy) {
            self::assertFalse($busy->reconciling);
        }

        // A sent row without ids (hand-edited) is reported unknown rather than invented.
        $this->mailboxConnection->executeStatement("INSERT INTO mail_kit_mailbox_send (mailbox_id, idempotency_key, payload_hash, status, claim_token, attempts, row_version, claimed_at, updated_at) VALUES ('mbx-ana', 'third-key-000001', ?, 'sent', 't', 1, 0, 0, 0)", [$this->mail(key: 'third-key-000001')->fingerprint()]);

        try {
            $this->sender->send($this->ana, $this->mail(key: 'third-key-000001'));
            self::fail('A sent row without ids is unknown.');
        } catch (MailboxSendOutcomeUnknown) {
        }

        // A sending row without a draft id cannot be reconciled: unknown.
        $this->mailboxConnection->executeStatement("INSERT INTO mail_kit_mailbox_send (mailbox_id, idempotency_key, payload_hash, status, claim_token, attempts, row_version, claimed_at, updated_at) VALUES ('mbx-ana', 'fourth-key-00001', ?, 'sending', 't', 1, 0, 0, 0)", [$this->mail(key: 'fourth-key-00001')->fingerprint()]);
        $this->expectException(MailboxSendOutcomeUnknown::class);
        $this->sender->send($this->ana, $this->mail(key: 'fourth-key-00001'));
    }

    public function testRareRacesAndFailuresOnTheWayStayOnTheSafeSide(): void
    {
        // The claim is taken over right after the draft was created: our draft is deleted, nothing is sent.
        $this->graph->override = function (string $method, string $path): ?MockResponse {
            if ($method === 'POST' && $path === '/v1.0/me/messages') {
                $this->mailboxConnection->executeStatement("UPDATE mail_kit_mailbox_send SET claim_token = 'another-worker'");
            }

            return null;
        };

        try {
            $this->sender->send($this->ana, $this->mail(cc: [new MailAddress('cc@example.org', 'Cc')]));
            self::fail('Taken over.');
        } catch (MailboxSendInProgress) {
        }
        self::assertSame(['DRAFT-1'], $this->graph->deleted);
        self::assertStringContainsString('Cc: Cc <cc@example.org>', $this->graph->mime['DRAFT-1']);

        // The abandoned claim cannot be recorded: the original failure still reaches the caller.
        $this->mailboxConnection->executeStatement("CREATE TRIGGER no_release BEFORE UPDATE ON mail_kit_mailbox_send WHEN NEW.status = 'released' BEGIN SELECT RAISE(ABORT, 'disk full'); END");
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && $path === '/v1.0/me/messages' ? MailboxGraphFake::json([], 503) : null;

        try {
            $this->sender->send($this->ana, $this->mail(key: 'second-key-00001'));
            self::fail('Throttled.');
        } catch (MailboxProviderFailure $failure) {
            self::assertTrue($failure->isRetryable());
        }
        self::assertTrue($this->log->has('Mailbox send: the abandoned claim could not be recorded; it is taken over once stale.'));
        $this->mailboxConnection->executeStatement('DROP TRIGGER no_release');

        // The draft of a failed attempt can be neither read nor deleted: its id stays for the next holder.
        foreach (['GET' => 'isDraft', 'DELETE' => 'DRAFT-'] as $verb => $fragment) {
            $key                   = 'third-key-' . $verb . '-01';
            $this->graph->override = static function (string $method, string $path) use ($verb, $fragment): ?MockResponse {
                if ($method === 'POST' && str_contains($path, '/attachments')) {
                    return MailboxGraphFake::json([], 503);
                }
                if ($method === $verb && str_contains($path, $fragment)) {
                    return MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], $verb === 'GET' ? 401 : 500);
                }

                return null;
            };

            try {
                $this->sender->send($this->ana, $this->mail(key: $key, attachments: [OutgoingAttachment::fromString('a', 'a.txt')]));
                self::fail('Throttled.');
            } catch (MailboxProviderFailure) {
            }
            self::assertSame('released', $this->row($key)['status']);
            self::assertNotNull($this->row($key)['provider_message_id'], $verb);
            $this->mailboxStore->activate($this->ana);
        }

        // The token port fails while the state of an ambiguous submit is read: pending, never resent.
        $issued                     = 0;
        $this->graph->override      = static fn (string $method, string $path): ?MockResponse => $method === 'POST' && str_contains($path, '/send') ? MailboxGraphFake::json(['error' => ['code' => 'InvalidAuthenticationToken']], 401) : null;
        $this->ports->tokenOverride = static function () use (&$issued): ?MailboxAccessToken {
            if (++$issued > 1) {
                throw new MailboxTokenUnavailable();
            }

            return null;
        };

        try {
            $this->sender->send($this->ana, $this->mail(key: 'fourth-key-00001'));
            self::fail('Pending.');
        } catch (MailboxSendInProgress $pending) {
            self::assertTrue($pending->reconciling);
        }
        self::assertSame('sending', $this->row('fourth-key-00001')['status']);
        $this->ports->tokenOverride = null;

        // The account check of a reconcile is throttled: still pending.
        $this->clock->modify('+121 seconds');
        $this->graph->override = static fn (string $method, string $path): ?MockResponse => $method === 'GET' && str_starts_with($path, '/v1.0/me?') ? MailboxGraphFake::json([], 503) : null;

        try {
            $this->sender->send($this->ana, $this->mail(key: 'fourth-key-00001'));
            self::fail('Pending.');
        } catch (MailboxSendInProgress $pending) {
            self::assertTrue($pending->reconciling);
        }

        // Another worker wins the takeover between the read and the update.
        $this->clock->modify('+121 seconds');
        $this->graph->override = null;
        $this->mailboxConnection->executeStatement('CREATE TRIGGER lose_takeover BEFORE UPDATE ON mail_kit_mailbox_send WHEN NEW.attempts > OLD.attempts BEGIN SELECT RAISE(IGNORE); END');

        try {
            $this->sender->send($this->ana, $this->mail(key: 'fourth-key-00001'));
            self::fail('Lost the takeover.');
        } catch (MailboxSendInProgress $pending) {
            self::assertTrue($pending->reconciling);
        }
        $this->mailboxConnection->executeStatement('DROP TRIGGER lose_takeover');
        self::assertTrue($this->sender->send($this->ana, $this->mail(key: 'fourth-key-00001'))->replayed);

        // A sent copy of a paused mailbox is dropped.
        $this->mailboxStore->pauseByHost($this->ana);
        $this->bus->messages = [];
        $this->handler->sentCopy(new DeliverSentCopy('mbx-ana', 'DRAFT-9'));
        self::assertSame([], $this->bus->messages);
    }

    private function submits(): int
    {
        return count(array_filter($this->graph->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_ends_with($r['path'], '/send')));
    }

    /**
     * @return array{status: string, provider_message_id: string|null, attempts: int}
     */
    private function row(string $key = self::KEY): array
    {
        $row = $this->mailboxConnection->fetchAssociative('SELECT status, provider_message_id, attempts FROM mail_kit_mailbox_send WHERE idempotency_key = ?', [$key]);
        self::assertIsArray($row);

        return ['status' => (string) $row['status'], 'provider_message_id' => $row['provider_message_id'] !== null ? (string) $row['provider_message_id'] : null, 'attempts' => (int) $row['attempts']];
    }

    /**
     * @param list<OutgoingAttachment> $attachments
     * @param list<MailAddress> $cc
     * @param list<MailAddress> $bcc
     */
    private function mail(OutgoingKind $kind = OutgoingKind::New, string $key = self::KEY, ?string $subject = 'Plan', array $attachments = [], array $cc = [], array $bcc = [], ?string $html = null, string $text = 'Hello', string $inResponseTo = 'orig-1'): OutgoingMailboxMail
    {
        return new OutgoingMailboxMail(
            $kind,
            $kind === OutgoingKind::New ? null : new ProviderMessageId($inResponseTo),
            [new MailAddress('pedro@example.org', 'Pedro')],
            $cc,
            $bcc,
            $subject,
            $text,
            $html,
            $attachments,
            $key,
        );
    }
}
