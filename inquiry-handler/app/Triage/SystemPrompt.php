<?php

namespace App\Triage;

/**
 * Single source of truth for the classification system prompt.
 *
 * RAG context retrieval has been replaced by this FIXED system prompt: the AI
 * judgment receives ONLY these instructions plus the visitor's inquiry (user
 * role DATA) — nothing request-influenced can alter the scope rules (FR-003).
 * The identical string is persisted on every classification record
 * (`system_prompt`) so admins can audit the exact context each inquiry was
 * judged against. The scope statement comes from deployment config (env).
 */
class SystemPrompt
{
    public function content(): string
    {
        return 'You are the scope-judgment gate for a sales team. '
            .'The company served by this deployment'
            ." handles ONLY the scope described below. Judge whether the user's "
            .'inquiry is something this company actually sells or serves. '
            .'Answer with strict JSON: {"in_scope": true|false, "reason": string}. '
            .'When in_scope is false, "reason" must be a short visitor-facing '
            .'explanation of why, grounded in the retrieved documents. '
            .'The "reason" is empty when in_scope is true.'
            ."\n\nSERVED SCOPE\n".trim((string) config('services.company_scope'));
    }
}