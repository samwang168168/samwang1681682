<?php

declare(strict_types=1);

namespace App\Claude;

use Anthropic\Client;

/**
 * Gemini -> Claude 相容轉接層。
 *
 * 目的是讓既有呼叫端少改：原本組好的 Gemini payload 直接丟進來，
 * 轉成 Claude 的參數。建議的遷移節奏是兩步：
 *
 *   第一步 —— 接上這層。呼叫端幾乎不動，立刻拿到 systemInstruction 那層的快取。
 *   第二步 —— 用 promoteToSystem 把公司共用知識庫從 contents 提到 system 層，
 *             拿到跨客戶共用快取（省最多錢的一步）。之後再視情況整個換成
 *             ClaudeCustomerService 的原生三層寫法。
 *
 * 轉接層只負責翻譯格式與擺斷點，不會幫你把不相容的參數變出來 ——
 * 丟掉的東西一律記進 warnings。
 */
final class GeminiCompat
{
    private const CACHE_CONTROL = ['type' => 'ephemeral'];

    /**
     * Gemini 的 sampling 參數在 Claude Opus 5 上已經被移除，送了會直接 400。
     * 這是遷移時最常踩的雷。
     */
    private const REMOVED_SAMPLING = ['temperature', 'topP', 'topK'];

    /**
     * @param array<string, mixed> $gemini  Gemini generateContent 的 request body
     * @param array{
     *     model?: string,
     *     maxTokens?: int,
     *     effort?: string|null,
     *     promoteToSystem?: list<int>,
     *     customerDataParts?: list<int>,
     * } $options
     *     promoteToSystem   —— contents[0].parts 裡屬於「公司共用知識」的索引，
     *                          會被提到 system 層並獨立打斷點（跨客戶共用快取）。
     *     customerDataParts —— contents[0].parts 裡屬於「客戶專屬資料」的索引，
     *                          留在原地但打上斷點（該客戶命中）。
     */
    public static function translate(array $gemini, array $options = []): GeminiTranslation
    {
        $warnings = [];

        $system = self::translateSystemInstruction($gemini['systemInstruction'] ?? null);
        $contents = array_values($gemini['contents'] ?? []);

        // promoteToSystem / customerDataParts 的索引都是指「原始 payload」的位置。
        // 提取會讓剩下的 parts 重新編號，所以要把 customerDataParts 一起換算過去。
        $customerDataParts = array_values($options['customerDataParts'] ?? []);

        if ($contents !== [] && ($options['promoteToSystem'] ?? []) !== []) {
            [$contents, $promoted, $indexMap] = self::promoteParts($contents, $options['promoteToSystem']);

            foreach ($promoted as $text) {
                $system[] = ['type' => 'text', 'text' => $text];
            }

            $remapped = [];
            foreach ($customerDataParts as $original) {
                if (!isset($indexMap[$original])) {
                    throw new \InvalidArgumentException(sprintf(
                        'customerDataParts 的索引 %d 同時也在 promoteToSystem 裡，'
                        . '一個 part 不能既提到 system 又留在 messages。',
                        $original,
                    ));
                }
                $remapped[] = $indexMap[$original];
            }
            $customerDataParts = $remapped;
        }

        // 斷點蓋在 system 的最後一塊 —— 一個斷點會把它前面的 tools + system 一起快取。
        if ($system !== []) {
            $system[count($system) - 1]['cacheControl'] = self::CACHE_CONTROL;
        }

        $messages = self::translateContents($contents, $customerDataParts, $warnings);

        if ($messages === []) {
            throw new \InvalidArgumentException('Gemini payload 的 contents 是空的，沒有東西可以送。');
        }

        $params = [
            'model' => $options['model'] ?? 'claude-opus-5',
            // Gemini 的 maxOutputTokens 可以不給（有預設值），Claude 的 maxTokens 是必填。
            'maxTokens' => $options['maxTokens']
                ?? $gemini['generationConfig']['maxOutputTokens']
                ?? 4096,
            'messages' => $messages,
        ];

        if ($system !== []) {
            $params['system'] = $system;
        }

        $generationConfig = $gemini['generationConfig'] ?? [];

        foreach (self::REMOVED_SAMPLING as $key) {
            if (array_key_exists($key, $generationConfig)) {
                $warnings[] = sprintf(
                    'generationConfig.%s 已丟棄：Claude Opus 5 移除了 sampling 參數，送出會回 400。'
                    . '要控制輸出深度請改用 outputConfig.effort。',
                    $key,
                );
            }
        }

        if (isset($generationConfig['stopSequences'])) {
            $params['stopSequences'] = array_values($generationConfig['stopSequences']);
        }

        if (($generationConfig['responseMimeType'] ?? null) === 'application/json') {
            $warnings[] = 'generationConfig.responseMimeType=application/json 沒有直接對應：'
                . '請改用 outputConfig.format（structured outputs）並提供 schema。';
        }

        if (isset($gemini['safetySettings'])) {
            $warnings[] = 'safetySettings 已丟棄：Claude 沒有對應的逐類別門檻設定。';
        }

        if (isset($gemini['cachedContent'])) {
            $warnings[] = 'cachedContent 已丟棄：Claude 的快取是 inline 的 cacheControl 斷點，'
                . '不需要預先建立快取物件、也不用自己管 TTL。這層已經幫你擺好斷點了。';
        }

        if (isset($gemini['tools'])) {
            $params['tools'] = self::translateTools($gemini['tools'], $warnings);
        }

        $effort = $options['effort'] ?? null;
        if ($effort !== null) {
            $params['outputConfig'] = ['effort' => $effort];
        }

        return new GeminiTranslation($params, $warnings);
    }

