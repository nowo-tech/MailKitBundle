<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking\Command;

use Nowo\MailKitBundle\Tracking\Store\TrackingStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * `nowo:mail-kit:tracking:purge` (1.10): deletes the tracked sends (and their open counters) prepared more than
 * `tracking.retention` ago, in batches. Schedule it daily (cron, Symfony Scheduler). Tokens older than the retention
 * are refused by the pixel anyway.
 */
#[AsCommand(name: self::NAME, description: 'Delete e-mail open tracking rows older than tracking.retention.')]
final class TrackingPurgeCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:tracking:purge';

    /**
     * @param int $retention seconds (`tracking.retention`)
     * @param int $batchSize rows deleted per statement
     */
    public function __construct(
        private readonly TrackingStore $store,
        private readonly int $retention,
        private readonly int $batchSize = 1000,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    /**
     * Purges and prints the number of deleted rows.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->store->purge($this->clock->now()->getTimestamp() - $this->retention, $this->batchSize);
        $this->logger?->info('Mail tracking purged.', ['bundle' => 'NowoMailKitBundle', 'deleted' => $deleted]);
        (new SymfonyStyle($input, $output))->success(sprintf('E-mail tracking: %d send row(s) older than the retention deleted.', $deleted));

        return Command::SUCCESS;
    }
}
