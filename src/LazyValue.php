<?php

declare(strict_types=1);

namespace Componenta\Config;

use Closure;
use Componenta\Config\Exception\ConfigResolutionException;
use Fiber;
use stdClass;
use WeakMap;

/**
 * Explicit lazily evaluated configuration value.
 *
 * Cached results are scoped to the context object passed to resolve(). A single
 * LazyValue can therefore be reused by filtered Config instances or different
 * ContainerValue instances without leaking a result from another context.
 */
final class LazyValue
{
    private readonly Closure $callback;

    private readonly object $mainExecution;

    /** @var WeakMap<object, array{mixed}>|null */
    private ?WeakMap $values = null;

    /** @var WeakMap<object, WeakMap<object, true>>|null */
    private ?WeakMap $resolving = null;

    public function __construct(callable $callback, public readonly bool $cache = true)
    {
        $this->callback = Closure::fromCallable($callback);
        $this->mainExecution = new stdClass();
    }

    public function resolve(Config|ContainerValue $context): mixed
    {
        if ($this->cache) {
            $values = $this->values ??= new WeakMap();
            if (isset($values[$context])) {
                return $values[$context][0];
            }
        }

        $execution = Fiber::getCurrent() ?? $this->mainExecution;
        $resolving = $this->resolving ??= new WeakMap();
        $executions = $resolving[$context] ??= new WeakMap();

        if (isset($executions[$execution])) {
            throw ConfigResolutionException::lazyValueCycle();
        }

        $executions[$execution] = true;
        try {
            $value = ($this->callback)($context);
            if ($this->cache) {
                $values = $this->values ??= new WeakMap();
                if (!isset($values[$context])) {
                    $values[$context] = [$value];
                }
            }
            return $value;
        } finally {
            unset($executions[$execution]);

            if (count($executions) === 0) {
                unset($resolving[$context]);
            }
        }
    }
}
