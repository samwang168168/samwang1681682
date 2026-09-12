<?php

declare(strict_types=1);

namespace App\Claude;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;

/**
 * 客服請求的三層快取分層。
 *
 * Claude 的 prompt caching 是前綴比對，渲染順序固定為 tools -> system -> messages。
 * 所以把內容照「變動頻率由低到高」排，在每個變動邊界打一個斷點：
 *
 *   system[0]  系統提示詞      幾乎不變    -> 斷點 1
 *   system[1]  公司共用知識庫  偶爾更新    -> 斷點 2   ★ 跨客戶共用同一份快取
 *   user[0]    客戶專屬資料    每個客戶不同 -> 斷點 3
 *   user[1]    客人這次的問題  每次都不同   -> 不打斷點
 *
 * 斷點 2 是省最多錢的地方：公司知識庫獨立成一層，所有客戶讀同一份快取。
 * 只要五分鐘內有任何流量，它就一直是熱的。
 *
 * 每次請求依然是無狀態的（送完即忘），跟原本 Gemini 的用法一致 ——
 * 不需要改成累積對話歷史。
 */
final class ClaudeCustomerService
{
    /** 斷點上限是每次請求 4 個，這裡固定用掉 3 個，留 1 個給多輪對話的歷史。 */
    private const CACHE_CONTROL = ['type' => 'ephemeral'];

    private ?string $systemFingerprint = null;

    private int $requests = 0;
    private int $totalCacheRead = 0;
    private int $totalCacheWrite = 0;
    private int $totalUncachedInput = 0;

    /**
     * @param string         $systemPrompt     系統提示詞。必須是「凍結」的 —— 絕對不要把日期、
     *                                         customer_id 之類的動態值 interpolate 進來。
     * @param string|array   $companyKnowledge 全體客戶共用的公司資料。array 會走決定性序列化。
     * @param string|null    $effort           'low' | 'medium' | 'high' | 'xhigh' | 'max'，
     *                                         null = 用 API 預設（high）。必須固定在這裡，
     *                                         不要每次請求換 —— 換 effort 會讓 messages 快取失效。
     * @param (callable(string, string, string): void)|null $onPrefixChanged
     *                                         偵測到被快取的前綴變動時呼叫，用來抓無聲失效。
     * @param (callable(Reply): void)|null     $onUsage 每次回覆後呼叫，接去 log / metrics。
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $systemPrompt,
        private readonly string|array $companyKnowledge,
        private readonly string $model = 'claude-opus-5',
        private readonly int $maxTokens = 4096,
        private readonly ?string $effort = 'medium',
        private readonly mixed $onPrefixChanged = null,
        private readonly mixed $onUsage = null,
    ) {
    }

    /**
     * 問一個問題，拿一個回覆。
     *
     * @param string       $customerId      只用在診斷訊息上。★ 絕對不要把它放進 systemPrompt。
     * @param string       $question        客人這次問的問題。
     * @param string|array $customerData    該客戶的專屬資料（訂單、會員等級、歷史工單…）。
     * @param list<array>  $history         之前幾輪的對話，Claude messages 格式。
     *                                      無狀態用法就留空。
     * @param string|null  $turnInstruction 只對這一輪生效的營運指令（例：切換簡短模式）。
     *                                      走 system message 通道，不會動到已快取的前綴。
     */
    public function ask(
        string $customerId,
        string $question,
        string|array $customerData = '',
        array $history = [],
        ?string $turnInstruction = null,
    ): Reply {
        $params = $this->buildParams($question, $customerData, $history, $turnInstruction);

        $this->guardPrefix($params['system'], $customerId);

        $message = $this->send($params, $turnInstruction);
        $reply = Reply::fromMessage($message);

        $this->recordUsage($reply, $customerId);

        return $reply;
    }

