<?php

namespace BlueHex\DoclingRag\Contracts;

use BlueHex\DoclingRag\Conversion\ConvertedFile;

interface ConvertsUnsupportedFormats
{
    public function convert(string $filename, string $contents): ConvertedFile;
}
