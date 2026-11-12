<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound\Command;

use Nowo\MailKitBundle\Graph\GraphException;
use Nowo\MailKitBundle\Graph\Inbound\GraphInboundProfilesProviderInterface;
use Nowo\MailKitBundle\Graph\Inbound\GraphSubscriptionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;
use function sprintf;

/**
 * `nowo:mail-kit:graph:subscriptions` (1.9): creates or renews the change-notification subscriptions (schedule it at
 * least hourly), or deletes them with `--delete`.
 */
#[AsCommand(name: self::NAME, description: 'Create or renew the Microsoft Graph change-notification subscriptions of the inbound mailboxes.')]
final class GraphSubscriptionsCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:graph:subscriptions';

    /**
     * Wires the command.
     */
    public function __construct(
        private readonly GraphSubscriptionManager $subscriptions,
        private readonly GraphInboundProfilesProviderInterface $profiles,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Only this mailbox profile')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Delete the subscriptions instead (offboarding a mailbox)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profile = $input->getOption('profile');
        $status  = Command::SUCCESS;
        foreach (is_string($profile) && $profile !== '' ? [$profile] : $this->profiles->inboundProfiles() as $name) {
            try {
                $outcome = $input->getOption('delete') === true
                    ? ($this->subscriptions->delete($name) ? 'deleted' : 'none')
                    : $this->subscriptions->ensure($name);
                $output->writeln(sprintf('%s: %s', $name, $outcome));
            } catch (GraphException $exception) {
                $output->writeln(sprintf('<error>%s: %s</error>', $name, $exception->getMessage()));
                $status = Command::FAILURE;
            }
        }

        return $status;
    }
}
