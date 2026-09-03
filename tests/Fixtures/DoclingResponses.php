<?php

namespace BlueHex\DoclingRag\Tests\Fixtures;

class DoclingResponses
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function chunks(): array
    {
        return [
            [
                'filename' => 'report.pdf',
                'chunk_index' => 0,
                'text' => 'Introduction paragraph about the system.',
                'num_tokens' => 12,
                'headings' => ['Introduction'],
                'page_numbers' => [1],
                'metadata' => [],
            ],
            [
                'filename' => 'report.pdf',
                'chunk_index' => 1,
                'text' => "| Metric | Value |\n| --- | --- |\n| Recall | 0.91 |",
                'num_tokens' => 20,
                'headings' => ['Results'],
                'page_numbers' => [2],
                'metadata' => [],
            ],
            [
                'filename' => 'report.pdf',
                'chunk_index' => 2,
                'text' => 'See the architecture diagram.',
                'num_tokens' => 8,
                'headings' => ['Results', 'Figures'],
                'page_numbers' => [3],
                'metadata' => ['has_image' => true],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $chunks
     * @return array<string, mixed>
     */
    public static function result(?array $chunks = null): array
    {
        return [
            'chunks' => $chunks ?? self::chunks(),
            'documents' => [],
            'processing_time' => 0.42,
        ];
    }

    /**
     * A task that "succeeded" but every document was skipped, matching what
     * Docling actually returns for e.g. an unrecognized filename extension.
     *
     * @return array<string, mixed>
     */
    public static function resultWithDocumentError(string $errorMessage): array
    {
        return [
            'chunks' => [],
            'documents' => [
                [
                    'filename' => 'notes.markdown',
                    'status' => 'skipped',
                    'errors' => [
                        [
                            'component_type' => 'user_input',
                            'module_name' => '',
                            'error_message' => $errorMessage,
                            'category' => 'policy',
                            'page_no' => null,
                        ],
                    ],
                ],
            ],
            'processing_time' => 0.1,
        ];
    }
}
