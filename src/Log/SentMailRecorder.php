<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Normalizer;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;
use Nowo\MailKitBundle\Event\SentMailPersonalDataErasedEvent;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
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
use function array_intersect;
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
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mb_strcut;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function rawurlencode;
use function str_contains;
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
final readonly class SentMailRecorder implements SentMailErasureInterface, SentMailPersonalDataEraserInterface, SentMailPersonalDataExporterInterface, SentMailTaggedPersonalDataEraserInterface, SentMailTaggedPersonalDataExporterInterface
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
     * Marks the row sent (or inserts one when the message was never queued, id null); returns the row id (null when the
     * write failed, or when the queued row was erased or purged meanwhile: it is not written back, 2.8.1).
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
     * queued row, id null); returns the row id (null when the write failed, or when the queued row was erased or purged
     * meanwhile: it is not written back, 2.8.1).
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
     * Right to erasure for one address ({@see self::forgetAddress()}); returns the number of rows the address was
     * removed from (rows deleted plus rows unlinked, 1.7).
     */
    public function forgetRecipient(string $email): int
    {
        $result = $this->forgetAddress($email);

        return $result['deleted'] + $result['unlinked'];
    }

    /**
     * Right to erasure (GDPR art. 17) for one address, in every row the address sent or received (to, cc, bcc —
     * normalised index, no JSON matching). 1.7 (behaviour change, shared rows): a row that still has another recipient
     * is kept and **unlinked** — the address is removed from the recipient list and index, a matching sender is
     * replaced by {@see self::REDACTED}, the address is masked in the subject — and only a row with no other recipient
     * left is **deleted** (with its body). The address is also masked in the subjects and stored bodies of every
     * remaining row (`redacted`). Works the same on SQLite, MySQL / MariaDB and PostgreSQL. Also with
     * `log.enabled: false` (1.6): rows written while the log was on stay erasable. Idempotent: a second run returns
     * zero counts.
     *
     * 1.7: exact match — SQL only pre-filters, every row is re-checked in PHP against the normalised address (an
     * accent-insensitive MySQL collation must not erase `josé@` for `jose@`), then deleted by id in batches of
     * {@see self::PERSONAL_DATA_BATCH}. Transactional: without an open transaction on the log connection the erasure
     * runs in its own and {@see SentMailPersonalDataErasedEvent} is dispatched after the commit; inside the caller's
     * transaction it joins it and dispatches nothing (the returned counts are the outcome).
     *
     * 2.8.1: every row is re-read under a row lock (`SELECT … FOR UPDATE` on MySQL / MariaDB and PostgreSQL) before it
     * is written back, so two concurrent erasures of people who share a row never restore each other's address. Its
     * own transaction is a transaction-doctrine unit: a deadlock or lock wait timeout rolls it back and is thrown as a
     * `TransientStorageFailure` (nothing committed: run it again).
     *
     * @throws SentMailLogTableMissingException when a log table is missing and the entities are mapped (rows of the
     *                                          main table are erased first when only the body table is missing)
     *
     * @return array{deleted: int, unlinked: int, redacted: int}
     */
    public function forgetAddress(string $email): array
    {
        return $this->forget(self::forms($email), null);
    }

    /**
     * {@see self::forgetAddress()} for a validated subject (1.7): same matching, transaction and event semantics.
     *
     * @throws SentMailLogTableMissingException when a log table is missing and the entities are mapped
     */
    public function erase(SentMailPersonalDataSubject $subject): SentMailErasureResult
    {
        $result = $this->forgetAddress($subject->email);

        return new SentMailErasureResult($result['deleted'], $result['redacted'], $result['unlinked']);
    }

    /**
     * {@see self::erase()} limited to the rows with exactly this tag (2.8): rows with another tag or none, and their
     * subjects and bodies, are never touched. Same matching, transaction and event semantics (the event carries the
     * tag).
     *
     * @throws InvalidArgumentException for an invalid tag ({@see self::tagFilter()})
     * @throws SentMailLogTableMissingException when a log table is missing and the entities are mapped
     */
    public function eraseTagged(SentMailPersonalDataSubject $subject, string $tag): SentMailErasureResult
    {
        $result = $this->forget($subject->forms(), self::tagFilter($tag));

        return new SentMailErasureResult($result['deleted'], $result['redacted'], $result['unlinked']);
    }

    /**
     * Rows the address received (to, cc, bcc) or sent (1.7, GDPR art. 15 / 20): envelope only, exact match (SQL
     * pre-filter, PHP re-check), read in id batches of {@see self::PERSONAL_DATA_BATCH}.
     *
     * @throws SentMailLogTableMissingException when the log table is missing and the entities are mapped
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null, error: string|null, html_body: string|null, text_body: string|null}>
     */
    public function export(SentMailPersonalDataSubject $subject): array
    {
        return $this->exportRows($subject->forms(), null);
    }

    /**
     * {@see self::export()} limited to the rows with exactly this tag (2.8).
     *
     * @throws InvalidArgumentException for an invalid tag ({@see self::tagFilter()})
     * @throws SentMailLogTableMissingException when the log table is missing and the entities are mapped
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null, error: string|null, html_body: string|null, text_body: string|null}>
     */
    public function exportTagged(SentMailPersonalDataSubject $subject, string $tag): array
    {
        return $this->exportRows($subject->forms(), self::tagFilter($tag));
    }

    /**
     * The tag a tag-scoped erasure or export matches (2.8): trimmed like {@see MailHeaders::tag()} stores it, and
     * refused instead of truncated, so a caller never matches a shorter tag than the one it asked for.
     *
     * @throws InvalidArgumentException for an empty tag, control characters, invalid UTF-8 or more than
     *                                  {@see MailHeaders::TAG_MAX} characters
     */
    public static function tagFilter(string $tag): string
    {
        // Before trimming: trim() also strips NUL and other control characters at the edges.
        if (preg_match('//u', $tag) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $tag) === 1) {
            throw new InvalidArgumentException('A sent-mail log tag must be valid UTF-8 without control characters.');
        }
        $tag = trim($tag);
        if ($tag === '' || preg_match('/[\x00-\x1F]/', $tag) === 1) {
            throw new InvalidArgumentException('A sent-mail log tag must not be empty or hold line breaks or tabs.');
        }
        if (mb_strlen($tag) > MailHeaders::TAG_MAX) {
            throw new InvalidArgumentException('A sent-mail log tag must not be longer than 64 characters.');
        }

        return $tag;
    }

    /**
     * Export rows of the forms, all of them or only those with the tag.
     *
     * @param list<string> $forms
     *
     * @return list<array{id: string, roles: list<'recipient'|'sender'>, status: string, template: string|null, subject: string, queued_at: string|null, sent_at: string|null, failed_at: string|null, error: string|null, html_body: string|null, text_body: string|null}>
     */
    private function exportRows(array $forms, ?string $tag): array
    {
        $connection    = $this->connection();
        [$main, $body] = $this->tables($connection);
        if (!$main) {
            return [];
        }

        $rows   = [];
        $source = [];
        foreach ($this->rowsOf($connection, $forms, ', status, tag, subject, queued_at, sent_at, failed_at, recipients, error', $tag) as [$row, $roles]) {
            $rows[(string) $row['id']] = [
                'id'        => (string) $row['id'],
                'roles'     => $roles,
                'status'    => (string) $row['status'],
                'template'  => is_string($row['tag']) ? $row['tag'] : null,
                'subject'   => (string) $row['subject'],
                'queued_at' => $this->atom($connection, $row['queued_at']),
                'sent_at'   => $this->atom($connection, $row['sent_at']),
                'failed_at' => $this->atom($connection, $row['failed_at']),
                // 2.8.1 (ZDL-MK-L3): the error and the stored body are data held about the subject too; the other
                // people of the row stay out (their addresses are masked, the recipient list is never exported).
                'error'     => is_string($row['error']) ? $this->maskOthers($row['error'], $row, $forms) : null,
                'html_body' => null,
                'text_body' => null,
            ];
            $source[(string) $row['id']] = $row;
        }
        if ($body && $rows !== []) {
            foreach (array_chunk(array_keys($rows), self::PERSONAL_DATA_BATCH) as $batch) {
                foreach ($connection->fetchAllAssociative('SELECT id, html_body, text_body FROM ' . $this->bodyTable() . ' WHERE id IN (?)', [$batch], [ArrayParameterType::STRING]) as $stored) {
                    $id = (string) $stored['id'];
                    // `id IN (?)` may widen under a case-insensitive collation: only the exact ids.
                    if (isset($rows[$id], $source[$id])) {
                        $rows[$id]['html_body'] = is_string($stored['html_body']) ? $this->maskOthers($stored['html_body'], $source[$id], $forms) : null;
                        $rows[$id]['text_body'] = is_string($stored['text_body']) ? $this->maskOthers($stored['text_body'], $source[$id], $forms) : null;
                    }
                }
            }
        }

        return array_values($rows);
    }

    /**
     * The text with the addresses of the row's other people (recipients and sender that are not the subject's forms)
     * masked (2.8.1, export of the error and the stored body).
     *
     * @param array<string, mixed> $row
     * @param list<string> $forms
     */
    private function maskOthers(string $text, array $row, array $forms): string
    {
        $recipients = $this->decodeRecipients($row['recipients']);
        $addresses  = array_merge($recipients['to'] ?? [], $recipients['cc'] ?? [], $recipients['bcc'] ?? [], [(string) $row['sender']]);
        $others     = [];
        foreach ($addresses as $address) {
            $own = self::forms($address);
            if (array_intersect($own, $forms) === []) {
                $others = [...$others, ...$own];
            }
        }
        // A blank or redacted sender has no address to mask.
        $others = array_values(array_unique(array_filter($others, static fn (string $form): bool => str_contains($form, '@'))));

        return $others === [] ? $text : $this->mask($text, $others);
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
     * Erasure of {@see self::forgetAddress()} (tag null) and {@see self::eraseTagged()} (only rows with the tag).
     *
     * @param list<string> $forms
     *
     * @throws SentMailLogTableMissingException
     *
     * @return array{deleted: int, unlinked: int, redacted: int}
     */
    private function forget(array $forms, ?string $tag): array
    {
        if ($forms === []) {
            return ['deleted' => 0, 'unlinked' => 0, 'redacted' => 0];
        }
        $connection    = $this->connection();
        [$main, $body] = $this->tables($connection);
        if (!$main) {
            return ['deleted' => 0, 'unlinked' => 0, 'redacted' => 0];
        }

        if ($connection->isTransactionActive()) {
            // The caller's transaction may still roll back: no event, the counts are the outcome.
            $result = $this->eraseRows($connection, $forms, $body, $tag);
        } else {
            // 2.8.1: one transaction-doctrine unit (REQ-RUNTIME-003): a lock conflict on the locked rows (ZDL-MK-L1) is
            // rolled back and rethrown as a TransientStorageFailure (CONFLICT_001: nothing committed, a retry is safe).
            // @igor-ignore - Closure runs synchronously inside run(); it is not stored on the service.
            $result = DoctrineTransactionRunner::forConnection($connection, null, $this->logger)->run(fn (): array => $this->eraseRows($connection, $forms, $body, $tag));
            $this->dispatcher?->dispatch(new SentMailPersonalDataErasedEvent($forms[0], $result['deleted'], $result['redacted'], $result['unlinked'], $tag));
        }
        $this->assertBodyTable($body, $result['deleted'], $result['redacted']);

        return $result;
    }

    /**
     * Unlinks the address from the rows other people still share, deletes the rows nobody else is left in, and masks
     * the address elsewhere (inside the transaction of {@see self::forgetAddress()}). With a tag (2.8) every step is
     * limited to the rows with exactly that tag.
     *
     * @param list<string> $forms
     *
     * @return array{deleted: int, unlinked: int, redacted: int}
     */
    private function eraseRows(Connection $connection, array $forms, bool $body, ?string $tag = null): array
    {
        $ids      = [];
        $unlinked = 0;
        // 2.8.1 (ZDL-MK-L1): every row is re-read under a row lock before it is written back, so two erasures of
        // people who share a row never restore each other's address from a stale read.
        foreach ($this->rowsOf($connection, $forms, ', recipients, subject, error', $tag, true) as [$row, $roles]) {
            $recipients = $this->withoutAddress($this->decodeRecipients($row['recipients']), $forms);
            if ($recipients === []) {
                // Nobody else is left in this row (sole recipient, or own message to self): delete it.
                $ids[] = (string) $row['id'];

                continue;
            }
            $values = [
                'recipients'      => json_encode($recipients, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'recipient_index' => self::recipientIndex($recipients),
                'subject'         => $this->mask((string) $row['subject'], $forms),
                // LY-D-L1: a bounce kept on a shared row (`550 <x@…>`) may name the erased address.
                'error' => is_string($row['error']) ? $this->mask($row['error'], $forms) : null,
            ];
            if (in_array('sender', $roles, true)) {
                $values['sender'] = self::REDACTED;
            }
            $unlinked += (int) $connection->update($this->table(), $values, ['id' => $row['id']]);
        }
        $deleted = 0;
        foreach (array_chunk($ids, self::PERSONAL_DATA_BATCH) as $batch) {
            $deleted += $this->deleteIds($connection, $batch, $body);
        }

        $redacted = 0;
        // 2.8: with a tag only the rows (and bodies) carrying it; the PHP `===` re-check below keeps a case- or
        // pad-insensitive collation from widening the scope.
        $mainScope = $tag === null ? '' : ' AND tag = ?';
        $bodyScope = $tag === null ? '' : ' AND id IN (SELECT id FROM ' . $this->table() . ' WHERE tag = ?)';
        $scope     = $tag === null ? [] : [$tag];
        foreach ($forms as $form) {
            $pattern = '%' . self::likeEscape($form) . '%';
            // The LIKE only picks the candidates; their values are re-read under a row lock (2.8.1).
            $candidates = $this->withTag($connection->fetchAllAssociative('SELECT id, tag FROM ' . $this->table() . " WHERE (LOWER(subject) LIKE ? ESCAPE '!' OR LOWER(error) LIKE ? ESCAPE '!')" . $mainScope, [$pattern, $pattern, ...$scope]), $tag, 'tag');
            foreach ($this->locked($connection, $this->table(), 'id, tag, subject, error', $candidates) as $row) {
                $subject = $this->mask((string) $row['subject'], $forms);
                $error   = is_string($row['error']) ? $this->mask($row['error'], $forms) : null;
                // The LIKE is only a pre-filter (case / accent-insensitive collations): count real changes only.
                if ($subject !== $row['subject'] || $error !== $row['error']) {
                    $redacted += (int) $connection->update($this->table(), ['subject' => $subject, 'error' => $error], ['id' => $row['id']]);
                }
            }
            $bodies = $body ? $connection->fetchFirstColumn('SELECT id FROM ' . $this->bodyTable() . " WHERE (LOWER(html_body) LIKE ? ESCAPE '!' OR LOWER(text_body) LIKE ? ESCAPE '!')" . $bodyScope, [$pattern, $pattern, ...$scope]) : [];
            $bodies = array_map(static fn (mixed $id): string => (string) $id, $bodies);
            if ($tag !== null && $bodies !== []) {
                $bodies = $this->idsTagged($connection, $bodies, $tag);
            }
            foreach ($this->locked($connection, $this->bodyTable(), 'id, html_body, text_body', array_map(static fn (string $id): array => ['id' => $id], $bodies)) as $row) {
                $html = is_string($row['html_body']) ? $this->mask($row['html_body'], $forms) : null;
                $text = is_string($row['text_body']) ? $this->mask($row['text_body'], $forms) : null;
                if ($html !== $row['html_body'] || $text !== $row['text_body']) {
                    $redacted += (int) $connection->update($this->bodyTable(), ['html_body' => $html, 'text_body' => $text], ['id' => $row['id']]);
                }
            }
        }

        return ['deleted' => $deleted, 'unlinked' => $unlinked, 'redacted' => $redacted];
    }

    /**
     * Current values of the candidate rows, read by id under a row lock (`FOR UPDATE` on MySQL / MariaDB and
     * PostgreSQL; SQLite locks the whole database for a writer and has no row lock), in id order and batches of
     * {@see self::PERSONAL_DATA_BATCH}, inside the erasure's transaction (2.8.1, ZDL-MK-L1). Rows deleted since the
     * pre-filter are gone; only exact ids are kept (a case-insensitive collation may widen `id IN (?)`).
     *
     * @param list<array<string, mixed>> $candidates rows with an `id`
     *
     * @return list<array<string, mixed>>
     */
    private function locked(Connection $connection, string $table, string $columns, array $candidates): array
    {
        $ids      = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['id'], $candidates)));
        $platform = $connection->getDatabasePlatform();
        $lock     = $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform ? ' FOR UPDATE' : '';
        $rows     = [];
        foreach (array_chunk($ids, self::PERSONAL_DATA_BATCH) as $batch) {
            foreach ($connection->fetchAllAssociative('SELECT ' . $columns . ' FROM ' . $table . ' WHERE id IN (?) ORDER BY id' . $lock, [$batch], [ArrayParameterType::STRING]) as $row) {
                if (in_array((string) $row['id'], $batch, true)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Stored recipient lists (`{"to": [...], "cc": [...], "bcc": [...]}`, normalised addresses); empty when unreadable.
     *
     * @return array{to?: list<string>, cc?: list<string>, bcc?: list<string>}
     */
    private function decodeRecipients(mixed $json): array
    {
        $decoded    = is_string($json) ? json_decode($json, true) : null;
        $recipients = [];
        foreach (['to', 'cc', 'bcc'] as $kind) {
            $list = is_array($decoded) ? ($decoded[$kind] ?? null) : null;
            if (is_array($list)) {
                $recipients[$kind] = array_values(array_filter($list, is_string(...)));
            }
        }

        return $recipients;
    }

    /**
     * The recipient lists without the address (exact: an entry goes only when one of its forms is one of the
     * address's forms), empty lists dropped as on write.
     *
     * @param array{to?: list<string>, cc?: list<string>, bcc?: list<string>} $recipients
     * @param list<string> $forms
     *
     * @return array{to?: list<string>, cc?: list<string>, bcc?: list<string>}
     */
    private function withoutAddress(array $recipients, array $forms): array
    {
        $kept = [];
        foreach ($recipients as $kind => $list) {
            $kept[$kind] = array_values(array_filter($list, static fn (string $address): bool => array_intersect(self::forms($address), $forms) === []));
        }

        return array_filter($kept);
    }

    /**
     * Rows whose recipient index or sender holds one of the forms, with the roles of the address: SQL pre-filter
     * (index LIKE, sender IN), then an exact re-check in PHP (`===` on the index entries and the sender), in id batches.
     *
     * With a tag (2.8) only rows whose tag is exactly that one (SQL `tag = ?`, then `===` in PHP). With `$lock`
     * (erasure, 2.8.1) the pre-filter reads only the ids and each page is re-read under a row lock ({@see self::locked()}).
     *
     * @param list<string> $forms
     * @param string $columns extra columns, each preceded by a comma
     *
     * @return list<array{array<string, mixed>, list<'recipient'|'sender'>}>
     */
    private function rowsOf(Connection $connection, array $forms, string $columns = '', ?string $tag = null, bool $lock = false): array
    {
        $where  = [];
        $params = [];
        foreach ($forms as $form) {
            $where[]  = "recipient_index LIKE ? ESCAPE '!'";
            $params[] = '%' . self::likeEscape(self::SEPARATOR . $form . self::SEPARATOR) . '%';
        }
        $where[] = 'sender IN (' . implode(', ', array_fill(0, count($forms), '?')) . ')';
        $select  = 'id, recipient_index, sender, tag AS row_tag' . $columns;
        $sql     = 'SELECT ' . ($lock ? 'id, tag AS row_tag' : $select) . ' FROM ' . $this->table() . ' WHERE (' . implode(' OR ', $where) . ')' . ($tag === null ? '' : ' AND tag = ?') . ' AND id > ? ORDER BY id LIMIT ' . self::PERSONAL_DATA_BATCH;
        $scope   = $tag === null ? [] : [$tag];

        $rows = [];
        $last = '';
        do {
            $page  = $connection->fetchAllAssociative($sql, [...$params, ...$forms, ...$scope, $last]);
            $last  = $page === [] ? $last : (string) $page[count($page) - 1]['id'];
            $found = $this->withTag($page, $tag, 'row_tag');
            if ($lock) {
                // 2.8.1 (ZDL-MK-L1): the roles and the values come from the row re-read under its lock.
                $found = $this->withTag($this->locked($connection, $this->table(), $select, $found), $tag, 'row_tag');
            }
            foreach ($found as $row) {
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

    /**
     * The ids among the given ones whose tag is exactly the tag (PHP `===` re-check of a SQL pre-filter, 2.8).
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function idsTagged(Connection $connection, array $ids, string $tag): array
    {
        $owned = [];
        foreach (array_chunk($ids, self::PERSONAL_DATA_BATCH) as $batch) {
            foreach ($this->withTag($connection->fetchAllAssociative('SELECT id, tag FROM ' . $this->table() . ' WHERE id IN (?)', [$batch], [ArrayParameterType::STRING]), $tag, 'tag') as $row) {
                $owned[] = (string) $row['id'];
            }
        }

        return $owned;
    }

    /**
     * The rows whose tag column is exactly the tag (all of them without a tag, 2.8): the `===` re-check of a SQL
     * `tag = ?` pre-filter that a case- or pad-insensitive collation may have widened.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withTag(array $rows, ?string $tag, string $column): array
    {
        return $tag === null ? $rows : array_values(array_filter($rows, static fn (array $row): bool => $row[$column] === $tag));
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
                // 2.8.1 (ZDL-MK-L2): the queued row was written and later removed (an erasure or a purge while the
                // message waited in a queue or a retry). Writing it back would undo the erasure: nothing is stored.
                $this->logger?->debug('Sent-mail log row removed while the message was queued; outcome not recorded.', ['bundle' => 'NowoMailKitBundle', 'status' => $status->value]);

                return null;
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
