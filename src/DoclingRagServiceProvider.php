<?php

namespace BlueHex\DoclingRag;

use BlueHex\DoclingRag\Commands\HealthCommand;
use BlueHex\DoclingRag\Commands\IndexCommand;
use BlueHex\DoclingRag\Commands\InstallCommand;
use BlueHex\DoclingRag\Commands\ReembedCommand;
use BlueHex\DoclingRag\Contracts\ChunksDocuments;
use BlueHex\DoclingRag\Contracts\ConvertsUnsupportedFormats;
use BlueHex\DoclingRag\Contracts\EmbedsChunks;
use BlueHex\DoclingRag\Contracts\MapsChunks;
use BlueHex\DoclingRag\Contracts\RoutesFormats;
use BlueHex\DoclingRag\Contracts\StoresChunkEmbeddings;
use BlueHex\DoclingRag\Conversion\FormatRouter;
use BlueHex\DoclingRag\Conversion\GotenbergConverter;
use BlueHex\DoclingRag\Docling\ChunkMapper;
use BlueHex\DoclingRag\Docling\DoclingClient;
use BlueHex\DoclingRag\Embedding\ChunkStore;
use BlueHex\DoclingRag\Embedding\Embedder;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DoclingRagServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('docling-rag')
            ->hasConfigFile()
            ->hasMigrations([
                'create_rag_documents_table',
                'create_rag_chunks_table',
            ])
            ->hasCommands([
                InstallCommand::class,
                IndexCommand::class,
                HealthCommand::class,
                ReembedCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Rag::class);

        $this->app->bind(RoutesFormats::class, FormatRouter::class);
        $this->app->bind(ConvertsUnsupportedFormats::class, GotenbergConverter::class);
        $this->app->bind(ChunksDocuments::class, DoclingClient::class);
        $this->app->bind(MapsChunks::class, ChunkMapper::class);
        $this->app->bind(StoresChunkEmbeddings::class, ChunkStore::class);
        $this->app->bind(EmbedsChunks::class, Embedder::class);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__.'/../docker-compose.example.yml' => base_path('docker-compose.docling-rag.yml'),
        ], 'docling-rag-docker');
    }
}
