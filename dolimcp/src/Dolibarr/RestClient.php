<?php

declare(strict_types=1);

namespace DoliMcp\Dolibarr;

use DoliMcp\Mcp\ToolError;

/** Calls the local Dolibarr REST API with the caller's own API key, so every Dolibarr permission applies. */
final class RestClient
{
    private const TIMEOUT = 30;

    /** @param ?\Closure(string,string,list<string>,?string):array{status:int,body:string} $transport */
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

        if ($response['status'] >= 400) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            if (!is_string($message)) {
                $excerpt = self::excerpt($response['body']);
                $message = 'Dolibarr answered HTTP ' . $response['status'] . ($excerpt === '' ? '' : ': ' . $excerpt);
            }
            throw new RestError($message, $response['status']);
        }
        if ($invalidJson) {
            throw new RestError('Dolibarr returned a non-JSON answer: ' . self::excerpt($response['body']), $response['status']);
        }

        return $decoded;
    }

    /** @return array{status:int,body:string} */
    private static function send(string $method, string $url, array $headers, ?string $body): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if (!is_string($raw)) {
            throw new ToolError(self::failureMessage(curl_errno($curl), curl_error($curl)));
        }

        return ['status' => $status, 'body' => $raw];
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
