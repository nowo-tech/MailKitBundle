<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Ui;

use DateTimeInterface;
use Nowo\MailKitBundle\Entity\SentMail;
use Nowo\MailKitBundle\Entity\SentMailBody;
use Nowo\MailKitBundle\Readiness\MailStatus;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Crumb;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FilterField;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\ShowPage;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function implode;

/**
 * Builds the UiKit view models of the sent-mail log pages (REQ-UI-003): the list (`log/index.html.twig`, list
 * scaffold) and the detail (`log/show.html.twig`, show scaffold). Plain data in, immutable view models out — no
 * request state is kept (worker safe).
 */
final readonly class AdminPages
{
    /** Route of the list. */
    public const ROUTE_INDEX = 'nowo_mail_kit_log';

    /** Route of the detail. */
    public const ROUTE_SHOW = 'nowo_mail_kit_log_show';

    /** Route of the sandboxed HTML body. */
    public const ROUTE_BODY = 'nowo_mail_kit_log_body';

    /**
     * @param MailKitPages $pages layout / template configuration
     */
    public function __construct(
        private UrlGeneratorInterface $urls,
        private MailKitPages $pages,
    ) {
    }

    /**
     * List page: header with the redacted current transport, filters, table, pagination, empty state.
     *
     * @param list<SentMail> $rows entries of the current page
     */
    public function listPage(array $rows, Pagination $pagination, FormView $filters, MailStatus $status, bool $filtered): ListPage
    {
        return new ListPage(
            new PageHeader(
                MailKitPages::label('log.index.title'),
                MailKitPages::label($status->ready ? 'log.index.intro_ready' : 'log.index.intro_not_ready', [
                    '%transport%' => $status->label,
                    '%source%'    => $status->source->value,
                    '%count%'     => $pagination->total,
                ]),
            ),
            [
                new Column('queuedAt', MailKitPages::label('log.column.queued_at'), required: true),
                new Column('status', MailKitPages::label('log.column.status'), required: true),
                new Column('subject', MailKitPages::label('log.column.subject'), required: true),
                new Column('to', MailKitPages::label('log.column.to'), priority: 2),
                new Column('tag', MailKitPages::label('log.column.tag'), priority: 3),
                new Column('transport', MailKitPages::label('log.column.transport'), priority: 3),
            ],
            $rows,
            $filters,
            fn (SentMail $row): array => [
                Action::view($this->urls->generate(self::ROUTE_SHOW, ['id' => $row->getId()]), MailKitPages::label('log.action.view')),
            ],
            $pagination,
            new EmptyState(MailKitPages::label($filtered ? 'log.index.empty_filtered' : 'log.index.empty')),
            $filtered ? $this->urls->generate(self::ROUTE_INDEX) : null,
            bundle: MailKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'mail-kit-log'],
            filterFields: [
                'q'      => new FilterField('q', MailKitPages::label('log.filter.q_placeholder'), width: 'lg'),
                'status' => new FilterField('status', width: 'sm'),
                'period' => new FilterField('period', width: 'sm'),
            ],
            filtersSubmit: new Action('filter', MailKitPages::label('log.action.filter')),
        );
    }

    /**
     * Detail page: envelope, delivery and (when stored) a link to the sandboxed HTML body.
     */
    public function showPage(SentMail $row, ?SentMailBody $body): ShowPage
    {
        $index   = $this->urls->generate(self::ROUTE_INDEX);
        $title   = MailKitPages::label('log.show.title');
        $actions = [new Action('prev', MailKitPages::label('log.action.back'), href: $index)];
        if ($body?->getHtmlBody() !== null) {
            $actions[] = new Action('view', MailKitPages::label('log.action.html_body'), href: $this->urls->generate(self::ROUTE_BODY, ['id' => $row->getId()]), attributes: ['target' => '_blank', 'rel' => 'noopener noreferrer']);
        }
        $recipients = $row->getRecipients();

        return new ShowPage(
            new PageHeader($title, null, $actions, [new Crumb(MailKitPages::label('log.index.title'), $index), new Crumb($title)]),
            [
                new DetailSection(MailKitPages::label('log.show.envelope'), [
                    new DetailItem(MailKitPages::label('log.column.subject'), $row->getSubject()),
                    new DetailItem(MailKitPages::label('log.field.sender'), $row->getSender()),
                    new DetailItem(MailKitPages::label('log.column.to'), implode(', ', $recipients['to'] ?? [])),
                    new DetailItem(MailKitPages::label('log.field.cc'), implode(', ', $recipients['cc'] ?? [])),
                    new DetailItem(MailKitPages::label('log.field.bcc'), implode(', ', $recipients['bcc'] ?? [])),
                    new DetailItem(MailKitPages::label('log.column.tag'), $row->getTag()),
                ], 'mail-kit-envelope'),
                new DetailSection(MailKitPages::label('log.show.delivery'), [
                    new DetailItem(MailKitPages::label('log.column.status'), MailKitPages::label('log.status.' . $row->getStatus()->value)),
                    new DetailItem(MailKitPages::label('log.column.transport'), $row->getTransport()),
                    new DetailItem(MailKitPages::label('log.field.provider_message_id'), $row->getProviderMessageId()),
                    new DetailItem(MailKitPages::label('log.field.size'), $row->getSize()),
                    new DetailItem(MailKitPages::label('log.field.attempts'), $row->getAttempts()),
                    new DetailItem(MailKitPages::label('log.column.queued_at'), $row->getQueuedAt()->format(DateTimeInterface::ATOM)),
                    new DetailItem(MailKitPages::label('log.field.sent_at'), $row->getSentAt()?->format(DateTimeInterface::ATOM)),
                    new DetailItem(MailKitPages::label('log.field.failed_at'), $row->getFailedAt()?->format(DateTimeInterface::ATOM)),
                    new DetailItem(MailKitPages::label('log.field.error'), $row->getError()),
                ], 'mail-kit-delivery'),
            ],
            bundle: MailKitPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'mail-kit-log-show'],
        );
    }
}
