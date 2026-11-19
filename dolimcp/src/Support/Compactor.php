<?php

declare(strict_types=1);

namespace DoliMcp\Support;

/** Shrinks Dolibarr payloads (no empty values, readable dates) and masks every secret. */
final class Compactor
{
    public const MASK = '********';

    // Same regex as dolibarr_set_const(); the extra names are the user/API credential fields.
    private const SECRET_REGEX = '/(_KEY|_EXPORTKEY|_SECUREKEY|_SERVERKEY|_PASS|_PASSWORD|_PW|_PW_TICKET|_PW_EMAILING|_SECRET|_TOKEN|_REFRESH)$/';
    private const SECRET_NAMES = ['pass', 'password', 'pass_crypted', 'pass_indatabase', 'pass_indatabase_crypted', 'api_key', 'token'];
    private const DATE_KEYS = ['date', 'datec', 'datem', 'datep', 'datef', 'tms'];

    public static function isSecretName(string $name): bool
    {
        $name = strtoupper(preg_replace('/(_TEST|_PROD)$/i', '', $name) ?? $name);

        return in_array(strtolower($name), self::SECRET_NAMES, true) || preg_match(self::SECRET_REGEX, $name) === 1;
    }

    public static function compact(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $maskValue = isset($data['name'], $data['value']) && is_string($data['name']) && self::isSecretName($data['name']);
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
