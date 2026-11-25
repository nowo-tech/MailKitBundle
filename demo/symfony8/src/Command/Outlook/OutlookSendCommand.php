<?php

declare(strict_types=1);

namespace App\Command\Outlook;

use App\Outlook\HarnessMailboxDirectory;
use App\Outlook\HarnessMessageSink;
use App\Outlook\TestAttachment;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendFailed;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendInProgress;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendKeyConflict;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxSendOutcomeUnknown;
use Nowo\MailKitBundle\Mailbox\Infrastructure\Messenger\DeliverSentCopy;
use Nowo\MailKitBundle\Mailbox\Service\MailboxSenderInterface;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use Nowo\MailKitBundle\Mailbox\Value\MailboxSettings;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingAttachment;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingKind;
use Nowo\MailKitBundle\Mailbox\Value\OutgoingMailboxMail;
use Nowo\MailKitBundle\Mailbox\Value\ProviderMessageId;
use Nowo\MailKitBundle\Sender\MailAddress;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_map;
use function bin2hex;
use function is_array;
use function is_numeric;
use function is_string;
use function random_bytes;
use function sleep;
use function sprintf;

/**
 * DEMO ONLY (Outlook manual test harness): MailboxSenderInterface::send() with an idempotency key and an attachment.
 * Everything that makes up the message (subject, body, the generated attachment's bytes) is derived from the key and
 * the options, so running the command again with the same key and options must answer `replayed: true` and send
 * nothing (a different message with the same key answers MailboxSendKeyConflict).
 */
