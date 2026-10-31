<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\MailKitBundle\Dsn\DsnSource;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;
use Nowo\MailKitBundle\Form\SentMailFilterType;
use Nowo\MailKitBundle\Log\SentMailFilters;
use Nowo\MailKitBundle\Log\SentMailStatus;
use Nowo\MailKitBundle\Readiness\MailStatus;
use Nowo\MailKitBundle\Ui\AdminPages;
use Nowo\MailKitBundle\Ui\MailKitPages;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

use function count;
use function http_build_query;

/**
 * Page contexts of the REQ-UI-003 contract test: view models from {@see AdminPages} (the service the controller uses)
 * and the FormView of the real filter type.
 */
final class PageFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function index(bool $rows = true, bool $ready = true): array
    {
        $filters = new SentMailFilters();
        $list    = $rows ? [self::row(SentMailStatus::Sent), self::row(SentMailStatus::Failed, 'otp'), self::row(SentMailStatus::Queued)] : [];
        $form    = FormFactorySupport::factory()->create(SentMailFilterType::class, $filters->formData(), ['action' => '/log'])->createView();

        return [
            'page'    => self::pages()->listPage($list, new Pagination(1, 25, count($list), route: AdminPages::ROUTE_INDEX), $form, new MailStatus($ready ? DsnSource::Settings : DsnSource::Fallback, $ready ? 'smtp://smtp.example.com:587' : 'default', $ready), !$rows),
            'filters' => $filters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function show(bool $body = true): array
    {
        $row  = self::row(SentMailStatus::Sent, 'invoice');
        $data = $body ? new SentMailBody($row->getId(), '<p>Hello</p>', "Hello\n<script>alert(1)</script>") : null;

        return ['page' => self::pages()->showPage($row, $data), 'entry' => $row, 'body' => $data];
    }

    public static function row(SentMailStatus $status, ?string $tag = null): SentMail
    {
        return new SentMail('018f2c4e-1111-7000-8000-00000000000' . match ($status) {
            SentMailStatus::Sent   => '1',
            SentMailStatus::Failed => '2',
            SentMailStatus::Queued => '3',
        }, new DateTimeImmutable('2026-10-31 10:00:00'), $status, 'Your invoice', 'billing@example.com', ['to' => ['alice@example.com'], 'cc' => ['bob@example.com']], 'smtp', $tag, 'provider-42', 2048, $status === SentMailStatus::Failed ? '550 mailbox unavailable' : null, 1, new DateTimeImmutable('2026-10-31 10:00:01'));
    }

    public static function pages(): AdminPages
    {
        return new AdminPages(self::urlGenerator(), new MailKitPages());
    }

    public static function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters));
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }
}
