<?php

declare(strict_types=1);

namespace App\Outlook;

use Nowo\MailKitBundle\Mailbox\Exception\MailboxGrantFailure;
use Nowo\MailKitBundle\Mailbox\Exception\MailboxTokenUnavailable;
use Nowo\MailKitBundle\Mailbox\Value\GrantFailureReason;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function rawurlencode;
use function time;

/**
 * DEMO ONLY (Outlook manual test harness): the Microsoft identity platform calls of a public client (no secret):
 * the device-code login and the refresh-token grant. MailKit itself runs no OAuth flow (Q-254-15 = b); a real host
 * does this in its own connection flow. Token values never appear in an exception message.
 */
final class MicrosoftIdentityClient
{
    /** Delegated scopes the owner accepted (Q-MB-2). */
    public const SCOPES = 'offline_access User.Read Mail.ReadWrite Mail.Send';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::MAILKIT_OUTLOOK_CLIENT_ID)%')]
        private readonly ?string $clientId,
        #[Autowire('%env(default:app.outlook.default_tenant:MAILKIT_OUTLOOK_TENANT)%')]
        private readonly string $tenant = 'consumers',
    ) {
    }

    /**
     * Starts a device-code login.
     *
     * @return array{device_code: string, user_code: string, verification_uri: string, interval: int, expires_in: int, message: string}
     */
    public function startDeviceCode(): array
    {
        $data = $this->post('devicecode', ['client_id' => $this->clientId(), 'scope' => self::SCOPES]);
        if (!is_string($data['device_code'] ?? null) || !is_string($data['user_code'] ?? null) || !is_string($data['verification_uri'] ?? null)) {
            throw new RuntimeException('Unexpected device-code answer: ' . self::error($data));
        }

        return [
            'device_code'      => $data['device_code'],
            'user_code'        => $data['user_code'],
            'verification_uri' => $data['verification_uri'],
            'interval'         => is_numeric($data['interval'] ?? null) ? (int) $data['interval'] : 5,
            'expires_in'       => is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 900,
            'message'          => is_string($data['message'] ?? null) ? $data['message'] : '',
        ];
    }

    /**
     * Polls the device-code grant once: the token data, or a pending state ("authorization_pending", "slow_down").
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}|string
     */
    public function pollDeviceCode(string $deviceCode): array|string
    {
        $data = $this->post('token', [
            'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id'   => $this->clientId(),
            'device_code' => $deviceCode,
        ]);
        $error = $data['error'] ?? null;
        if (is_string($error) && in_array($error, ['authorization_pending', 'slow_down'], true)) {
            return $error;
        }

        return $this->tokens($data, null);
    }

    /**
     * Refresh-token grant.
     *
     * @throws MailboxGrantFailure the refresh token is gone (invalid_grant, consent withdrawn)
     * @throws MailboxTokenUnavailable transient (network, 5xx)
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}
     */
    public function refresh(#[SensitiveParameter] string $refreshToken): array
    {
        try {
            $data = $this->post('token', [
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->clientId(),
                'refresh_token' => $refreshToken,
                'scope'         => self::SCOPES,
            ]);
        } catch (RuntimeException $exception) {
            throw new MailboxTokenUnavailable('Token endpoint unavailable: ' . $exception->getMessage());
        }
        $error = $data['error'] ?? null;
        if (is_string($error)) {
            if (in_array($error, ['invalid_grant', 'interaction_required', 'consent_required', 'invalid_client', 'unauthorized_client'], true)) {
                throw new MailboxGrantFailure($error === 'consent_required' ? GrantFailureReason::ConsentMissing : GrantFailureReason::Revoked, 'The refresh token was refused (' . $error . '): run the device-code login again.');
            }

            throw new MailboxTokenUnavailable('Token endpoint error: ' . self::error($data));
        }

        return $this->tokens($data, $refreshToken);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: int, scope: string|null}
     */
    private function tokens(array $data, ?string $previousRefresh): array
    {
        if (!is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new RuntimeException('Unexpected token answer: ' . self::error($data));
        }
        $refresh = $data['refresh_token'] ?? null;
        $scope   = $data['scope'] ?? null;

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => is_string($refresh) && $refresh !== '' ? $refresh : $previousRefresh,
            'expires_at'    => time() + (is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 3600),
            'scope'         => is_string($scope) ? $scope : null,
        ];
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<mixed>
     */
    private function post(string $endpoint, array $body): array
    {
        $url = 'https://login.microsoftonline.com/' . rawurlencode($this->tenant) . '/oauth2/v2.0/' . $endpoint;

        try {
            $response = $this->httpClient->request('POST', $url, ['body' => $body, 'timeout' => 30, 'max_redirects' => 0]);
            $status   = $response->getStatusCode();
            $data     = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new RuntimeException('Microsoft identity platform unreachable (' . $exception::class . ').');
        }
        if ($status >= 500) {
            throw new RuntimeException('Microsoft identity platform answered ' . $status . '.');
        }

        return is_array($data) ? $data : [];
    }

    private function clientId(): string
    {
        if (!is_string($this->clientId) || preg_match('/^[0-9a-fA-F-]{36}\z/', $this->clientId) !== 1) {
            throw new RuntimeException('Set MAILKIT_OUTLOOK_CLIENT_ID to the Entra application (client) id.');
        }

        return $this->clientId;
    }

    /**
     * Error code and description only (never a token).
     *
     * @param array<mixed> $data
     */
    private static function error(array $data): string
    {
        $error = $data['error'] ?? 'unknown';
        $codes = $data['error_codes'] ?? [];

        return (is_string($error) ? $error : 'unknown') . (is_array($codes) && $codes !== [] && is_numeric($codes[0] ?? null) ? ' (AADSTS' . $codes[0] . ')' : '');
    }
}
