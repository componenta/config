<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Loader;

use Componenta\Config\Loader\EnvLoader;

it('preserves multibyte UTF-8 characters while separating physical dotenv lines', function (string $source, string $expected, string $newline): void {
    $directory = sys_get_temp_dir() . '/config-env-utf8-' . bin2hex(random_bytes(8));
    mkdir($directory);
    $file = $directory . '/.env';
    try {
        file_put_contents($file, 'MESSAGE=' . $source . $newline . 'NEXT=ready' . $newline);

        expect((new EnvLoader($directory))->read())->toBe([
            'MESSAGE' => $expected,
            'NEXT' => 'ready',
        ]);
    } finally {
        unlink($file);
        rmdir($directory);
    }
})->with([
    'unquoted Cyrillic with LF' => ["left\u{0445}right", "left\u{0445}right", "\n"],
    'double quoted Latin with CRLF' => ['"left' . "\u{0145}" . 'right"', "left\u{0145}right", "\r\n"],
    'single quoted Greek with CR' => ["'left\u{03C5}right'", "left\u{03C5}right", "\r"],
]);
