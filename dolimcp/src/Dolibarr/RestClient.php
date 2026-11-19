<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

use DoliMcp\Mcp\ToolError;

/** Calls the local Dolibarr REST API with the caller's own API key, so every Dolibarr permission applies. */
final class RestClient
{
    private const TIMEOUT = 30;

    /** @param ?\Closure(string,string,list<string>,?string):array{status:int,body:string,headers?:array<string,string>} $transport */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly ?\Closure $transport = null,
    ) {
    }

    public function request(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $headers = ['Accept: application/json', 'DOLAPIKEY: ' . $this->apiKey];
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $response = ($this->transport ?? self::send(...))(strtoupper($method), $url, $headers, $payload);
        $decoded = $response['body'] === '' ? null : json_decode($response['body'], true);
        $invalidJson = $response['body'] !== '' && json_last_error() !== JSON_ERROR_NONE;

        if ($response['status'] < 200 || $response['status'] > 299) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            $location = $response['headers']['location'] ?? null;
            if ($response['status'] >= 300 && $response['status'] < 400) {
                $message = 'Dolibarr answered HTTP ' . $response['status'] . ' (a redirect)' . ($location === null ? '' : ' to ' . $location)
                    . ': the internal REST call must not be redirected. Set DOLIMCP_API_URL to the final URL of the Dolibarr REST API (e.g. https://your-host/api/index.php).';
            } elseif (!is_string($message)) {
                $excerpt = self::excerpt($response['body']);
                $message = 'Dolibarr answered HTTP ' . $response['status'] . ($excerpt === '' ? '' : ': ' . $excerpt);
            }
            throw new RestError($message, $response['status'], $response['status'] === 404 && is_array($decoded) && ($decoded['error']['code'] ?? null) === 404);
        }
        if ($invalidJson) {
            throw new RestError('Dolibarr returned a non-JSON answer: ' . self::excerpt($response['body']), $response['status']);
        }

        return $decoded;
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    private static function send(string $method, string $url, array $headers, ?string $body): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        // http(s) only, whatever DOLIMCP_API_URL holds.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt_array($curl, [CURLOPT_PROTOCOLS_STR => 'http,https', CURLOPT_REDIR_PROTOCOLS_STR => 'http,https']);
        } else {
            curl_setopt_array($curl, [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        }
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (!is_string($raw)) {
            throw new ToolError(self::failureMessage(curl_errno($curl), curl_error($curl)));
        }

        $redirect = curl_getinfo($curl, CURLINFO_REDIRECT_URL);

        return ['status' => $status, 'body' => $raw, 'headers' => is_string($redirect) && $redirect !== '' ? ['location' => $redirect] : []];
    }

    public static function failureMessage(int $errno, string $error): string
    {
        $reason = $errno === CURLE_OPERATION_TIMEDOUT ? 'the request timed out after ' . self::TIMEOUT . ' s' : $error;

        return 'The Dolibarr REST API could not be reached: ' . $reason;
    }

    private static function excerpt(string $body): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($body))), 0, 200);
    }
}
