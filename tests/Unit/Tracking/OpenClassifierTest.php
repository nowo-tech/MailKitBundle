<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Tests\Unit\Tracking;

use Nowo\MailKitBundle\DependencyInjection\Configuration;
use Nowo\MailKitBundle\Tracking\OpenClassifier;
use PHPUnit\Framework\TestCase;

final class OpenClassifierTest extends TestCase
{
    private const APPLE_MAIL = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko)';

    public function testMachineOpensByUserAgentAddressAndEmptyAgent(): void
    {
        $classifier = new OpenClassifier(Configuration::MACHINE_USER_AGENTS, [...Configuration::MACHINE_IP_RANGES, '2001:db8:abc::/45', '198.51.100.7', '192.0.2.128/25', 'not-an-ip']);

        self::assertTrue($classifier->isMachine('203.0.113.9', null));
        self::assertTrue($classifier->isMachine('203.0.113.9', ''));
        self::assertTrue($classifier->isMachine('203.0.113.9', 'Mozilla/5.0 (compatible; Barracuda Sentinel)'));
        self::assertTrue($classifier->isMachine('17.58.101.4', self::APPLE_MAIL), 'Apple Mail Privacy Protection proxy.');
        self::assertTrue($classifier->isMachine('2001:db8:abf:1::1', self::APPLE_MAIL));
        self::assertTrue($classifier->isMachine('198.51.100.7', self::APPLE_MAIL));
        self::assertTrue($classifier->isMachine('192.0.2.200', self::APPLE_MAIL));

        self::assertFalse($classifier->isMachine('192.0.2.100', self::APPLE_MAIL));
        self::assertFalse($classifier->isMachine('2001:db8:ab0::1', self::APPLE_MAIL));
        self::assertFalse($classifier->isMachine('18.0.0.1', self::APPLE_MAIL));
        self::assertFalse($classifier->isMachine('::ffff:17.0.0.1', self::APPLE_MAIL), 'Families compare apart.');
        self::assertFalse($classifier->isMachine(null, self::APPLE_MAIL));
        self::assertFalse($classifier->isMachine('garbage', self::APPLE_MAIL));
        self::assertFalse((new OpenClassifier())->isMachine('17.0.0.1', self::APPLE_MAIL));
        self::assertTrue((new OpenClassifier([], ['0.0.0.0/0']))->isMachine('8.8.8.8', 'x'));
    }

    public function testFamilies(): void
    {
        $classifier = new OpenClassifier();
        $cases      = [
            ''                                                                                                      => 'unknown',
            'Mozilla/5.0 (Windows NT 5.1; rv:11.0) Gecko Firefox/11.0 (via ggpht.com GoogleImageProxy)'             => 'gmail_proxy',
            'YahooMailProxy; https://help.yahoo.com/kb/yahoo-mail-proxy-SLN28749.html'                              => 'yahoo_proxy',
            'Microsoft Office/16.0 (Windows NT 10.0; Microsoft Outlook 16.0.5000)'                                  => 'outlook',
            'Mozilla/5.0 (X11; Linux x86_64; rv:115.0) Gecko/20100101 Thunderbird/115.3.1'                          => 'thunderbird',
            'Googlebot-Image/1.0'                                                                                   => 'bot',
            'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36 Edg/120' => 'edge',
            'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0'                                => 'firefox',
            'Mozilla/5.0 (Linux) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36'            => 'chrome',
            'Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile Safari/604'          => 'safari',
            self::APPLE_MAIL                                                                                        => 'apple_mail',
            'SomethingElse/1.0'                                                                                     => 'other',
        ];
        foreach ($cases as $agent => $family) {
            self::assertSame($family, $classifier->family((string) $agent), (string) $agent);
            self::assertContains($family, OpenClassifier::FAMILIES);
        }
        self::assertSame('unknown', $classifier->family(null));
    }

    public function testAddressesAreTruncated(): void
    {
        $classifier = new OpenClassifier();
        self::assertSame('203.0.113.0', $classifier->truncate('203.0.113.9'));
        self::assertSame('2001:db8:85a3::', $classifier->truncate('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
        self::assertNull($classifier->truncate(null));
        self::assertNull($classifier->truncate('not-an-ip'));
    }
}
