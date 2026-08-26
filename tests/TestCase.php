<?php

namespace BlueHex\DoclingRag\Tests;

use BlueHex\DoclingRag\DoclingRagServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Embeddings;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'BlueHex\\DoclingRag\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        Schema::create('data_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        foreach (File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }

        Storage::fake((string) config('docling-rag.storage.disk'));
        Embeddings::fake();
    }

    protected function getPackageProviders($app)
    {
        return [
            AiServiceProvider::class,
            DoclingRagServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('queue.default', 'sync');
        config()->set('docling-rag.docling.url', 'http://docling.test');
        config()->set('docling-rag.gotenberg.enabled', false);
        config()->set('docling-rag.gotenberg.url', 'http://gotenberg.test');
        config()->set('docling-rag.storage.disk', 'local');
    }
}
