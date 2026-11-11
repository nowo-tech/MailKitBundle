<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Normalizer;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;
use Nowo\MailKitBundle\Event\SentMailPersonalDataErasedEvent;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function array_chunk;
use function array_fill;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function base64_encode;
use function count;
use function explode;
use function htmlspecialchars;
use function idn_to_ascii;
use function idn_to_utf8;
use function implode;
use function in_array;
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
use function strrpos;
use function substr;
use function trim;
use function urlencode;

use const ENT_HTML5;
use const ENT_QUOTES;
use const IDNA_DEFAULT;
use const IDNA_NONTRANSITIONAL_TO_ASCII;
use const INTL_IDNA_VARIANT_UTS46;
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
final readonly class SentMailRecorder implements SentMailErasureInterface, SentMailPersonalDataEraserInterface, SentMailPersonalDataExporterInterface
{
    /** Separator of the recipient index (unit separator: refused in addresses). */
    public const SEPARATOR = "\x1F";

    /** Masking text of redacted values. */
    public const REDACTED = '[redacted]';

    /** Cap of one stored body, in bytes. */
    public const BODY_MAX = 1_048_576;

    /**
     * Masking boundary (1.7): no letter, digit, `.`, `_`, `+` or `-` right before the address, so `na@example.com`
     * never masks part of `ana@example.com`. Rarer local-part characters (`/`, `=`, …) still count as a boundary:
     * `/unsubscribe/ana@example.com` and `?email=ana%40example.com` are masked.
     */
    private const MASK_BEFORE = '(?<![\\p{L}\\p{N}\\p{M}._+-])';

    /** Masking boundary (1.7): the domain does not go on (no label character, no `.label`) right after the address. */
    private const MASK_AFTER = '(?![\\p{L}\\p{N}\\p{M}_-]|\\.[\\p{L}\\p{N}])';

    /** Rows read or deleted per statement by the export and the erasure of one address (1.7). */
    public const PERSONAL_DATA_BATCH = 500;

    /**
     * @param string|null $entityManager entity manager name (null = default)
     * @param string $tablePrefix `doctrine.table_prefix`
     * @param bool $storeBodies `log.store_bodies`
     * @param bool $optionalTables true with `log.map_entities: false`: missing log tables mean nothing is stored;
     *                             otherwise erasure and retention fail with {@see SentMailLogTableMissingException}
     * @param EventDispatcherInterface|null $dispatcher receives {@see SentMailPersonalDataErasedEvent} (1.7)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?string $entityManager = null,
        private string $tablePrefix = '',
        private bool $storeBodies = false,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
        private bool $optionalTables = false,
        private ?EventDispatcherInterface $dispatcher = null,
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
     * Marks the row sent (or inserts one when there is no queued row); returns the row id (null when the write failed).
     */
    public function sent(?string $id, RawMessage $message, string $transport, ?string $providerMessageId, ?int $size): ?string
    {
        return $this->resolve($id, $message, $transport, SentMailStatus::Sent, [
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
     * queued row); returns the row id (null when the write failed).
     */
    public function failed(?string $id, RawMessage $message, string $transport, string $error): ?string
    {
        return $this->resolve($id, $message, $transport, SentMailStatus::Failed, [
            'status'    => SentMailStatus::Failed->value,
            'transport' => mb_substr($transport, 0, 32),
            'error'     => ErrorScrubber::scrub($error),
            'failed_at' => $this->now(),
        ], ['failed_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Deletes rows queued before the date in keyset batches; returns the number of deleted rows.
     *
     * @throws SentMailLogTableMissingException when a log table is missing and the entities are mapped (rows of the
     *                                          main table are deleted first when only the body table is missing)
     */
    public function purgeBefore(DateTimeImmutable $before, int $batch = 1000): int
    {
        $batch         = max(1, $batch);
        $connection    = $this->connection();
        [$main, $body] = $this->tables($connection);
        if (!$main) {
            return 0;
        }
        $deleted = 0;
        do {
            $ids = $connection->fetchFirstColumn(
                'SELECT id FROM ' . $this->table() . ' WHERE queued_at < ? ORDER BY queued_at, id LIMIT ' . $batch,
                [$before],
                [Types::DATETIME_IMMUTABLE],
            );
            $deleted += $this->deleteIds($connection, $ids, $body);
        } while (count($ids) === $batch);
        $this->assertBodyTable($body, $deleted, 0);

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
     * remaining rows. Works the same on SQLite, MySQL / MariaDB and PostgreSQL. Also with `log.enabled: false` (1.6):
     * rows written while the log was on stay erasable. Idempotent: a second run returns zero counts.
     *
     * 1.7: exact match — SQL only pre-filters, every row is re-checked in PHP against the normalised address (an
     * accent-insensitive MySQL collation must not erase `josé@` for `jose@`), then deleted by id in batches of
     * {@see self::PERSONAL_DATA_BATCH}. Transactional: without an open transaction on the log connection the erasure
     * runs in its own and {@see SentMailPersonalDataErasedEvent} is dispatched after the commit; inside the caller's
     * transaction it joins it and dispatches nothing (the returned counts are the outcome).
     *
     * @throws SentMailLogTableMissingException when a log table is missing and the entities are mapped (rows of the
     *                                          main table are erased first when only the body table is missing)
     *
     * @return array{deleted: int, redacted: int}
     */
    public function forgetAddress(string $email): array
    {
        $forms = self::forms($email);
        if ($forms === []) {
            return ['deleted' => 0, 'redacted' => 0];
        }
        $connection    = $this->connection();
        [$main, $body] = $this->tables($connection);
        if (!$main) {
            return ['deleted' => 0, 'redacted' => 0];
        }

        if ($connection->isTransactionActive()) {
            // The caller's transaction may still roll back: no event, the counts are the outcome.
            $result = $this->eraseRows($connection, $forms, $body);
        } else {
            // @igor-ignore - Closure runs synchronously inside transactional(); it is not stored on the service.
            $result = $connection->transactional(fn (Connection $connection): array => $this->eraseRows($connection, $forms, $body));
            $this->dispatcher?->dispatch(new SentMailPersonalDataErasedEvent($forms[0], $result['deleted'], $result['redacted']));
        }
        $this->assertBodyTable($body, $result['deleted'], $result['redacted']);

        return $result;
    }

    /**
     * {@see self::forgetAddress()} for a validated subject (1.7): same matching, transaction and event semantics.
     *
     * @throws SentMailLogTableMissingException when a log table is missing and the entities are mapped
     */
    public function erase(SentMailPersonalDataSubject $subject): SentMailErasureResult
    {
        $result = $this->forgetAddress($subject->email);

        return new SentMailErasureResult($result['deleted'], $result['redacted']);
    }

    /**
     * Rows the address received (to, cc, bcc) or sent (1.7, GDPR art. 15 / 20): envelope only, exact match (SQL
     * pre-filter, PHP re-check), read in id batches of {@see self::PERSONAL_DATA_BATCH}.
     *
     * @throws SentMailLogTableMissingException when the log table is missing and the entities are mapped
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null}>
     */
    public function export(SentMailPersonalDataSubject $subject): array
    {
        $connection = $this->connection();
        [$main]     = $this->tables($connection);
        if (!$main) {
            return [];
        }

        $rows = [];
        foreach ($this->rowsOf($connection, $subject->forms(), ', status, tag, subject, queued_at, sent_at, failed_at') as [$row, $roles]) {
            $rows[] = [
                'id'        => (string) $row['id'],
                'roles'     => $roles,
                'status'    => (string) $row['status'],
                'template'  => is_string($row['tag']) ? $row['tag'] : null,
                'subject'   => (string) $row['subject'],
                'queued_at' => $this->atom($connection, $row['queued_at']),
                'sent_at'   => $this->atom($connection, $row['sent_at']),
                'failed_at' => $this->atom($connection, $row['failed_at']),
            ];
        }

        return $rows;
    }

    /**
     * Normalised form of an address (trimmed, lowercase, multibyte-aware) — the form stored in the log.
     */
    public static function normalize(string $email): string
    {
        $email = trim($email);
        $email = Normalizer::normalize($email, Normalizer::FORM_C);
        $email = mb_strtolower(is_string($email) ? $email : '');
        $at    = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $domain = idn_to_utf8(substr($email, $at + 1), IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return substr($email, 0, $at + 1) . (is_string($domain) && $domain !== '' ? $domain : substr($email, $at + 1));
    }

    /**
     * Normalised form with the domain in punycode (`user@xn--bcher-kva.de`).
     */
    public static function asciiForm(string $email): string
    {
        $email = self::normalize($email);
        $at    = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $domain = idn_to_ascii(substr($email, $at + 1), IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return substr($email, 0, $at + 1) . (is_string($domain) && $domain !== '' ? strtolower($domain) : substr($email, $at + 1));
    }

    /**
     * Unicode and punycode forms of an address (one when they are equal), empty for a blank input.
     *
     * @return list<string>
     */
    public static function forms(string $email): array
    {
        $display = self::normalize($email);

        return $display === '' ? [] : array_values(array_unique([$display, self::asciiForm($email)]));
    }

    /**
     * Index of the to / cc / bcc addresses (search and erasure without JSON matching): each address in its Unicode and
     * punycode forms, wrapped in {@see self::SEPARATOR} (a control character no address can contain, so `bob@x.com` never
     * matches `a|bob@x.com`).
     *
     * @param array{to?: list<string>, cc?: list<string>, bcc?: list<string>} $recipients
     */
    public static function recipientIndex(array $recipients): string
    {
        $all = array_merge($recipients['to'] ?? [], $recipients['cc'] ?? [], $recipients['bcc'] ?? []);

        $forms = [];
        foreach ($all as $address) {
            foreach (self::forms($address) as $form) {
                $forms[] = $form;
            }
        }

        return $forms === [] ? '' : self::SEPARATOR . implode(self::SEPARATOR, array_values(array_unique($forms))) . self::SEPARATOR;
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
     * Deletes the rows of the address and masks it elsewhere (inside the transaction of {@see self::forgetAddress()}).
     *
     * @param list<string> $forms
     *
     * @return array{deleted: int, redacted: int}
     */
    private function eraseRows(Connection $connection, array $forms, bool $body): array
    {
        $ids = [];
        foreach ($this->rowsOf($connection, $forms) as [$row]) {
            $ids[] = (string) $row['id'];
        }
        $deleted = 0;
        foreach (array_chunk($ids, self::PERSONAL_DATA_BATCH) as $batch) {
            $deleted += $this->deleteIds($connection, $batch, $body);
        }

        $redacted = 0;
        foreach ($forms as $form) {
            $pattern = '%' . self::likeEscape($form) . '%';
            foreach ($connection->fetchAllAssociative('SELECT id, subject FROM ' . $this->table() . " WHERE LOWER(subject) LIKE ? ESCAPE '!'", [$pattern]) as $row) {
                $masked = $this->mask((string) $row['subject'], $forms);
                // The LIKE is only a pre-filter (case / accent-insensitive collations): count real changes only.
                if ($masked !== $row['subject']) {
                    $redacted += (int) $connection->update($this->table(), ['subject' => $masked], ['id' => $row['id']]);
                }
            }
            $bodies = $body ? $connection->fetchAllAssociative('SELECT id, html_body, text_body FROM ' . $this->bodyTable() . " WHERE LOWER(html_body) LIKE ? ESCAPE '!' OR LOWER(text_body) LIKE ? ESCAPE '!'", [$pattern, $pattern]) : [];
            foreach ($bodies as $row) {
                $html = is_string($row['html_body']) ? $this->mask($row['html_body'], $forms) : null;
                $text = is_string($row['text_body']) ? $this->mask($row['text_body'], $forms) : null;
                if ($html !== $row['html_body'] || $text !== $row['text_body']) {
                    $redacted += (int) $connection->update($this->bodyTable(), ['html_body' => $html, 'text_body' => $text], ['id' => $row['id']]);
                }
            }
        }

        return ['deleted' => $deleted, 'redacted' => $redacted];
    }

    /**
     * Rows whose recipient index or sender holds one of the forms, with the roles of the address: SQL pre-filter
     * (index LIKE, sender IN), then an exact re-check in PHP (`===` on the index entries and the sender), in id batches.
     *
     * @param list<string> $forms
     * @param string $columns extra columns, each preceded by a comma
     *
     * @return list<array{array<string, mixed>, list<'recipient'|'sender'>}>
     */
    private function rowsOf(Connection $connection, array $forms, string $columns = ''): array
    {
        $where  = [];
        $params = [];
        foreach ($forms as $form) {
            $where[]  = "recipient_index LIKE ? ESCAPE '!'";
            $params[] = '%' . self::likeEscape(self::SEPARATOR . $form . self::SEPARATOR) . '%';
        }
        $where[] = 'sender IN (' . implode(', ', array_fill(0, count($forms), '?')) . ')';
        $sql     = 'SELECT id, recipient_index, sender' . $columns . ' FROM ' . $this->table() . ' WHERE (' . implode(' OR ', $where) . ') AND id > ? ORDER BY id LIMIT ' . self::PERSONAL_DATA_BATCH;

        $rows = [];
        $last = '';
        do {
            $page = $connection->fetchAllAssociative($sql, [...$params, ...$forms, $last]);
            foreach ($page as $row) {
                $last  = (string) $row['id'];
                $roles = $this->roles($row, $forms);
                if ($roles !== []) {
                    $rows[] = [$row, $roles];
                }
            }
        } while (count($page) === self::PERSONAL_DATA_BATCH);

        return $rows;
    }

    /**
     * Exact roles of the address in one row: `recipient` when one form is an entry of the index, `sender` when the
     * sender is one of the forms.
     *
     * @param array<string, mixed> $row
     * @param list<string> $forms
     *
     * @return list<'recipient'|'sender'>
     */
    private function roles(array $row, array $forms): array
    {
        $entries = explode(self::SEPARATOR, (string) $row['recipient_index']);
        $roles   = [];
        foreach ($forms as $form) {
            if (in_array($form, $entries, true)) {
                $roles[] = 'recipient';

                break;
            }
        }
        if (in_array((string) $row['sender'], $forms, true)) {
            $roles[] = 'sender';
        }

        return $roles;
    }

    private function atom(Connection $connection, mixed $value): ?string
    {
        $date = $value === null ? null : $connection->convertToPHPValue($value, Types::DATETIME_IMMUTABLE);

        return $date instanceof DateTimeInterface ? $date->format(DateTimeInterface::ATOM) : null;
    }

    /**
     * @param list<mixed> $ids
     */
    private function deleteIds(Connection $connection, array $ids, bool $bodyTable = true): int
    {
        if ($ids === []) {
            return 0;
        }
        if ($bodyTable) {
            $connection->executeStatement('DELETE FROM ' . $this->bodyTable() . ' WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
        }

        return (int) $connection->executeStatement('DELETE FROM ' . $this->table() . ' WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $types
     */
    private function resolve(?string $id, RawMessage $message, string $transport, SentMailStatus $status, array $values, array $types): ?string
    {
        if ($id === null) {
            return $this->insert($message, $transport, $status, 1, $values, $types);
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
                return $this->insert($message, $transport, $status, 1, $values, $types);
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('Sent-mail log update failed.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);

            return null;
        }

        return $id;
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

    /**
     * @param list<string> $forms
     */
    private function mask(string $text, array $forms): string
    {
        $alternatives = [];
        foreach ($forms as $form) {
            $alternatives[] = preg_quote($form, '/');
            $alternatives[] = preg_quote(rawurlencode($form), '/');
        }

        // Whole addresses only (1.7): erasing `na@example.com` must not mask part of `ana@example.com` or
        // `na@example.com.au`, which belong to other people.
        return (string) preg_replace('/' . self::MASK_BEFORE . '(?:' . implode('|', $alternatives) . ')' . self::MASK_AFTER . '/iu', self::REDACTED, $text);
    }

    /**
     * Which log tables exist, checked one by one: `[main, body]`. With the entities mapped a missing main table fails
     * here (nothing can be erased); with `log.map_entities: false` missing tables mean nothing is stored.
     *
     * @throws SentMailLogTableMissingException
     *
     * @return array{bool, bool}
     */
    private function tables(Connection $connection): array
    {
        $schema = $connection->createSchemaManager();
        $main   = $schema->tablesExist([$this->table()]);
        $body   = $schema->tablesExist([$this->bodyTable()]);
        if (!$main && !$this->optionalTables) {
            throw new SentMailLogTableMissingException($body ? [$this->table()] : [$this->table(), $this->bodyTable()]);
        }

        return [$main, $body];
    }

    /**
     * After the main-table rows were handled: a missing body table fails (stored bodies may hold personal data),
     * except with `log.map_entities: false`.
     *
     * @throws SentMailLogTableMissingException
     */
    private function assertBodyTable(bool $body, int $deleted, int $redacted): void
    {
        if (!$body && !$this->optionalTables) {
            throw new SentMailLogTableMissingException([$this->bodyTable()], $deleted, $redacted);
        }
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
