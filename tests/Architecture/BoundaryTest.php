<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

it('keeps Config independent from DI and VarExport packages', function (): void {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 2) . '/composer.json') ?: '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if (!is_array($manifest) || !is_array($manifest['require'] ?? null)) {
        throw new RuntimeException('Config composer.json must contain a runtime require map.');
    }

    expect($manifest['require'])->not->toHaveKeys([
        'componenta/di',
        'componenta/var-export',
    ]);
});

it('uses strict types throughout Config source files', function (): void {
    $sourceDirectory = dirname(__DIR__, 2) . '/src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDirectory),
    );

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        expect($source)->toStartWith("<?php\n\ndeclare(strict_types=1);");
    }
});
