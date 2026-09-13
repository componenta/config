<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Support;

use Psr\Container\ContainerInterface;
use RuntimeException;

final class ContainerValueTestContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries) {}

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new RuntimeException("Missing test entry {$id}.");
        }

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
