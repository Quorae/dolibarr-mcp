<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Unit;

use DoliMcp\Support\Compactor;
use PHPUnit\Framework\TestCase;

final class CompactorTest extends TestCase
{
    public function testEmptyValuesAreRemoved(): void
    {
        $this->assertSame(['id' => 1, 'zero' => 0, 'list' => [['id' => 2]]], Compactor::compact(['id' => 1, 'a' => null, 'b' => '', 'c' => [], 'zero' => 0, 'list' => [['id' => 2, 'x' => null], []]]));
    }

    public function testDateKeysBecomeDates(): void
    {
        $ts = mktime(0, 0, 0, 3, 15, 2026);
        $this->assertSame(['date_lim_reglement' => '2026-03-15', 'date' => '2026-03-15'], Compactor::compact(['date_lim_reglement' => $ts, 'date' => $ts]));
    }

    public function testDateWithTimeKeepsTime(): void
    {
        $this->assertSame(['datec' => '2026-03-15 14:30'], Compactor::compact(['datec' => mktime(14, 30, 0, 3, 15, 2026)]));
    }

    public function testApiKeyIsMasked(): void
    {
        $this->assertSame(['api_key' => '********', 'id' => 1], Compactor::compact(['api_key' => 'abc', 'id' => 1]));
    }

    public function testConstantNameValuePairIsMasked(): void
    {
        $this->assertSame(['name' => 'MAIN_MAIL_SMTPS_PW', 'value' => '********'], Compactor::compact(['name' => 'MAIN_MAIL_SMTPS_PW', 'value' => 'x']));
        $this->assertSame(['name' => 'MAIN_FOO', 'value' => 'x'], Compactor::compact(['name' => 'MAIN_FOO', 'value' => 'x']));
    }

    public function testTestAndProdSuffixesAreStripped(): void
    {
        $this->assertTrue(Compactor::isSecretName('MAIN_MAIL_SMTPS_PW_TEST'));
        $this->assertTrue(Compactor::isSecretName('STRIPE_KEY_PROD'));
        $this->assertFalse(Compactor::isSecretName('MAIN_MAIL_SMTPS_ID'));
    }

    public function testFieldsAreMaskedFromTheFixedListOnly(): void
    {
        $this->assertSame(['import_key' => 'x', 'pass_temp' => '********', 'pass' => '********'], Compactor::compact(['import_key' => 'x', 'pass_temp' => 't', 'pass' => 'p']));
    }

    public function testUppercaseConstantNamesAreMasked(): void
    {
        $this->assertSame(['MAIN_MAIL_SMTPS_PW' => '********'], Compactor::compact(['MAIN_MAIL_SMTPS_PW' => 'x']));
        $this->assertTrue(Compactor::isSecretConstant('main_mail_smtps_pw'));
        $this->assertTrue(Compactor::isSecretConstant('SOME_SECRET2'));
    }

    public function testNestedConstantListIsMasked(): void
    {
        $out = Compactor::compact(['constants' => [['name' => 'A_TOKEN', 'value' => 'x'], ['name' => 'A_LABEL', 'value' => 'y']]]);
        $this->assertSame([['name' => 'A_TOKEN', 'value' => '********'], ['name' => 'A_LABEL', 'value' => 'y']], $out['constants']);
    }

    public function testThirdpartyWebservicesKeyIsMasked(): void
    {
        $this->assertSame(['webservices_key' => '********', 'id' => 1], Compactor::compact(['webservices_key' => 'abc', 'id' => 1]));
    }

    public function testExtraSecretFieldsAreMaskedWhenGiven(): void
    {
        Compactor::setExtraSecretFields(['options_testsecret']);
        try {
            $this->assertSame(['array_options' => ['options_testsecret' => '********', 'options_other' => 'x']], Compactor::compact(['array_options' => ['options_testsecret' => 's3cr3t', 'options_other' => 'x']]));
        } finally {
            Compactor::setExtraSecretFields([]);
        }
        $this->assertSame(['options_testsecret' => 's3cr3t'], Compactor::compact(['options_testsecret' => 's3cr3t']));
    }

    public function testDateKeyRule(): void
    {
        $this->assertTrue(Compactor::isDateKey('date_lim_reglement'));
        $this->assertTrue(Compactor::isDateKey('date'));
        $this->assertFalse(Compactor::isDateKey('label'));
    }
}
