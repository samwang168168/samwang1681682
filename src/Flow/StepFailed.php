<?php

declare(strict_types=1);

namespace App\Claude\Flow;

use RuntimeException;
use Throwable;

/** 某個 step 重試用盡後仍然失敗。 */
final class StepFailed extends RuntimeException
{
    public function __construct(
        public readonly string $stepName,
        public readonly int $attempts,
        Throwable $previous,
    ) {
        parent::__construct(
            "step「{$stepName}」重試 {$attempts} 次後仍失敗：" . $previous->getMessage(),
            0,
            $previous,
        );
    }
}
