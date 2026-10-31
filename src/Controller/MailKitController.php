<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Controller;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\EventListener\MailKitAccessListener;
use Nowo\MailKitBundle\Form\SentMailFilterType;
use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailRepository;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\MailKitBundle\Readiness\OutboundMailReadiness;
use Nowo\MailKitBundle\Resend\ResendApiChecker;
use Nowo\MailKitBundle\Security\AccessGuard;
use Nowo\MailKitBundle\Settings\MailerSettingsSection;
use Nowo\MailKitBundle\Settings\MailSettings;
use Nowo\MailKitBundle\Settings\ResendSettingsSection;
use Nowo\MailKitBundle\TestMail\TestMailGuard;
use Nowo\MailKitBundle\TestMail\TestMailOutcome;
use Nowo\MailKitBundle\TestMail\TestMailResult;
use Nowo\MailKitBundle\TestMail\TestMailSender;
use Nowo\MailKitBundle\Ui\AdminPages;
use Nowo\MailKitBundle\Ui\MailKitPages;
use Nowo\MailKitBundle\Ui\SettingsActions;
use Nowo\UiKitBundle\View\Pagination;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_unique;
use function array_values;
use function implode;
use function sort;
use function sprintf;

/**
 * Admin UI of MailKit (REQ-UI-002: the {@see MailKitAccessListener} checks every
 * request under `web_ui.path_prefix`; each action checks again). Routes come only from the `nowo_mail_kit` route
 * loader (REQ-SF-004 rule 7).
 *
 * - `GET  /log`, `/log/{id}`: sent-mail log list and detail (UiKit list / show scaffolds, `no-store`).
 * - `GET  /log/{id}/body`: stored HTML body in a sandboxed, script-less response (only with `log.store_bodies`).
 * - `POST /actions/test`, `/actions/resend/check`, `/actions/resend/test`: the settings actions — CSRF (FormKit
 *   CSRF-only form, fail closed), MailKit access **and** Instance Settings section access, per-user rate limit for
 *   the test e-mails; they redirect back to the settings section with a flash.
 */
final class MailKitController extends AbstractController
{
    /** CSP of the stored HTML body: no script, no network except inline images, sandboxed. */
    public const BODY_CSP = "default-src 'none'; img-src data:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox";

    /**
     * @param int $pageSize `web_ui.page_size`
     */
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly CsrfOnlyFormFactory $csrfForms,
        private readonly MailKitPages $pages,
        private readonly AdminPages $adminPages,
        private readonly OutboundMailReadiness $readiness,
        private readonly TestMailSender $testMail,
        private readonly TestMailGuard $testMailGuard,
        private readonly MailSettings $settings,
        private readonly ?SentMailRepository $repository = null,
        private readonly ?ResendApiChecker $resendChecker = null,
        private readonly int $pageSize = 25,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Sent-mail log list.
     */
    #[Route(path: '/log', name: AdminPages::ROUTE_INDEX, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $repository = $this->repository();
        $filters    = SentMailFilters::fromRequest($request, $this->clock->now());
        $allowed    = array_values(array_unique([$this->pageSize, 10, 25, 50, 100]));
        sort($allowed);
        $pagination = Pagination::fromRequest($request, $repository->countFiltered($filters), $this->pageSize, $allowed, AdminPages::ROUTE_INDEX);
        $form       = $this->createForm(SentMailFilterType::class, $filters->formData(), [
            'action' => $this->generateUrl(AdminPages::ROUTE_INDEX),
        ]);
        $rows = $repository->findFiltered($filters, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);