    /**
     * 轉換 + 送出。warnings 走 error_log，不會被默默吞掉。
     */
    public static function send(Client $client, array $gemini, array $options = []): Reply
    {
        $translation = self::translate($gemini, $options);

        foreach ($translation->warnings as $warning) {
            error_log('[gemini-compat] ' . $warning);
        }

        $message = $client->messages->create(...$translation->params);

        return Reply::fromMessage($message);
    }

    /**
     * systemInstruction 可以是字串、['parts' => [...]]，或直接是 parts 陣列。
     *
     * @return list<array{type: string, text: string}>
     */
    private static function translateSystemInstruction(mixed $instruction): array
    {
        if ($instruction === null || $instruction === '') {
            return [];
        }

        if (is_string($instruction)) {
            return [['type' => 'text', 'text' => $instruction]];
        }

        $parts = $instruction['parts'] ?? $instruction;

        $blocks = [];
        foreach ($parts as $part) {
            $text = is_string($part) ? $part : ($part['text'] ?? null);
            if (is_string($text) && $text !== '') {
                $blocks[] = ['type' => 'text', 'text' => $text];
            }
        }

        return $blocks;
    }

    /**
     * 把 contents[0].parts 裡指定索引的文字搬出來（給 system 層用）。
     *
     * @param list<array>  $contents
     * @param list<int>    $indices
     * @return array{0: list<array>, 1: list<string>, 2: array<int, int>} 第三個是「原始索引 -> 新索引」
     */
    private static function promoteParts(array $contents, array $indices): array
    {
        $parts = array_values($contents[0]['parts'] ?? []);
        $wanted = array_flip($indices);

        $promoted = [];
        $remaining = [];
        $indexMap = [];

        foreach ($parts as $i => $part) {
            $text = is_string($part) ? $part : ($part['text'] ?? null);

            if (isset($wanted[$i]) && is_string($text)) {
                $promoted[] = $text;
                continue;
            }

            $indexMap[$i] = count($remaining);
            $remaining[] = $part;
        }

        if ($remaining === []) {
            throw new \InvalidArgumentException(
                'promoteToSystem 把 contents[0].parts 全部搬走了，user 訊息不能是空的。',
            );
        }

        $contents[0]['parts'] = $remaining;

        return [$contents, $promoted, $indexMap];
    }

    /**
     * contents[] -> messages[]
     *
     * @param list<array>   $contents
     * @param list<int>     $customerDataParts 只作用在第一個訊息上
     * @param list<string>  $warnings
     * @return list<array>
     */
    private static function translateContents(array $contents, array $customerDataParts, array &$warnings): array
    {
        $messages = [];

        foreach ($contents as $index => $content) {
            $role = $content['role'] ?? 'user';

            // Gemini 的助理角色叫 "model"，Claude 叫 "assistant"。
            if ($role === 'model') {
                $role = 'assistant';
            }

            $blocks = [];
            $parts = array_values($content['parts'] ?? []);
            $wanted = $index === 0 ? array_flip($customerDataParts) : [];

            foreach ($parts as $partIndex => $part) {
                $block = self::translatePart($part, $warnings);
                if ($block === null) {
                    continue;
                }

                if (isset($wanted[$partIndex])) {
                    $block['cacheControl'] = self::CACHE_CONTROL;
                }

                $blocks[] = $block;
            }

            if ($blocks === []) {
                continue;
            }

            $messages[] = ['role' => $role, 'content' => $blocks];
        }

        return $messages;
    }

