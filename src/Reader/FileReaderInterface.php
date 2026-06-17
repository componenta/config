<?php

declare(strict_types=1);

namespace Componenta\Config\Reader;

/**
 * Interface for configuration file readers.
 *
 * Readers determine if they can handle a file format and parse it.
 */
interface FileReaderInterface
{
    /**
     * Read and parse a configuration file.
     *
     * @param string $file Path to the configuration file
     * @return array|null Parsed data or null if format not supported
     */
    public function readFile(string $file): ?array;
}
