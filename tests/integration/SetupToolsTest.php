<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Integration;

final class SetupToolsTest extends McpTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testCompanyIdentityRoundTrips(): void
    {
        $before = $this->callTool('company_get')['data'];
        try {
            $set = $this->callTool('company_set', ['name' => 'Acme Setup Test', 'siren' => '123456789', 'country' => 'FR']);
            $this->assertFalse($set['isError'], $set['text']);

            $got = $this->callTool('company_get');
            $this->assertSame('Acme Setup Test', $got['data']['name']);
            $this->assertSame('123456789', $got['data']['siren']);
            $this->assertSame('FR', $got['data']['country']);

            $rest = $this->rest('GET', 'setup/company');
            $this->assertSame('Acme Setup Test', $rest['body']['name']);
            $this->assertSame('FR', $rest['body']['country_code']);
        } finally {
            $restore = ['name' => '', 'siren' => '', 'country' => ''];
            foreach (['name', 'siren', 'country'] as $field) {
                $restore[$field] = $before[$field] ?? '';
            }
            $this->callTool('company_set', $restore);
        }
    }

    public function testLogoIsStoredWithThumbnails(): void
    {
        $before = $this->callTool('const_get', ['prefix' => 'MAIN_INFO_SOCIETE_LOGO'])['data'];
        try {
            $r = $this->callTool('company_logo_set', ['filename' => 'dolimcp-test.png', 'content_base64' => self::PNG]);
            $this->assertFalse($r['isError'], $r['text']);

            $consts = array_column($this->callTool('const_get', ['prefix' => 'MAIN_INFO_SOCIETE_LOGO'])['data'], 'value', 'name');
            $this->assertSame('dolimcp-test.png', $consts['MAIN_INFO_SOCIETE_LOGO']);
            $this->assertNotEmpty($consts['MAIN_INFO_SOCIETE_LOGO_SMALL']);
            $this->assertNotEmpty($consts['MAIN_INFO_SOCIETE_LOGO_MINI']);
        } finally {
            foreach (['MAIN_INFO_SOCIETE_LOGO', 'MAIN_INFO_SOCIETE_LOGO_SMALL', 'MAIN_INFO_SOCIETE_LOGO_MINI'] as $name) {
                $old = array_column($before, 'value', 'name')[$name] ?? '';
                $this->callTool('const_set', ['name' => $name, 'value' => $old]);
            }
        }
    }

    public function testModuleCanBeDisabledAndEnabled(): void
    {
        $find = fn (): array => array_values(array_filter(
            $this->callTool('module_list')['data'],
            static fn (array $m): bool => $m['name'] === 'Agenda',
        ));
        try {
            $this->assertTrue($find()[0]['enabled']);

            $off = $this->callTool('module_set', ['module' => 'Agenda', 'enabled' => false]);
            $this->assertFalse($off['isError'], $off['text']);
            $this->assertFalse($find()[0]['enabled']);
            $enabled = array_column($this->callTool('module_list', ['enabled_only' => true])['data'], 'name');
            $this->assertNotContains('Agenda', $enabled);

            $on = $this->callTool('module_set', ['module' => 'Agenda', 'enabled' => true]);
            $this->assertFalse($on['isError'], $on['text']);
            $this->assertTrue($find()[0]['enabled']);
        } finally {
            $this->callTool('module_set', ['module' => 'Agenda', 'enabled' => true]);
        }
    }

    public function testUnknownModuleIsAnError(): void
    {
        $this->assertTrue($this->callTool('module_set', ['module' => 'NoSuchModule', 'enabled' => true])['isError']);
    }

    public function testSecretConstantIsNeverReturned(): void
    {
        try {
            $set = $this->callTool('const_set', ['name' => 'DOLIMCP_TEST_KEY', 'value' => 'secret-value-123']);
            $this->assertFalse($set['isError'], $set['text']);
            $this->assertStringNotContainsString('secret-value-123', $set['text']);

            $got = $this->callTool('const_get', ['names' => ['DOLIMCP_TEST_KEY']]);
            $this->assertSame([['name' => 'DOLIMCP_TEST_KEY', 'value' => '********']], $got['data']);
            $this->assertStringNotContainsString('secret-value-123', $got['text']);

            $api = $this->callTool('api_request', ['method' => 'GET', 'path' => 'setup/conf/DOLIMCP_TEST_KEY']);
            $this->assertStringNotContainsString('secret-value-123', $api['text']);
        } finally {
            $this->callTool('const_set', ['name' => 'DOLIMCP_TEST_KEY', 'value' => '']);
        }
    }

    public function testEmptyValueDeletesTheConstant(): void
    {
        try {
            $this->callTool('const_set', ['name' => 'DOLIMCP_TEST_PLAIN', 'value' => 'abc']);
            $this->assertSame([['name' => 'DOLIMCP_TEST_PLAIN', 'value' => 'abc']], $this->callTool('const_get', ['names' => ['DOLIMCP_TEST_PLAIN']])['data']);

            $this->callTool('const_set', ['name' => 'DOLIMCP_TEST_PLAIN', 'value' => '']);
            $this->assertSame([], $this->callTool('const_get', ['names' => ['DOLIMCP_TEST_PLAIN']])['data']);
        } finally {
            $this->callTool('const_set', ['name' => 'DOLIMCP_TEST_PLAIN', 'value' => '']);
        }
    }

    public function testNonAdminIsRefused(): void
    {
        $user = $this->restrictedUser();
        foreach ([['const_set', ['name' => 'DOLIMCP_TEST_PLAIN', 'value' => 'x']], ['const_get', []], ['company_get', []], ['module_list', []]] as [$tool, $args]) {
            $r = $this->callTool($tool, $args, $user['key']);
            $this->assertTrue($r['isError'], $tool);
            $this->assertStringContainsString('Administrator rights required', $r['text']);
        }
        $this->assertSame([], $this->callTool('const_get', ['names' => ['DOLIMCP_TEST_PLAIN']])['data']);
    }
}
