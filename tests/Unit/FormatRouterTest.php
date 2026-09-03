<?php

use BlueHex\DoclingRag\Conversion\FormatRouter;
use BlueHex\DoclingRag\Enums\ConversionPath;
use BlueHex\DoclingRag\Exceptions\UnsupportedFormatException;

it('routes native Docling formats without Gotenberg', function (string $filename) {
    expect((new FormatRouter)->route($filename))->toBe(ConversionPath::Native);
})->with([
    'report.pdf',
    'notes.docx',
    'slides.pptx',
    'sheet.xlsx',
    'page.html',
    'readme.md',
    'spec.adoc',
    'scan.png',
    'legacy.doc',
    'calc.ods',
    'book.epub',
]);

it('routes .markdown as native (Docling only recognizes .md)', function () {
    expect((new FormatRouter)->route('notes.markdown'))->toBe(ConversionPath::Native);
});

it('rewrites .markdown to .md before it reaches Docling', function () {
    expect((new FormatRouter)->normalizeFilename('notes.markdown'))->toBe('notes.md')
        ->and((new FormatRouter)->normalizeFilename('report.pdf'))->toBe('report.pdf');
});

it('sends leftover office formats through Gotenberg when enabled', function () {
    config(['docling-rag.gotenberg.enabled' => true]);

    expect((new FormatRouter)->route('memo.rtf'))->toBe(ConversionPath::Gotenberg);
});

it('raises when Gotenberg is disabled and the format is not native', function () {
    config(['docling-rag.gotenberg.enabled' => false]);

    (new FormatRouter)->route('memo.rtf');
})->throws(UnsupportedFormatException::class, 'Gotenberg is disabled');

it('raises for unknown formats instead of skipping', function () {
    config(['docling-rag.gotenberg.enabled' => true]);

    (new FormatRouter)->route('payload.exe');
})->throws(UnsupportedFormatException::class, 'not supported');
