<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Ui;

use Nowo\FormKitBundle\Form\CsrfOnlyFormFactory;
use Nowo\MailKitBundle\Controller\MailKitController;
use Nowo\MailKitBundle\NowoMailKitBundle;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * UiKit actions rendered by Instance Settings after the Save button of the MailKit sections: POST buttons carrying a
 * FormKit CSRF-only form (`csrf_only[_token]`, REQ-SEC-005), handled by {@see MailKitController}. Built per
 * call (no state kept between requests).
 */
final readonly class SettingsActions
{
    /** Route and CSRF token id of the mailer test. */
    public const MAILER_TEST = 'nowo_mail_kit_test';

    /** Route and CSRF token id of the Resend key check. */
    public const RESEND_CHECK = 'nowo_mail_kit_resend_check';

    /** Route and CSRF token id of the Resend test. */
    public const RESEND_TEST = 'nowo_mail_kit_resend_test';

    /**
     * @param CsrfOnlyFormFactory $forms FormKit CSRF-only forms
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private CsrfOnlyFormFactory $forms,
    ) {
    }

    /**
     * "Send a test e-mail" of the mailer section.
     *
     * @return list<Action>
     */
    public function mailer(): array
    {
        return [$this->action(self::MAILER_TEST, 'settings.mailer.action.send_test')];
    }

    /**
     * "Check the API key" and "Send a test e-mail with Resend" of the Resend section.
     *
     * @return list<Action>
     */
    public function resend(): array
    {
        return [
            $this->action(self::RESEND_CHECK, 'settings.resend.action.check'),
            $this->action(self::RESEND_TEST, 'settings.resend.action.send_test'),
        ];
    }

    private function action(string $route, string $label): Action
    {
        $url = $this->urls->generate($route);

        return new Action(
            'custom',
            new Label($label, NowoMailKitBundle::TRANSLATION_DOMAIN),
            href: $url,
            method: 'POST',
            variant: 'secondary',
            attributes: ['data-testid' => $route],
            form: $this->forms->createNamed($url, $route)->createView(),
        );
    }
}
