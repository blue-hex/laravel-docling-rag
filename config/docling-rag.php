<?php

return [
    'docling' => [
        'url' => env('DOCLING_URL', 'http://localhost:5001'),
        'api_key' => env('DOCLING_API_KEY'),
        'timeout' => (int) env('DOCLING_TIMEOUT', 120),
        // Separate, longer timeout for the upload itself — a large file takes
        // longer to transfer than a status poll or result fetch does.
        'upload_timeout' => (int) env('DOCLING_UPLOAD_TIMEOUT', 300),
        'poll' => [
            'max_attempts' => (int) env('DOCLING_POLL_MAX_ATTEMPTS', 30),
            'backoff' => [5, 10, 20, 40, 60],
        ],
        // Default fields sent to Docling's /v1/chunk/hybrid/file/async request.
        // Use the exact field names from that endpoint's schema (convert_* and
        // chunking_*) — anything valid there is valid here. Omitted keys fall
        // back to Docling's own defaults. Override per-call via
        // Rag::ingest($file, $owner, options: [...]).
        'request_options' => [
            'convert_do_ocr' => (bool) env('DOCLING_DO_OCR', true),
            'chunking_max_tokens' => (int) env('DOCLING_CHUNK_MAX_TOKENS', 512),
            'chunking_merge_peers' => true,
            'chunking_use_markdown_tables' => true,
            'chunking_tokenizer' => env('DOCLING_CHUNK_TOKENIZER'),
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
        'max_upload_mb' => (int) env('DOCLING_RAG_MAX_UPLOAD_MB', 100),
    ],

    'storage' => [
        'disk' => env('DOCLING_RAG_DISK', 'local'),
        'path' => env('DOCLING_RAG_PATH', 'rag'),
    ],

    'fts' => [
        'language' => env('DOCLING_RAG_FTS_LANGUAGE', 'english'),
    ],

    'retrieval' => [
        // Rows pulled from each retriever (vector + FTS) before fusion.
        'candidates' => (int) env('DOCLING_RAG_RETRIEVAL_CANDIDATES', 50),

        // RRF constant. Larger flattens the contribution of top ranks.
        'rrf_k' => (int) env('DOCLING_RAG_RETRIEVAL_RRF_K', 60),

        // Default number of chunks returned to the caller.
        'k' => (int) env('DOCLING_RAG_RETRIEVAL_K', 8),

        // Cap chunks returned from any single document. 0 disables.
        'per_document_cap' => (int) env('DOCLING_RAG_RETRIEVAL_PER_DOCUMENT_CAP', 3),

        // pgvector filtered-ANN recovery. off | relaxed_order | strict_order.
        'iterative_scan' => env('DOCLING_RAG_RETRIEVAL_ITERATIVE_SCAN', 'relaxed_order'),

        // Query-embedding cache TTL in seconds.
        'cache_ttl' => (int) env('DOCLING_RAG_RETRIEVAL_CACHE_TTL', 300),

        'rerank' => [
            'enabled' => (bool) env('DOCLING_RAG_RERANK_ENABLED', false),
            'provider' => env('DOCLING_RAG_RERANK_PROVIDER'),
            'model' => env('DOCLING_RAG_RERANK_MODEL'),
            // Fused results reranked before capping.
            'top_n' => (int) env('DOCLING_RAG_RERANK_TOP_N', 30),
        ],
    ],
];
