<?php

declare(strict_types=1);

namespace App\Tests\Outlook;

use App\Outlook\TestAttachment;
use PHPUnit\Framework\TestCase;

use function strlen;

final class TestAttachmentTest extends TestCase
{
    public function testTheBytesAreDerivedFromTheKeyOnly(): void
    {
        $size = 4 * 1024 * 1024;
        $a    = TestAttachment::bytes('outlook-test-0123456789', $size);

        self::assertSame($size, strlen($a));
        self::assertSame($a, TestAttachment::bytes('outlook-test-0123456789', $size));
        self::assertNotSame($a, TestAttachment::bytes('outlook-test-9876543210', $size));
    }
}
