<?php

namespace BlueHex\DoclingRag\Tools;

use BlueHex\DoclingRag\Facades\Rag;
use BlueHex\DoclingRag\Retrieval\ChunkResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Drop-in retrieval tool for laravel/ai agents. The host binds the owner it
 * should search; the package owns the description, because retrieval quality
 * lives in how the model is told to phrase and cite.
 */
class SearchDocuments implements Tool
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        protected Model $owner,
        protected array $filters = [],
        protected ?int $k = null,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function for(Model $owner, array $filters = [], ?int $k = null): self
    {
        return new self($owner, $filters, $k);
    }

    public function description(): string
    {
        return <<<'TEXT'
        Search the user's uploaded documents for passages relevant to a question.

        Use this whenever answering requires information that could live in those
        documents — do not answer from memory when the documents may hold the facts.

        Phrase the `query` as a complete, standalone question, resolving pronouns
        and context from the conversation ("What was Q3 revenue?" not "and Q3?").
        Search with the terms you expect in the source, not the user's exact words.

        Each result carries a page number. Cite the page for every fact you use.
        If the results do not answer the question, re-search with different phrasing
        or synonyms before concluding the documents do not cover it — one empty
        search is not proof of absence.
        TEXT;
    }

    public function handle(Request $request): string
    {
        $query = trim((string) $request->string('query'));

        if ($query === '') {
            return 'Provide a non-empty query.';
        }

        $results = Rag::search($query, $this->owner, $this->filters, $this->k);

        if ($results->isEmpty()) {
            return 'No relevant passages found. Try rephrasing the query with different terms.';
        }

        $blocks = $results->map(function (ChunkResult $r, int $i): string {
            $cite = $r->pageNo !== null ? "page {$r->pageNo}" : 'page unknown';

            if ($r->headingPath !== []) {
                $cite .= ' — '.implode(' › ', $r->headingPath);
            }

            return '['.($i + 1)."] ({$cite})\n".$r->text;
        })->implode("\n\n");

        return "Relevant passages, most relevant first. Cite the page number for each fact used:\n\n".$blocks;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema
                ->string()
                ->description('A complete, standalone question to search for.')
                ->required(),
        ];
    }
}
