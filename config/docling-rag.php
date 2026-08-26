<?php

return [
    'docling' => [
        'url' => env('DOCLING_URL', 'http://localhost:5001'),
        'api_key' => env('DOCLING_API_KEY'),
        'timeout' => (int) env('DOCLING_TIMEOUT', 120),
        'poll' => [
            'max_attempts' => (int) env('DOCLING_POLL_MAX_ATTEMPTS', 30),
            'backoff' => [5, 10, 20, 40, 60],
        ],
        'chunking' => [
            'max_tokens' => (int) env('DOCLING_CHUNK_MAX_TOKENS', 512),
            'merge_peers' => true,
            'use_markdown_tables' => true,
            'tokenizer' => env('DOCLING_CHUNK_TOKENIZER'),
        ],
    ],

    'gotenberg' => [
        'enabled' => (bool) env('DOCLING_RAG_GOTENBERG_ENABLED', false),
        'url' => env('GOTENBERG_URL', 'http://localhost:3000'),
    ],

    'embedding' => [
        'provider' => env('DOCLING_RAG_EMBEDDING_PROVIDER'),
        'model' => env('DOCLING_RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('DOCLING_RAG_EMBEDDING_DIMENSIONS', 1536),
        'halfvec' => (bool) env('DOCLING_RAG_EMBEDDING_HALFVEC', false),
        'batch_size' => (int) env('DOCLING_RAG_EMBEDDING_BATCH_SIZE', 100),
    ],

    'limits' => [
        'max_pages' => (int) env('DOCLING_RAG_MAX_PAGES', 200),
        'max_chunks' => (int) env('DOCLING_RAG_MAX_CHUNKS', 4000),
    ],

    'storage' => [
        'disk' => env('DOCLING_RAG_DISK', 'local'),
        'path' => env('DOCLING_RAG_PATH', 'rag'),
    ],

    'fts' => [
        'language' => env('DOCLING_RAG_FTS_LANGUAGE', 'english'),
    ],
];
