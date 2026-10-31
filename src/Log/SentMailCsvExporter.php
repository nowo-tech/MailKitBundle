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
 * (`web_ui.export.include_bodies`, which needs `log.store_bodies`) **and** the request asks for them.
 *
 * Stateless (worker safe): the streaming closure captures only this request's values.
 */
final readonly class SentMailCsvExporter
{
    /** Header telling whether the export was truncated (`1`) or complete (`0`). */
    public const TRUNCATED_HEADER = 'X-Mail-Kit-Export-Truncated';

    /** Rows whose bodies are fetched with one query. */
    public const BODY_BATCH = 100;

    /** Default `web_ui.export.max_rows`. */
    public const DEFAULT_MAX_ROWS = 10_000;

    /** Header keys (translation domain of the bundle), in column order. */
    public const COLUMNS = ['date', 'status', 'tag', 'from', 'to', 'cc', 'bcc', 'subject', 'error', 'reference'];

    /** Extra header keys of an export with bodies. */
    public const BODY_COLUMNS = ['text_body', 'html_body'];

    /**
     * @param int $maxRows `web_ui.export.max_rows`
     * @param bool $bodiesAllowed `web_ui.export.include_bodies` (only with `log.store_bodies`)
     * @param int $bodyBatch rows read before their bodies are fetched with one query
     */
    public function __construct(
        private SentMailRepository $repository,
        private int $maxRows = self::DEFAULT_MAX_ROWS,
        private bool $bodiesAllowed = false,
        private ?TranslatorInterface $translator = null,
        private ClockInterface $clock = new NativeClock(),
        private int $bodyBatch = self::BODY_BATCH,
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
        $bodies = $withBodies ? $this->repository->findBodies(array_map(static fn (SentMail $row): string => $row->getId(), $batch)) : [];
        foreach ($batch as $row) {
            $recipients = $row->getRecipients();
            $error      = $row->getError();
            $line       = [
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
            if ($withBodies) {
                $body   = $bodies[$row->getId()] ?? null;
                $line[] = $body?->getTextBody();
                $line[] = $body?->getHtmlBody();
            }

            yield $line;
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator?->trans($key, $params, NowoMailKitBundle::TRANSLATION_DOMAIN) ?? $key;
    }
}
