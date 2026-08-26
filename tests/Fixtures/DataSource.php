<?php

namespace BlueHex\DoclingRag\Tests\Fixtures;

use BlueHex\DoclingRag\Support\HasRagDocuments;
use Illuminate\Database\Eloquent\Model;

class DataSource extends Model
{
    use HasRagDocuments;

    protected $guarded = [];
}
