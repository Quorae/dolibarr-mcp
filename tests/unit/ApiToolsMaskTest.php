<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Unit;

use DoliMcp\Support\Compactor;
use DoliMcp\Tools\ApiTools;
use PHPUnit\Framework\TestCase;

final class ApiToolsMaskTest extends TestCase
{
    public function testSecretConstantAnswerIsMasked(): void
    {
        $this->assertSame(Compactor::MASK, ApiTools::mask('setup/conf/MAIN_MAIL_SMTPS_PW', 'hunter2'));
        $this->assertSame(Compactor::MASK, ApiTools::mask('setup/conf/main_mail_smtps_pw_test', 'hunter2'));
    }

    public function testOtherAnswersAreUntouched(): void
    {
        $this->assertSame('x', ApiTools::mask('setup/conf/MAIN_LANG_DEFAULT', 'x'));
        $this->assertSame('x', ApiTools::mask('thirdparties/1', 'x'));
    }
}
