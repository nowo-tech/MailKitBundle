<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Engine;

use DateTimeInterface;
use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;
use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateStore;
use Nowo\MailKitBundle\Template\Service\TemplatePersonalDataInterface;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateErasureResult;
use Psr\EventDispatcher\EventDispatcherInterface;

use function count;

/**
 * {@see TemplatePersonalDataInterface} on the {@see TemplateStore} (2.7.0). The deleted-row events are dispatched
 * after the erasure transaction ran (inside an open host transaction: before the host commits). Stateless.
 *
 * @internal
 */
final readonly class TemplatePersonalData implements TemplatePersonalDataInterface
{
    /**
     * Wires the port.
     */
    public function __construct(
        private TemplateStore $store,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function exportOwner(string $owner): array
    {
        TemplateActor::assertRef($owner);
        $rows = [];
        foreach ($this->store->personalRows($owner) as $template) {
            $rows[] = $this->row($template, 'owner', true);
        }
        foreach ($this->store->authoredSharedRows($owner) as $template) {
            $rows[] = $this->row($template, $template->createdBy === $owner ? 'author' : 'last_author', false);
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    public function eraseOwner(string $owner): TemplateErasureResult
    {
        TemplateActor::assertRef($owner);
        $result = $this->store->eraseOwner($owner);
        foreach ($result['deleted'] as $template) {
            $this->events?->dispatch(new TemplateDeletedEvent($template->id, $template->tenant, $template->kind, $template->scope));
        }

        return new TemplateErasureResult(count($result['deleted']), $result['anonymised']);
    }

    /**
     * @return array{id: string, tenant: string, kind: string, scope: string, role: string, key: string, name: string, locale: string, subject: string|null, html: string|null, text: string|null, created_at: string, updated_at: string}
     */
    private function row(StoredTemplate $template, string $role, bool $content): array
    {
        return [
            'id'         => $template->id,
            'tenant'     => $template->tenant,
            'kind'       => $template->kind->value,
            'scope'      => $template->scope->value,
            'role'       => $role,
            'key'        => $template->key,
            'name'       => $template->name,
            'locale'     => $template->locale,
            'subject'    => $content ? $template->subject : null,
            'html'       => $content ? $template->html : null,
            'text'       => $content ? $template->text : null,
            'created_at' => $template->createdAt->format(DateTimeInterface::ATOM),
            'updated_at' => $template->updatedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