#[AsCommand(name: 'app:outlook:send', description: 'DEMO ONLY: send() from the delegated mailbox with an idempotency key and an attachment')]
final class OutlookSendCommand extends Command
{
    public function __construct(
        private readonly MailboxSenderInterface $sender,
        private readonly HarnessMailboxDirectory $directory,
        private readonly HarnessMessageSink $sink,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Mailbox id', OutlookCommandSupport::DEFAULT_ID)
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'Idempotency key (16-128 of [A-Za-z0-9_.:-]); generated and printed when omitted')
            ->addOption('to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Recipient (repeat it); default: the mailbox itself')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Subject (default for a new message: "MailKit 2.5.0 manual test <key>"; a reply keeps Outlook\'s "RE: …")')
            ->addOption('reply-to', null, InputOption::VALUE_REQUIRED, 'Provider id of the original (app:outlook:list --full-ids): sends a reply')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'new | reply | reply_all | forward (default: new, or reply with --reply-to)')
            ->addOption('attach', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'File to attach (repeat it)')
            ->addOption('attach-mb', null, InputOption::VALUE_REQUIRED, 'Size in MB of a generated attachment derived from the key (0 = none; > 3 uses an upload session)', '4')
            ->addOption('sent-copy-wait', null, InputOption::VALUE_REQUIRED, 'Seconds to wait before asking MailKit once more for the sent copy (0 = skip)', '15');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $mailbox  = new MailboxId(OutlookCommandSupport::id($input));
            $settings = $this->directory->mailbox($mailbox);
            if (!$settings instanceof MailboxSettings) {
                throw new InvalidArgumentException('Unknown mailbox: run app:outlook:register first.');
            }
            $key = $input->getOption('key');
            if (!is_string($key) || $key === '') {
                $key = 'outlook-test-' . bin2hex(random_bytes(8));
                $output->writeln('Idempotency key: <info>' . $key . '</info> (re-run with --key=' . $key . ' and the same options: it must answer replayed and send nothing)');
            }
            $mail = $this->mail($input, $settings, $key);
        } catch (InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::INVALID;
        }

        try {
            $sent = $this->sender->send($mailbox, $mail);
        } catch (MailboxSendInProgress $exception) {
            $output->writeln(sprintf('<comment>MailboxSendInProgress (reconciling: %s)</comment>: retry later with the SAME key%s.', $exception->reconciling ? 'yes' : 'no', $exception->reconciling ? ' after send.stale_claim_seconds (300 s)' : ''));

            return 2;
        } catch (MailboxSendKeyConflict $exception) {
            $output->writeln('<error>MailboxSendKeyConflict: this key was used for a different message (' . $exception->getMessage() . ').</error>');

            return Command::FAILURE;
        } catch (MailboxSendFailed $exception) {
            $output->writeln('<error>MailboxSendFailed: ' . $exception->reason . ' (nothing was sent).</error>');

            return Command::FAILURE;
        } catch (MailboxSendOutcomeUnknown $exception) {
            $output->writeln('<error>MailboxSendOutcomeUnknown: ' . $exception->getMessage() . ' Check Sent Items.</error>');

            return Command::FAILURE;
        } catch (MailboxException $exception) {
            $output->writeln('<error>' . $exception::class . ': ' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('Sent: <info>replayed: %s</info>', $sent->replayed ? 'true (nothing sent again)' : 'false (submitted now)'));
        $output->writeln('  provider id: ' . $sent->id->value);
        $output->writeln('  internet message id: ' . $sent->internetMessageId);
        $output->writeln('  thread: ' . $sent->threadKey->value);

        $wait = $input->getOption('sent-copy-wait');
        $wait = is_numeric($wait) ? (int) $wait : 0;
        if (!$sent->replayed && $wait > 0 && $this->sink->find($mailbox->value, $sent->id->value) === null) {
            // The demo bus is synchronous: MailKit's own DeliverSentCopy already ran inside send() without its delay
            // and Graph may not have filed the message yet. Ask once more after a pause, as an async transport would.
            $output->writeln(sprintf('Waiting %d s for Graph to file the sent copy…', $wait));
            sleep($wait);
            $this->bus->dispatch(new DeliverSentCopy($mailbox->value, $sent->id->value));
        }
        $row = $this->sink->find($mailbox->value, $sent->id->value);
        $output->writeln($row === null
            ? 'Sent copy: not in the sink yet (a sweep of a followed sentitems folder delivers it).'
            : sprintf('Sent copy in the sink: origin %s, folder %s, duplicates %s.', (string) $row['origin'], (string) $row['folder'], (string) $row['duplicate_count']));

        return Command::SUCCESS;
    }

    private function mail(InputInterface $input, MailboxSettings $settings, string $key): OutgoingMailboxMail
    {
        $replyTo = $input->getOption('reply-to');
        $kindOpt = $input->getOption('kind');
        $kind    = is_string($kindOpt) && $kindOpt !== ''
            ? (OutgoingKind::tryFrom($kindOpt) ?? throw new InvalidArgumentException('--kind must be new, reply, reply_all or forward.'))
            : (is_string($replyTo) && $replyTo !== '' ? OutgoingKind::Reply : OutgoingKind::New);
        $original = $kind !== OutgoingKind::New
            ? new ProviderMessageId(is_string($replyTo) && $replyTo !== '' ? $replyTo : throw new InvalidArgumentException('--reply-to is required for a reply, reply all or forward.'))
            : null;
        $to      = $input->getOption('to');
        $to      = is_array($to) && $to !== [] ? $to : [$settings->address];
        $subject = $input->getOption('subject');
        $subject = is_string($subject) && $subject !== '' ? $subject : ($kind === OutgoingKind::New ? 'MailKit 2.5.0 manual test ' . $key : null);

        $attachments = [];
        $attach      = $input->getOption('attach');
        foreach (is_array($attach) ? $attach : [] as $path) {
            $attachments[] = OutgoingAttachment::fromPath((string) $path);
        }
        $mb = $input->getOption('attach-mb');
        $mb = is_numeric($mb) ? (float) $mb : 0.0;
        if ($mb > 0) {
            $attachments[] = OutgoingAttachment::fromString(TestAttachment::bytes($key, (int) ($mb * 1024 * 1024)), 'mailkit-test-' . $mb . 'MB.bin', 'application/octet-stream');
        }

        return new OutgoingMailboxMail(
            kind: $kind,
            inResponseTo: $original,
            to: array_map(static fn (mixed $address): MailAddress => new MailAddress((string) $address), $to),
            cc: [],
            bcc: [],
            subject: $subject,
            text: "MailKit 2.5.0 manual test.\nIdempotency key: " . $key . "\n",
            html: null,
            attachments: $attachments,
            idempotencyKey: $key,
        );
    }
}
