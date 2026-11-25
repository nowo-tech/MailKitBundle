<?php

declare(strict_types=1);

namespace App\Outlook;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Port\MailboxTokenProviderInterface;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use Nowo\MailKitBundle\Mailbox\Value\MailboxAccessToken;
use Nowo\MailKitBundle\Mailbox\Value\MailboxId;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function explode;
use function hash;
use function is_string;
use function substr;
use function time;

/**
 * DEMO ONLY (Outlook manual test harness): MailKit's token port read from the scratch token file
 * (MAILKIT_OUTLOOK_TOKEN_FILE, outside every repository). One file = one account, so every harness mailbox gets the
 * same token; MailKit's account check (start, every sweep and send) refuses a mailbox whose address is not the
 * token's account. An expired token (or one Graph answered 401 to) is refreshed with the stored refresh token and the
 * new pair is written back to the same file only. The token is never logged, echoed or cached in memory.
 */
final class ScratchFileTokenProvider implements MailboxTokenProviderInterface
{
    /** @var array<string, true> fingerprints Graph refused (refresh before handing them out again) */
    private array $rejected = [];

    /**
     * @param list<string> $forbiddenRoots
     */
    public function __construct(
        private readonly MicrosoftIdentityClient $identity,
        #[Autowire('%env(default::MAILKIT_OUTLOOK_TOKEN_FILE)%')]
        private readonly ?string $tokenFile,
        #[Autowire(param: 'app.outlook.forbidden_roots')]
        private readonly array $forbiddenRoots,
    ) {
    }

    /**
     * The guarded scratch file (refuses a path inside the repository tree).
     *
     * @throws InvalidArgumentException
     */
    public function file(?string $override = null): TokenFile
    {
        $path = is_string($override) && $override !== '' ? $override : (string) $this->tokenFile;

        return new TokenFile($path, $this->forbiddenRoots);
    }

    public function accessToken(MailboxId $mailbox): MailboxAccessToken
    {
        try {
            $file = $this->file();
        } catch (InvalidArgumentException $exception) {
            throw new MailboxTokenUnavailable($exception->getMessage());
        }

        try {
            $data = $file->update(function (array $current): ?array {
                if (time() < $current['expires_at'] - TokenFile::EXPIRY_MARGIN && !isset($this->rejected[self::fingerprint($current['access_token'])])) {
                    return null;
                }
                if ($current['refresh_token'] === null) {
                    throw new MailboxGrantFailure(GrantFailureReason::Revoked, 'The access token expired and the token file has no refresh_token: run the device-code login again.');
                }

                return $this->identity->refresh($current['refresh_token']);
            });
        } catch (MailboxGrantFailure|MailboxTokenUnavailable $failure) {
            throw $failure;
        } catch (RuntimeException $exception) {
            throw new MailboxTokenUnavailable($exception->getMessage());
        }
        $this->rejected = [];

        return new MailboxAccessToken($data['access_token'], new DateTimeImmutable('@' . $data['expires_at']), $data['scope'] !== null ? explode(' ', $data['scope']) : []);
    }

    public function rejected(MailboxId $mailbox, string $tokenFingerprint): void
    {
        $this->rejected[$tokenFingerprint] = true;
    }

    /**
     * Same fingerprint as {@see MailboxAccessToken::fingerprint()}.
     */
    private static function fingerprint(#[SensitiveParameter] string $token): string
    {
        return substr(hash('sha256', $token), 0, 12);
    }
}
