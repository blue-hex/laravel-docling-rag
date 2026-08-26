<?php

namespace BlueHex\DoclingRag\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    public $signature = 'rag:install
                            {--with-gotenberg : Publish the example Compose file and enable the Gotenberg fallback}';

    public $description = 'Publish Docling RAG config and migrations';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'docling-rag-config',
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'docling-rag-migrations',
        ]);

        if ($this->option('with-gotenberg')) {
            $this->call('vendor:publish', [
                '--tag' => 'docling-rag-docker',
            ]);

            $this->enableGotenberg();
            $this->info('Gotenberg fallback enabled. Set GOTENBERG_URL if the default is not correct.');
        }

        $this->info('Docling RAG installed. Run `php artisan migrate` then `php artisan rag:index` after embeddings land.');

        return self::SUCCESS;
    }

    protected function enableGotenberg(): void
    {
        $path = config_path('docling-rag.php');

        if (! File::exists($path)) {
            return;
        }

        $contents = File::get($path);
        $updated = str_replace(
            "env('DOCLING_RAG_GOTENBERG_ENABLED', false)",
            "env('DOCLING_RAG_GOTENBERG_ENABLED', true)",
            $contents,
        );

        if ($updated !== $contents) {
            File::put($path, $updated);
        }
    }
}
