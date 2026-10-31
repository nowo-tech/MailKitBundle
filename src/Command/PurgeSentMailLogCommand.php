<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Command;

use Nowo\MailKitBundle\Log\SentMailRecorder;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_numeric;
use function is_string;
use function sprintf;

/**
 * `nowo:mail-kit:log:purge`: deletes sent-mail log rows older than the retention (keyset batches). Schedule it
 * (cron, Symfony Scheduler) to enforce `log.retention_days`.
 */
#[AsCommand(name: self::NAME, description: 'Delete sent-mail log rows older than the retention (default: log.retention_days).')]
final class PurgeSentMailLogCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:log:purge';

    /**
     * @param int $retentionDays `log.retention_days`
     * @param int $batchSize `log.purge_batch_size`
     */
    public function __construct(
        private readonly SentMailRecorder $recorder,
        private readonly int $retentionDays = 30,
        private readonly int $batchSize = 1000,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    /**
     * Option `--older-than-days`.
     */
    protected function configure(): void
    {
        $this->addOption('older-than-days', null, InputOption::VALUE_REQUIRED, 'Delete rows queued more than this many days ago (default: log.retention_days).');
    }

    /**
     * Purges and prints the number of deleted rows.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $option = $input->getOption('older-than-days');
        if ($option !== null && (!is_string($option) || !is_numeric($option) || (int) $option < 1)) {
            $io->error('--older-than-days must be a positive integer.');

            return Command::INVALID;
        }
        $days    = $option === null ? $this->retentionDays : (int) $option;
        $deleted = $this->recorder->purgeBefore($this->clock->now()->modify(sprintf('-%d days', $days)), $this->batchSize);
        $this->logger?->info('Sent-mail log purged.', ['bundle' => 'NowoMailKitBundle', 'days' => $days, 'deleted' => $deleted]);
        $io->success(sprintf('Sent-mail log: %d row(s) older than %d day(s) deleted.', $deleted, $days));

        return Command::SUCCESS;
    }
}
