<?php

declare(strict_types=1);

namespace Componenta\Config\Loader;

use Componenta\Config\Environment;

interface EnvLoaderInterface
{
    /**
     * Load configured dotenv files into $_ENV and return the effective runtime
     * environment snapshot. Existing runtime values win unless overridden.
     */
    public function load(bool $override = false): Environment;
}