    /**
     * 組出送給 API 的參數。單獨開放出來是為了能在不花錢的情況下檢查分層是否正確
     * （tools/verify_cache.php 會用到）。
     *
     * @return array{model: string, maxTokens: int, system: list<array>, messages: list<array>}
     */
    public function buildParams(
        string $question,
        string|array $customerData = '',
        array $history = [],
        ?string $turnInstruction = null,
    ): array {
        // --- 第 1、2 層：system。凍結內容，兩個斷點。 ---
        $system = [
            [
                'type' => 'text',
                'text' => $this->systemPrompt,
                'cacheControl' => self::CACHE_CONTROL,
            ],
        ];

        $knowledge = Prompt::asText($this->companyKnowledge);
        if ($knowledge !== '') {
            // 自己一塊、自己一個斷點：知識庫更新時，系統提示詞那層的快取還在。
            $system[] = [
                'type' => 'text',
                'text' => $knowledge,
                'cacheControl' => self::CACHE_CONTROL,
            ];
        }

        // --- 第 3 層：客戶專屬資料，放在第一個 user 訊息的開頭。 ---
        $customerBlock = null;
        $customerText = Prompt::asText($customerData);
        if ($customerText !== '') {
            $customerBlock = [
                'type' => 'text',
                'text' => $customerText,
                'cacheControl' => self::CACHE_CONTROL,
            ];
        }

        // --- 第 4 層：客人的問題，不打斷點。 ---
        $questionBlock = ['type' => 'text', 'text' => $question];

        if ($history === []) {
            $messages = [[
                'role' => 'user',
                'content' => $customerBlock === null
                    ? [$questionBlock]
                    : [$customerBlock, $questionBlock],
            ]];
        } else {
            $messages = $this->withHistory($history, $customerBlock, $questionBlock);
        }

        // 只對這一輪生效的營運指令。用 messages 裡的 system role 而不是改頂層
        // system —— 改頂層 system 會讓整段對話歷史的快取全部重算。
        // 這同時也是不可偽造的營運通道：塞在 user 內容裡的指令，任何能寫入
        // 使用者輸入的人都能假冒。
        if ($turnInstruction !== null && $turnInstruction !== '') {
            $messages[] = ['role' => 'system', 'content' => $turnInstruction];
        }

        return [
            'model' => $this->model,
            'maxTokens' => $this->maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];
    }

    /**
     * 多輪對話：客戶資料要接在「第一個」user 訊息的最前面，讓前綴只會往後長、
     * 不會從中間被改寫。每輪都重新組一次 messages[0] 是對的，只要 bytes 一樣，
     * 快取就照樣命中。
     *
     * @param list<array> $history
     * @return list<array>
     */
    private function withHistory(array $history, ?array $customerBlock, array $questionBlock): array
    {
        $messages = array_values($history);

        if ($customerBlock !== null) {
            $first = $messages[0];
            $content = self::normalizeContent($first['content'] ?? []);

            // 已經有客戶資料區塊就不要重複加（呼叫端可能把完整 history 存下來了）。
            if (($content[0]['text'] ?? null) !== $customerBlock['text']) {
                array_unshift($content, $customerBlock);
            }

            $first['content'] = $content;
            $messages[0] = $first;
        }

        // 第 4 個斷點：蓋在歷史的最後一塊，讓累積的對話也能命中。
        $lastIndex = count($messages) - 1;
        $lastContent = self::normalizeContent($messages[$lastIndex]['content'] ?? []);
        if ($lastContent !== []) {
            $lastContent[count($lastContent) - 1]['cacheControl'] = self::CACHE_CONTROL;
            $messages[$lastIndex]['content'] = $lastContent;
        }

        $messages[] = ['role' => 'user', 'content' => [$questionBlock]];

        return $messages;
    }

    /**
     * @return list<array>
     */
    private static function normalizeContent(string|array $content): array
    {
        if (is_string($content)) {
            return [['type' => 'text', 'text' => $content]];
        }

        return array_values($content);
    }

    /**
     * 送出請求。錯誤用「由具體到一般」的 catch 鏈分類，因為可重試（429、5xx、連線）
     * 跟不可重試（400、404）的處理方式完全不同，一個 catch 全包會丟掉這個資訊。
     */
    private function send(array $params, ?string $turnInstruction): Message
    {
        $args = [
            'model' => $params['model'],
            'maxTokens' => $params['maxTokens'],
            'system' => $params['system'],
            'messages' => $params['messages'],
        ];

        if ($this->effort !== null) {
            $args['outputConfig'] = ['effort' => $this->effort];
        }

        try {
            return $this->client->messages->create(...$args);
        } catch (BadRequestException $e) {
            // system role 訊息只有部分模型支援（Opus 5 可以，Sonnet 5 不行）。
            // 被拒就把指令降級塞回 user 輪次重試一次。
            if ($turnInstruction !== null && str_contains($e->getMessage(), 'system')) {
                $args['messages'] = self::foldInstructionIntoUserTurn(
                    $params['messages'],
                    $turnInstruction,
                );

                return $this->client->messages->create(...$args);
            }

            throw $e;
        } catch (NotFoundException $e) {
            // 幾乎都是 model ID 打錯。
            throw new \RuntimeException("Claude 模型不存在：{$this->model}", previous: $e);
        } catch (RateLimitException|InternalServerException|APIConnectionException $e) {
            // 可重試。交給呼叫端的重試機制 / queue 決定退避策略。
            throw $e;
        } catch (APIStatusException $e) {
            throw new \RuntimeException(
                sprintf('Claude API 錯誤 [%s]: %s', $e->type?->value ?? 'unknown', $e->getMessage()),
                previous: $e,
            );
        }
    }

