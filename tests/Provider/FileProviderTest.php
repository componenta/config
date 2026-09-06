<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Provider;

use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigArgumentException;
use Componenta\Config\FileProvider;
use Componenta\Config\Reader\FileReaderInterface;
use stdClass;

function fileProviderDirectory(): string
{
    $directory = sys_get_temp_dir()
        . '/componenta_config_file_provider_'
        . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);

    return $directory;
}

function removeFileProviderDirectory(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($directory);
}

it('returns one contribution per matched file in lexical order without merging', function (): void {
    $directory = fileProviderDirectory();
    file_put_contents(
        $directory . '/20-second.php',
        '<?php return ["shared" => "php", "php" => true];',
    );
    file_put_contents(
        $directory . '/10-first.json',
        '{"shared":"json","json":true}',
    );

    try {
        $contributions = iterator_to_array(
            (new FileProvider($directory . '/*.{php,json}'))(),
            false,
        );

        expect($contributions)->toBe([
            ['shared' => 'json', 'json' => true],
            ['shared' => 'php', 'php' => true],
        ]);
    } finally {
        removeFileProviderDirectory($directory);
    }
});

it('keeps file override operations in the single global provider fold', function (): void {
    $directory = fileProviderDirectory();
    file_put_contents(
        $directory . '/10-additive.php',
        '<?php return ["sequence" => ["file"]];',
    );
    file_put_contents(
        $directory . '/20-operations.php',
        '<?php return ['
        . '"settings" => \\Componenta\\Config\\ConfigOverride::replace(["strict" => true]), '
        . '"obsolete" => \\Componenta\\Config\\ConfigOverride::remove()];',
    );

    try {
        $composition = (new ConfigFactory())->create(
            new Environment([]),
            static fn(): array => [
                'sequence' => ['base'],
                'settings' => ['legacy' => true],
                'obsolete' => 'value',
            ],
            new FileProvider($directory . '/*.php'),
        );

        expect($composition->config->toArray())->toBe([
            'sequence' => ['base', 'file'],
            'settings' => ['strict' => true],
        ]);
    } finally {
        removeFileProviderDirectory($directory);
    }
});

it('preserves JSON integers beyond the platform range without precision loss', function (): void {
    $directory = fileProviderDirectory();
    $bigInteger = PHP_INT_MAX . '0';
    file_put_contents($directory . '/config.json', '{"id":' . $bigInteger . '}');

    try {
        expect(iterator_to_array(
            (new FileProvider($directory . '/*.json'))(),
            false,
        ))->toBe([
            ['id' => $bigInteger],
        ]);
    } finally {
        removeFileProviderDirectory($directory);
    }
});

it('propagates reader failures while consuming contributions', function (
    string $extension,
    string $contents,
    string $message,
): void {
    $directory = fileProviderDirectory();
    file_put_contents($directory . '/invalid.' . $extension, $contents);

    try {
        expect(fn() => iterator_to_array(
            (new FileProvider($directory . '/*.' . $extension))(),
            false,
        ))->toThrow(ConfigException::class, $message);
    } finally {
        removeFileProviderDirectory($directory);
    }
})->with([
    'non-array PHP result' => ['php', '<?php return "invalid";', 'must return an array'],
    'scalar JSON root' => ['json', '"invalid"', 'object or array'],
    'malformed JSON' => ['json', '{', 'Failed to parse JSON'],
]);

it('rejects matched files without a reader and invalid custom readers', function (): void {
    $directory = fileProviderDirectory();
    file_put_contents($directory . '/config.yaml', "app: test\n");

    try {
        expect(fn() => iterator_to_array(
            (new FileProvider($directory . '/*.yaml'))(),
            false,
        ))->toThrow(
            ConfigException::class,
            'No configuration reader supports',
        )->and(fn() => new FileProvider('*.php', [new stdClass()]))
            ->toThrow(InvalidConfigArgumentException::class, FileReaderInterface::class);
    } finally {
        removeFileProviderDirectory($directory);
    }
});

it('returns no contributions when the pattern matches no files', function (): void {
    $directory = fileProviderDirectory();

    try {
        expect(iterator_to_array(
            (new FileProvider($directory . '/*.php'))(),
            false,
        ))->toBe([]);
    } finally {
        removeFileProviderDirectory($directory);
    }
});

it('rejects invalid glob patterns through the package exception contract', function (): void {
    expect(fn() => new FileProvider(''))->toThrow(
        InvalidConfigArgumentException::class,
        'non-empty',
    )->and(fn() => new FileProvider("invalid\0pattern"))->toThrow(
        InvalidConfigArgumentException::class,
        'null bytes',
    );
});
