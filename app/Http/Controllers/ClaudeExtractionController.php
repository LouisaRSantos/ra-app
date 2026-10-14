<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExtractionRequest;
use App\Jobs\DispatchClaudeExtraction;
use App\Support\ExtractionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
        $uuid = (string) Str::uuid();
        $entry = ['disk_paths' => [], 'mime_types' => [], 'original_filenames' => []];

        foreach ($request->file('images') as $index => $file) {
            $entry['disk_paths'][] = $file->storeAs('extractions', "{$uuid}-{$index}.".$file->getClientOriginalExtension(), 'local');
            $entry['mime_types'][] = $file->getMimeType();
            $entry['original_filenames'][] = $file->getClientOriginalName();
        }

        ExtractionStore::create($uuid, $entry);

        DispatchClaudeExtraction::dispatch($uuid);

        return response()->json(['uuid' => $uuid, 'status' => 'pending']);
    }

    /** Public endpoint: the body comes from a model reading user-uploaded images, so treat it as untrusted. */
    public function callback(Request $request, string $uuid): JsonResponse
    {
        $entry = ExtractionStore::get($uuid);

        abort_if($entry === null, 404);

        // Only a run in flight can be completed; this also makes a replay a 409.
        if ($entry['status'] !== 'processing' || empty($entry['callback_token'])) {
            return response()->json(['message' => 'Not awaiting a result.'], 409);
        }

        if (! hash_equals($entry['callback_token'], (string) $request->bearerToken())) {
            return response()->json(['message' => 'Invalid token.'], 403);
        }

        // From here the token is spent, whatever the payload turns out to be.
        $reject = function (string $message, int $code): JsonResponse {
            return response()->json(['message' => $message], $code);
        };
        $markFailed = fn () => ExtractionStore::update($uuid, [
            'status' => 'failed',
            'callback_token' => null,
            'error_message' => 'We couldn\'t read this document. Please try re-uploading a clearer image.',
        ]);

        if ($request->input('error') !== null) {
            $markFailed();

            return $reject('Routine reported an error.', 200);
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
            'invoices.*.si_number' => ['required', 'string', 'max:255'],
            'invoices.*.amount' => ['nullable', 'numeric'],
            'invoices.*.cwt_amount' => ['nullable', 'numeric'],
            'invoices.*.low_confidence_fields' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            $markFailed();

            return $reject('Invalid payload.', 422);
        }

        $data = $validator->validated();

        ExtractionStore::update($uuid, [
            'status' => 'completed',
            'callback_token' => null,
            'payment_reference_number' => $data['payment_reference_number'] ?? null,
            'cr_number' => $data['cr_number'] ?? null,
            'total_amount' => $data['total_amount'] ?? null,
            'total_cwt_amount' => $data['total_cwt_amount'] ?? null,
            'deposit_date' => $data['deposit_date'] ?? null,
            'low_confidence_fields' => $data['low_confidence_fields'] ?? [],
            'invoices' => $data['invoices'],
        ]);

        Log::info('Claude routine completed', [
            'uuid' => $uuid,
            'seconds' => isset($entry['fired_at']) ? now()->timestamp - $entry['fired_at'] : null,
            'images' => count($entry['disk_paths']),
            'session_url' => $entry['routine_session_url'] ?? null,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Fetched by the routine; protected by the `signed` middleware (expiring URL). */
    public function image(string $uuid, int $index): StreamedResponse
    {
        $entry = ExtractionStore::get($uuid);

        abort_if($entry === null, 404);
        abort_unless(isset($entry['disk_paths'][$index]) && Storage::disk('local')->exists($entry['disk_paths'][$index]), 404);

        return Storage::disk('local')->response($entry['disk_paths'][$index]);
    }
}
