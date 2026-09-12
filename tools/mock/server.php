<?php

declare(strict_types=1);

/**
 * 本機 mock server：把收到的 request body 原樣寫到檔案，回一個假的 Message。
 * 用來在不花錢、不連外網的情況下驗證真正送出去的 wire payload。
 */
$body = file_get_contents('php://input');
file_put_contents(getenv('CAPTURE_FILE') ?: '/tmp/claude-capture.json', $body);

$decoded = json_decode($body, true) ?: [];

header('Content-Type: application/json');
echo json_encode([
    'id' => 'msg_mock_0001',
    'type' => 'message',
    'role' => 'assistant',
    'model' => $decoded['model'] ?? 'claude-opus-5',
    'content' => [['type' => 'text', 'text' => '（mock 回覆）']],
    'stop_reason' => 'end_turn',
    'stop_sequence' => null,
    'usage' => [
        'input_tokens' => 120,
        'output_tokens' => 8,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 51200,
    ],
], JSON_UNESCAPED_UNICODE);
