<?php

declare(strict_types=1);

namespace Componenta\Config\Exception;

use RuntimeException;

final class InvalidContainerValueException extends RuntimeException implements ConfigExceptionInterface
{
    public static function forService(string $id, string $expectedType, mixed $service): self
    {
        return new self(sprintf(
            'Container entry "%s" must be an instance of %s, %s given.',
            $id,
            $expectedType,
            get_debug_type($service),
        ));
    }
}
