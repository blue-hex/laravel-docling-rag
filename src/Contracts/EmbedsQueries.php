<?php

namespace BlueHex\DoclingRag\Contracts;

interface EmbedsQueries
{
    /**
     * Embed a search query with the corpus model.
     *
     * @return list<float>
     */
    public function embed(string $query): array;
}
