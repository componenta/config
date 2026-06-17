<?php

declare(strict_types=1);

namespace Componenta\Config;

use Closure;

final class LazyValue
{
    public Closure $callback;
    private bool $resolved = false;
    private mixed $value = null;

    public function __construct(
        callable $callback,
        public readonly bool $cache = true,
    ) {
        $this->callback = Closure::fromCallable($callback);
    }

    public function resolve(Config|ContainerValue $context): mixed
    {
        if ($this->cache && $this->resolved) {
            return $this->value;
        }

        $value = ($this->callback)($context);

        if ($this->cache) {
            $this->resolved = true;
            $this->value = $value;
        }

        return $value;
    }
}
