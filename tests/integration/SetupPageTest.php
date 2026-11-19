<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class SetupPageTest extends McpTestCase
{
    public function testSetupPageShowsEndpointAndJournal(): void
    {
        $name = 'DOLIMCP_TEST_SETUP_PAGE';
        try {
            $this->assertFalse($this->callTool('const_set', ['name' => $name, 'value' => 'x'])['isError']);

            $page = $this->adminGet('/custom/dolimcp/admin/setup.php');

            $this->assertStringContainsString('mcp.php', $page);
            $this->assertStringContainsString('const_set', $page);
            $this->assertStringContainsString('&lt;your API key&gt;', $page);
        } finally {
            $this->callTool('const_set', ['name' => $name, 'value' => '']);
        }
    }

    /** Logs into the web interface as admin (login form with its CSRF token), then fetches a page. */
    private function adminGet(string $path): string
    {
        $base = (string) preg_replace('#/custom/dolimcp/mcp\.php$#', '', (string) getenv('MCP_URL'));
        $jar = tempnam(sys_get_temp_dir(), 'dolimcp');
        $fetch = function (string $url, ?array $post = null) use ($jar): string {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_COOKIEJAR => $jar,
                CURLOPT_COOKIEFILE => $jar,
                CURLOPT_TIMEOUT => 60,
            ]);
            if ($post !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
            }
            $body = curl_exec($curl);
            $this->assertIsString($body, "Request failed: $url");

            return $body;
        };

        try {
            $form = $fetch($base . '/index.php');
            $this->assertSame(1, preg_match('/name="token" value="([^"]+)"/', $form, $m), 'no CSRF token on the login form');
            $fetch($base . '/index.php?mainmenu=home', [
                'token' => $m[1],
                'actionlogin' => 'login',
                'loginfunction' => 'loginfunction',
                'username' => 'admin',
                'password' => 'admin-test-pass',
            ]);

            return $fetch($base . $path);
        } finally {
            @unlink($jar);
        }
    }
}
