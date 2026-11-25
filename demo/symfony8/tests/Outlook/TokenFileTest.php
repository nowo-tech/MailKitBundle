<?php

declare(strict_types=1);

namespace App\Tests\Outlook;

use App\Outlook\TokenFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function in_array;

final class TokenFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/outlook-harness-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (is_dir($entry) && !in_array(basename($entry), ['.', '..'], true)) {
                @rmdir($entry);
            } elseif (is_file($entry)) {
                @unlink($entry);
            }
        }
        @rmdir($this->dir);
    }

    public function testRefusesAFileInsideAForbiddenRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inside the repository tree');

        new TokenFile($this->dir . '/token.json', [$this->dir]);
    }

    public function testRefusesAFileInsideAGitWorkTree(): void
    {
        mkdir($this->dir . '/.git');
        mkdir($this->dir . '/sub');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('git work tree');

            new TokenFile($this->dir . '/sub/token.json', []);
        } finally {
            rmdir($this->dir . '/sub');
            rmdir($this->dir . '/.git');
        }
    }

    public function testRefusesAnEmptyPathOrAMissingDirectory(): void
    {
        foreach (['', $this->dir . '/missing/token.json'] as $path) {
            try {
                new TokenFile($path, []);
                self::fail('Accepted ' . $path);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWritesWithMode0600AndReadsBack(): void
    {
        $file = new TokenFile($this->dir . '/token.json', ['/nonexistent-root']);
        $file->write(['access_token' => 'abc.def', 'refresh_token' => 'r1', 'expires_at' => 2_000_000_000, 'scope' => 'Mail.Send']);

        self::assertSame(0o600, fileperms($file->path()) & 0o777);
        self::assertSame(['access_token' => 'abc.def', 'refresh_token' => 'r1', 'expires_at' => 2_000_000_000, 'scope' => 'Mail.Send'], $file->read());
    }

    public function testParsesTheRawMicrosoftAnswerWithExpiresInFromTheMtime(): void
    {
        $data = TokenFile::parse('{"token_type":"Bearer","access_token":"abc","refresh_token":"r","expires_in":3599,"scope":"Mail.Send"}', 1_000);

        self::assertSame(['access_token' => 'abc', 'refresh_token' => 'r', 'expires_at' => 4_599, 'scope' => 'Mail.Send'], $data);
    }

    public function testErrorsNeverEchoTheFileContent(): void
    {
        try {
            TokenFile::parse('{"refresh_token":"SECRET-REFRESH"}', 0);
            self::fail('No exception');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('SECRET', $exception->getMessage());
        }
    }

    public function testUpdateWritesBackOnlyWhenTheCallbackReturnsData(): void
    {
        $file = new TokenFile($this->dir . '/token.json', []);
        $file->write(['access_token' => 'old', 'refresh_token' => 'r', 'expires_at' => 1, 'scope' => null]);

        $file->update(static fn (array $current): ?array => null);
        self::assertSame('old', $file->read()['access_token']);

        $file->update(static fn (array $current): array => ['access_token' => 'new'] + $current);
        self::assertSame('new', $file->read()['access_token']);
        self::assertSame('r', $file->read()['refresh_token']);
    }
}
