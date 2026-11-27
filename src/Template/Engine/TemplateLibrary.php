<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Template\Engine;

use Nowo\MailKitBundle\Mailbox\Html\MailHtmlSanitizer;
use Nowo\MailKitBundle\Template\Event\TemplateDeletedEvent;
use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
use Nowo\MailKitBundle\Template\Exception\TemplateAccessDenied;
use Nowo\MailKitBundle\Template\Exception\TemplateKeyTaken;
use Nowo\MailKitBundle\Template\Exception\TemplateNotFound;
use Nowo\MailKitBundle\Template\Exception\TemplateVersionConflict;
use Nowo\MailKitBundle\Template\Infrastructure\Store\TemplateStore;
use Nowo\MailKitBundle\Template\Port\TemplatePermissionInterface;
use Nowo\MailKitBundle\Template\Service\TemplateLibraryInterface;
use Nowo\MailKitBundle\Template\Value\StoredTemplate;
use Nowo\MailKitBundle\Template\Value\TemplateActor;
use Nowo\MailKitBundle\Template\Value\TemplateDraft;
use Nowo\MailKitBundle\Template\Value\TemplateKind;
use Nowo\MailKitBundle\Template\Value\TemplatePermission;
use Nowo\MailKitBundle\Template\Value\TemplateScope;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

use function explode;
use function html_entity_decode;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function rawurldecode;
use function str_contains;
use function str_replace;
use function strlen;
use function strtolower;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PREG_SET_ORDER;

/**
 * {@see TemplateLibraryInterface} on the {@see TemplateStore} (2.7.0). Access rules, in this order: the tenant is part
 * of every query; a personal row is visible to its owner only and only while the host grants
 * {@see TemplatePermission::Personal}; a shared row is visible with {@see TemplatePermission::UseShared} or
 * {@see TemplatePermission::ManageShared}; changing a row needs `Personal` (own personal rows) or `ManageShared`.
 * The HTML body is sanitised before it is stored. Stateless (worker mode).
 *
 * @internal
 */
