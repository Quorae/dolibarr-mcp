<?php

declare(strict_types=1);

namespace DoliMcp\Support;

/** Shrinks Dolibarr payloads (no empty values, readable dates) and masks every secret. */
final class Compactor
{
    public const MASK = '********';

    // Constant names (uppercase): the dolibarr_set_const() regex plus what isASecretKey() adds (p12key, secretN, _PW_*).
    private const SECRET_CONSTANT_REGEX = '/(_KEY|_EXPORTKEY|SECUREKEY|SERVERKEY|P12KEY|_PASS|PASSWORD|_PW|_PW_[A-Z]+|SECRET\d?|_TOKEN|TOKEN|_REFRESH)$/';
    // Object field names (lowercase): fixed list, so that fields such as import_key stay visible.
    private const SECRET_FIELDS = ['pass', 'password', 'pass_crypted', 'pass_indatabase', 'pass_indatabase_crypted', 'pass_temp', 'api_key', 'token'];
    private const DATE_KEYS = ['date', 'datec', 'datem', 'datep', 'datef', 'tms'];

    /** A field name (lowercase list) or an uppercase constant name. */
    public static function isSecretName(string $name): bool
    {
        return in_array($name, self::SECRET_FIELDS, true) || ($name === strtoupper($name) && self::isSecretConstant($name));
    }

    /** A Dolibarr constant name, in any case (Dolibarr itself matches case-insensitively). */
    public static function isSecretConstant(string $name): bool
    {
        return preg_match(self::SECRET_CONSTANT_REGEX, strtoupper((string) preg_replace('/(_TEST|_PROD)$/i', '', $name))) === 1;
    }

    public static function compact(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $maskValue = isset($data['name'], $data['value']) && is_string($data['name']) && self::isSecretConstant($data['name']);
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && ($maskValue && $key === 'value' || self::isSecretName($key)) && $value !== null && $value !== '') {
                $out[$key] = self::MASK;
                continue;
            }
            if (is_string($key) && is_int($value) && $value > 0 && self::isDateKey($key)) {
                $value = date(date('H:i', $value) === '00:00' ? 'Y-m-d' : 'Y-m-d H:i', $value);
            }
            $value = self::compact($value);
            if ($value !== null && $value !== '' && $value !== []) {
                $out[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($out) : $out;
    }

    private static function isDateKey(string $key): bool
    {
        return in_array($key, self::DATE_KEYS, true) || str_starts_with($key, 'date_') || str_ends_with($key, '_date');
    }
}
