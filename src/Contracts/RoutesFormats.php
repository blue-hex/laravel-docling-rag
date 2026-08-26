<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Enums\ConversionPath;

interface RoutesFormats
{
    public function route(string $filename, ?string $mime = null): ConversionPath;
}
