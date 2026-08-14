<?php

namespace App\Accounting\Events;

use App\Models\JournalEntry;
use Illuminate\Foundation\Events\Dispatchable;

class JournalEntryPosted
{
    use Dispatchable;

    public function __construct(
        public readonly JournalEntry $journalEntry
    ) {}
}
