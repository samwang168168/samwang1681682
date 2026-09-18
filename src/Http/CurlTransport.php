<?php

declare(strict_types=1);

namespace App\Claude\Http;

use RuntimeException;

/** ext-curl 實作。沒有其他依賴，不需要 composer。 */
final class CurlTransport implements Transport
{
    public function __construct(private readonly int $connectTimeoutSeconds = 5)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('需要 ext-curl');
        }
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = 20,
    ): Response {
        $ch = curl_init();

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            // 憑證驗證絕對不要關。關掉等於把 bot token 送給任何能攔截的人。
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            return new Response(0, '', $responseHeaders, $error !== '' ? $error : 'curl failed');
        }

        return new Response($status, (string) $raw, $responseHeaders);
    }
}
