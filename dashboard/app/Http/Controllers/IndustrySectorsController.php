<?php

namespace App\Http\Controllers;

use App\Services\InquiryHandlerApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Industry sectors" tab (feature 012 US3): manage the inquiry handler's
 * sector catalog via its bearer-verified admin API (contracts/sectors-admin.md).
 *
 * Read failures degrade to an inline error on the page (the dashboard stays
 * usable); a 401 from upstream is treated as an expired session and sends the
 * user back to login. Writes follow the factor-weights editor: back() with a
 * status flash on success and withErrors on any upstream failure.
 */
class IndustrySectorsController extends Controller
{
    private const SECTORS_UNAVAILABLE = 'The sector catalog is unavailable. Please try again.';

    public function index(): View|RedirectResponse
    {
        $response = $this->call(fn () => app(InquiryHandlerApiClient::class)->getIndustrySectors());

        if ($response?->status() === 401) {
            return redirect()->route('login.show');
        }

        if ($response === null) {
            return view('sectors.index', ['sectors' => [], 'error' => self::SECTORS_UNAVAILABLE]);
        }

        if ($response->failed()) {
            return view('sectors.index', [
                'sectors' => [],
                'error' => $response->json('detail') ?? self::SECTORS_UNAVAILABLE,
            ]);
        }

        return view('sectors.index', [
            'sectors' => $response->json('items', []),
            'error' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $response = $this->call(fn () => app(InquiryHandlerApiClient::class)->createIndustrySector(
            (string) $request->string('name')->trim(),
            (int) $request->integer('rating'),
        ));

        return $this->redirectFor($request, $response, 'Sector created.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $response = $this->call(fn () => app(InquiryHandlerApiClient::class)->updateIndustrySector(
            $id,
            (string) $request->string('name')->trim(),
            (int) $request->integer('rating'),
        ));

        return $this->redirectFor($request, $response, 'Sector updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $response = $this->call(fn () => app(InquiryHandlerApiClient::class)->deleteIndustrySector($id));

        if ($response === null) {
            return back()->withErrors(['sectors' => 'Could not delete the sector.']);
        }

        if ($response->status() === 401) {
            return redirect()->route('login.show');
        }

        if ($response->failed()) {
            return back()->withErrors(['sectors' => $response->json('detail', 'Could not delete the sector.')]);
        }

        return back()->with('status', 'Sector deleted.');
    }

    /**
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

    /**
     * Shared write-decision for create/update: 401 → login, failure → inline
     * errors on the form, success → status flash.
     *
     * @param  callable(): Response  $request
     */
    private function redirectFor(Request $request, ?Response $response, string $successMessage): RedirectResponse
    {
        if ($response === null) {
            return back()->withErrors(['sectors' => 'The sector catalog is unavailable. Please try again.']);
        }

        if ($response->status() === 401) {
            return redirect()->route('login.show');
        }

        if ($response->failed()) {
            return back()->withErrors([
                'sectors' => $response->json('detail', 'Could not save the sector.'),
            ])->withInput($request->only('name', 'rating'));
        }

        return back()->with('status', $successMessage);
    }
}