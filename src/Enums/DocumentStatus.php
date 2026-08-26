<?php

namespace BlueHex\DoclingRag\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Converting = 'converting';
    case Chunking = 'chunking';
    case Embedding = 'embedding';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isInFlight(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Converting,
            self::Chunking,
            self::Embedding,
        ], true);
    }
}
