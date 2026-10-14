<?php

namespace App\Http\Controllers;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Http\Requests\StoreExtractionRequest;
use App\Jobs\DispatchClaudeExtraction;
use App\Models\Remittance;
use App\Support\RemittanceWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Claude-routine engine, alongside the Groq one. Status/review/confirm reuse ExtractionController's routes.
 */
class ClaudeExtractionController extends Controller
{
    public function index()
    {
        return view('extractions.index', ['storeUrl' => route('claude.store')]);
    }

    public function store(StoreExtractionRequest $request): JsonResponse
    {
        $run = RemittanceWriter::createFromUpload($request->user(), $request->file('images'), ExtractionEngine::Claude);

        DispatchClaudeExtraction::dispatch($run->id);

        return response()->json(['uuid' => $run->remittance->uuid, 'status' => 'pending']);
    }

    /** Public endpoint: the body comes from a model reading user-uploaded images, so treat it as untrusted. */
    public function callback(Request $request, string $uuid): JsonResponse
    {
        $remittance = Remittance::where('uuid', $uuid)->firstOrFail();
        $run = $remittance->runs()->where('engine', ExtractionEngine::Claude)->latest('id')->first();

        // Only a run in flight can be completed; this also makes a replay a 409.
        if ($run === null || $run->status !== ExtractionStatus::Processing || $run->callback_token_hash === null) {
            return response()->json(['message' => 'Not awaiting a result.'], 409);
        }

        if (! $run->tokenMatches($request->bearerToken())) {
            return response()->json(['message' => 'Invalid token.'], 403);
        }

        // From here the token is spent (fail/complete clear it), whatever the payload turns out to be.
        $unreadable = 'We couldn\'t read this document. Please try re-uploading a clearer image.';

        if ($request->input('error') !== null) {
            RemittanceWriter::fail($run, $unreadable);

            return response()->json(['message' => 'Routine reported an error.']);
        }

        $validator = Validator::make($request->all(), [
            'payment_reference_number' => ['nullable', 'string', 'max:255'],
            'cr_number' => ['nullable', 'string', 'max:255'],
            'total_amount' => ['nullable', 'numeric'],
            'total_cwt_amount' => ['nullable', 'numeric'],
            'deposit_date' => ['nullable', 'date'],
            'low_confidence_fields' => ['nullable', 'array'],
            'low_confidence_fields.*' => ['string', 'max:100'],
            'invoices' => ['present', 'array', 'max:100'],
            'invoices.*.si_number' => ['required', 'string', 'max:64'],
            'invoices.*.amount' => ['nullable', 'numeric'],
            'invoices.*.cwt_amount' => ['nullable', 'numeric'],
            'invoices.*.low_confidence_fields' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            RemittanceWriter::fail($run, $unreadable);

            return response()->json(['message' => 'Invalid payload.'], 422);
        }

        RemittanceWriter::complete($run, $validator->validated());

        Log::info('Claude routine completed', [
            'uuid' => $uuid,
            'seconds' => $run->fresh()->duration_seconds,
            'images' => $remittance->files()->count(),
            'session_url' => $run->session_url,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Fetched by the routine; protected by the `signed` middleware (expiring URL). */
    public function image(string $uuid, int $index): StreamedResponse
    {
        $file = Remittance::where('uuid', $uuid)->firstOrFail()->files()->where('position', $index)->firstOrFail();

        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path);
    }
}
