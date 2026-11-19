<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;

/** Numbering model, numbering masks and PDF model of invoices and proposals, like Dolibarr's admin/facture.php and admin/propal.php. */
final class DocumentSetupTools
{
    private const TYPES = [
        'invoice' => [
            'dir' => 'facture', 'prefix' => 'mod_facture_', 'model_type' => 'invoice',
            'numbering' => 'FACTURE_ADDON', 'pdf' => 'FACTURE_ADDON_PDF',
            'masks' => [
                'invoice' => 'FACTURE_MERCURE_MASK_INVOICE', 'credit' => 'FACTURE_MERCURE_MASK_CREDIT',
                'deposit' => 'FACTURE_MERCURE_MASK_DEPOSIT', 'replacement' => 'FACTURE_MERCURE_MASK_REPLACEMENT',
            ],
        ],
        'proposal' => [
            'dir' => 'propale', 'prefix' => 'mod_propale_', 'model_type' => 'propal',
            'numbering' => 'PROPALE_ADDON', 'pdf' => 'PROPALE_ADDON_PDF',
            'masks' => ['proposal' => 'PROPALE_SAPHIR_MASK'],
        ],
    ];

    public static function register(ToolRegistry $registry, Context $context): void
    {
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        $docType = ['type' => 'string', 'enum' => array_keys(self::TYPES)];

        $registry->add(new Tool('document_setup_get',
            'Read how invoices or proposals are numbered and rendered: numbering model, masks (e.g. invoice: F-{yyyy}-{0000}), an example of the next number, the PDF model, and the models available.',
            SetupTools::schema(['doc_type' => $docType], ['doc_type']),
            SetupTools::admin($context, static fn (array $a): array => self::get(self::type((string) $a['doc_type'])))));
        $registry->add(new Tool('document_setup_set',
            'Change the numbering model, the numbering masks (invoice: invoice, credit, deposit, replacement; proposal: proposal; an empty mask erases it) and/or the PDF model of invoices or proposals. Returns the new setup. Models come from document_setup_get.',
            SetupTools::schema([
                'doc_type' => $docType,
                'numbering_model' => ['type' => 'string', 'description' => 'e.g. mod_facture_mercure'],
                'masks' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'pdf_model' => ['type' => 'string', 'description' => 'e.g. sponge, azur, cyan'],
            ], ['doc_type']),
            SetupTools::admin($context, static fn (array $a): array => self::set($context, self::type((string) $a['doc_type']), $a)), true));
    }

    private static function type(string $docType): array
    {
        return self::TYPES[$docType] ?? throw new ToolError('doc_type must be invoice or proposal.');
    }

    /** @return array{numbering_model: string, masks: array<string,string>, next_example: string, pdf_model: string, available_numbering_models: list<string>, available_pdf_models: list<string>} */
    private static function get(array $type): array
    {
        $model = getDolGlobalString($type['numbering']);
        $masks = [];
        foreach ($type['masks'] as $key => $constant) {
            $masks[$key] = getDolGlobalString($constant);
        }

        return [
            'numbering_model' => $model,
            'masks' => $masks,
            'next_example' => self::example($type, $model),
            'pdf_model' => getDolGlobalString($type['pdf']),
            'available_numbering_models' => self::numberingModels($type),
            'available_pdf_models' => self::pdfModels($type),
        ];
    }

    private static function example(array $type, string $model): string
    {
        if (!in_array($model, self::numberingModels($type), true)) {
            return '';
        }
        require_once DOL_DOCUMENT_ROOT . '/core/modules/' . $type['dir'] . '/' . $model . '.php';

        return (string) (new $model())->getExample();
    }

    /** @return list<string> */
    private static function numberingModels(array $type): array
    {
        $files = glob(DOL_DOCUMENT_ROOT . '/core/modules/' . $type['dir'] . '/' . $type['prefix'] . '*.php') ?: [];

        return array_map(static fn (string $f): string => basename($f, '.php'), $files);
    }

    /** @return list<string> */
    private static function pdfModels(array $type): array
    {
        $files = glob(DOL_DOCUMENT_ROOT . '/core/modules/' . $type['dir'] . '/doc/pdf_*.modules.php') ?: [];

        return array_map(static fn (string $f): string => substr(basename($f, '.modules.php'), 4), $files);
    }

    private static function set(Context $context, array $type, array $a): array
    {
        $model = isset($a['numbering_model']) ? (string) $a['numbering_model'] : null;
        $pdf = isset($a['pdf_model']) ? (string) $a['pdf_model'] : null;
        $masks = (array) ($a['masks'] ?? []);
        if ($model === null && $pdf === null && $masks === []) {
            throw new ToolError('Give numbering_model, masks or pdf_model.');
        }
        // Everything is checked before the first write.
        if ($model !== null && !in_array($model, self::numberingModels($type), true)) {
            throw new ToolError("Unknown numbering model $model; available: " . implode(', ', self::numberingModels($type)) . '.');
        }
        if ($pdf !== null && !in_array($pdf, self::pdfModels($type), true)) {
            throw new ToolError("Unknown PDF model $pdf; available: " . implode(', ', self::pdfModels($type)) . '.');
        }
        $unknown = array_diff(array_keys($masks), array_keys($type['masks']));
        if ($unknown !== []) {
            throw new ToolError('Unknown mask ' . implode(', ', $unknown) . '; available: ' . implode(', ', array_keys($type['masks'])) . '.');
        }

        if ($model !== null) {
            SetupTools::write($context, $type['numbering'], $model);
        }
        foreach ($masks as $key => $mask) {
            SetupTools::write($context, $type['masks'][$key], (string) $mask);
        }
        if ($pdf !== null) {
            // Like admin/*.php "setdoc": the constant, then the model declared again for the entity.
            SetupTools::write($context, $type['pdf'], $pdf);
            if (delDocumentModel($pdf, $type['model_type']) < 0 || addDocumentModel($pdf, $type['model_type'], '', '') < 0) {
                throw new ToolError("Could not declare the PDF model $pdf: " . $context->db->lasterror());
            }
        }

        return self::get($type);
    }
}
