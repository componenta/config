<?php

declare(strict_types=1);

namespace Componenta\Config;

use Closure;
use Componenta\Config\Exception\ConfigException;
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

    /** @var WeakMap<object, array{mixed}>|null */
    private ?WeakMap $values = null;

    /** @var WeakMap<object, true>|null */
    private ?WeakMap $resolving = null;

    public function __construct(
        callable $callback,
        public readonly bool $cache = true,
    ) {
        $this->callback = Closure::fromCallable($callback);
    }

    public function resolve(Config|ContainerValue $context): mixed
    {
        if ($this->cache) {
            $this->values ??= new WeakMap();

            if (isset($this->values[$context])) {
                return $this->values[$context][0];
            }
        }

        $this->resolving ??= new WeakMap();
        if (isset($this->resolving[$context])) {
            throw new ConfigException(
                'Circular LazyValue resolution detected for the same runtime context.',
            );
        }

        $this->resolving[$context] = true;

        try {
            $value = ($this->callback)($context);

            if ($this->cache) {
                $this->values[$context] = [$value];
            }

            return $value;
        } finally {
            unset($this->resolving[$context]);
        }
    }
}
