<?php

declare(strict_types=1);

namespace DoliMcp\Tests\Unit;

use DoliMcp\Mcp\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'status' => ['enum' => ['open', 'closed']],
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ],
        'required' => ['name'],
        'additionalProperties' => false,
    ];

    public function testValidInputHasNoErrors(): void
    {
        $this->assertSame([], SchemaValidator::validate(self::SCHEMA, ['name' => 'a', 'ids' => [1, 2]]));
    }

    public function testWrongTypeIsReported(): void
    {
        $errors = SchemaValidator::validate(self::SCHEMA, ['name' => 5]);
        $this->assertSame(['name must be of type string'], $errors);
    }

    public function testMissingRequiredFieldIsReported(): void
    {
        $this->assertSame(['name is required'], SchemaValidator::validate(self::SCHEMA, []));
    }

    public function testExtraFieldIsRejected(): void
    {
        $errors = SchemaValidator::validate(self::SCHEMA, ['name' => 'a', 'x' => 1]);
        $this->assertSame(['x is not allowed'], $errors);
    }

    public function testValueOutsideEnumIsReported(): void
    {
        $errors = SchemaValidator::validate(self::SCHEMA, ['name' => 'a', 'status' => 'nope']);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('status must be one of', $errors[0]);
    }

    public function testArrayOfIntegersChecksEachItem(): void
    {
        $errors = SchemaValidator::validate(self::SCHEMA, ['name' => 'a', 'ids' => [1, 'x']]);
        $this->assertSame(['ids[1] must be of type integer'], $errors);
    }

    public function testTypeListAcceptsAnyListedType(): void
    {
        $schema = ['type' => ['string', 'null']];
        $this->assertSame([], SchemaValidator::validate($schema, null));
        $this->assertNotSame([], SchemaValidator::validate($schema, 3));
    }
}
