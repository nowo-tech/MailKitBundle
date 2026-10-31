<?php

declare(strict_types=1);

namespace App\Command;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailRecorder;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Seeds the demo (idempotent): the mailer settings (runtime DSN pointing at Mailpit) and, when the log is empty, a few
 * realistic messages sent through the plain MailerInterface plus one failed delivery.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed the Mail Kit demo (settings and sent mail, idempotent)')]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly InstanceSettingsInterface $settings,
        private readonly MailerInterface $mailer,
        private readonly SentMailRepository $log,
        private readonly SentMailRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changed = $this->settings->save('mailer', [
            'dsn'            => 'smtp://mailpit:1025',
            'from'           => 'noreply@example.com',
            'from_name'      => 'Acme Ops',
            'reply_to'       => 'support@example.com',
            'test_recipient' => 'ops@example.com',
        ]);

        $sent = 0;
        if ($this->log->countFiltered(new SentMailFilters(period: 'all')) === 0) {
            foreach ([
                ['alice@example.com', 'Welcome to Acme Ops', 'welcome', 'Your account is ready.'],
                ['billing@example.org', 'Invoice #1042 is available', 'invoice', 'Your October invoice is attached.'],
                ['bob@example.com', 'Reset your password', 'password_reset', 'Use this one-time link: https://ops.example.com/reset/REDACTED-DEMO'],
            ] as [$to, $subject, $tag, $text]) {
                $email = (new Email())->to($to)->subject($subject)->text($text);
                MailHeaders::tag($email, $tag);
                MailHeaders::redact($email, 'https://ops.example.com/reset/REDACTED-DEMO');
                $this->mailer->send($email);
                ++$sent;
            }
            $bounce = (new Email())->from('noreply@example.com')->to('carol@unreachable.example')->subject('Weekly incident digest')->text('…');
            MailHeaders::tag($bounce, 'digest');
            $this->recorder->failed(null, $bounce, 'smtp', new RuntimeException('Expected response code "250" but got code "550", with message "550 5.1.1 <carol@unreachable.example>: mailbox unavailable".'));
            ++$sent;
        }

        $output->writeln(sprintf('Mail Kit demo seeded (%d setting(s) changed, %d log row(s) added).', count($changed), $sent));

        return Command::SUCCESS;
    }
}
