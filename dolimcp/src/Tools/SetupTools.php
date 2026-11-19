<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;
use DoliMcp\Support\Compactor;

/** What Dolibarr's REST API cannot configure: company identity and logo, modules, constants. Administrators only, like admin/. */
final class SetupTools
{
    /** Tool field => Dolibarr constant. country, state, legal_form, vat_liable and fiscal_month_start have their own format (see COMPANY_TYPES and write()). */
    private const COMPANY_FIELDS = [
        'name' => 'MAIN_INFO_SOCIETE_NOM', 'address' => 'MAIN_INFO_SOCIETE_ADDRESS', 'zip' => 'MAIN_INFO_SOCIETE_ZIP',
        'town' => 'MAIN_INFO_SOCIETE_TOWN', 'state' => 'MAIN_INFO_SOCIETE_STATE', 'country' => 'MAIN_INFO_SOCIETE_COUNTRY',
        'email' => 'MAIN_INFO_SOCIETE_MAIL', 'phone' => 'MAIN_INFO_SOCIETE_TEL', 'mobile' => 'MAIN_INFO_SOCIETE_MOBILE',
        'website' => 'MAIN_INFO_SOCIETE_WEB', 'managers' => 'MAIN_INFO_SOCIETE_MANAGERS', 'object' => 'MAIN_INFO_SOCIETE_OBJECT',
        'note' => 'MAIN_INFO_SOCIETE_NOTE', 'legal_form' => 'MAIN_INFO_SOCIETE_FORME_JURIDIQUE',
        'vat_exemption_code' => 'MAIN_INFO_SOCIETE_VAT_EXEMPTION_CODE', 'gdpr_contact' => 'MAIN_INFO_GDPR',
        'capital' => 'MAIN_INFO_CAPITAL', 'siren' => 'MAIN_INFO_SIREN', 'siret' => 'MAIN_INFO_SIRET', 'ape' => 'MAIN_INFO_APE',
        'rcs' => 'MAIN_INFO_RCS', 'vat_number' => 'MAIN_INFO_TVAINTRA', 'vat_liable' => 'FACTURE_TVAOPTION',
        'currency' => 'MAIN_MONNAIE', 'fiscal_month_start' => 'SOCIETE_FISCAL_MONTH_START',
    ];
    private const COMPANY_TYPES = [
        'vat_liable' => ['type' => 'boolean', 'description' => 'true when the company is subject to VAT.'],
        'fiscal_month_start' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
        'capital' => ['type' => ['string', 'number']],
        'country' => ['type' => 'string', 'description' => 'ISO code, e.g. FR.'],
        'state' => ['type' => 'string', 'description' => 'Department code in the company country, e.g. 75.'],
        'legal_form' => ['type' => 'string', 'description' => 'Dolibarr legal form code for the company country, e.g. 54 (SARL) in France.'],
    ];
    private const MAX_LOGO_BYTES = 5_000_000;

    public static function register(ToolRegistry $registry, Context $context): void
    {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';
        require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
        $schema = static fn (array $properties, array $required = []): array => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        $admin = static fn (callable $run): \Closure => static function (array $a) use ($context, $run): ToolResult {
            self::requireAdmin($context);

            return ToolResult::ok($run($a));
        };
        $company = [];
        foreach (self::COMPANY_FIELDS as $field => $constant) {
            $company[$field] = self::COMPANY_TYPES[$field] ?? ['type' => 'string'];
        }

        $registry->add(new Tool('company_get', 'Read the identity of your company (the one on documents): name, address, country (ISO code), SIREN/SIRET, VAT number, currency...', $schema([]),
            $admin(fn (array $a): array => self::companyGet($context))));
        $registry->add(new Tool('company_set', 'Change the identity of your company; give only the fields to change. An empty string erases a field.', $schema($company),
            $admin(fn (array $a): array => self::companySet($context, $a)), true));
        $registry->add(new Tool('company_logo_set', 'Set the company logo (png, jpg, gif...) from base64 content; thumbnails are generated.',
            $schema(['filename' => ['type' => 'string'], 'content_base64' => ['type' => 'string']], ['filename', 'content_base64']),
            $admin(fn (array $a): array => self::logoSet($context, (string) $a['filename'], (string) $a['content_base64'])), true));
        $registry->add(new Tool('module_list', 'List the Dolibarr modules with their enabled state. The name is what module_set takes.',
            $schema(['enabled_only' => ['type' => 'boolean']]),
            $admin(fn (array $a): array => self::modules($context, (bool) ($a['enabled_only'] ?? false)))));
        $registry->add(new Tool('module_set', 'Enable or disable a Dolibarr module by its name from module_list (e.g. Agenda).',
            $schema(['module' => ['type' => 'string'], 'enabled' => ['type' => 'boolean']], ['module', 'enabled']),
            $admin(fn (array $a): array => self::moduleSet($context, (string) $a['module'], (bool) $a['enabled'])), true));
        $registry->add(new Tool('const_get', 'Read Dolibarr constants by exact names and/or name prefix (no argument: all of them). Secret values (keys, passwords, tokens) are masked.',
            $schema(['names' => ['type' => 'array', 'items' => ['type' => 'string']], 'prefix' => ['type' => 'string']]),
            $admin(fn (array $a): array => self::constGet($context, (array) ($a['names'] ?? []), (string) ($a['prefix'] ?? '')))));
        $registry->add(new Tool('const_set', 'Set a Dolibarr constant; an empty value deletes it.',
            $schema(['name' => ['type' => 'string'], 'value' => ['type' => 'string']], ['name', 'value']),
            $admin(fn (array $a): array => self::constSet($context, (string) $a['name'], (string) $a['value'])), true));
    }

