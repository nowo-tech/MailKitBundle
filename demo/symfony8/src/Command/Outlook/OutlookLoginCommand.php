<?php

declare(strict_types=1);

namespace App\Command\Outlook;

use App\Outlook\MicrosoftIdentityClient;
use App\Outlook\ScratchFileTokenProvider;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function date;
use function is_array;
use function is_string;
use function sleep;
use function time;

/**
 * DEMO ONLY (Outlook manual test harness): device-code login of the test Outlook.com account (public client,
 * delegated scopes offline_access User.Read Mail.ReadWrite Mail.Send). The token pair goes to the scratch file only
 * (mode 0600); it is never printed.
 */
#[AsCommand(name: 'app:outlook:login', description: 'DEMO ONLY: device-code login; writes the token to the scratch file (never printed)')]
final class OutlookLoginCommand extends Command
{
    public function __construct(
        private readonly MicrosoftIdentityClient $identity,
        private readonly ScratchFileTokenProvider $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('token-file', null, InputOption::VALUE_REQUIRED, 'Scratch token file (default: MAILKIT_OUTLOOK_TOKEN_FILE)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $file = $this->tokens->file(self::option($input, 'token-file'));
            $code = $this->identity->startDeviceCode();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('Open ' . $code['verification_uri'] . ' and enter the code <info>' . $code['user_code'] . '</info> (sign in with the dedicated TEST Outlook.com account).');
        $deadline = time() + $code['expires_in'];
        $interval = $code['interval'];
        while (time() < $deadline) {
            sleep($interval);

            try {
                $result = $this->identity->pollDeviceCode($code['device_code']);
            } catch (RuntimeException $exception) {
                $output->writeln('<error>' . $exception->getMessage() . '</error>');

                return Command::FAILURE;
            }
            if (is_array($result)) {
                $file->write($result);
                $output->writeln('Token written to ' . $file->path() . ' (mode 0600), access token valid until ' . date('c', $result['expires_at']) . ($result['refresh_token'] !== null ? ', refresh token stored.' : ', NO refresh token (offline_access missing?).'));

                return Command::SUCCESS;
            }
            if ($result === 'slow_down') {
                $interval += 5;
            }
        }
        $output->writeln('<error>The device code expired before the sign-in finished.</error>');

        return Command::FAILURE;
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
