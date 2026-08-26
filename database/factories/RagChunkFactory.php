<?php

namespace BlueHex\DoclingRag\Database\Factories;

use BlueHex\DoclingRag\Enums\ContentType;
use BlueHex\DoclingRag\Models\RagChunk;
use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RagChunk>
 */
class RagChunkFactory extends Factory
{
    protected $model = RagChunk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rag_document_id' => RagDocument::factory(),
            'ord' => 0,
            'parent_id' => null,
            'text' => fake()->paragraph(),
            'heading_path' => ['Introduction'],
            'page_no' => 1,
            'content_type' => ContentType::Text,
            'token_count' => 24,
            'embedding' => null,
            'embedding_model' => null,
            'embedded_at' => null,
        ];
    }

    public function embedded(): static
    {
        return $this->state(fn (): array => [
            'embedding' => array_fill(0, 8, 0.1),
            'embedding_model' => 'text-embedding-3-small',
            'embedded_at' => now(),
        ]);
    }
}