final readonly class TemplateLibrary implements TemplateLibraryInterface
{
    /** Private-use sentinel of a `{` written as a character reference (ZCZ-I3). */
    private const OPEN_SENTINEL = "\u{E000}";

    /** Private-use sentinel of a `}` written as a character reference (ZCZ-I3). */
    private const CLOSE_SENTINEL = "\u{E001}";

    /** The sentinels themselves, removed from the input. */
    private const BRACE_REFERENCES = '/[\x{E000}\x{E001}]/u';

    /** Character references of `{` and `}` (decimal, hexadecimal, named; the `;` is optional for numeric ones). */
    private const BRACE_ENTITY = '/&#0*12[35](?![0-9]);?|&#x0*7[bd](?![0-9a-f]);?|&(?:lbrace|rbrace|lcub|rcub);/i';

    /**
     * @param int $maxBodyBytes `templates.max_body_bytes` (each body, before sanitising)
     * @param int $maxPerScope `templates.max_per_scope` (rows per tenant, kind, scope and owner; also the list cap)
     */
    public function __construct(
        private TemplateStore $store,
        private TemplatePermissionInterface $permission,
        private PlaceholderResolver $placeholders,
        private MailHtmlSanitizer $sanitizer,
        private int $maxBodyBytes,
        private int $maxPerScope,
        private ?EventDispatcherInterface $events = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function list(TemplateActor $actor, TemplateKind $kind, ?TemplateScope $scope = null, ?string $locale = null): array
    {
        $personal = $scope !== TemplateScope::Shared && $this->granted(TemplatePermission::Personal, $actor);
        $shared   = $scope !== TemplateScope::Personal && $this->mayUseShared($actor);

        return $this->store->summaries($actor->tenant, $kind, $personal ? $actor->owner : null, $shared, $locale, $this->maxPerScope * 2);
    }

    /**
     * {@inheritdoc}
     */
    public function get(TemplateActor $actor, string $id): StoredTemplate
    {
        $template = preg_match('/^[0-9a-f-]{36}\z/', $id) === 1 ? $this->store->find($actor->tenant, $id) : null;
        if (!$template instanceof StoredTemplate || !$this->maySee($actor, $template)) {
            throw new TemplateNotFound();
        }

        return $template;
    }

    /**
     * {@inheritdoc}
     */
    public function findByKey(TemplateActor $actor, TemplateKind $kind, TemplateScope $scope, string $key, string $locale): StoredTemplate
    {
        $candidates = [$locale];
        if (str_contains($locale, '_')) {
            $candidates[] = explode('_', $locale, 2)[0];
        }
        foreach ($candidates as $candidate) {
            $template = $this->store->findByKey($actor->tenant, $kind, $scope, $scope === TemplateScope::Personal ? $actor->owner : '', $key, $candidate);
            if ($template instanceof StoredTemplate && $this->maySee($actor, $template)) {
                return $template;
            }
        }

        throw new TemplateNotFound();
    }

    /**
     * {@inheritdoc}
     */
    public function create(TemplateActor $actor, TemplateDraft $draft): StoredTemplate
    {
        $this->assertManage($actor, $draft->scope);
        $html     = $this->prepare($draft);
        $ownerKey = $draft->scope === TemplateScope::Personal ? $actor->owner : '';
        // A soft cap: concurrent creates may pass it together (bounded by the number of concurrent requests).
        if ($this->store->count($actor->tenant, $draft->kind, $draft->scope, $ownerKey) >= $this->maxPerScope) {
            throw new InvalidTemplate('scope', 'There are too many templates in this scope.');
        }
        $id  = Uuid::v7()->toRfc4122();
        $now = $this->now();
        $row = [
            'id'           => $id,
            'tenant'       => $actor->tenant,
            'kind'         => $draft->kind->value,
            'scope'        => $draft->scope->value,
            'owner_id'     => $ownerKey,
            'template_key' => $draft->key,
            'locale'       => $draft->locale,
            'name'         => $draft->name,
            'subject'      => $draft->subject,
            'body_html'    => $html,
            'body_text'    => $draft->text,
            'created_by'   => $actor->owner,
            'created_at'   => $now,
        ];
        if (!$this->store->insert($row)) {
            throw new TemplateKeyTaken();
        }

        return $this->store->find($actor->tenant, $id) ?? throw new TemplateNotFound();
    }

    /**
     * {@inheritdoc}
     */
    public function update(TemplateActor $actor, string $id, int $expectedVersion, TemplateDraft $draft): StoredTemplate
    {
        $current = $this->get($actor, $id);
        $this->assertManage($actor, $current->scope);
        if ($draft->kind !== $current->kind || $draft->scope !== $current->scope) {
            throw new InvalidTemplate('scope', 'The kind and scope of a template cannot change.');
        }
        $html     = $this->prepare($draft);
        $ownerKey = $current->owner ?? '';
        $updated  = $this->store->update($actor->tenant, $id, $current->scope, $ownerKey, $expectedVersion, [
            'template_key' => $draft->key,
            'locale'       => $draft->locale,
            'name'         => $draft->name,
            'subject'      => $draft->subject,
            'body_html'    => $html,
            'body_text'    => $draft->text,
            'updated_by'   => $actor->owner,
            'updated_at'   => $this->now(),
        ]);
        if ($updated !== 1) {
            $this->get($actor, $id);

            throw new TemplateVersionConflict($expectedVersion);
        }

        return $this->get($actor, $id);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(TemplateActor $actor, string $id, int $expectedVersion): void
    {
        $current = $this->get($actor, $id);
        $this->assertManage($actor, $current->scope);
        // ZCZ-L2: the statement and the listeners run in one transaction on the store connection (a savepoint inside the
        // host's), so a listener that throws rolls the delete back and a retry dispatches the event again.
        $deleted = $this->store->transactional(function () use ($actor, $id, $current, $expectedVersion): bool {
            if ($this->store->delete($actor->tenant, $id, $current->scope, $current->owner ?? '', $expectedVersion) !== 1) {
                return false;
            }
            $this->events?->dispatch(new TemplateDeletedEvent($id, $actor->tenant, $current->kind, $current->scope));

            return true;
        });
        if (!$deleted) {
            $this->get($actor, $id);

            throw new TemplateVersionConflict($expectedVersion);
        }
    }

    /**
     * Checks the draft's sizes and placeholder count and returns its sanitised HTML (empty for a text-only draft).
     *
     * @throws InvalidTemplate
     */
    public function prepare(TemplateDraft $draft): string
    {
        if (strlen($draft->html) > $this->maxBodyBytes || strlen($draft->text) > $this->maxBodyBytes) {
            throw new InvalidTemplate('body', 'A body is larger than the configured limit.');
        }
        $this->placeholders->assertCount($draft->subject, $draft->html, $draft->text);
        if (trim($draft->html) === '') {
            return '';
        }
        // ZCZ-I3: braces written as character references (`&#123;&#123; x.y &#125;&#125;`) are decoded by the sanitiser;
        // they travel through it as private-use sentinels and come back as references, so they never form a live
        // placeholder. Sentinels already in the input are removed first.
        $html = (string) preg_replace(self::BRACE_REFERENCES, '', $draft->html);
        $html = (string) preg_replace_callback(self::BRACE_ENTITY, static fn (array $m): string => preg_match('/125|7d|rbrace|rcub/i', $m[0]) === 1 ? self::CLOSE_SENTINEL : self::OPEN_SENTINEL, $html);
        $safe = str_replace([self::OPEN_SENTINEL, self::CLOSE_SENTINEL], ['&#123;', '&#125;'], $this->sanitizer->sanitize($html));
        self::assertNoPlaceholderInUrls($safe);

        return $safe;
    }

    /**
     * ZCZ-L1: a placeholder is refused in every attribute value of the sanitised HTML except `title` and `alt`
     * (an address such as `href` / `src` would receive the value HTML-escaped but not URL-encoded), also in its
     * percent-encoded or character-reference form.
     *
     * @throws InvalidTemplate
     */
    public static function assertNoPlaceholderInUrls(string $html): void
    {
        preg_match_all('/<[a-zA-Z][^>]*>/', $html, $tags);
        foreach ($tags[0] as $tag) {
            preg_match_all('/\s([a-zA-Z][a-zA-Z0-9:_.-]*)\s*=\s*"([^"]*)"/', $tag, $attributes, PREG_SET_ORDER);
            foreach ($attributes as [, $name, $value]) {
                if (in_array(strtolower($name), ['title', 'alt'], true)) {
                    continue;
                }
                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                foreach ([$decoded, rawurldecode($decoded), rawurldecode(rawurldecode($decoded))] as $candidate) {
                    if (preg_match('/\{\{\s{0,8}[a-z][a-z0-9_]{0,31}\.[a-z0-9_]{1,40}\s{0,8}\}\}/', $candidate) === 1) {
                        throw new InvalidTemplate('html', 'Placeholders cannot be used inside a link or image address; put them in the text.');
                    }
                }
            }
        }
    }

    /**
     * Whether the actor may see a row of its tenant.
     */
    public function maySee(TemplateActor $actor, StoredTemplate $template): bool
    {
        if ($template->tenant !== $actor->tenant) {
            return false;
        }

        return $template->scope === TemplateScope::Personal
            ? $template->owner === $actor->owner && $this->granted(TemplatePermission::Personal, $actor)
            : $this->mayUseShared($actor);
    }

    /**
     * Throws unless the actor may manage rows of that scope.
     *
     * @throws TemplateAccessDenied
     */
    public function assertManage(TemplateActor $actor, TemplateScope $scope): void
    {
        $permission = $scope === TemplateScope::Personal ? TemplatePermission::Personal : TemplatePermission::ManageShared;
        if (!$this->granted($permission, $actor)) {
            throw new TemplateAccessDenied($permission);
        }
    }

    private function mayUseShared(TemplateActor $actor): bool
    {
        return $this->granted(TemplatePermission::UseShared, $actor) || $this->granted(TemplatePermission::ManageShared, $actor);
    }

    private function granted(TemplatePermission $permission, TemplateActor $actor): bool
    {
        return $this->permission->isGranted($permission, $actor);
    }

    private function now(): int
    {
        return ($this->clock ?? new NativeClock())->now()->getTimestamp();
    }
}
