<?php

declare(strict_types=1);

namespace App\Claude\Flow\Steps;

use App\Claude\Flow\Step;

/** 逐筆轉換。callback 回 null 代表丟掉這一筆。 */
final class Map implements Step
{
    /** @param callable(array<string, mixed>): (array<string, mixed>|null) $fn */
    public function __construct(
        private readonly string $name,
        private readonly mixed $fn,
    ) {
    }

    public function __invoke(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $mapped = ($this->fn)($item);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    public function name(): string
    {
        return $this->name;
    }
}
