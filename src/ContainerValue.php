<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidContainerValueException;
use Psr\Container\ContainerInterface;

final readonly class ContainerValue implements ContainerInterface
{
    public Config $config;

    public function __construct(
        public ContainerInterface $value,
        ?Config $config = null,
    ) {
        $this->config = $config ?? $this->resolveConfig();
    }

    public function has(string $id): bool
    {
        return $this->value->has($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|null $type
     * @return ($type is null ? mixed : T)
     */
    public function get(string $id, ?string $type = null): mixed
    {
        $service = $this->value->get($id);

        if ($type === null) {
            return $service;
        }

        if (!$service instanceof $type) {
            throw InvalidContainerValueException::forService($id, $type, $service);
        }

        return $service;
    }

    public function find(string $id, mixed $default = null): mixed
    {
        if ($this->value->has($id)) {
            $type = $default instanceof ContainerEntry ? $default->type : null;

            return $this->get($id, $type);
        }

        if ($default instanceof ContainerEntry) {
            return $default->resolve($this);
        }

        if ($default instanceof ConfigEntry) {
            return $default->resolve($this->config);
        }

        if ($default instanceof LazyValue) {
            return $default->resolve($this);
        }

        return $default;
    }

    private function resolveConfig(): Config
    {
        if (!$this->value->has(Config::class)) {
            throw new ConfigException(sprintf(
                'Wrapped container must expose "%s" or Config must be provided explicitly.',
                Config::class,
            ));
        }

        return $this->get(Config::class, Config::class);
    }
}
