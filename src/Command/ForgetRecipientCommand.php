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
use Symfony\Component\Mime\Address;
use Throwable;

use function is_string;
use function sprintf;

/**
 * `nowo:mail-kit:log:forget <email>`: deletes every sent-mail log row (and stored body) sent to or by an
 * address and masks it in the remaining subjects / bodies — the right to erasure (GDPR art. 17). Internationalised
 * addresses are accepted. The address is not logged.
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
        if (!is_string($email) || !$this->isAddress($email)) {
            $io->error('The argument must be an e-mail address.');

            return Command::INVALID;
        }
        $result = $this->recorder->forgetAddress($email);
        $this->logger?->info('Sent-mail log rows of one address erased.', ['bundle' => 'NowoMailKitBundle'] + $result);
        $io->success(sprintf('Sent-mail log: %d row(s) deleted, %d row(s) redacted.', $result['deleted'], $result['redacted']));

        return Command::SUCCESS;
    }

    /**
     * Any address the mailer accepts (internationalised addresses included).
     */
    private function isAddress(string $email): bool
    {
        try {
            new Address(trim($email));
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
