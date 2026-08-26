<?php

namespace BlueHex\DoclingRag\Events;

use BlueHex\DoclingRag\Models\RagDocument;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IngestionFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public RagDocument $document) {}
}
