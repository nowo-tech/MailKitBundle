<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\DemoSendType;
use Nowo\MailKitBundle\Mailer\MailHeaders;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

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

        return $this->render('home.html.twig', ['form' => $form]);
    }
}
