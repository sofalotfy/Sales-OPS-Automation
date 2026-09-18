<?php

namespace App\Http\Controllers;

use App\Services\InquiryHandlerApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Inquiry classification" tab: the factor-weights editor plus the
 * classification log (list + per-run detail), both served over inquiry
 * handler's bearer-verified admin APIs. A failure in either upstream call
 * degrades its section to an inline error without hiding the other.
 *
 * A connection timeout (inquiry-handler busy with a slow triage) throws
 * before a response is produced, so each call is wrapped: timeouts degrade
 * exactly like a failed upstream response instead of erroring the page.
 */
class ClassificationController extends Controller
{
    private const PAGE_SIZE = 20;

    private const WEIGHTS_UNAVAILABLE = 'The inquiry handler is unavailable. Please try again.';

    private const LOG_UNAVAILABLE = 'The classification log is unavailable. Please try again.';

    public function index(): View|RedirectResponse
    {
        $page = max(1, (int) request()->integer('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $client = app(InquiryHandlerApiClient::class);

        $factors = [];
        $weightsError = null;
        $weightsResponse = $this->call(fn () => $client->getFactorSettings());
        if ($weightsResponse === null) {
            $weightsError = self::WEIGHTS_UNAVAILABLE;
        } elseif ($weightsResponse->status() === 401) {
            return redirect()->route('login.show');
        } elseif ($weightsResponse->failed()) {
            $weightsError = $weightsResponse->json('detail') ?? self::WEIGHTS_UNAVAILABLE;
        } else {
            $factors = $weightsResponse->json('factors', []);
        }

        $results = [];
        $resultsError = null;
        $total = 0;
        $resultsResponse = $this->call(fn () => $client->getClassificationResults(self::PAGE_SIZE, $offset));
        if ($resultsResponse === null) {
            $resultsError = self::LOG_UNAVAILABLE;
        } elseif ($resultsResponse->status() === 401) {
            return redirect()->route('login.show');
        } elseif ($resultsResponse->failed()) {
            $resultsError = $resultsResponse->json('detail') ?? self::LOG_UNAVAILABLE;
        } else {
            $results = $resultsResponse->json('items', []);
            $total = (int) $resultsResponse->json('total', 0);
        }

        return view('classification.index', [
            'factors' => $factors,
            'weightsError' => $weightsError,
            'results' => $results,
            'resultsError' => $resultsError,
            'total' => $total,
            'page' => $page,
            'pageCount' => max(1, (int) ceil($total / self::PAGE_SIZE)),
        ]);
    }

    /** Full classification run detail (contracts/classification-reporting.md). */
    public function show(int $id): View|RedirectResponse
    {
        $response = $this->call(fn () => app(InquiryHandlerApiClient::class)->getClassificationResult($id));

        if ($response?->status() === 401) {
            return redirect()->route('login.show');
        }

        if ($response === null || $response->failed()) {
            return view('classification.show', [
                'record' => null,
                'error' => $response?->json('detail') ?? 'Could not load this classification.',
            ]);
        }

        return view('classification.show', [
            'record' => $response->json(),
            'error' => null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $weights = $request->input('weights', []);
        $weights = is_array($weights) ? $weights : [];

        $response = $this->call(fn () => app(InquiryHandlerApiClient::class)->updateFactorWeights($weights));

        if ($response === null) {
            return back()->withErrors(['weights' => 'Could not save the factor weights.']);
        }

        if ($response->status() === 401) {
            return redirect()->route('login.show');
        }

        if ($response->failed()) {
            return back()->withErrors(
                ['weights' => $response->json('detail', 'Could not save the factor weights.')],
            );
        }

        return back()->with('status', 'Factor weights saved.');
    }

    /**
     * Run an upstream admin call, converting a connection timeout/transport
     * failure into a surrogate failed response so callers degrade gracefully.
     *
     * @param  callable(): Response  $request
     */
    private function call(callable $request): ?Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            return null;
        }
    }
}