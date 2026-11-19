<?php

declare(strict_types=1);

namespace DoliMcp\Mcp;

/** Validates the JSON Schema subset used by tool inputs. Returns a list of error messages. */
final class SchemaValidator
{
    /** @return list<string> */
    public static function validate(array $schema, mixed $value, string $path = ''): array
    {
        $at = $path === '' ? 'input' : $path;

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $ok = false;
            foreach ($types as $type) {
                $ok = $ok || self::isType((string) $type, $value);
            }
            if (!$ok) {
                return [$at . ' must be of type ' . implode('|', $types)];
            }
        }

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return [$at . ' must be one of: ' . json_encode($schema['enum'], JSON_UNESCAPED_UNICODE)];
        }

        $errors = [];
        if (is_array($value) && ($value === [] || !array_is_list($value)) && !isset($schema['items'])) {
            $props = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $name) {
                if (!array_key_exists($name, $value)) {
                    $errors[] = ($path === '' ? '' : $path . '.') . $name . ' is required';
                }
            }
            foreach ($value as $name => $item) {
                $sub = ($path === '' ? '' : $path . '.') . $name;
                if (isset($props[$name])) {
                    array_push($errors, ...self::validate($props[$name], $item, $sub));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $sub . ' is not allowed';
                }
            }
        } elseif (is_array($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                array_push($errors, ...self::validate($schema['items'], $item, $path . '[' . $i . ']'));
            }
        }

        return $errors;
    }

    private static function isType(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }
}