    /**
     * @param list<string> $warnings
     * @return array<string, mixed>|null
     */
    private static function translatePart(mixed $part, array &$warnings): ?array
    {
        if (is_string($part)) {
            return $part === '' ? null : ['type' => 'text', 'text' => $part];
        }

        if (isset($part['text'])) {
            return $part['text'] === '' ? null : ['type' => 'text', 'text' => $part['text']];
        }

        // inlineData -> image / document。content block 的型別必須跟 MIME 對上。
        $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;
        if ($inline !== null) {
            $mime = $inline['mimeType'] ?? $inline['mime_type'] ?? '';
            $data = $inline['data'] ?? '';

            if ($mime === 'application/pdf') {
                return [
                    'type' => 'document',
                    'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => $data],
                ];
            }

            if (str_starts_with($mime, 'image/')) {
                return [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => $data],
                ];
            }

            $warnings[] = sprintf('inlineData 的 mimeType "%s" 無法對應到 Claude 的 content block，已略過。', $mime);

            return null;
        }

        if (isset($part['functionCall'])) {
            return [
                'type' => 'tool_use',
                'id' => $part['functionCall']['id'] ?? ('call_' . substr(hash('sha256', Prompt::canonicalJson($part)), 0, 16)),
                'name' => $part['functionCall']['name'] ?? '',
                'input' => $part['functionCall']['args'] ?? [],
            ];
        }

        if (isset($part['functionResponse'])) {
            return [
                'type' => 'tool_result',
                'toolUseID' => $part['functionResponse']['id'] ?? '',
                'content' => Prompt::canonicalJson($part['functionResponse']['response'] ?? []),
            ];
        }

        if (isset($part['fileData'])) {
            $warnings[] = 'fileData（Gemini File API 的 fileUri）沒有直接對應：'
                . '請改用 Claude 的 Files API 先上傳，再用 {"type":"document","source":{"type":"file","fileId":...}} 引用。';

            return null;
        }

        return null;
    }

    /**
     * tools[].functionDeclarations[] -> Claude tools[]
     *
     * @param list<array>  $geminiTools
     * @param list<string> $warnings
     * @return list<array>
     */
    private static function translateTools(array $geminiTools, array &$warnings): array
    {
        $tools = [];

        foreach ($geminiTools as $tool) {
            if (isset($tool['googleSearchRetrieval']) || isset($tool['googleSearch'])) {
                $warnings[] = 'googleSearch / googleSearchRetrieval 要換成 Claude 的 server tool：'
                    . '{"type":"web_search_20260209","name":"web_search"}。';
                continue;
            }

            if (isset($tool['codeExecution'])) {
                $warnings[] = 'codeExecution 要換成 Claude 的 '
                    . '{"type":"code_execution_20260521","name":"code_execution"}。';
                continue;
            }

            foreach ($tool['functionDeclarations'] ?? [] as $declaration) {
                $tools[] = [
                    'name' => $declaration['name'] ?? '',
                    'description' => $declaration['description'] ?? '',
                    'inputSchema' => self::normalizeSchema(
                        $declaration['parameters'] ?? ['type' => 'object', 'properties' => []],
                    ),
                ];
            }
        }

        // tools 渲染在位置 0，順序變了整個快取就全毀 —— 照名稱固定排序。
        usort($tools, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $tools;
    }

    /**
     * Gemini 的 OpenAPI 子集用大寫型別（"STRING"、"OBJECT"），
     * Claude 吃標準 JSON Schema 的小寫型別。遞迴轉掉。
     */
    private static function normalizeSchema(mixed $schema): mixed
    {
        if (!is_array($schema)) {
            return $schema;
        }

        $out = [];
        foreach ($schema as $key => $value) {
            if ($key === 'type' && is_string($value)) {
                $out[$key] = strtolower($value);
                continue;
            }

            $out[$key] = self::normalizeSchema($value);
        }

        return $out;
    }
}
