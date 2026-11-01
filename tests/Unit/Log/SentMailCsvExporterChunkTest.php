<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Log;

use Nowo\MailKitBundle\Log\SentMailCsvExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_merge;

/**
 * Security (CA delta 1.1.0, D2): the bodies of an export are loaded in chunks bounded by a byte budget.
 */
final class SentMailCsvExporterChunkTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, array<string, int>, int, list<list<string>>}>
     */
    public static function chunks(): iterable
    {
        yield 'no ids' => [[], [], 10, []];
        yield 'all fit' => [['a', 'b', 'c'], ['a' => 3, 'b' => 3, 'c' => 4], 10, [['a', 'b', 'c']]];
        yield 'budget reached exactly' => [['a', 'b', 'c'], ['a' => 5, 'b' => 5, 'c' => 1], 10, [['a', 'b'], ['c']]];
        yield 'split in order' => [['a', 'b', 'c', 'd'], ['a' => 6, 'b' => 6, 'c' => 6, 'd' => 1], 10, [['a'], ['b'], ['c', 'd']]];
        yield 'one row over the budget is alone' => [['a', 'b', 'c'], ['a' => 1, 'b' => 50, 'c' => 1], 10, [['a'], ['b'], ['c']]];
        yield 'missing size counts as zero' => [['a', 'b', 'c'], ['b' => 10], 10, [['a', 'b', 'c']]];
        yield 'two max bodies of 1 MiB each per row' => [
            ['a', 'b', 'c', 'd', 'e'],
            ['a' => 2_097_152, 'b' => 2_097_152, 'c' => 2_097_152, 'd' => 2_097_152, 'e' => 2_097_152],
            SentMailCsvExporter::BODY_BATCH_BYTES,
            [['a', 'b', 'c', 'd'], ['e']],
        ];
    }

    /**
     * @param list<string> $ids
     * @param array<string, int> $sizes
     * @param list<list<string>> $expected
     */
    #[DataProvider('chunks')]
    public function testChunksStayWithinTheBudget(array $ids, array $sizes, int $budget, array $expected): void
    {
        self::assertSame($expected, SentMailCsvExporter::chunkBySize($ids, $sizes, $budget));
    }

    public function testAFixedBatchOfMaximalBodiesNeverLoadsMoreThanTheBudgetPlusOneRow(): void
    {
        $ids   = [];
        $sizes = [];
        for ($i = 0; $i < SentMailCsvExporter::BODY_BATCH; ++$i) {
            $ids[]             = 'id-' . $i;
            $sizes['id-' . $i] = 2_097_152;
        }

        $chunks = SentMailCsvExporter::chunkBySize($ids, $sizes, SentMailCsvExporter::BODY_BATCH_BYTES);
        self::assertSame($ids, array_merge(...$chunks));
        foreach ($chunks as $chunk) {
            $bytes = 0;
            foreach ($chunk as $id) {
                $bytes += $sizes[$id];
            }
            self::assertLessThanOrEqual(SentMailCsvExporter::BODY_BATCH_BYTES, $bytes);
        }
        self::assertCount(25, $chunks);
    }
}
