<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Log;

use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

use function array_key_exists;
use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * Filters of the sent-mail log list, read from the query string (`status`, `q`, `period`). Unknown values fall back
 * to the defaults; the search needs at least {@see self::QUERY_MIN} characters and is capped at {@see self::QUERY_MAX}.
 */
final readonly class SentMailFilters
{
    /** Period choices => relative date (null = all time). */
    public const PERIODS = ['24h' => '-24 hours', '7d' => '-7 days', '30d' => '-30 days', 'all' => null];

    /** Default period. */
    public const DEFAULT_PERIOD = '7d';

    /** Minimum search length. */
    public const QUERY_MIN = 3;

    /** Maximum search length. */
    public const QUERY_MAX = 120;

    /**
     * @param string $period key of {@see self::PERIODS}
     */
    public function __construct(
        public ?SentMailStatus $status = null,
        public ?string $query = null,
        public string $period = self::DEFAULT_PERIOD,
        public ?DateTimeImmutable $since = null,
    ) {
    }

    /**
     * Filters of the current request.
     */
    public static function fromRequest(Request $request, DateTimeImmutable $now): self
    {
        $status   = SentMailStatus::tryFrom($request->query->getString('status'));
        $query    = trim($request->query->getString('q'));
        $period   = $request->query->getString('period', self::DEFAULT_PERIOD);
        $period   = array_key_exists($period, self::PERIODS) ? $period : self::DEFAULT_PERIOD;
        $relative = self::PERIODS[$period];

        return new self(
            $status,
            mb_strlen($query) >= self::QUERY_MIN ? mb_substr($query, 0, self::QUERY_MAX) : null,
            $period,
            $relative === null ? null : $now->modify($relative),
        );
    }

    /**
     * True when any filter differs from the defaults.
     */
    public function isFiltered(): bool
    {
        return $this->status instanceof SentMailStatus || $this->query !== null || $this->period !== self::DEFAULT_PERIOD;
    }

    /**
     * Data of the filter form.
     *
     * @return array{status: string, q: string, period: string}
     */
    public function formData(): array
    {
        return [
            'status' => $this->status->value ?? '',
            'q'      => $this->query ?? '',
            'period' => $this->period,
        ];
    }
}
