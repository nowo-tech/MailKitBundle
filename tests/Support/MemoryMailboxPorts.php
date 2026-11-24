<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use Closure;
use DateTimeImmutable;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Port\MailboxCallObserverInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxDirectoryInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxExclusionPolicyInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxMessageSinkInterface;
use Nowo\MailKitBundle\Mailbox\Port\MailboxTokenProviderInterface;
use Nowo\MailKitBundle\Mailbox\Value\ExclusionDecision;
use Nowo\MailKitBundle\Mailbox\Value\FolderRef;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxProvider;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;
use Nowo\MailKitBundle\Mailbox\Value\MessageEnvelope;
use Nowo\MailKitBundle\Mailbox\Value\ProviderCall;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Mailbox\Value\RemovalReason;
use Nowo\MailKitBundle\Mailbox\Value\StoreOutcome;
use Nowo\MailKitBundle\Mailbox\Value\SyncedMessage;

use function array_map;
use function array_values;

/**
 * In-memory host ports of the delegated mailboxes (a DDD host's adapters in tests): token provider (fake tokens built
 * at runtime: `fake-<mailbox>-<n>`), directory, idempotent sink (unique by mailbox + provider id), exclusion policy
 * and call observer.
 */
final class MemoryMailboxPorts implements MailboxTokenProviderInterface, MailboxDirectoryInterface, MailboxMessageSinkInterface, MailboxExclusionPolicyInterface, MailboxCallObserverInterface
{
    /** @var array<string, MailboxSettings> */
    public array $mailboxes = [];

    /** @var array<string, int> tokens issued per mailbox */
    public array $issued = [];

    /** @var list<string> fingerprints reported as rejected */
    public array $rejectedTokens = [];

    /** @var array<string, GrantFailureReason> mailboxes whose grant is gone */
    public array $revoked = [];

    /** @var array<string, SyncedMessage> mailbox\nid => stored message */
    public array $stored = [];

    /** @var list<string> */
    public array $duplicates = [];

    /** @var list<string> mailbox\nid\nfolder */
    public array $removedMessages = [];

    /** @var list<MessageEnvelope> */
    public array $envelopes = [];

    /** @var list<ProviderCall> */
    public array $calls = [];

    /** @var (Closure(MessageEnvelope): ExclusionDecision)|null */
    public ?Closure $policy = null;

    /** @var (Closure(MailboxId): ?MailboxAccessToken)|null token override (throw to simulate the host's failures) */
    public ?Closure $tokenOverride = null;

    /**
     * @param list<string> $folders
     */
    public static function settings(string $id = 'mbx-ana', string $address = 'ana@example.com', array $folders = ['inbox'], bool $paused = false, ?string $window = null): MailboxSettings
    {
        return new MailboxSettings(new MailboxId($id), MailboxProvider::Graph, $address, array_values(array_map(static fn (string $key): FolderRef => new FolderRef($key), $folders)), $paused, $window);
    }

    public function add(MailboxSettings $settings): self
    {
        $this->mailboxes[$settings->id->value] = $settings;

        return $this;
    }

    public function accessToken(MailboxId $mailbox): MailboxAccessToken
    {
        if ($this->tokenOverride instanceof Closure) {
            $token = ($this->tokenOverride)($mailbox);
            if ($token instanceof MailboxAccessToken) {
                return $token;
            }
        }
        if (isset($this->revoked[$mailbox->value])) {
            throw new MailboxGrantFailure($this->revoked[$mailbox->value]);
        }
        $this->issued[$mailbox->value] = ($this->issued[$mailbox->value] ?? 0) + 1;

        return new MailboxAccessToken('fake-' . $mailbox->value . '-' . $this->issued[$mailbox->value], new DateTimeImmutable('+1 hour'), ['Mail.ReadWrite']);
    }

    public function rejected(MailboxId $mailbox, string $tokenFingerprint): void
    {
        $this->rejectedTokens[] = $tokenFingerprint;
    }

    public function mailbox(MailboxId $mailbox): ?MailboxSettings
    {
        return $this->mailboxes[$mailbox->value] ?? null;
    }

    public function activeMailboxes(MailboxProvider $provider): iterable
    {
        foreach ($this->mailboxes as $settings) {
            if ($settings->provider === $provider && !$settings->paused) {
                yield $settings->id;
            }
        }
    }

    public function findByAddress(MailboxProvider $provider, string $address): ?MailboxId
    {
        foreach ($this->mailboxes as $settings) {
            if ($settings->provider === $provider && $settings->address === $address) {
                return $settings->id;
            }
        }

        return null;
    }

    public function store(SyncedMessage $message): StoreOutcome
    {
        $key = $message->mailbox->value . "\n" . $message->id->value;
        if (isset($this->stored[$key])) {
            $this->duplicates[] = $key;

            return StoreOutcome::Duplicate;
        }
        $this->stored[$key] = $message;

        return StoreOutcome::Stored;
    }

    public function removed(MailboxId $mailbox, ProviderMessageId $message, FolderRef $folder, RemovalReason $reason): void
    {
        $this->removedMessages[] = $mailbox->value . "\n" . $message->value . "\n" . $folder->key . "\n" . $reason->value;
    }

    public function decide(MessageEnvelope $envelope): ExclusionDecision
    {
        $this->envelopes[] = $envelope;

        return $this->policy instanceof Closure ? ($this->policy)($envelope) : ExclusionDecision::keep();
    }

    public function called(ProviderCall $call): void
    {
        $this->calls[] = $call;
    }
}
