<?php

declare(strict_types=1);

namespace Componenta\Config;

/**
 * Marker indicating no default value was provided.
 *
 * When used as default parameter, the method will throw
 * ConfigException if the key is not found.
 */
enum DefaultValue
{
    case None;
}
