<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\MailKitBundle\Doctrine\TablePrefixListener;
use Nowo\MailKitBundle\Entity\SentMail;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixListenerTest extends TestCase
{
    public function testPrefixesOnlyBundleTablesAndIndexes(): void
    {
        $metadata = $this->metadata(SentMail::class, 'mail_kit_sent_mail', ['idx_a' => ['columns' => ['a']], 0 => ['columns' => ['b']]]);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createMock(EntityManagerInterface::class)));
        self::assertSame('app_mail_kit_sent_mail', $metadata->getTableName());
        self::assertSame(['app_idx_a', 0], array_keys($metadata->table['indexes'] ?? []));

        $host = $this->metadata(stdClass::class, 'host_table');
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($host, $this->createMock(EntityManagerInterface::class)));
        self::assertSame('host_table', $host->getTableName());

        $plain = $this->metadata(SentMail::class, 'mail_kit_sent_mail');
        (new TablePrefixListener())->loadClassMetadata(new LoadClassMetadataEventArgs($plain, $this->createMock(EntityManagerInterface::class)));
        self::assertSame('mail_kit_sent_mail', $plain->getTableName());

        self::assertSame(['log' => 'x_mail_kit_sent_mail', 'body' => 'x_mail_kit_sent_mail_body'], (new TablePrefixListener('x_'))->tableNames());
    }

    /**
     * @param class-string $class
     * @param array<int|string, array<string, mixed>> $indexes
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class, string $table, array $indexes = []): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => $table] + ($indexes === [] ? [] : ['indexes' => $indexes]));

        return $metadata;
    }
}
