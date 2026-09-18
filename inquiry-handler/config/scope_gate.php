<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scope gate tunables (feature 007 / research R6)
    |--------------------------------------------------------------------------
    |
    | Pre-classification scope check: the middleware retrieves the company's
    | knowledge documents, asks the AI whether the inquiry is in scope, then
    | passes through (accept / fail-open indeterminate) or refuses (decline).
    |
    | `enabled` is the kill switch: when false, the gate is fully bypassed and
    | the response carries no scope_check marker. `rag_top_k` reuses the same
    | RAG_TOP_K env the classification flow reads (config('app.rag_top_k')),
    | keeping the gate's retrieval depth aligned with classification's.
    |
    */

    'enabled' => (bool) env('SCOPE_GATE_ENABLED', true),

    'rag_top_k' => (int) env('RAG_TOP_K', 5),

];
