<?php

declare(strict_types=1);

namespace DoliMcp\Tools;

use DoliMcp\Dolibarr\Context;
use DoliMcp\Dolibarr\RestError;
use DoliMcp\Mcp\Tool;
use DoliMcp\Mcp\ToolError;
use DoliMcp\Mcp\ToolRegistry;
use DoliMcp\Mcp\ToolResult;

/** PDF generation and the files attached to a proposal or an invoice. */
final class DocumentFiles
{
    public static function register(ToolRegistry $registry, Context $context): void
    {
        $base = [
            'type' => ['type' => 'string', 'enum' => ['proposal', 'invoice']],
            'id' => ['type' => 'integer'],
        ];
        $schema = static fn (array $extra = [], array $required = []): array => [
            'type' => 'object',
            'properties' => $base + $extra,
            'required' => ['type', 'id', ...$required],
            'additionalProperties' => false,
        ];

        $registry->add(new Tool(
            'document_pdf',
            'Generate (or regenerate) the PDF of a proposal or an invoice with its template, or with model when given. Returns the file name and size; read the content with file_get.',
            $schema(['model' => ['type' => 'string']]),
            static function (array $a) use ($context): ToolResult {
                $ref = self::ref($context, $a);
                $built = (array) $context->rest->request('PUT', 'documents/builddoc', [], array_filter([
                    'modulepart' => self::modulepart($a),
                    'original_file' => "$ref/$ref.pdf",
                    'doctemplate' => (string) ($a['model'] ?? ''),
                ], static fn (string $value): bool => $value !== ''));

                return ToolResult::ok(['filename' => $built['filename'] ?? "$ref.pdf", 'size' => (int) ($built['filesize'] ?? 0)]);
            },
            true,
        ));

        $registry->add(new Tool(
            'file_list',
            'List the files attached to a proposal or an invoice (generated PDF included).',
            $schema(),
            static function (array $a) use ($context): ToolResult {
                try {
                    $files = (array) $context->rest->request('GET', 'documents', ['modulepart' => self::modulepart($a), 'id' => (int) $a['id']]);
                } catch (RestError $e) {
                    if ($e->status === 404) { // Dolibarr answers 404 to an empty folder
                        return ToolResult::ok([]);
                    }
                    throw $e;
                }

                return ToolResult::ok(array_map(static fn (array $file): array => [
                    'name' => $file['name'],
                    'size' => (int) $file['size'],
                    'date' => gmdate('Y-m-d\TH:i:s\Z', (int) $file['date']),
                ], array_values($files)));
            },
        ));

        $registry->add(new Tool(
            'file_get',
            'Download a file attached to a proposal or an invoice; the content comes back as a resource.',
            $schema(['filename' => ['type' => 'string']], ['filename']),
            static function (array $a) use ($context): ToolResult {
                $name = self::filename($a);
                $ref = self::ref($context, $a);
                $file = (array) $context->rest->request('GET', 'documents/download', ['modulepart' => self::modulepart($a), 'original_file' => "$ref/$name"]);

                return ToolResult::file(
                    "dolibarr://{$a['type']}/{$a['id']}/$name",
                    (string) ($file['content-type'] ?? 'application/octet-stream'),
                    (string) $file['content'],
                    ['filename' => $name, 'size' => (int) ($file['filesize'] ?? 0)],
                );
            },
        ));

        $registry->add(new Tool(
            'file_attach',
            'Attach a file (base64 content) to a proposal or an invoice. Refuses to replace an existing file unless overwrite is true.',
            $schema(['filename' => ['type' => 'string'], 'content_base64' => ['type' => 'string'], 'overwrite' => ['type' => 'boolean']], ['filename', 'content_base64']),
            static function (array $a) use ($context): ToolResult {
                $name = self::filename($a);
                $content = base64_decode((string) $a['content_base64'], true);
                if ($content === false) {
                    throw new ToolError('content_base64 is not valid base64.');
                }
                $context->rest->request('POST', 'documents/upload', [], [
                    'filename' => $name,
                    'modulepart' => self::modulepart($a),
                    'ref' => '',
                    'subdir' => self::ref($context, $a),
                    'filecontent' => $a['content_base64'],
                    'fileencoding' => 'base64',
                    'overwriteifexists' => ($a['overwrite'] ?? false) ? 1 : 0,
                ]);

                return ToolResult::ok(['filename' => $name, 'size' => strlen($content)]);
            },
            true,
        ));
    }

    private static function modulepart(array $a): string
    {
        return DocumentTools::TYPES[(string) $a['type']]['modulepart'];
    }

    private static function ref(Context $context, array $a): string
    {
        $object = (array) $context->rest->request('GET', DocumentTools::TYPES[(string) $a['type']]['path'] . '/' . (int) $a['id']);

        return (string) $object['ref'];
    }

    private static function filename(array $a): string
    {
        $name = (string) $a['filename'];
        if ($name === '' || $name !== basename($name) || str_starts_with($name, '.')) {
            throw new ToolError('filename must be a plain file name, without a folder.');
        }

        return $name;
    }
}
