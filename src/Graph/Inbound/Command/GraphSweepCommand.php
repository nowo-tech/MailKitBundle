<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Command;

use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\Inbound\Message\GraphInboundMessageHandler;
use Nowo\MailKitBundle\Graph\Inbound\Message\SweepGraphMailbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function is_string;
use function sprintf;

/**
 * `nowo:mail-kit:graph:sweep` (1.9): delta sweep of the inbound mailboxes (dispatches one fetch message per new
 * message) and purge of old idempotency records. Schedule it every few minutes (cron or Symfony Scheduler with
 * {@see SweepGraphMailbox}).
 */
#[AsCommand(name: self::NAME, description: 'Delta sweep of the Microsoft Graph inbound mailboxes (missed notifications, hosts without a public URL).')]
final class GraphSweepCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:graph:sweep';

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly GraphInboundMessageHandler $handler,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Only this mailbox profile');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profile = $input->getOption('profile');

        try {
            $this->handler->sweep(new SweepGraphMailbox(is_string($profile) && $profile !== '' ? $profile : null));
        } catch (Throwable $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception instanceof GraphException ? $exception->getMessage() : 'Sweep failed: ' . $exception::class));

            return Command::FAILURE;
        }
        $output->writeln('Sweep done.');

        return Command::SUCCESS;
    }
}
