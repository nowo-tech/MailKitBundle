<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Command;

use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function implode;
use function sprintf;

/**
 * `nowo:mail-kit:purge-legacy-resend [--dry-run] [--force]` (since 1.3, with `resend.enabled`): deletes the rows of
 * the MailKit 1.2 `resend` settings section (the encrypted 1.2 API key included) once they have been copied into the
 * `mailer` section by `nowo:instance-settings:seed-from-fallbacks --section=mailer`.
 *
 * Refuses (exit 1, nothing deleted) while `mailer.provider` is not stored, and when a legacy value was not copied:
 * `mailer.resend_api_key` / `mailer.resend_from` hold a different value than the old row (e.g. a mailer value stored
 * before the seed, or changed since). `--force` deletes anyway (the mailer values win). Idempotent: with no legacy
 * row there is nothing to do. Prints and logs row names only, never a value.
 */
#[AsCommand(name: self::NAME, description: 'Delete the MailKit 1.2 "resend" settings rows once they are copied into the mailer section.')]
final class PurgeLegacyResendCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:mail-kit:purge-legacy-resend';

    /**
     * @param SettingValueRepositoryInterface $repository Instance Settings storage (the legacy rows)
     * @param InstanceSettingsInterface $settings the migrated `mailer` values
     * @param LegacyResendSettings $legacy legacy view (memo reset after the purge)
     */
    public function __construct(
        private readonly SettingValueRepositoryInterface $repository,
        private readonly InstanceSettingsInterface $settings,
        private readonly LegacyResendSettings $legacy,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    /**
     * Options `--dry-run` and `--force`.
     */
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be deleted; delete nothing.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete even when a legacy value differs from the mailer value (the mailer value wins).');
    }

    /**
     * Checks that every legacy value was copied, then deletes the legacy rows.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->legacy->reset();
        $rows   = $this->repository->findBySection(LegacyResendSettings::SECTION);
        $values = $this->legacy->values();
        if ($rows === [] || $values === null) {
            $io->success('No MailKit 1.2 "resend" row is stored: nothing to purge.');

            return Command::SUCCESS;
        }
        $mailer = MailerSettingsSection::NAME . '.';
        if (!$this->settings->isOverridden($mailer . 'provider')) {
            $io->error('mailer.provider is not stored yet: run "nowo:instance-settings:seed-from-fallbacks --section=mailer" first. Nothing was deleted.');

            return Command::FAILURE;
        }

        $notCopied = [];
        if ($values['api_key'] !== null && $this->settings->getSecret($mailer . 'resend_api_key') !== $values['api_key']) {
            $notCopied[] = 'resend.api_key (mailer.resend_api_key holds another value)';
        }
        if ($values['from'] !== null && $this->settings->getString($mailer . 'resend_from') !== $values['from']) {
            $notCopied[] = 'resend.from (mailer.resend_from holds another value)';
        }
        $force = (bool) $input->getOption('force');
        if ($notCopied !== [] && !$force) {
            $io->listing($notCopied);
            $io->error('These legacy values were not copied into the mailer section. Nothing was deleted; check the mailer settings, then run again with --force to delete them anyway.');

            return Command::FAILURE;
        }

        $names = [];
        foreach ($rows as $row) {
            $names[] = LegacyResendSettings::SECTION . '.' . $row->getName();
        }
        if ((bool) $input->getOption('dry-run')) {
            $io->listing($names);
            $io->success(sprintf('Dry run: %d legacy row(s) would be deleted; nothing deleted.', count($rows)));

            return Command::SUCCESS;
        }

        $this->repository->apply([], $rows);
        $this->legacy->reset();
        $this->logger?->notice('MailKit 1.2 resend settings rows deleted.', ['bundle' => 'NowoMailKitBundle', 'rows' => implode(',', $names), 'forced' => $notCopied !== []]);
        $io->listing($names);
        $io->success(sprintf('Deleted %d legacy row(s).', count($rows)));

        return Command::SUCCESS;
    }
}