        return $this->noStore($this->render($this->pages->template('log_index'), [
            'page'    => $this->adminPages->listPage($rows, $pagination, $form->createView(), $this->readiness->status(), $filters->isFiltered()),
            'filters' => $filters,
        ]));
    }

    /**
     * Sent-mail log detail.
     */
    #[Route(path: '/log/{id}', name: AdminPages::ROUTE_SHOW, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): Response
    {
        $repository = $this->repository();
        $row        = $repository->find($id);
        if (!$row instanceof SentMail) {
            throw new NotFoundHttpException('Unknown sent-mail log entry.');
        }
        $body = $repository->findBody($id);

        return $this->noStore($this->render($this->pages->template('log_show'), [
            'page'  => $this->adminPages->showPage($row, $body),
            'entry' => $row,
            'body'  => $body,
        ]));
    }

    /**
     * Stored HTML body, sandboxed (no script, no remote loads, no forms).
     */
    #[Route(path: '/log/{id}/body', name: AdminPages::ROUTE_BODY, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function body(string $id): Response
    {
        $html = $this->repository()->findBody($id)?->getHtmlBody();
        if ($html === null) {
            throw new NotFoundHttpException('No stored HTML body.');
        }

        return $this->noStore(new Response($html, Response::HTTP_OK, [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => self::BODY_CSP,
            'X-Content-Type-Options'  => 'nosniff',
            'X-Robots-Tag'            => 'noindex',
            'Referrer-Policy'         => 'no-referrer',
        ]));
    }

    /**
     * "Send a test e-mail" of the mailer section.
     */
    #[Route(path: '/actions/test', name: SettingsActions::MAILER_TEST, methods: ['POST'])]
    public function sendTest(Request $request): Response
    {
        $this->guardAction($request, SettingsActions::MAILER_TEST, MailerSettingsSection::NAME);
        $result = $this->rateLimited($request) ? new TestMailResult(TestMailOutcome::RateLimited) : $this->testMail->sendMailer(null, $this->guard->user());
        $this->flashResult($result);

        return $this->backTo(MailerSettingsSection::NAME);
    }

    /**
     * "Check the API key" of the Resend section.
     */
    #[Route(path: '/actions/resend/check', name: SettingsActions::RESEND_CHECK, methods: ['POST'])]
    public function resendCheck(Request $request): Response
    {
        $this->guardAction($request, SettingsActions::RESEND_CHECK, ResendSettingsSection::NAME);
        $key = $this->settings->resendApiKey();
        if ($key === null || !$this->resendChecker instanceof ResendApiChecker) {
            $this->addFlash('error', $this->trans('resend.check.no_key'));

            return $this->backTo(ResendSettingsSection::NAME);
        }

        $result = $this->resendChecker->check($key);
        if (!$result->ok) {
            $this->addFlash('error', $this->trans('resend.check.failed', ['%error%' => (string) $result->error]));
        } elseif ($result->domains === null) {
            $this->addFlash('success', $this->trans('resend.check.ok_sending_only'));
        } else {
            $domains = [];
            foreach ($result->domains as $name => $state) {
                $domains[] = sprintf('%s (%s)', $name, $state);
            }
            $this->addFlash('success', $this->trans('resend.check.ok', ['%domains%' => $domains === [] ? '—' : implode(', ', $domains)]));
        }

        return $this->backTo(ResendSettingsSection::NAME);
    }

    /**
     * "Send a test e-mail with Resend" of the Resend section.
     */
    #[Route(path: '/actions/resend/test', name: SettingsActions::RESEND_TEST, methods: ['POST'])]
    public function resendTest(Request $request): Response
    {
        $this->guardAction($request, SettingsActions::RESEND_TEST, ResendSettingsSection::NAME);
        $result = $this->rateLimited($request) ? new TestMailResult(TestMailOutcome::RateLimited) : $this->testMail->sendResend(null, $this->guard->user());
        $this->flashResult($result);

        return $this->backTo(ResendSettingsSection::NAME);
    }

    /**
     * Access (MailKit + Instance Settings section) and CSRF of a settings action; denies with 403 otherwise.
     */
    private function guardAction(Request $request, string $tokenId, string $section): void
    {
        if (!$this->guard->isSectionGranted($section)) {
            $this->guard->deny('Access denied to this mail settings action.');
        }
        $form = $this->csrfForms->createNamed($this->generateUrl($tokenId), $tokenId);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }

    private function rateLimited(Request $request): bool
    {
        $user = $this->guard->user();
        $key  = $user instanceof UserInterface ? 'user:' . $user->getUserIdentifier() : 'ip:' . $request->getClientIp();

        return !$this->testMailGuard->allows($key);
    }

    private function flashResult(TestMailResult $result): void
    {
        $this->addFlash($result->isSent() ? 'success' : 'error', $this->trans($result->outcome->messageKey(), $result->parameters()));
    }

    private function backTo(string $section): Response
    {
        return $this->noStore($this->redirectToRoute(InstanceSettingsRoutes::SECTION, ['section' => $section]));
    }

    private function repository(): SentMailRepository
    {
        return $this->repository ?? throw new NotFoundHttpException('The sent-mail log is disabled.');
    }

    private function noStore(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @param array<string, string> $params
     */
    private function trans(string $key, array $params = []): string
    {
        return $this->translator?->trans($key, $params, NowoMailKitBundle::TRANSLATION_DOMAIN) ?? $key;
    }
}
