<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\DemoLegacyResendType;
use App\Form\DemoSendType;
use App\Http\ResendDemoApi;
use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Nowo\MailKitBundle\Settings\LegacyResendSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

use function in_array;

/**
 * Demo home: the current MailKit status and a form that sends a message through the plain MailerInterface (the
 * DSN and the sender come from the settings at runtime).
 */
final class DemoController extends AbstractController
{
    #[Route('/', name: 'demo_home', methods: ['GET', 'POST'])]
    public function home(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(DemoSendType::class, ['to' => 'alice@example.com', 'subject' => 'Hello from the demo']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{to: string, subject: string} $data */
            $data  = $form->getData();
            $email = (new Email())->to($data['to'])->subject($data['subject'])->text('Sent by the Mail Kit demo through MailerInterface.');
            MailHeaders::tag($email, 'demo');
            $mailer->send($email);
            $this->addFlash('success', 'Message sent — see the sent-mail log and Mailpit.');

            return $this->redirectToRoute('demo_home');
        }

        return $this->render('home.html.twig', ['form' => $form, 'legacy' => $this->createForm(DemoLegacyResendType::class, null, ['action' => $this->generateUrl('demo_legacy_resend')])]);
    }

    /**
     * Demo only: rewrites the stored settings as MailKit 1.2 left them (`resend.enabled = true`, `resend.api_key`,
     * `resend.from`; no `mailer.provider` / Resend fields in the mailer section), so the 1.3 upgrade path can be tried:
     * the mailer page pre-selects Resend and saving it keeps Resend live.
     */
    #[Route('/demo/legacy-resend', name: 'demo_legacy_resend', methods: ['POST'])]
    public function legacyResend(Request $request, SettingValueRepositoryInterface $repository, LegacyResendSettings $legacy): Response
    {
        $form = $this->createForm(DemoLegacyResendType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $remove = [];
            foreach ([...$repository->findBySection('mailer'), ...$repository->findBySection('resend')] as $row) {
                if ($row->getSection() === 'resend' || in_array($row->getName(), ['provider', 'resend_api_key', 'resend_from'], true)) {
                    $remove[] = $row;
                }
            }
            $repository->apply([], $remove);
            $now = new DateTimeImmutable();
            $repository->apply([
                (new SettingValue('resend', 'enabled', $now))->storeValue(true, $now),
                (new SettingValue('resend', 'api_key', $now))->storeSecret(ResendDemoApi::VALID_PREFIX . 'legacy_12_key', $now),
                (new SettingValue('resend', 'from', $now))->storeValue('legacy@example.com', $now),
            ], []);
            $legacy->reset();
            $this->addFlash('success', 'Settings rewritten as MailKit 1.2 left them (Resend on).');
        }

        return $this->redirectToRoute('demo_home');
    }
}
