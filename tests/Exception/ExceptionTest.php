<?php

declare(strict_types=1);

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\ConfigExceptionInterface;
use Componenta\Config\Exception\EnvLoaderException;
use Componenta\Config\Exception\InvalidConfigValueException;
use Componenta\Config\Exception\InvalidContainerValueException;

it('exposes a common package exception contract', function (): void {
    expect(ConfigException::missingKey('app'))->toBeInstanceOf(ConfigExceptionInterface::class)
        ->and(new InvalidConfigValueException('invalid'))->toBeInstanceOf(ConfigExceptionInterface::class)
        ->and(new InvalidContainerValueException('invalid'))->toBeInstanceOf(ConfigExceptionInterface::class)
        ->and(new EnvLoaderException('invalid'))->toBeInstanceOf(ConfigExceptionInterface::class);
});

it('keeps structured conversion diagnostics', function (): void {
    $exception = InvalidConfigValueException::cannotConvert('port', 'int', []);

    expect($exception->key)->toBe('port')
        ->and($exception->expectedType)->toBe('int')
        ->and($exception->actualType)->toBe('array');
});
