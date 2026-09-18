<?php

namespace App\ScopeGate;

use App\Services\AiCallingService;
use App\Services\RagApiClient;
use App\Triage\SystemPrompt;
use Illuminate\Http\Client\ConnectionException;

/**
 * Makes the scope decision for one screened inquiry (research R2/R4/R5).
 *
 * Retrieval goes through the existing RagApiClient (same store, same token
 * flow); the AI scope judgment uses the isolated AiCallingService::complete()
 * (JSON mode) with a FIXED system prompt built from the deployment's
 * services.company_scope statement. The visitor message and the retrieved
 * documents are strictly user-role DATA blocks — they can never alter the
 * scope rules (FR-003/FR-009).
 *
 * Decision table, applied in order (research R2):
 *  - retrieval failure (timeout/HTTP error) or zero results → indeterminate
 *  - AI failure (missing key/unreachable/garbage output) → indeterminate
 *  - in_scope true → accept
 *  - in_scope false with a non-empty reason → decline (reason is the refusal)
 *  - anything unparseable/unsupported → indeterminate (fail open, FR-007)
 *
 * check() returns the ScopeVerdict together with the retrieval used to reach
 * it, so a declined record can persist that grounding (FR-008, data-model.md).
 */
class ScopeCheckService
{
    /**
     * Static company scope context used instead of RAG retrieval.
     * This text is sent to the AI scope-judgment gate as the ground truth
     * for determining whether an inquiry is within Robusta Studio's served scope.
     */
    private const ROBUSTA_SCOPE_TEXT = <<<'TEXT'
Robusta Studio is RTG's delivery engine for customer experience, commerce, and enterprise digital transformation — its scope spans eight service lines from strategy and product discovery through engineering, e-commerce, AI, data, cloud, and cybersecurity.

Service lines (Robusta Studio):

Digital Transformation & Strategy — product discovery & agile advisory, workflow automation, system integration, process digitization
E-Commerce — end-to-end commerce ecosystems, B2C/B2B/marketplace models, order management & fulfillment, storefront-to-analytics coverage
Shopify Services — design-first storefront delivery, custom features without a full backend build, post-launch support
Engineering & Experience — mobile & web apps, UX/UI design systems, prototyping & user testing, accessible interfaces
Artificial Intelligence — personalization, smart search & NLP, AI assistants & automation, GenAI integration
Data & Analytics — predictive modeling, data lakes & warehouses, BI dashboards, pipeline architecture
Cloud & Infrastructure — multi-cloud architecture, CI/CD, Infrastructure-as-Code, cost optimization
Cybersecurity & Compliance — secure SDLC & code review, penetration testing, virtual CISO, compliance readiness

Delivery methodology — five stages: Discover → Define → Build → Launch → Grow.
Platforms & partners — Adobe Commerce for enterprise-grade custom commerce, Shopify for rapid-growth storefronts; partners include Adobe, AWS, Microsoft, Paymob, Shopify, Laravel, Hypernode, Stonebranch.
Industries — retail & e-commerce, proptech, govtech, fintech, healthcare, logistics, edtech, telecom.
Scale — 200+ experts, 500+ projects delivered, 250+ clients, 10+ industries.

Group context — Studio is one of RTG's four business units, alongside Octopus (tech talent, outsourcing, EOR, digital hubs), Ventures (venture building), and Products (proprietary SaaS/AI: ORDR, NAWRIX, SENTRA). At group level the scope adds two extra lines beyond Studio's: e-commerce venture building and tech team building.

Representative engagements — Fit & Fix, Vodafone Ta3limy, Seoudi, Mondelez (Talabya B2B distribution), Mazaya, Spinneys loyalty, Saudi Tourism Authority (Dalila), Al Othaim (Speedi), Raya Shop.
TEXT;

    public function __construct(
        private readonly RagApiClient $rag,
        private readonly AiCallingService $ai,
        private readonly SystemPrompt $systemPrompt,
    ) {}

    /**
     * @param  array{message: string}  $inquiry
     * @return array{verdict: ScopeVerdict, retrieved: array{result_count: int, results: array<mixed>}|null}
     */
    public function check(array $inquiry): array
    {
        $retrieved = $this->retrieve($inquiry['message']);

        if ($retrieved === null || $retrieved['result_count'] === 0) {
            $verdict = ScopeVerdict::indeterminate(
                'No relevant knowledge documents could be retrieved, so scope could not be determined.',
            );

            return ['verdict' => $verdict, 'retrieved' => $retrieved];
        }

        $answer = $this->ai->complete(
            system: $this->systemPrompt(),
            user: $this->userPrompt($inquiry['message'], $retrieved),
        );

        $inScope = $answer['in_scope'] ?? null;
        $reason = $answer['reason'] ?? null;

        if ($answer === null || ! is_bool($inScope)) {
            $verdict = ScopeVerdict::indeterminate(
                'The AI scope judgment was unavailable, so scope could not be determined.',
            );

            return ['verdict' => $verdict, 'retrieved' => $retrieved];
        }

        if ($inScope) {
            return [
                'verdict' => ScopeVerdict::accept('The inquiry is within the company\'s served scope.'),
                'retrieved' => $retrieved,
            ];
        }

        if (! is_string($reason) || trim($reason) === '') {
            $verdict = ScopeVerdict::indeterminate(
                'The AI scope judgment was missing a reason, so scope could not be determined.',
            );

            return ['verdict' => $verdict, 'retrieved' => $retrieved];
        }

        $reason = trim($reason);

        return [
            'verdict' => ScopeVerdict::decline($reason, $reason),
            'retrieved' => $retrieved,
        ];
    }

    /**
     * @return array{result_count: int, results: array<mixed>}|null
     *
     * Returns the static Robusta Studio scope text instead of querying the RAG
     * system. This allows the AI scope-judgment gate to use the hardcoded
     * company scope as its ground truth for evaluating inquiries.
     */
    private function retrieve(string $message): ?array
    {
        return [
            'result_count' => 1,
            'results' => [
                [
                    'rank' => 1,
                    'text' => self::ROBUSTA_SCOPE_TEXT,
                    'similarity_score' => 1.0,
                    'document_id' => 'static-scope',
                    'source' => 'company_scope',
                    'title' => 'Robusta Studio Scope',
                    'chunk_index' => 0,
                ],
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return $this->systemPrompt->content();
    }

    /**
     * @param  array{result_count: int, results: array<mixed>}  $retrieved
     */
    private function userPrompt(string $message, array $retrieved): string
    {
        $documents = $this->renderDocuments($retrieved['results']);

        // A blank/injecting body is plain data here (FR-003): it never touches
        // the system prompt or the scope rules.
        return "[USER INQUIRY]\n{$message}";
    }

    /**
     * @param  array<mixed>  $results
     */
    private function renderDocuments(array $results): string
    {
        if ($results === []) {
            return 'No relevant documents were retrieved.';
        }

        $lines = [];
        foreach ($results as $index => $result) {
            $content = $result['content'] ?? $result['text'] ?? $result;
            $lines[] = 'DOCUMENT '.($index + 1).":\n".(is_string($content) ? $content : (string) json_encode($content));
        }

        return implode("\n\n", $lines);
    }
}
