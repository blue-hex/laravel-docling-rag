<?php

namespace BlueHex\DoclingRag\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexCommand extends Command
{
    public $signature = 'rag:index';

    public $description = 'Create HNSW and GIN indexes on rag_chunks';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('rag:index requires PostgreSQL with pgvector.');

            return self::FAILURE;
        }

        $ops = config('docling-rag.embedding.halfvec') ? 'halfvec_cosine_ops' : 'vector_cosine_ops';

        DB::statement("CREATE INDEX IF NOT EXISTS rag_chunks_embedding_hnsw ON rag_chunks USING hnsw (embedding {$ops})");
        DB::statement('CREATE INDEX IF NOT EXISTS rag_chunks_tsv_gin ON rag_chunks USING gin (tsv)');

        $this->info('Created rag_chunks HNSW (cosine) and GIN (tsv) indexes.');

        return self::SUCCESS;
    }
}
