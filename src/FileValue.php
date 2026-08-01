<?php

declare(strict_types=1);

namespace Componenta\Config;

/**
 * Exportable lazy reference to a separately compiled configuration value.
 */
final readonly class FileValue implements ResolvableValueInterface
{
    public function __construct(public string $file) {}

    public function resolve(Config $config): mixed
    {
        return ConfigShardCache::load($this->file);
    }
}
