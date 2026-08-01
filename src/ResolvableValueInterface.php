<?php

declare(strict_types=1);

namespace Componenta\Config;

interface ResolvableValueInterface
{
    public function resolve(Config $config): mixed;
}
