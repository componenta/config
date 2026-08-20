<?php

declare(strict_types=1);

namespace Componenta\Config;

use Closure;
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
    public Closure $callback;

    /** @var WeakMap<object, array{mixed}>|null */
    private ?WeakMap $values = null;

    public function __construct(
        callable $callback,
        public readonly bool $cache = true,
    ) {
        $this->callback = Closure::fromCallable($callback);
    }

    public function resolve(Config|ContainerValue $context): mixed
    {
        if (!$this->cache) {
            return ($this->callback)($context);
        }

        $this->values ??= new WeakMap();

        if (isset($this->values[$context])) {
            return $this->values[$context][0];
        }

        $value = ($this->callback)($context);
        $this->values[$context] = [$value];

        return $value;
    }
}
