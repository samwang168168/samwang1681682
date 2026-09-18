<?php

declare(strict_types=1);

namespace App\Claude\Flow;

use Throwable;

/**
 * 把一串 step 接起來跑 —— 等同 n8n 的一個 workflow。
 *
 * 三個從 n8n 學來、但自己做才控制得住的行為：
 *
 *   1. 每個 step 可獨立設重試次數與退避（n8n 的 retryOnFail）
 *   2. continueOnError：失敗時放行原本的 items 往下走（n8n 的 onError: continueRegularOutput）
 *   3. tap()：旁支。跑但不影響主線資料，失敗也不中斷 —— 送「打字中」這種副作用用它
 *
 * 外加一個 n8n 給不了的：trace() 是純資料，可以直接寫進 log 或拿去做 CI 斷言。
 */
final class Flow
{
    /** @var list<array{step: Step, retries: int, delayMs: int, continueOnError: bool, tap: bool}> */
    private array $steps = [];

    /** @var list<array{name: string, in: int, out: int, ms: float, attempts: int, error: ?string, tap: bool}> */
    private array $trace = [];

    /** @param (callable(string): void)|null $logger */
    public function __construct(
        public readonly string $name = 'flow',
        private readonly mixed $logger = null,
    ) {
    }

    /**
     * 串一個節點進來。
     *
     * @param int  $retries         失敗時額外重試幾次（0 = 不重試）
     * @param int  $delayMs         第一次重試前等多久；之後每次加倍
     * @param bool $continueOnError 重試用盡後，放行原本的 items 而不是中斷整條 flow
     */
    public function step(
        Step $step,
        int $retries = 0,
        int $delayMs = 500,
        bool $continueOnError = false,
    ): self {
        $this->steps[] = compact('step', 'retries', 'delayMs', 'continueOnError')
            + ['tap' => false];

        return $this;
    }

    /**
     * 旁支：跑這個 step，但把它的輸出丟掉，主線繼續帶原本的 items。
     * 失敗永遠不中斷 flow（只記進 trace）。
     *
     * 典型用途：送「打字中」、寫 log、推 metrics。
     */
    public function tap(Step $step): self
    {
        $this->steps[] = [
            'step' => $step, 'retries' => 0, 'delayMs' => 0,
            'continueOnError' => true, 'tap' => true,
        ];

        return $this;
    }

    /**
     * @param  list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function run(array $items): array
    {
        $this->trace = [];

        foreach ($this->steps as $config) {
            /** @var Step $step */
            $step = $config['step'];
            $stepName = $step->name();
            $inCount = count($items);

            // 沒有 item 就別浪費一次 API 呼叫。n8n 也是這個行為。
            if ($inCount === 0) {
                $this->record($stepName, 0, 0, 0.0, 0, null, $config['tap']);
                continue;
            }

            $startedAt = microtime(true);
            $attempts = 0;
            $lastError = null;
            $output = null;

            while ($attempts <= $config['retries']) {
                $attempts++;
                try {
                    $output = $step($items);
                    $lastError = null;
                    break;
                } catch (Throwable $e) {
                    $lastError = $e;
                    if ($attempts > $config['retries']) {
                        break;
                    }
                    // 指數退避：500ms → 1s → 2s …
                    $waitMs = $config['delayMs'] * (2 ** ($attempts - 1));
                    $this->log("step「{$stepName}」第 {$attempts} 次失敗，{$waitMs}ms 後重試：" . $e->getMessage());
                    if ($waitMs > 0) {
                        usleep($waitMs * 1000);
                    }
                }
            }

            $elapsedMs = (microtime(true) - $startedAt) * 1000;

            if ($lastError !== null) {
                $this->record($stepName, $inCount, $inCount, $elapsedMs, $attempts, $lastError->getMessage(), $config['tap']);

                if (!$config['continueOnError']) {
                    throw new StepFailed($stepName, $attempts, $lastError);
                }

                $this->log("step「{$stepName}」失敗但放行：" . $lastError->getMessage());
                continue; // items 維持原樣往下走
            }

            /** @var list<array<string, mixed>> $output */
            if ($config['tap']) {
                // 旁支的輸出丟掉，主線資料不動
                $this->record($stepName, $inCount, $inCount, $elapsedMs, $attempts, null, true);
                continue;
            }

            $items = array_values($output);
            $this->record($stepName, $inCount, count($items), $elapsedMs, $attempts, null, false);
        }

        return $items;
    }

    /**
     * 每個 step 的進出筆數、耗時、重試次數與錯誤 —— 等同 n8n 的 Executions 畫面，
     * 但是純資料。
     *
     * @return list<array{name: string, in: int, out: int, ms: float, attempts: int, error: ?string, tap: bool}>
     */
    public function trace(): array
    {
        return $this->trace;
    }

    /** 一行摘要，適合丟進 error_log。 */
    public function traceSummary(): string
    {
        $parts = [];
        foreach ($this->trace as $entry) {
            $label = $entry['tap'] ? "{$entry['name']}(旁支)" : $entry['name'];
            $segment = "{$label} {$entry['in']}→{$entry['out']} " . round($entry['ms']) . 'ms';
            if ($entry['attempts'] > 1) {
                $segment .= " 試{$entry['attempts']}次";
            }
            if ($entry['error'] !== null) {
                $segment .= ' ✗';
            }
            $parts[] = $segment;
        }

        return "[{$this->name}] " . implode(' | ', $parts);
    }

    private function record(
        string $name,
        int $in,
        int $out,
        float $ms,
        int $attempts,
        ?string $error,
        bool $tap,
    ): void {
        $this->trace[] = compact('name', 'in', 'out', 'ms', 'attempts', 'error', 'tap');
    }

    private function log(string $message): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)("[{$this->name}] {$message}");
        }
    }
}
