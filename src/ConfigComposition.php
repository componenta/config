<?php

declare(strict_types=1);

namespace Componenta\Config;

final readonly class ConfigComposition
{
    public function __construct(
        public Config $config,
        public DependencyDefinitions $dependencies,
    ) {}
}
