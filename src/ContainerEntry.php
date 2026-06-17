<?php

declare(strict_types=1);

namespace Componenta\Config;

final readonly class ContainerEntry
{
    /**
     * @param class-string|null $type
     */
    public function __construct(
        public string $id,
        public ?string $type = null,
    ) {}

    public function resolve(ContainerValue $container): mixed
    {
        return $container->get($this->id, $this->type);
    }
}
