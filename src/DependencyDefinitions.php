<?php

declare(strict_types=1);

namespace Componenta\Config;

final readonly class DependencyDefinitions
{
    /** @param array<string, mixed> $sections */
    public function __construct(
        public array $sections,
    ) {}
}
