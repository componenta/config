<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use InvalidArgumentException;

final class InvalidConfigArgumentException extends InvalidArgumentException implements ConfigExceptionInterface {}
