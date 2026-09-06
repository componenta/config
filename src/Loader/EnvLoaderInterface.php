<?php

declare(strict_types=1);

namespace Componenta\Config\Loader;

use Componenta\Config\Environment;

interface EnvLoaderInterface
{
    /**
     * Return an immutable snapshot composed from process values and configured
     * dotenv files without mutating process globals.
     */
    public function load(bool $override = false): Environment;
}
