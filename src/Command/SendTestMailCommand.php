<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Command;

use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

/**
 * `nowo:mail-kit:send-test [to] [--resend]`: sends the test e-mail synchronously through the current runtime
 * configuration (or the stored Resend key) and prints the transport answer.
 */
#[AsCommand(name: self::NAME, description: 'Send a test e-mail through the runtime mailer configuration.')]
final class SendTestMailCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:send-test';

    /**
     * @param TestMailSender $sender test e-mail sender
     */
    public function __construct(
        private readonly TestMailSender $sender,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
    }

    /**
     * Argument `to` (default `mailer.test_recipient`), option `--resend`.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::OPTIONAL, 'Recipient (default: the mailer.test_recipient setting).')
            ->addOption('resend', null, InputOption::VALUE_NONE, 'Send with the stored Resend API key.');
    }

    /**
     * Sends and prints the outcome.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $to     = $input->getArgument('to');
        $to     = is_string($to) ? $to : null;
        $result = $input->getOption('resend') === true ? $this->sender->sendResend($to) : $this->sender->sendMailer($to);
        $key    = $result->outcome->messageKey();
        $text   = $this->translator?->trans($key, $result->parameters(), NowoMailKitBundle::TRANSLATION_DOMAIN) ?? $key;

        if ($result->isSent()) {
            $io->success($text);

            return Command::SUCCESS;
        }
        $io->error($text);

        return Command::FAILURE;
    }
}
