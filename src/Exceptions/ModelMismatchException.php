<?php

namespace BlueHex\DoclingRag\Exceptions;

class ModelMismatchException extends RetrievalException
{
    public static function make(string $configured, string $stored): self
    {
        return new self(
            "Embedding model mismatch: corpus embedded with [{$stored}] but ".
            "docling-rag.embedding.model is [{$configured}]. Run rag:reembed to migrate."
        );
    }
}
