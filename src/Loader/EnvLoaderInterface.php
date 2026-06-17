<?php

declare(strict_types=1);

namespace Componenta\Config\Loader;

use Componenta\Config\Environment;

/**
 * Environment loader interface.
 */
interface EnvLoaderInterface
{
    /**
     * Load environment variables and populate $_ENV/$_SERVER superglobals.
     *
     * @param bool $override Whether to override existing values
     * @param bool $populateServer Whether to also populate $_SERVER
     */
    public function load(bool $override = false, bool $populateServer = true):? Environment;
}