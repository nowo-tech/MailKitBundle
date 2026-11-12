<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tracking\Http;

use Closure;
use Nowo\MailKitBundle\Tracking\Message\RecordMailOpen;
use Nowo\MailKitBundle\Tracking\OpenClassifier;
use Nowo\MailKitBundle\Tracking\OpenRecorder;
use Nowo\MailKitBundle\Tracking\TrackingTokenSigner;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

use function base64_decode;
use function is_string;
use function mb_substr;
use function strlen;

/**
 * `GET /e/o/{token}.gif` (1.10, route loader `nowo_mail_kit_tracking`): always the same 1×1 transparent GIF with
 * `Cache-Control: no-store`, for valid, expired, forged and unknown tokens alike, and whatever happens behind it.
 *
 * Stateless (`_stateless`, no session, no cookie, no user): it verifies the signed token (constant-time MAC), reduces
 * the request to a machine flag, the truncated address and the user-agent family, and counts the open with one indexed
 * UPDATE — or only dispatches it with `tracking.bus`. `HEAD` answers the pixel without counting. Immutable service
 * (FrankenPHP worker mode).
 */
final readonly class TrackingPixelController
{
    /** The 43-byte transparent GIF (GIF89a, 1×1). */
    public const GIF = 'R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==';

    /** Longest user agent read. */
    private const MAX_USER_AGENT = 512;

    /**
     * Wires the endpoint.
     *
     * @param Closure(): TrackingTokenSigner|TrackingTokenSigner $signer the signer, or a factory of it (built lazily: a
     *                                                                   bad secret is logged and still answers the GIF)
     */
    public function __construct(
        private TrackingTokenSigner|Closure $signer,
        private OpenClassifier $classifier,
        private OpenRecorder $recorder,
        private ?MessageBusInterface $bus = null,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The pixel; counts the open when the token is valid.
     */
    public function __invoke(Request $request, string $token): Response
    {
        if ($request->isMethod('GET')) {
            try {
                $this->count($request, $token);
            } catch (Throwable $exception) {
                $this->logger?->warning('Mail open not recorded.', ['bundle' => 'NowoMailKitBundle', 'error' => $exception::class]);
            }
        }

        return self::pixel();
    }

    /**
     * The pixel response (also used for unknown tokens).
     */
    public static function pixel(): Response
    {
        $gif      = base64_decode(self::GIF, true);
        $response = new Response($gif, Response::HTTP_OK, [
            'Content-Type'           => 'image/gif',
            'Content-Length'         => (string) strlen($gif),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy'        => 'no-referrer',
            'X-Robots-Tag'           => 'noindex, nofollow',
            'Pragma'                 => 'no-cache',
            'Expires'                => '0',
        ]);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');

        return $response;
    }

    private function count(Request $request, string $token): void
    {
        $signer = $this->signer instanceof Closure ? ($this->signer)() : $this->signer;
        $sendId = $signer->verify($token);
        if ($sendId === null) {
            return;
        }
        $agent = $request->headers->get('User-Agent');
        $agent = is_string($agent) ? mb_substr($agent, 0, self::MAX_USER_AGENT) : null;
        $ip    = $request->getClientIp();
        $open  = new RecordMailOpen(
            $sendId,
            $this->classifier->isMachine($ip, $agent),
            $this->classifier->truncate($ip),
            $this->classifier->family($agent),
            ($this->clock ?? new NativeClock())->now()->getTimestamp(),
        );
        if ($this->bus instanceof MessageBusInterface) {
            $this->bus->dispatch($open);

            return;
        }
        $this->recorder->record($open);
    }
}
