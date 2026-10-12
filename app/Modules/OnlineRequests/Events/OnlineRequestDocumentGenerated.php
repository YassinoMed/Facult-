<?php

namespace App\Modules\OnlineRequests\Events;

use App\Modules\OnlineRequests\Models\OnlineRequestDocument;
use Illuminate\Foundation\Events\Dispatchable;

class OnlineRequestDocumentGenerated
{
    use Dispatchable;

    public function __construct(public readonly OnlineRequestDocument $document) {}
}
