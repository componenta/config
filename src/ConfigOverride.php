<?php

declare(strict_types=1);

namespace Componenta\Config;

final readonly class ConfigOverride
{
    private function __construct(
        private bool $remove,
        private mixed $replacement,
    ) {}

    public static function replace(mixed $value): self
    {
        return new self(false, $value);
    }

    public static function remove(): self
    {
        return new self(true, null);
    }

    /** @internal */
    public function removesKey(): bool
    {
        return $this->remove;
    }

    /** @internal */
    public function replacement(): mixed
    {
        return $this->replacement;
    }
}
