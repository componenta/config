<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Reader\FileReaderInterface;
use Componenta\Config\Reader\JsonFileReader;
use Componenta\Config\Reader\PhpFileReader;

/**
 * File-based configuration provider.
 *
 * Loads configuration from files matching a glob pattern.
 * Files are processed in order and merged recursively.
 *
 * @example
 * ```php
 * $provider = new FileProvider('config/*.php');
 * $config = $provider();
 * ```
 */
final class FileProvider
{
    /** @var FileReaderInterface[] */
    private readonly array $readers;

    /**
     * @param string $pattern Glob pattern for configuration files
     * @param iterable<FileReaderInterface>|null $readers Custom readers (defaults to PHP, JSON)
     */
    public function __construct(
        private readonly string $pattern,
        ?iterable $readers = null,
    ) {
        if ($readers === null) {
            $this->readers = [
                new PhpFileReader(),
                new JsonFileReader(),
            ];
        } else {
            $this->readers = is_array($readers) ? $readers : iterator_to_array($readers);
        }
    }

    /**
     * Load and merge configuration from matching files.
     */
    public function __invoke(): array
    {
        $config = [];

        foreach ($this->glob($this->pattern) as $file) {
            foreach ($this->readers as $reader) {
                $data = $reader->readFile($file);

                if ($data !== null) {
                    if ($data !== []) {
                        $config = config_merge($config, $data);
                    }

                    break;
                }
            }
        }

        return $config;
    }

    /**
     * Glob with brace expansion support.
     *
     * @return string[]
     */
    private function glob(string $pattern): array
    {
        $files = glob($pattern, GLOB_BRACE) ?: [];
        sort($files);

        return $files;
    }
}
