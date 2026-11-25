<?php

declare(strict_types=1);

namespace App\Command\Outlook;

use App\Outlook\HarnessMailboxDirectory;
use App\Outlook\RawDoubleSendCheck;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxException;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function bin2hex;
use function is_string;
use function random_bytes;
use function sprintf;
use function var_export;

/**
 * DEMO ONLY (Outlook manual test harness), guide check ZAV-L1: two raw `POST /me/messages/{id}/send` on the same
 * draft. Passes when the first answers 202, the draft then reads `isDraft: false` and the second answers 4xx; the
 * recipient must also confirm a single copy.
 */
#[AsCommand(name: 'app:outlook:double-send', description: 'DEMO ONLY: raw double POST /me/messages/{id}/send on one draft (ZAV-L1)')]
final class OutlookDoubleSendCommand extends Command
{
    public function __construct(
        private readonly RawDoubleSendCheck $check,
        private readonly HarnessMailboxDirectory $directory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Mailbox id', OutlookCommandSupport::DEFAULT_ID)
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Recipient (default: the mailbox itself)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $mailbox  = new MailboxId(OutlookCommandSupport::id($input));
            $settings = $this->directory->mailbox($mailbox);
            if (!$settings instanceof MailboxSettings) {
                throw new InvalidArgumentException('Unknown mailbox: run app:outlook:register first.');
            }
            $to      = $input->getOption('to');
            $subject = 'MailKit 2.5.0 ZAV-L1 double send ' . bin2hex(random_bytes(4));
            $result  = $this->check->run($mailbox, is_string($to) && $to !== '' ? $to : $settings->address, $subject);
        } catch (InvalidArgumentException|RuntimeException|MailboxException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('Subject: ' . $subject);
        $output->writeln('Draft id: ' . $result['draft_id']);
        $output->writeln(sprintf('1st POST /send: HTTP %d (expected 202)', $result['first_status']));
        $output->writeln(sprintf('isDraft after the 1st send: %s (expected false)', var_export($result['is_draft_after_first'], true)));
        $output->writeln(sprintf('2nd POST /send: HTTP %d%s (expected 4xx)', $result['second_status'], $result['second_error'] !== null ? ' ' . $result['second_error'] : ''));
        if ($result['refused'] && $result['is_draft_after_first'] === false) {
            $output->writeln('<info>PASS</info>: Graph refused the second submit. Now confirm the recipient got ONE copy of "' . $subject . '".');

            return Command::SUCCESS;
        }
        $output->writeln('<error>FAIL</error>: Graph accepted the second submit or the draft still reads as a draft. Do NOT tag 2.5.0: report this output (the resend after an ambiguous answer must be removed).');

        return Command::FAILURE;
    }
}
