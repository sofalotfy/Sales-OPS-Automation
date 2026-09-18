<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scoring engine tunables (feature 006 / research R11)
    |--------------------------------------------------------------------------
    |
    | Factor scores are always clamped into [score_min, score_max] before the
    | weighted combination runs (R1). Thresholds map the final weighted score
    | to a Classification with inclusive lower bounds (R2): a score of exactly
    | 75 → high, exactly 55 → medium, exactly 30 → low, anything below → disqualify.
    |
    */

    'score_min' => (int) env('SCORING_SCORE_MIN', 0),
    'score_max' => (int) env('SCORING_SCORE_MAX', 100),

    // Weight used for a registered factor when no stored weight exists (R3c).
    'default_factor_weight' => (float) env('SCORING_DEFAULT_FACTOR_WEIGHT', 1.0),

    'thresholds' => [
        'high' => (float) env('SCORING_THRESHOLD_HIGH', 75),
        'medium' => (float) env('SCORING_THRESHOLD_MEDIUM', 55),
        'low' => (float) env('SCORING_THRESHOLD_LOW', 30),
    ],

    // Classification returned when the factor catalog is empty (clarification
    // Q2 / FR-008): score 0.00 + `low`, never disqualify.
    'empty_catalog_classification' => 'low',

    /*
    |--------------------------------------------------------------------------
    | Visitor-facing reply placeholders (per classification)
    |--------------------------------------------------------------------------
    |
    | Placeholder copy for now (spec assumption; to be confirmed with sales).
    | `high` uses the {booking_url} placeholder, substituted with the configured
    | services.booking_url only when that value is a valid URL — otherwise a
    | generic placeholder is used instead (contracts/inquiry-web.md).
    |
    */

    'replies' => [
        'high' => 'You are in the right place. Pick a time here: {booking_url}',
        'medium' => 'Thanks for reaching out. A member of our team will follow up with the details.',
        'low' => 'Thank you for your inquiry. We will be in touch if there is a match.',
        'disqualify' => 'Thank you for your interest. We are unable to help with this request.',
    ],

];