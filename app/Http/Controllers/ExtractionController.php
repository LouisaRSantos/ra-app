<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExtractionRequest;
use App\Http\Requests\UpdateExtractionRequest;
use App\Jobs\ExtractRemittanceData;
use App\Support\ExtractionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExtractionController extends Controller
{
    public function index()
    {
        return view('extractions.index');
    }

    public function store(StoreExtractionRequest $request): JsonResponse
    {
        $files = $request->file('images');
        $uuid = (string) Str::uuid();

        $diskPaths = [];
        $mimeTypes = [];
        $originalFilenames = [];

        foreach ($files as $index => $file) {
            $diskPaths[] = $file->storeAs('extractions', "{$uuid}-{$index}.".$file->getClientOriginalExtension(), 'local');
            $mimeTypes[] = $file->getMimeType();
            $originalFilenames[] = $file->getClientOriginalName();
        }

        ExtractionStore::create($uuid, [
            'user_id' => $request->user()->id,
            'disk_paths' => $diskPaths,
            'mime_types' => $mimeTypes,
            'original_filenames' => $originalFilenames,
        ]);

        ExtractRemittanceData::dispatch($uuid);

        return response()->json(['uuid' => $uuid, 'status' => 'pending']);
    }

    public function status(string $uuid): JsonResponse
    {
        $entry = ExtractionStore::getForUser($uuid);

        return response()->json([
            'status' => $entry['status'],
            'error_message' => $entry['status'] === 'failed' ? $entry['error_message'] : null,
        ]);
    }

    public function show(string $uuid)
    {
        $entry = ExtractionStore::getForUser($uuid);

        return view('extractions.show', ['uuid' => $uuid, 'entry' => $entry]);
    }

    public function update(UpdateExtractionRequest $request, string $uuid): JsonResponse
    {
        ExtractionStore::getForUser($uuid);

        ExtractionStore::update($uuid, [
            'payment_reference_number' => $request->input('payment_reference_number'),
            'cr_number' => $request->input('cr_number'),
            'total_amount' => $request->input('total_amount'),
            'total_cwt_amount' => $request->input('total_cwt_amount'),
            'deposit_date' => $request->input('deposit_date'),
            'invoices' => $request->input('invoices'),
            'low_confidence_fields' => [],
            'confirmed' => true,
        ]);

        return response()->json(['confirmed' => true]);
    }

    public function image(string $uuid, int $index): StreamedResponse
    {
        $entry = ExtractionStore::getForUser($uuid);

        abort_unless(array_key_exists($index, $entry['disk_paths']), 404);
        abort_unless(Storage::disk('local')->exists($entry['disk_paths'][$index]), 404);

        return Storage::disk('local')->response($entry['disk_paths'][$index]);
    }
}
