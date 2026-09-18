<?php

declare(strict_types=1);

namespace App\Claude\Flow\Steps;

use App\Claude\Flow\Step;

/** 把任意 callable 包成節點。臨時的一次性邏輯用它，不必開一個新類別。 */
final class Call implements Step
{
    /** @param callable(list<array<string, mixed>>): list<array<string, mixed>> $fn */
    public function __construct(
        private readonly string $name,
        private readonly mixed $fn,
    ) {
    }

    public function __invoke(array $items): array
    {
        return array_values(($this->fn)($items));
    }

    public function name(): string
    {
        return $this->name;
    }
}
