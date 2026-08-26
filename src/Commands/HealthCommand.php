<?php

namespace BlueHex\DoclingRag\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HealthCommand extends Command
{
    public $signature = 'rag:health';

    public $description = 'Check Docling, Gotenberg, and pgvector availability';

    public function handle(): int
    {
        $ok = $this->checkDocling()
            && $this->checkGotenberg()
            && $this->checkPgvector();

        if ($ok) {
            $this->info('Docling RAG is healthy.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    protected function checkDocling(): bool
    {
        $url = rtrim((string) config('docling-rag.docling.url'), '/');

        try {
            $response = Http::timeout(5)->get($url.'/health');

            if ($response->successful()) {
                $this->info("Docling reachable at {$url}.");

                return true;
            }

            $this->error("Docling at {$url} returned HTTP {$response->status()}.");
        } catch (\Throwable $exception) {
            $this->error("Docling at {$url} is unreachable: {$exception->getMessage()}");
        }

        return false;
    }

    protected function checkGotenberg(): bool
    {
        if (! config('docling-rag.gotenberg.enabled')) {
            $this->comment('Gotenberg is disabled.');

            return true;
        }

        $url = rtrim((string) config('docling-rag.gotenberg.url'), '/');

        try {
            $response = Http::timeout(5)->get($url.'/health');

            if ($response->successful()) {
                $this->info("Gotenberg reachable at {$url}.");

                return true;
            }

            $this->error("Gotenberg at {$url} returned HTTP {$response->status()}.");
        } catch (\Throwable $exception) {
            $this->error("Gotenberg at {$url} is unreachable: {$exception->getMessage()}");
        }

        return false;
    }

    protected function checkPgvector(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('PostgreSQL is required. Current driver: '.DB::connection()->getDriverName().'.');

            return false;
        }

        $extension = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");

        if ($extension === null) {
            $this->error('pgvector extension is not installed.');

            return false;
        }

        if (version_compare((string) $extension->extversion, '0.8', '<')) {
            $this->error("pgvector {$extension->extversion} is too old; >= 0.8 is required.");

            return false;
        }

        $this->info("pgvector {$extension->extversion} is available.");

        return true;
    }
}
