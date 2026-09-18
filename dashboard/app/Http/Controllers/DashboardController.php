<?php

namespace App\Http\Controllers;

use App\Services\RagApiClient;
use App\Support\UpstreamSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Public liveness probe for the Compose healthcheck. */
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Dashboard home — overview of the RAG corpus (stat cards + recent docs).
     * Documents live on their own sub-page below it.
     */
    public function home(): View|RedirectResponse
    {
        $token = UpstreamSession::token() ?? '';
        $rag = app(RagApiClient::class);

        $error = null;
        $counts = ['ready' => 0, 'processing' => 0, 'failed' => 0];

        foreach (array_keys($counts) as $status) {
            $response = $rag->listDocuments($token, status: $status, limit: 1);
            if ($response->status() === 401) {
                return redirect()->route('login.show');
            }
            if ($response->failed()) {
                $error = $response->json('detail')
                    ?? 'The document service is unavailable. Please try again.';
                break;
            }
            $counts[$status] = (int) $response->json('total', 0);
        }

        $recent = [];
        if ($error === null) {
            $response = $rag->listDocuments($token, limit: 8);
            if ($response->status() === 401) {
                return redirect()->route('login.show');
            }
            if ($response->failed()) {
                $error = $response->json('detail')
                    ?? 'The document service is unavailable. Please try again.';
            } else {
                $recent = $response->json('items', []);
            }
        }

        return view('dashboard.home', [
            'counts' => $counts,
            'recent' => $recent,
            'total' => array_sum($counts),
            'error' => $error,
        ]);
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