<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\InvalidConfigArgumentException;

final readonly class ContainerEntry
{
    /** @var class-string<object>|null */
    public ?string $type;

    public function __construct(
        public string $id,
        ?string $type = null,
    ) {
        if ($id === '') {
            throw new InvalidConfigArgumentException('Container entry id must not be empty.');
        }

        if ($type === '') {
            throw new InvalidConfigArgumentException('Container entry type must not be empty.');
        }

        if ($type !== null
            && !class_exists($type)
            && !interface_exists($type)
            && !enum_exists($type)
        ) {
            throw new InvalidConfigArgumentException(sprintf(
                'Container entry type "%s" must name an existing class, interface, or enum.',
                $type,
            ));
        }

        /** @var class-string<object>|null $type */
        $this->type = $type;
    }

    public function resolve(ContainerValue $container): mixed
    {
        return $container->get($this->id, $this->type);
    }
}
