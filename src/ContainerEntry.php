<?php

declare(strict_types=1);

namespace Componenta\Config;

use InvalidArgumentException;

final readonly class ContainerEntry
{
    public function __construct(
        public string $id,
        public ?string $type = null,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('Container entry id must not be empty.');
        }

        if ($type === '') {
            throw new InvalidArgumentException('Container entry type must not be empty.');
        }
    }

    public function resolve(ContainerValue $container): mixed
    {
        return $container->get($this->id, $this->type);
    }
}
