<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\InvalidConfigArgumentException;

/** Runtime-bound reference to a value in Config::$environment. */
final readonly class EnvironmentEntry
{
    public function __construct(
        public string|ConfigPath $key,
        public mixed $default = DefaultValue::None,
    ) {
        if ($key === '') {
            throw new InvalidConfigArgumentException('Environment entry key must not be empty.');
        }
    }

    public function resolve(Environment $environment): mixed
    {
        return $environment->get($this->key, $this->default);
    }
}
