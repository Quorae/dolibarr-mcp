<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Unit;

use DoliMcp\Dolibarr\RestClient;
use DoliMcp\Dolibarr\RestError;
use PHPUnit\Framework\TestCase;

final class RestClientTest extends TestCase
{
    public function testSendsTheApiKeyHeaderAndDecodesJson(): void
    {
        $seen = [];
        $client = new RestClient('http://x/api/index.php', 'k3y', function (string $method, string $url, array $headers, ?string $body) use (&$seen): array {
            $seen = [$method, $url, $headers, $body];

            return ['status' => 200, 'body' => '{"id":5}'];
        });

        $this->assertSame(['id' => 5], $client->request('POST', 'thirdparties', ['limit' => 2], ['name' => 'A']));
        $this->assertSame('POST', $seen[0]);
        $this->assertSame('http://x/api/index.php/thirdparties?limit=2', $seen[1]);
        $this->assertContains('DOLAPIKEY: k3y', $seen[2]);
        $this->assertSame('{"name":"A"}', $seen[3]);
    }

    public function testErrorStatusRaisesRestErrorWithDolibarrMessage(): void
    {
        $client = new RestClient('http://x', 'k', static fn (): array => ['status' => 401, 'body' => '{"error":{"code":401,"message":"Unauthorized: Invalid login"}}']);
        try {
            $client->request('GET', 'users');
            $this->fail('RestError expected');
        } catch (RestError $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('Unauthorized: Invalid login', $e->getMessage());
        }
    }

    public function testNonJsonSuccessBodyIsAnError(): void
    {
        $client = new RestClient('http://x', 'k', static fn (): array => ['status' => 200, 'body' => '<html><b>PHP Fatal error</b> in x.php</html>']);
        $this->expectException(RestError::class);
        $this->expectExceptionMessage('non-JSON answer: PHP Fatal error in x.php');
        $client->request('GET', 'users');
    }

    public function testErrorStatusWithoutDolibarrMessageShowsAnExcerpt(): void
    {
        $client = new RestClient('http://x', 'k', static fn (): array => ['status' => 502, 'body' => '<h1>Bad Gateway</h1>']);
        $this->expectExceptionMessage('Dolibarr answered HTTP 502: Bad Gateway');
        $client->request('GET', 'users');
    }

    public function testFailureMessageNamesTimeoutAndCurlError(): void
    {
        $this->assertStringContainsString('timed out after 30 s', RestClient::failureMessage(CURLE_OPERATION_TIMEDOUT, 'Operation timed out'));
        $this->assertStringContainsString('Connection refused', RestClient::failureMessage(CURLE_COULDNT_CONNECT, 'Connection refused'));
    }
}
