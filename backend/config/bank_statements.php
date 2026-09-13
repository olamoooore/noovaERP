<?php

return [
    /*
    | Candidate search may be wider than the ten-day date-scoring window.
    | Matches outside ten days receive no date points but can still be shown
    | when stronger evidence such as amount or reference is present.
    */
    'matching_search_window_days' => (int) env('BANK_STATEMENT_MATCH_SEARCH_WINDOW_DAYS', 45),
];
