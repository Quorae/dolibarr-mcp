<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

final class SetupPageTest extends McpTestCase
{
    private const PAGE = '/custom/dolimcp/admin/setup.php';
    private const CONST_NAME = 'DOLIMCP_API_URL';

    public function testSetupPageShowsEndpointAndJournal(): void
    {
        $name = 'DOLIMCP_TEST_SETUP_PAGE';
        try {
            $this->assertFalse($this->callTool('const_set', ['name' => $name, 'value' => 'x'])['isError']);

            $page = $this->webGet(self::PAGE, $this->webLogin('admin', 'admin-test-pass'));

            $this->assertStringContainsString('mcp.php', $page);
            $this->assertStringContainsString('const_set', $page);
            $this->assertStringContainsString('&lt;your API key&gt;', $page);
        } finally {
            $this->callTool('const_set', ['name' => $name, 'value' => '']);
        }
    }

    public function testNonAdminIsRefused(): void
    {
        $user = $this->restrictedUser();
        $login = self::db()->query('SELECT login FROM llx_user WHERE rowid = ' . $user['id'])->fetch_row()[0];

        $page = $this->webGet(self::PAGE, $this->webLogin($login, 'Restricted-pass-1'));

        $this->assertStringNotContainsString('DOLIMCP_API_URL', $page);
        $this->assertStringNotContainsString('claude mcp add', $page);
    }

    public function testPostWithTokenSavesTheApiUrl(): void
    {
        $old = self::constValue();
        try {
            $jar = $this->webLogin('admin', 'admin-test-pass');
            $this->savePost($jar, 'https://dolibarr.example.com/api/index.php', true);

            $this->assertSame('https://dolibarr.example.com/api/index.php', self::constValue());
        } finally {
            $this->restoreConst($old);
        }
    }

    public function testPostWithoutTokenIsRejected(): void
    {
        $old = self::constValue();
        // The page itself must demand the token, whatever the instance-wide CSRF option says.
        $csrf = self::db()->query("SELECT value FROM llx_const WHERE name = 'MAIN_SECURITY_CSRF_WITH_TOKEN' AND entity = 1")->fetch_row();
        self::setConst('MAIN_SECURITY_CSRF_WITH_TOKEN', '0');
        try {
            self::setConst(self::CONST_NAME, 'https://known.example.com');
            $jar = $this->webLogin('admin', 'admin-test-pass');
            $this->savePost($jar, 'https://evil.example.com', false);

            $this->assertSame('https://known.example.com', self::constValue());
        } finally {
            self::db()->query("DELETE FROM llx_const WHERE name = 'MAIN_SECURITY_CSRF_WITH_TOKEN' AND entity = 1");
            if ($csrf !== null) {
                self::setConst('MAIN_SECURITY_CSRF_WITH_TOKEN', (string) $csrf[0]);
            }
            $this->restoreConst($old);
        }
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidApiUrlIsRefused(string $url): void
    {
        $old = self::constValue();
        try {
            self::setConst(self::CONST_NAME, 'https://known.example.com');
            $jar = $this->webLogin('admin', 'admin-test-pass');
            $this->savePost($jar, $url, true);

            $this->assertSame('https://known.example.com', self::constValue());
        } finally {
            $this->restoreConst($old);
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidUrls(): array
    {
        return ['javascript' => ['javascript:alert(1)'], 'ftp' => ['ftp://x'], 'no host' => ['http://']];
    }

    public function testJournalRowsAreEscaped(): void
    {
        $db = self::db();
        $db->query("INSERT INTO llx_dolimcp_journal (entity, datec, fk_user, tool, params, outcome) VALUES (1, NOW(), 1, '<script>tool1</script>', '{}', '<script>out1</script>')");
        $id = $db->insert_id;
        try {
            $page = $this->webGet(self::PAGE, $this->webLogin('admin', 'admin-test-pass'));

            $this->assertStringNotContainsString('<script>tool1', $page);
            $this->assertStringNotContainsString('<script>out1', $page);
            $this->assertStringContainsString('&lt;script&gt;tool1', $page);
            $this->assertStringContainsString('&lt;script&gt;out1', $page);
        } finally {
            $db->query("DELETE FROM llx_dolimcp_journal WHERE rowid = $id");
        }
    }

    private static function constValue(): string
    {
        $row = self::db()->query("SELECT value FROM llx_const WHERE name = '" . self::CONST_NAME . "' AND entity = 1")->fetch_row();

        return $row === null ? '' : (string) $row[0];
    }

    private function restoreConst(string $old): void
    {
        self::db()->query("DELETE FROM llx_const WHERE name = '" . self::CONST_NAME . "' AND entity = 1");
        if ($old !== '') {
            self::setConst(self::CONST_NAME, $old);
        }
    }

    /** Posts the setup form, with the page's own CSRF token or none. */
    private function savePost(string $jar, string $url, bool $withToken): void
    {
        $fields = ['action' => 'save', self::CONST_NAME => $url];
        if ($withToken) {
            $page = $this->webGet(self::PAGE, $jar);
            $this->assertSame(1, preg_match('/name="token" value="([^"]+)"/', $page, $m), 'no token on the setup form');
            $fields['token'] = $m[1];
        }
        $this->web($jar, self::PAGE, $fields);
    }

    private static function webBase(): string
    {
        return (string) preg_replace('#/custom/dolimcp/mcp\.php$#', '', (string) getenv('MCP_URL'));
    }

    private function webGet(string $path, string $jar): string
    {
        return $this->web($jar, $path);
    }

    private function web(string $jar, string $path, ?array $post = null): string
    {
        $curl = curl_init(self::webBase() . $path);
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
        $this->assertIsString($body, "Request failed: $path");

        return $body;
    }

    /** Logs into the web interface (login form with its CSRF token); returns the cookie jar file, removed at tear down. */
    private function webLogin(string $login, string $password): string
    {
        $jar = (string) tempnam(sys_get_temp_dir(), 'dolimcp');
        $this->cleanup[] = static function () use ($jar): void {
            @unlink($jar);
        };
        $form = $this->web($jar, '/index.php');
        $this->assertSame(1, preg_match('/name="token" value="([^"]+)"/', $form, $m), 'no CSRF token on the login form');
        $this->web($jar, '/index.php?mainmenu=home', [
            'token' => $m[1],
            'actionlogin' => 'login',
            'loginfunction' => 'loginfunction',
            'username' => $login,
            'password' => $password,
        ]);

        return $jar;
    }
}
