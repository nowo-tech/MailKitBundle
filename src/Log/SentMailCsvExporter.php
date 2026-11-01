<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeInterface;
use Nowo\ControllerKitBundle\Http\CsvStreamedResponse;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function count;
use function implode;

/**
 * CSV export of the sent-mail log (the current filters and sort applied), streamed through ControllerKit's
 * {@see CsvStreamedResponse}: every cell goes through `CsvSafeWriter` (a subject or tag such as `=HYPERLINK(…)` is
 * written as text, never evaluated by a spreadsheet), `Cache-Control: no-store, private`, `Content-Disposition:
 * attachment`. Rows are hydrated one at a time and detached; at most `max_rows` are written — when more match, a last
 * row says so and the `X-Mail-Kit-Export-Truncated` header is `1`.
 *
 * Columns: date, status, tag, from, to, cc, bcc, subject, error (credentials scrubbed again), reference (provider
 * message id); header row translated. The text / HTML bodies are added only when the export allows them
 * (`web_ui.export.include_bodies`, which needs `log.store_bodies`) **and** the request asks for them; the bodies are
 * then loaded in chunks of about `web_ui.export.body_batch_bytes` (8 MiB), never a fixed number of rows.
 *
 * Stateless (worker safe): the streaming closure captures only this request's values.
 */
