<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Command;

use Nowo\MailKitBundle\Log\SentMailRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function filter_var;
use function is_string;
use function sprintf;

use const FILTER_VALIDATE_EMAIL;

/**
 * `nowo:mail-kit:log:forget <email>`: deletes every sent-mail log row (and stored body) addressed to an e-mail —
 * the right to erasure (GDPR art. 17). The address is not logged.
 */
#[AsCommand(name: self::NAME, description: 'Delete every sent-mail log row addressed to an e-mail address (right to erasure).')]
final class ForgetRecipientCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:log:forget';

    /**
     * @param SentMailRecorder $recorder log writer
     */
    public function __construct(
        private readonly SentMailRecorder $recorder,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    /**
     * Argument `email`.
     */
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Recipient address (to, cc or bcc).');
    }

    /**
     * Deletes and prints the number of rows.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error('The argument must be an e-mail address.');

            return Command::INVALID;
        }
        $deleted = $this->recorder->forgetRecipient($email);
        $this->logger?->info('Sent-mail log rows of one recipient deleted.', ['bundle' => 'NowoMailKitBundle', 'deleted' => $deleted]);
        $io->success(sprintf('Sent-mail log: %d row(s) deleted.', $deleted));

        return Command::SUCCESS;
    }
}
