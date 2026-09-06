<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\InvalidContainerValueException;
use Psr\Container\ContainerInterface;

final readonly class ContainerValue implements ContainerInterface
{
    public function __construct(
        public ContainerInterface $container,
        public Config $config,
    ) {}

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|null $type
     * @return ($type is null ? mixed : T)
     */
    public function get(string $id, ?string $type = null): mixed
    {
        $service = $this->container->get($id);

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
        if ($this->container->has($id)) {
            $type = $default instanceof ContainerEntry ? $default->type : null;

            return $this->get($id, $type);
        }

        if ($default instanceof ContainerEntry) {
            return $default->resolve($this);
        }

        if ($default instanceof ConfigEntry) {
            return $default->resolve($this->config);
        }

        if ($default instanceof EnvironmentEntry) {
            return $default->resolve($this->config->environment);
        }

        if ($default instanceof LazyValue) {
            return $default->resolve($this);
        }

        return $default;
    }
}
