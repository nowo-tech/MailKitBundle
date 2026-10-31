<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function base64_encode;
use function count;
use function htmlspecialchars;
use function implode;
use function is_string;
use function json_encode;
use function max;
use function mb_strcut;
use function mb_strtolower;
use function mb_substr;
use function preg_quote;
use function preg_replace;
use function rawurlencode;
use function str_replace;
use function trim;
use function urlencode;

use const ENT_HTML5;
use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Writes the sent-mail log with DBAL (never through the host unit of work, so logging cannot flush or roll back
 * application entities). Every write is best effort: a database error is logged as a warning and never breaks the
 * delivery.
 *
 * Privacy: envelope data only by default (subject, sender, recipients); bodies only with `log.store_bodies`, with the
 * {@see MailHeaders::REDACT} values masked. Errors are scrubbed of credentials ({@see ErrorScrubber}).
 */
final readonly class SentMailRecorder
{
    /** Masking text of redacted values. */
    public const REDACTED = '[redacted]';

    /** Cap of one stored body, in bytes. */
    public const BODY_MAX = 1_048_576;

    /**
     * @param string|null $entityManager entity manager name (null = default)
     * @param string $tablePrefix `doctrine.table_prefix`
     * @param bool $storeBodies `log.store_bodies`
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManager = null,
        private string $tablePrefix = '',
        private bool $storeBodies = false,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Inserts a "queued" row and returns its id (null when the insert failed).
     */
    public function queued(Message $message): ?string
    {
        return $this->insert($message, '', SentMailStatus::Queued, 0);
    }

    /**
     * Marks the row sent (or inserts one when there is no queued row).
     */
    public function sent(?string $id, RawMessage $message, string $transport, ?string $providerMessageId, ?int $size): void
    {
        $this->resolve($id, $message, $transport, SentMailStatus::Sent, [
            'status'              => SentMailStatus::Sent->value,
            'transport'           => mb_substr($transport, 0, 32),
            'provider_message_id' => $providerMessageId === null ? null : mb_substr($providerMessageId, 0, 255),
            'size'                => $size,
            'sent_at'             => $this->now(),
            'error'               => null,
        ], ['sent_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Marks the row failed with the (already public) error, scrubbed again and capped (or inserts one when there is no
     * queued row).
     */
    public function failed(?string $id, RawMessage $message, string $transport, string $error): void
    {
        $this->resolve($id, $message, $transport, SentMailStatus::Failed, [
            'status'    => SentMailStatus::Failed->value,
            'transport' => mb_substr($transport, 0, 32),
            'error'     => ErrorScrubber::scrub($error),
            'failed_at' => $this->now(),
        ], ['failed_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Deletes rows queued before the date in keyset batches; returns the number of deleted rows.
     */
    public function purgeBefore(DateTimeImmutable $before, int $batch = 1000): int
    {
        $batch      = max(1, $batch);
        $connection = $this->connection();
        $deleted    = 0;
        do {
            $ids = $connection->fetchFirstColumn(
                'SELECT id FROM ' . $this->table() . ' WHERE queued_at < ? ORDER BY queued_at, id LIMIT ' . $batch,
                [$before],
                [Types::DATETIME_IMMUTABLE],
            );
            $deleted += $this->deleteIds($connection, $ids);
        } while (count($ids) === $batch);

        return $deleted;
    }

    /**
     * Deletes every row addressed to the e-mail (to, cc or bcc) — right to erasure; returns the number of rows.
     */
    public function forgetRecipient(string $email): int
    {
        return $this->forgetAddress($email)['deleted'];
    }

    /**
     * Right to erasure (GDPR art. 17) for one address: deletes every row (and body) the address sent or received
     * (to, cc, bcc — normalised index, no JSON matching) and masks the address in the subjects and stored bodies of the
     * remaining rows. Works the same on SQLite, MySQL / MariaDB and PostgreSQL.
     *
     * @return array{deleted: int, redacted: int}
     */
    public function forgetAddress(string $email): array
    {
        $email = self::normalize($email);
        if ($email === '') {
            return ['deleted' => 0, 'redacted' => 0];
        }
        $connection = $this->connection();
        $ids        = $connection->fetchFirstColumn(
            'SELECT id FROM ' . $this->table() . " WHERE recipient_index LIKE ? ESCAPE '!' OR sender = ?",
            ['%' . self::likeEscape('|' . $email . '|') . '%', $email],
        );
        $deleted  = $this->deleteIds($connection, $ids);
        $pattern  = '%' . self::likeEscape($email) . '%';
        $redacted = 0;

        foreach ($connection->fetchAllAssociative('SELECT id, subject FROM ' . $this->table() . " WHERE LOWER(subject) LIKE ? ESCAPE '!'", [$pattern]) as $row) {
            $redacted += (int) $connection->update($this->table(), ['subject' => $this->mask((string) $row['subject'], $email)], ['id' => $row['id']]);
        }
        foreach ($connection->fetchAllAssociative('SELECT id, html_body, text_body FROM ' . $this->bodyTable() . " WHERE LOWER(html_body) LIKE ? ESCAPE '!' OR LOWER(text_body) LIKE ? ESCAPE '!'", [$pattern, $pattern]) as $row) {
            $redacted += (int) $connection->update($this->bodyTable(), [
                'html_body' => is_string($row['html_body']) ? $this->mask($row['html_body'], $email) : null,
                'text_body' => is_string($row['text_body']) ? $this->mask($row['text_body'], $email) : null,
            ], ['id' => $row['id']]);
        }

        return ['deleted' => $deleted, 'redacted' => $redacted];
    }

    /**
     * Normalised form of an address (trimmed, lowercase, multibyte-aware) — the form stored in the log.
     */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * `|a@x|b@y|` index of the to / cc / bcc addresses (search and erasure without JSON matching).
     *
     * @param array{to?: list<string>, cc?: list<string>, bcc?: list<string>} $recipients
     */
    public static function recipientIndex(array $recipients): string
    {
        $all = array_merge($recipients['to'] ?? [], $recipients['cc'] ?? [], $recipients['bcc'] ?? []);

        return $all === [] ? '' : '|' . implode('|', array_values(array_unique($all))) . '|';
    }

    /**
     * Prefixed log table name.
     */
    public function table(): string
    {
        return $this->tablePrefix . SentMail::TABLE;
    }

    /**
     * Prefixed body table name.
     */
    public function bodyTable(): string
    {
        return $this->tablePrefix . SentMailBody::TABLE;
    }

    /**
     * LIKE pattern escaping with `!` as the escape character.
     */
    public static function likeEscape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * @param list<mixed> $ids
     */
    private function deleteIds(Connection $connection, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $connection->executeStatement('DELETE FROM ' . $this->bodyTable() . ' WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);

        return (int) $connection->executeStatement('DELETE FROM ' . $this->table() . ' WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $types
     */
    private function resolve(?string $id, RawMessage $message, string $transport, SentMailStatus $status, array $values, array $types): void
    {
        if ($id === null) {
            $this->insert($message, $transport, $status, 1, $values, $types);

            return;
        }

        try {
            $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($values)));
            $updated     = $this->connection()->executeStatement(
                'UPDATE ' . $this->table() . ' SET ' . $assignments . ', attempts = attempts + 1 WHERE id = :id',
                $values + ['id' => $id],
                $types,
            );
            if ($updated === 0) {
                // The queued row was purged or never written: keep the outcome anyway.
                $this->insert($message, $transport, $status, 1, $values, $types);
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('Sent-mail log update failed.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);
        }
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $types
     */
    private function insert(RawMessage $message, string $transport, SentMailStatus $status, int $attempts, array $extra = [], array $types = []): ?string
    {
        $id = Uuid::v7()->toRfc4122();
        try {
            $connection = $this->connection();
            $recipients = $this->recipients($message);
            $connection->insert($this->table(), [
                'id'              => $id,
                'status'          => $status->value,
                'tag'             => $message instanceof Message ? MailHeaders::value($message, MailHeaders::TAG) : null,
                'subject'         => $message instanceof Email ? mb_substr((string) $message->getSubject(), 0, 255) : '',
                'sender'          => $message instanceof Email ? mb_substr($this->addresses($message->getFrom())[0] ?? '', 0, 255) : '',
                'recipients'      => json_encode($recipients, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'recipient_index' => self::recipientIndex($recipients),
                'transport'       => mb_substr($transport, 0, 32),
                'attempts'        => $attempts,
                'queued_at'       => $this->now(),
            ] + $extra, ['queued_at' => Types::DATETIME_IMMUTABLE] + $types);

            if ($this->storeBodies && $message instanceof Email) {
                $html = $this->body($message, $message->getHtmlBody());
                $text = $this->body($message, $message->getTextBody());
                if ($html !== null || $text !== null) {
                    $connection->insert($this->bodyTable(), ['id' => $id, 'html_body' => $html, 'text_body' => $text]);
                }
            }

            return $id;
        } catch (Throwable $exception) {
            $this->logger?->warning('Sent-mail log insert failed.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);

            return null;
        }
    }

    private function body(Email $message, mixed $body): ?string
    {
        if (!is_string($body)) {
            return null;
        }
        foreach (MailHeaders::secrets($message) as $secret) {
            // Literal, HTML-escaped, URL-encoded and base64 forms (CA I4).
            $body = str_replace(array_values(array_unique([$secret, htmlspecialchars($secret, ENT_QUOTES | ENT_HTML5), htmlspecialchars($secret), rawurlencode($secret), urlencode($secret), base64_encode($secret)])), self::REDACTED, $body);
        }

        return mb_strcut($body, 0, self::BODY_MAX);
    }

    /**
     * @return array{to?: list<string>, cc?: list<string>, bcc?: list<string>}
     */
    private function recipients(RawMessage $message): array
    {
        if (!$message instanceof Email) {
            return [];
        }

        return array_filter([
            'to'  => $this->addresses($message->getTo()),
            'cc'  => $this->addresses($message->getCc()),
            'bcc' => $this->addresses($message->getBcc()),
        ]);
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $address): string => self::normalize($address->getAddress()), $addresses));
    }

    private function mask(string $text, string $email): string
    {
        return (string) preg_replace('/' . preg_quote($email, '/') . '|' . preg_quote(rawurlencode($email), '/') . '/iu', self::REDACTED, $text);
    }

    private function connection(): Connection
    {
        $manager = $this->doctrine->getManager($this->entityManager);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('NowoMailKitBundle: the sent-mail log needs a Doctrine ORM entity manager.');
        }

        return $manager->getConnection();
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock ?? new NativeClock())->now();
    }
}
