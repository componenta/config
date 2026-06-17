<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use Throwable;

/**
 * Base interface for all Config exceptions.
 *
 * Allows catching all library exceptions with a single catch block.
 */
interface ConfigExceptionInterface extends Throwable
{
}
