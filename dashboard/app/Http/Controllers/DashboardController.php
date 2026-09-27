<?php

namespace App\Http\Controllers;

use App\Services\InquiryHandlerApiClient;
use App\Services\RagApiClient;
use App\Support\CalendarRange;
use App\Support\UpstreamSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** How many of the newest runs the landing page lists. */
    private const RECENT_RUN_LIMIT = 10;

    /** Public liveness probe for the Compose healthcheck. */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Dashboard home — overview of the inquiry classification log (run stat cards,
     * classification mix, anything failed, and the newest runs).
     *
     * Both sections are fetched independently and degrade on their own so a
     * failure in one does not blank the page. The RAG corpus has its own tab;
     * it is deliberately absent here even though the pipeline retrieves from it.
     */
    public function home(Request $request): View|RedirectResponse
    {
        $handler = app(InquiryHandlerApiClient::class);
        $range = CalendarRange::fromRequest($request);

        // A start after the end is a request with no answer rather than a typo,
        // so it is reported in the filter instead of being sent upstream to come
        // back 422 and blank the cards.
        if ($range->isReversed()) {
            return view('dashboard.home', [
                'stats' => null,
                'statsError' => 'The start date must not be after the end date.',
                'results' => [],
                'resultsError' => null,
                'total' => null,
                'range' => $range,
            ]);
        }

        // Both calls take the same window. The cards are aggregates and the list
        // is rows, so scoping only one would leave the page claiming a period
        // its own run list does not belong to.
        $statsResponse = $this->call(
            fn (): ClientResponse => $handler->getClassificationStats($range->from, $range->to),
        );
        if ($statsResponse?->status() === 401) {
            return redirect()->route('login.show');
        }

        $resultsResponse = $this->call(
            fn (): ClientResponse => $handler->getClassificationResults(
                self::RECENT_RUN_LIMIT,
                0,
                null,
                null,
                $range->from,
                $range->to,
            ),
        );
        if ($resultsResponse?->status() === 401) {
            return redirect()->route('login.show');
        }

        return view('dashboard.home', [
            'stats' => $statsResponse?->successful() ? $statsResponse->json() : null,
            'statsError' => $statsResponse === null || $statsResponse->failed()
                ? ($statsResponse?->json('detail') ?? 'The inquiry handler is unavailable. Please try again.')
                : null,
            'results' => $resultsResponse?->successful() ? $resultsResponse->json('items', []) : [],
            'resultsError' => $resultsResponse === null || $resultsResponse->failed()
                ? ($resultsResponse?->json('detail') ?? 'The inquiry handler is unavailable. Please try again.')
                : null,
            'total' => $statsResponse?->successful() ? (int) $statsResponse->json('total', 0) : null,
            'range' => $range,
        ]);
    }

    /**
     * Run an upstream admin call, converting a connection timeout/transport
     * failure into null so callers degrade gracefully.
     *
     * @param  callable(): ClientResponse  $request
     */
    private function call(callable $request): ?ClientResponse
    {
        try {
            return $request();
        } catch (ConnectionException) {
            return null;
        }
    }

    /** Documents list — hosts the DocumentsTable Livewire component. */
    public function index(): View
    {
        return view('documents.index');
    }

    /** Upload form — hosts the UploadDocument Livewire component. */
    public function create(): View
    {
        return view('documents.create');
    }

    /** Metadata edit form — hosts the EditDocument Livewire component. */
    public function edit(string $id): View
    {
        return view('documents.edit', ['documentId' => $id]);
    }

    /** Read-only content preview backed by the RAG content endpoint. */
    public function show(string $id): View|RedirectResponse
    {
        $response = app(RagApiClient::class)->getDocumentContent(
            UpstreamSession::token() ?? '',
            $id,
        );

        if ($response->status() === 401) {
            return redirect()->route('login.show');
        }

        if ($response->failed()) {
            return view('documents.show', [
                'document' => null,
                'error' => $response->json('detail') ?? 'Could not load this document.',
            ]);
        }

        return view('documents.show', [
            'document' => $response->json(),
            'error' => null,
        ]);
    }

    /** Stream the stored original upload through from work-scope-rag. */
    public function download(string $id): Response|RedirectResponse
    {
        $response = app(RagApiClient::class)->getDocumentFile(
            UpstreamSession::token() ?? '',
            $id,
        );

        if ($response->status() === 401) {
            return redirect()->route('login.show');
        }

        if ($response->failed()) {
            abort($response->status());
        }

        $headers = [
            'Content-Type' => $response->header('Content-Type', 'application/octet-stream'),
            'Content-Length' => strlen($response->body()),
        ];
        if ($response->header('Content-Disposition') !== null) {
            $headers['Content-Disposition'] = $response->header('Content-Disposition');
        }

        return response($response->body(), 200, $headers);
    }
}
