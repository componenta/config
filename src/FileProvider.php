<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Reader\FileReaderInterface;
use Componenta\Config\Reader\JsonFileReader;
use Componenta\Config\Reader\PhpFileReader;
use InvalidArgumentException;

/**
 * Loads every file matched by a glob pattern and merges them in sorted order.
 *
 * A matched file must be handled by one configured reader. Unsupported,
 * unreadable or malformed files fail fast instead of being silently ignored.
 */
final class FileProvider
{
    /** @var list<FileReaderInterface> */
    private readonly array $readers;

    /** @param iterable<FileReaderInterface>|null $readers */
    public function __construct(
        private readonly string $pattern,
        ?iterable $readers = null,
    ) {
        $readers ??= [new PhpFileReader(), new JsonFileReader()];

        $normalized = [];
        foreach ($readers as $reader) {
            if (!$reader instanceof FileReaderInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Configuration reader must implement %s; got %s.',
                    FileReaderInterface::class,
                    get_debug_type($reader),
                ));
            }

            $normalized[] = $reader;
        }

        $this->readers = $normalized;
    }

    /** @return array<array-key, mixed> */
    public function __invoke(): array
    {
        $config = [];

        foreach ($this->files() as $file) {
            $handled = false;

            foreach ($this->readers as $reader) {
                $data = $reader->readFile($file);
                if ($data === null) {
                    continue;
                }

                $handled = true;
                if ($data !== []) {
                    $config = config_merge($config, $data);
                }
                break;
            }

            if (!$handled) {
                throw new ConfigException(sprintf(
                    'No configuration reader supports file "%s".',
                    $file,
                ));
            }
        }

        return $config;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob($this->pattern, GLOB_BRACE);
        if ($files === false) {
            throw new ConfigException(sprintf(
                'Invalid configuration glob pattern "%s".',
                $this->pattern,
            ));
        }

        sort($files, SORT_STRING);

        return array_values(array_filter($files, 'is_file'));
    }
}
