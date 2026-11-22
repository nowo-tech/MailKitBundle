<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Integration\Contract;

use Nowo\MailKitBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;

use function dirname;

/**
 * REQ-UI-003 page contract: the sent-mail log pages extend the UiKit list / show scaffolds; no <html>, CDN, inline
 * handler or confirm(); framework classes only through theme slots; the stub theme is reflected; no raw or missing
 * translation (strict rule); a stored text body is escaped.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        yield 'log list' => new PageFixture('@NowoMailKitBundle/log/index.html.twig', PageFixtures::index());
        yield 'log list (empty, not ready)' => new PageFixture('@NowoMailKitBundle/log/index.html.twig', PageFixtures::index(false, false));
        yield 'log list (empty, filtered)' => new PageFixture('@NowoMailKitBundle/log/index.html.twig', PageFixtures::index(false, true, true));
        yield 'log entry (bodies stored)' => new PageFixture('@NowoMailKitBundle/log/show.html.twig', PageFixtures::show());
        yield 'log entry (no body)' => new PageFixture('@NowoMailKitBundle/log/show.html.twig', PageFixtures::show(false));
    }

    public function testAnEmptyLogIsOneSentenceThroughTheUiKitEmptyState(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoMailKitBundle/log/index.html.twig', PageFixtures::index(false, true, false)))->html;

        self::assertSame(1, substr_count($html, 'nowo-ui-empty__message'), 'One message.');
        self::assertStringContainsString('nowo-ui-empty__message">No e-mail has been sent yet.</p>', $html);
        self::assertStringNotContainsString('nowo-ui-empty__title', $html, 'No title line next to the sentence.');
        self::assertStringNotContainsString('No items found', $html, 'No second default line.');
        self::assertStringNotContainsString('nowo-ui-empty__action', $html, 'Nothing to offer on an empty, unfiltered log.');
    }

    public function testAnEmptyFilteredLogOffersToClearTheFilters(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoMailKitBundle/log/index.html.twig', PageFixtures::index(false, true, true)))->html;

        self::assertStringContainsString('nowo-ui-empty__message">No e-mail matches these filters.</p>', $html);
        self::assertStringNotContainsString('No items found', $html);
        self::assertMatchesRegularExpression('#nowo-ui-empty__action.*data-testid="mail-kit-clear-filters"#s', $html);
        self::assertStringContainsString('Clear filters', $html);
    }

    public function testTheStoredTextBodyIsEscaped(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoMailKitBundle/log/show.html.twig', PageFixtures::show()))->html;

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    protected static function templatePaths(): array
    {
        return ['NowoMailKitBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}
