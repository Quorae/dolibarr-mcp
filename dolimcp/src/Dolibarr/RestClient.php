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

        if ($response['status'] >= 400) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
            throw new RestError(is_string($message) ? $message : 'Dolibarr answered HTTP ' . $response['status'], $response['status']);
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
        curl_close($curl);
        if (!is_string($raw)) {
            throw new ToolError('The Dolibarr REST API could not be reached');
        }

        return ['status' => $status, 'body' => $raw];
    }
}
