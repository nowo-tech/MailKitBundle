<?php

declare(strict_types=1);

namespace Nowo\MailKitBundle\Graph\Inbound;

use function in_array;
use function strrpos;
use function strtolower;
use function substr;

/**
 * File-type rules of inbound attachments (1.9, audit NQ-L3): extensions that are refused whatever the bytes look
 * like (active content and executables a browser or desktop could run), and the extensions that match a detected
 * content type. A name whose extension disagrees with the detected type gets a safe name with the canonical extension.
 */
final class InboundFileTypes
{
    /** Extensions refused whatever the detected type. */
    public const DANGEROUS_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'xht', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'js', 'mjs', 'cjs', 'jse', 'wasm',
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb', 'sh',
        'hta', 'exe', 'bat', 'cmd', 'com', 'ps1', 'psm1', 'psd1', 'scr', 'pif', 'jar', 'lnk', 'vbs', 'vbe', 'wsf', 'wsh',
        'msi', 'msp', 'mst', 'dll', 'cpl', 'reg', 'inf', 'scf', 'url', 'appref-ms', 'application', 'gadget', 'iso', 'img',
        'vhd', 'vhdx', 'chm', 'docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'pptm', 'potm', 'ppam', 'ppsm', 'sldm',
        'ps1xml', 'ps2', 'ps2xml', 'psc1', 'psc2', 'wsc', 'sct', 'ws', 'msc', 'msh', 'msh1', 'msh2', 'mshxml', 'msh1xml', 'msh2xml',
        'xll', 'xla', 'mht', 'mhtml', 'jnlp', 'cab', 'settingcontent-ms', 'library-ms', 'search-ms', 'searchconnector-ms',
        'ade', 'adp', 'mde', 'accde', 'mda', 'pyw', 'pyc', 'command', 'desktop', 'apk', 'dmg', 'pkg',
    ];

    /** Accepted extensions per detected content type; the first one is canonical. */
    public const EXTENSIONS = [
        'application/pdf'                                                           => ['pdf'],
        'image/png'                                                                 => ['png'],
        'image/jpeg'                                                                => ['jpg', 'jpeg', 'jpe', 'jfif'],
        'image/gif'                                                                 => ['gif'],
        'image/webp'                                                                => ['webp'],
        'text/plain'                                                                => ['txt', 'text', 'log', 'md', 'csv'],
        'text/csv'                                                                  => ['csv', 'txt'],
        'application/zip'                                                           => ['zip'],
        'application/msword'                                                        => ['doc', 'dot'],
        'application/vnd.ms-excel'                                                  => ['xls', 'xlt'],
        'application/vnd.ms-powerpoint'                                             => ['ppt', 'pps', 'pot'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.oasis.opendocument.text'                                   => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet'                            => ['ods'],
        'application/vnd.oasis.opendocument.presentation'                           => ['odp'],
    ];

    /**
     * Lower-cased extension of a file name ('' when none).
     */
    public static function extension(string $filename): string
    {
        $dot = strrpos($filename, '.');

        return $dot === false ? '' : strtolower(substr($filename, $dot + 1));
    }

    /**
     * True when the extension is refused.
     */
    public static function dangerous(string $extension): bool
    {
        return in_array($extension, self::DANGEROUS_EXTENSIONS, true);
    }

    /**
     * Canonical extension of a detected type (`bin` for a type without a known extension).
     */
    public static function canonicalExtension(string $contentType): string
    {
        return self::EXTENSIONS[$contentType][0] ?? 'bin';
    }

    /**
     * The file name, with its extension replaced by the canonical one when it does not match the detected type.
     */
    public static function safeFilename(string $filename, string $contentType): string
    {
        $extension = self::extension($filename);
        if ($extension !== '' && in_array($extension, self::EXTENSIONS[$contentType] ?? [], true)) {
            return $filename;
        }
        $dot  = strrpos($filename, '.');
        $stem = $dot === false || $dot === 0 ? $filename : substr($filename, 0, $dot);

        return $stem . '.' . self::canonicalExtension($contentType);
    }
}
