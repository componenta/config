<?php

declare(strict_types=1);

namespace Componenta\Config;

final readonly class ConfigEntry
{
    public function __construct(
        public string|ConfigPath $key,
        public mixed $default = DefaultValue::None,
    ) {}

    public function resolve(Config $config): mixed
    {
        return $config->get($this->key, $this->default);
    }
}