    public static function requireAdmin(Context $context): void
    {
        if (!$context->user->admin) {
            throw new ToolError('Administrator rights required.');
        }
    }

    // ---- constants ----

    /** @return list<array{name: string, value: string}> */
    private static function constGet(Context $context, array $names, string $prefix): array
    {
        $db = $context->db;
        $where = [];
        if ($names !== []) {
            $where[] = 'name IN (' . implode(',', array_map(static fn ($n): string => "'" . $db->escape((string) $n) . "'", $names)) . ')';
        }
        if ($prefix !== '') {
            $where[] = "name LIKE '" . $db->escape($db->escapeforlike($prefix)) . "%'";
        }
        $resql = $db->query('SELECT name, value FROM ' . MAIN_DB_PREFIX . 'const WHERE entity IN (0, ' . (int) $context->user->entity . ')'
            . ($where === [] ? '' : ' AND (' . implode(' OR ', $where) . ')') . ' ORDER BY name');
        if (!$resql) {
            throw new ToolError('Could not read the constants: ' . $db->lasterror());
        }
        $rows = [];
        while ($row = $db->fetch_object($resql)) {
            $rows[] = self::masked((string) $row->name, (string) $row->value);
        }

        return $rows;
    }

    /** @return array{name: string, value: string} */
    private static function constSet(Context $context, string $name, string $value): array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new ToolError('A constant name is made of letters, digits and underscores.');
        }
        self::write($context, $name, $value);

        return self::masked($name, $value);
    }

    /** The single place where a constant is written: empty value deletes, like the admin pages. */
    private static function write(Context $context, string $name, string $value): void
    {
        $entity = (int) $context->user->entity;
        $done = $value === '' ? dolibarr_del_const($context->db, $name, $entity) : dolibarr_set_const($context->db, $name, $value, 'chaine', 0, '', $entity);
        if ($done < 0) {
            throw new ToolError("Could not write the constant $name: " . $context->db->lasterror());
        }
    }

    private static function masked(string $name, string $value): array
    {
        return ['name' => $name, 'value' => $value !== '' && Compactor::isSecretConstant($name) ? Compactor::MASK : $value];
    }

    // ---- company ----

    private static function companyGet(Context $context): array
    {
        $out = [];
        foreach (self::COMPANY_FIELDS as $field => $constant) {
            $value = getDolGlobalString($constant);
            $out[$field] = match ($field) {
                'country', 'state' => explode(':', $value)[1] ?? '',
                'vat_liable' => $value !== '' && $value !== '0',
                'fiscal_month_start' => $value === '' ? '' : (int) $value,
                default => $value,
            };
        }

        return $out;
    }

    private static function companySet(Context $context, array $fields): array
    {
        if ($fields === []) {
            throw new ToolError('Give at least one field to change.');
        }
        $db = $context->db;
        $countryCode = (string) ($fields['country'] ?? self::companyGet($context)['country']);
        foreach (self::COMPANY_FIELDS as $field => $constant) { // country first: state and legal_form depend on it
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = is_bool($fields[$field]) ? ($fields[$field] ? '1' : '0') : (string) $fields[$field];
            if ($value !== '') {
                $value = match ($field) {
                    'country' => self::countryValue($db, $value),
                    'state' => self::stateValue($db, $countryCode, $value),
                    'legal_form' => self::legalFormValue($db, $countryCode, $value),
                    default => $value,
                };
            }
            self::write($context, $constant, $value);
        }

        return self::companyGet($context);
    }

    // The formats written by admin/company.php: "id:code:label" for the country and the state, the bare code for the legal form.
    private static function countryValue(\DoliDB $db, string $code): string
    {
        $country = getCountry(strtoupper($code), 'all', $db, null, 0);
        if (empty($country['id'])) {
            throw new ToolError("Unknown country code $code.");
        }

        return $country['id'] . ':' . $country['code'] . ':' . $country['label'];
    }

    private static function stateValue(\DoliDB $db, string $countryCode, string $code): string
    {
        $resql = $db->query('SELECT d.rowid FROM ' . MAIN_DB_PREFIX . 'c_departements AS d, ' . MAIN_DB_PREFIX . 'c_regions AS r, ' . MAIN_DB_PREFIX . 'c_country AS c'
            . " WHERE d.fk_region = r.code_region AND r.fk_pays = c.rowid AND c.code = '" . $db->escape($countryCode) . "' AND d.code_departement = '" . $db->escape($code) . "' AND d.active = 1");
        $row = $resql ? $db->fetch_object($resql) : null;
        $state = $row ? getState((int) $row->rowid, 'all', $db, 0, null, 0) : [];
        if (empty($state['id'])) {
            throw new ToolError("Unknown state code $code for country $countryCode.");
        }

        return $state['id'] . ':' . $state['code'] . ':' . $state['label'];
    }

    private static function legalFormValue(\DoliDB $db, string $countryCode, string $code): string
    {
        $resql = $db->query('SELECT f.code FROM ' . MAIN_DB_PREFIX . 'c_forme_juridique AS f, ' . MAIN_DB_PREFIX . 'c_country AS c'
            . " WHERE f.fk_pays = c.rowid AND c.code = '" . $db->escape($countryCode) . "' AND f.code = '" . $db->escape($code) . "' AND f.active = 1");
        if (!$resql || !$db->fetch_object($resql)) {
            throw new ToolError("Unknown legal form code $code for country $countryCode.");
        }

        return $code;
    }

    private static function logoSet(Context $context, string $filename, string $base64): array
    {
        global $conf;

        $name = dol_sanitizeFileName(basename(str_replace('\\', '/', $filename)));
        if ($name === '' || image_format_supported($name) < 0) {
            throw new ToolError('Unsupported image format; use png, jpg or gif.');
        }
        $content = base64_decode($base64, true);
        if ($content === false || $content === '' || strlen($content) > self::MAX_LOGO_BYTES) {
            throw new ToolError('content_base64 must be valid base64 of at most 5 MB.');
        }

        $dir = $conf->mycompany->dir_output . '/logos/';
        dol_mkdir($dir);
        $tmp = tempnam(sys_get_temp_dir(), 'dolimcp');
        file_put_contents($tmp, $content);
        // Like admin/company.php, minus the HTTP upload: the file ends up in logos/ of the company directory.
        $moved = dol_move($tmp, $dir . $name, '0', 1, 0, 0);
        @unlink($tmp);
        if (!$moved) {
            throw new ToolError('Could not store the logo file.');
        }

        self::write($context, 'MAIN_INFO_SOCIETE_LOGO', $name);
        $sizes = getDefaultImageSizes();
        foreach (['_small' => ['maxwidthsmall', 'maxheightsmall'], '_mini' => ['maxwidthmini', 'maxheightmini']] as $suffix => [$width, $height]) {
            $thumb = vignette($dir . $name, $sizes[$width], $sizes[$height], $suffix, $sizes['quality']);
            if (image_format_supported($thumb) >= 0 && preg_match('/([^\\/:]+)$/', $thumb, $m) === 1) {
                self::write($context, 'MAIN_INFO_SOCIETE_LOGO' . strtoupper($suffix), $m[1]);
            }
        }

        return ['logo' => $name];
    }

    // ---- modules ----

    /** @return list<array{name: string, label: string, enabled: bool}> */
    private static function modules(Context $context, bool $enabledOnly): array
    {
        $modules = [];
        foreach (self::descriptors() as $name => $file) {
            include_once $file;
            $class = 'mod' . $name;
            if (!class_exists($class) || !is_subclass_of($class, \DolibarrModules::class)) {
                continue;
            }
            $module = new $class($context->db);
            $enabled = getDolGlobalString((string) $module->const_name) !== '';
            if (!$enabledOnly || $enabled) {
                $modules[] = ['name' => $name, 'label' => (string) $module->getName(), 'enabled' => $enabled];
            }
        }
        usort($modules, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $modules;
    }

    /** @return array<string, string> module name => descriptor file */
    private static function descriptors(): array
    {
        $files = [];
        foreach (dolGetModulesDirs() as $dir) {
            foreach (glob($dir . 'mod*.class.php') ?: [] as $file) {
                $files[substr(basename($file, '.class.php'), 3)] ??= $file;
            }
        }

        return $files;
    }

    private static function moduleSet(Context $context, string $module, bool $enabled): array
    {
        if (!isset(self::descriptors()[$module])) {
            throw new ToolError("Unknown module $module; module_list gives the names.");
        }
        if ($enabled) {
            $errors = (array) (activateModule('mod' . $module)['errors'] ?? []);
        } else {
            $error = unActivateModule('mod' . $module);
            $errors = $error === '' || $error === null ? [] : [$error];
        }
        if ($errors !== []) {
            throw new ToolError(trim(strip_tags(str_replace('<br>', ' ', implode(' ', array_map('strval', $errors))))));
        }

        return ['module' => $module, 'enabled' => $enabled];
    }
}
