<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigArgumentException;
use Componenta\Config\Reader\FileReaderInterface;
use Componenta\Config\Reader\JsonFileReader;
use Componenta\Config\Reader\PhpFileReader;

/**
 * Reads every file matched by a glob pattern in deterministic lexical order.
 *
 * Each file produces one contribution for the ConfigFactory fold. A matched
 * file must be handled by one configured reader. Unsupported, unreadable or
 * malformed files fail fast instead of being silently ignored.
 */
final class FileProvider
{
    /** @var list<FileReaderInterface> */
    private readonly array $readers;

    /** @param iterable<mixed>|null $readers */
    public function __construct(
        private readonly string $pattern,
        ?iterable $readers = null,
    ) {
        if ($pattern === '') {
            throw new InvalidConfigArgumentException(
                'Configuration glob pattern must be non-empty.',
            );
        }

        if (str_contains($pattern, "\0")) {
            throw new InvalidConfigArgumentException(
                'Configuration glob pattern must not contain null bytes.',
            );
        }

        $readers ??= [new PhpFileReader(), new JsonFileReader()];

        $normalized = [];
        foreach ($readers as $reader) {
            if (!$reader instanceof FileReaderInterface) {
                throw new InvalidConfigArgumentException(sprintf(
                    'Configuration reader must implement %s; got %s.',
                    FileReaderInterface::class,
                    get_debug_type($reader),
                ));
            }

            $normalized[] = $reader;
        }

        $this->readers = $normalized;
    }

    /** @return iterable<array<array-key, mixed>> */
    public function __invoke(): iterable
    {
        foreach ($this->files() as $file) {
            foreach ($this->readers as $reader) {
                $data = $reader->readFile($file);
                if ($data === null) {
                    continue;
                }

                yield $data;
                continue 2;
            }

            throw new ConfigException(sprintf(
                'No configuration reader supports file "%s".',
                $file,
            ));
        }
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