    /**
     * @param list<array> $messages
     * @return list<array>
     */
    private static function foldInstructionIntoUserTurn(array $messages, string $instruction): array
    {
        $messages = array_values(array_filter(
            $messages,
            static fn (array $m): bool => ($m['role'] ?? '') !== 'system',
        ));

        $lastIndex = count($messages) - 1;
        $content = self::normalizeContent($messages[$lastIndex]['content'] ?? []);
        $content[] = ['type' => 'text', 'text' => "<operator-instruction>{$instruction}</operator-instruction>"];
        $messages[$lastIndex]['content'] = $content;

        return $messages;
    }

    /**
     * 抓 1 號無聲殺手：被快取的 system 層在 process 生命週期內變了。
     *
     * 最典型的寫法就是在系統提示詞裡插 "今天是 2026-09-12" 或客戶名字 ——
     * 前綴一變，它後面全部重算，而且每個客戶各自持有一份快取，
     * 跨客戶完全無法共用。不會報錯，只有帳單會告訴你。
     */
    private function guardPrefix(array $system, string $customerId): void
    {
        $rendered = '';
        foreach ($system as $block) {
            $rendered .= $block['text'] ?? '';
        }

        $fingerprint = Prompt::fingerprint($rendered);

        if ($this->systemFingerprint === null) {
            $this->systemFingerprint = $fingerprint;

            return;
        }

        if ($this->systemFingerprint === $fingerprint) {
            return;
        }

        $previous = $this->systemFingerprint;
        $this->systemFingerprint = $fingerprint;

        $message = sprintf(
            'system 前綴變動了（%s -> %s，customer=%s）：所有客戶的共用快取都會失效。'
            . '檢查 systemPrompt / companyKnowledge 裡有沒有日期、ID 之類的動態值。',
            $previous,
            $fingerprint,
            $customerId,
        );

        if (is_callable($this->onPrefixChanged)) {
            ($this->onPrefixChanged)($previous, $fingerprint, $message);

            return;
        }

        error_log('[claude-cache] ' . $message);
    }

    private function recordUsage(Reply $reply, string $customerId): void
    {
        $this->requests++;
        $this->totalCacheRead += $reply->cacheReadTokens;
        $this->totalCacheWrite += $reply->cacheWriteTokens;
        $this->totalUncachedInput += $reply->inputTokens;

        // 打了斷點卻完全沒有快取活動 = 前綴沒到最小可快取長度（Opus 5 是 512 token）。
        // API 不會報錯，只會安靜地不快取。
        if ($reply->cacheReadTokens === 0 && $reply->cacheWriteTokens === 0) {
            error_log(sprintf(
                '[claude-cache] customer=%s 完全沒有快取活動：前綴可能未達最小可快取長度'
                . '（%s 為 512 token），或模型不支援。',
                $customerId,
                $this->model,
            ));
        }

        if (is_callable($this->onUsage)) {
            ($this->onUsage)($reply);
        }
    }

    /**
     * 累計快取成效。掛在健康檢查或 metrics 端點上，比等對帳單快得多。
     *
     * @return array{requests: int, cacheReadTokens: int, cacheWriteTokens: int, uncachedInputTokens: int, hitRatio: float}
     */
    public function cacheStats(): array
    {
        $total = $this->totalCacheRead + $this->totalCacheWrite + $this->totalUncachedInput;

        return [
            'requests' => $this->requests,
            'cacheReadTokens' => $this->totalCacheRead,
            'cacheWriteTokens' => $this->totalCacheWrite,
            'uncachedInputTokens' => $this->totalUncachedInput,
            'hitRatio' => $total > 0 ? $this->totalCacheRead / $total : 0.0,
        ];
    }
}