final readonly class SentMailCsvExporter
{
    /** Header telling whether the export was truncated (`1`) or complete (`0`). */
    public const TRUNCATED_HEADER = 'X-Mail-Kit-Export-Truncated';

    /** Rows read before their body sizes are looked up (metadata only: no body is loaded for this). */
    public const BODY_BATCH = 100;

    /**
     * Default `web_ui.export.body_batch_bytes`: approximate size of the bodies loaded with one query. Each stored body
     * part is capped at 1 MiB, so a fixed batch of 100 rows could hold ~200 MiB; a batch closes before it would pass
     * this budget (a single row over it is fetched alone).
     */
    public const BODY_BATCH_BYTES = 8_388_608;

    /** Default `web_ui.export.max_rows`. */
    public const DEFAULT_MAX_ROWS = 10_000;

    /**
     * Highest accepted `web_ui.export.max_rows`. `toIterable()` hydrates one row at a time, but pdo_mysql buffers the
     * whole result in the client (a few KiB per row), and the connection cannot be switched to unbuffered because the
     * body queries run while the rows are read.
     */
    public const MAX_ROWS_LIMIT = 100_000;

    /** Header keys (translation domain of the bundle), in column order. */
    public const COLUMNS = ['date', 'status', 'tag', 'from', 'to', 'cc', 'bcc', 'subject', 'error', 'reference'];

    /** Extra header keys of an export with bodies. */
    public const BODY_COLUMNS = ['text_body', 'html_body'];

    /**
     * @param int $maxRows `web_ui.export.max_rows`
     * @param bool $bodiesAllowed `web_ui.export.include_bodies` (only with `log.store_bodies`)
     * @param int $bodyBatch rows read before their body sizes are looked up
     * @param int $bodyBatchBytes approximate body bytes loaded with one query (`web_ui.export.body_batch_bytes`)
     */
    public function __construct(
        private SentMailRepository $repository,
        private int $maxRows = self::DEFAULT_MAX_ROWS,
        private bool $bodiesAllowed = false,
        private ?TranslatorInterface $translator = null,
        private ClockInterface $clock = new NativeClock(),
        private int $bodyBatch = self::BODY_BATCH,
        private int $bodyBatchBytes = self::BODY_BATCH_BYTES,
    ) {
    }

    /**
     * Maximum rows of one export.
     */
    public function maxRows(): int
    {
        return $this->maxRows;
    }

    /**
     * The export may include the stored bodies (on request).
     */
    public function bodiesAllowed(): bool
    {
        return $this->bodiesAllowed;
    }

    /**
     * Streamed CSV of the rows matching the filters, in the given order.
     *
     * @param string|null $sort key of {@see SentMailRepository::SORT_FIELDS} (null = default)
     * @param string $direction `asc` | `desc`
     * @param bool $withBodies the request asks for the bodies (ignored unless {@see self::bodiesAllowed()})
     */
    public function export(SentMailFilters $filters, ?string $sort = null, string $direction = SentMailRepository::DEFAULT_DIRECTION, bool $withBodies = false): CsvStreamedResponse
    {
        $withBodies = $withBodies && $this->bodiesAllowed;
        $total      = $this->repository->countFiltered($filters);
        $truncated  = $total > $this->maxRows;
        $notice     = $truncated ? $this->trans('log.export.truncated', ['%max%' => (string) $this->maxRows, '%total%' => (string) $total]) : null;
        $columns    = $withBodies ? [...self::COLUMNS, ...self::BODY_COLUMNS] : self::COLUMNS;
        $header     = array_map(fn (string $column): string => $this->trans('log.export.column.' . $column), $columns);
        $rows       = $this->repository->iterateFiltered($filters, $this->maxRows, $sort, $direction);

        return new CsvStreamedResponse(
            'sent-mail-' . $this->clock->now()->format('Ymd-His') . '.csv',
            function () use ($rows, $withBodies, $notice): iterable {
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = $row;
                    if (count($batch) >= $this->bodyBatch) {
                        yield from $this->lines($batch, $withBodies);
                        $batch = [];
                    }
                }
                yield from $this->lines($batch, $withBodies);
                if ($notice !== null) {
                    yield [$notice];
                }
            },
            $header,
            noStore: true,
            headers: [self::TRUNCATED_HEADER => $truncated ? '1' : '0', 'X-Robots-Tag' => 'noindex'],
        );
    }

    /**
     * @param list<SentMail> $batch
     *
     * @return iterable<list<string|null>>
     */
    private function lines(array $batch, bool $withBodies): iterable
    {
        if (!$withBodies) {
            foreach ($batch as $row) {
                yield $this->line($row);
            }

            return;
        }
        $rows = [];
        $ids  = [];
        foreach ($batch as $row) {
            $rows[$row->getId()] = $row;
            $ids[]               = $row->getId();
        }
        foreach (self::chunkBySize($ids, $this->repository->bodySizes($ids), $this->bodyBatchBytes) as $chunk) {
            $bodies = $this->repository->findBodies($chunk);
            foreach ($chunk as $id) {
                $body = $bodies[$id] ?? null;
                yield [...$this->line($rows[$id]), $body?->getTextBody(), $body?->getHtmlBody()];
            }
            $bodies = []; // free this chunk's bodies before the next query
        }
    }

    /**
     * Splits ids, in order, into chunks whose sizes add up to at most `$budget`; an id over the budget on its own is a
     * chunk of one, and an id without a size counts as 0. One body query then never loads more than the budget, or a
     * single row.
     *
     * @param list<string> $ids
     * @param array<string, int> $sizes size by id ({@see SentMailRepository::bodySizes()})
     * @param int $budget approximate bytes per chunk
     *
     * @return list<list<string>>
     */
    public static function chunkBySize(array $ids, array $sizes, int $budget): array
    {
        $chunks = [];
        $chunk  = [];
        $bytes  = 0;
        foreach ($ids as $id) {
            $size = $sizes[$id] ?? 0;
            if ($chunk !== [] && $bytes + $size > $budget) {
                $chunks[] = $chunk;
                $chunk    = [];
                $bytes    = 0;
            }
            $chunk[] = $id;
            $bytes += $size;
        }
        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * The metadata cells of one row.
     *
     * @return list<string|null>
     */
    private function line(SentMail $row): array
    {
        $recipients = $row->getRecipients();
        $error      = $row->getError();

        return [
            $row->getQueuedAt()->format(DateTimeInterface::ATOM),
            $row->getStatus()->value,
            $row->getTag(),
            $row->getSender(),
            implode(', ', $recipients['to'] ?? []),
            implode(', ', $recipients['cc'] ?? []),
            implode(', ', $recipients['bcc'] ?? []),
            $row->getSubject(),
            $error === null ? null : ErrorScrubber::scrub($error),
            $row->getProviderMessageId(),
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator?->trans($key, $params, NowoMailKitBundle::TRANSLATION_DOMAIN) ?? $key;
    }
}
