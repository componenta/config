<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigResolutionException;
use Componenta\Config\LazyValue;
use Fiber;
use RuntimeException;

it('allows concurrent resolution in another Fiber without reporting a cycle', function (): void {
    $calls = 0;
    $firstCall = true;
    $lazy = new LazyValue(static function () use (&$calls, &$firstCall): string {
        $call = ++$calls;

        if ($firstCall) {
            $firstCall = false;
            Fiber::suspend('waiting');
        }

        return 'value-' . $call;
    });
    $config = new Config(['value' => $lazy], new Environment([]));

    $first = new Fiber(static fn(): mixed => $config->get('value'));
    expect($first->start())->toBe('waiting');

    $second = new Fiber(static fn(): mixed => $config->get('value'));
    $second->start();

    expect($second->getReturn())->toBe('value-2');

    $first->resume();

    expect($first->getReturn())->toBe('value-1')
        ->and($config->get('value'))->toBe('value-2')
        ->and($calls)->toBe(2);
});

it('does not cache exceptions and permits a later retry', function (): void {
    $calls = 0;
    $expected = new RuntimeException('transient failure');
    $lazy = new LazyValue(static function () use (&$calls, $expected): string {
        ++$calls;

        if ($calls === 1) {
            throw $expected;
        }

        return 'resolved';
    });
    $config = new Config(['value' => $lazy], new Environment([]));

    try {
        $config->get('value');
        throw new \LogicException('The first LazyValue resolution should fail.');
    } catch (RuntimeException $actual) {
        expect($actual)->toBe($expected);
    }

    expect($config->get('value'))->toBe('resolved')
        ->and($config->get('value'))->toBe('resolved')
        ->and($calls)->toBe(2);
});

it('rejects a LazyValue result cycle instead of looping forever', function (): void {
    $lazy = null;
    $lazy = new LazyValue(static function () use (&$lazy): mixed {
        return $lazy;
    });
    $config = new Config(['value' => $lazy], new Environment([]));

    expect(fn() => $config->get('value'))->toThrow(
        ConfigResolutionException::class,
        'LazyValue -> LazyValue',
    );
});

it('keeps the recursion guard for a suspended Fiber after another Fiber finishes', function (): void {
    $calls = 0;
    $lazy = null;
    $context = new Config([], new Environment([]));
    $lazy = new LazyValue(static function () use (&$calls, &$lazy, $context): string {
        ++$calls;
        if ($calls === 1) {
            Fiber::suspend('pending');
            if ($lazy === null) {
                throw new \LogicException('LazyValue fixture is not initialized.');
            }
            $lazy->resolve($context);
            throw new \LogicException('Recursive resolution unexpectedly returned.');
        }
        if ($calls === 2) {
            return 'concurrent';
        }
        throw new \LogicException('A recursive callback must not execute again.');
    }, cache: false);
    $first = new Fiber(static fn(): mixed => $lazy->resolve($context));
    expect($first->start())->toBe('pending');
    $second = new Fiber(static fn(): mixed => $lazy->resolve($context));
    $second->start();

    expect($second->getReturn())->toBe('concurrent')
        ->and(fn() => $first->resume())->toThrow(ConfigResolutionException::class)
        ->and($calls)->toBe(2);
});
