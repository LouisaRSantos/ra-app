<?php

namespace App\Http\Controllers;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Http\Requests\StoreExtractionRequest;
use App\Http\Requests\UpdateExtractionRequest;
use App\Jobs\ExtractRemittanceData;
use App\Models\Remittance;
use App\Support\RemittanceWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Groq engine plus the review/confirm screens shared with the Claude engine.
 * Every lookup goes through Remittance::visibleTo(), so another user's record is a 404.
 */
class ExtractionController extends Controller
{
    public function index()
    {
        return view('extractions.index');
    }

    public function store(StoreExtractionRequest $request): JsonResponse
    {
        $run = RemittanceWriter::createFromUpload($request->user(), $request->file('images'), ExtractionEngine::Groq);

        ExtractRemittanceData::dispatch($run->id);

        return response()->json(['uuid' => $run->remittance->uuid, 'status' => 'pending']);
    }

    public function status(string $uuid): JsonResponse
    {
        // Polled every couple of seconds, so keep it to one narrow query (two when failed).
        $remittance = Remittance::visibleTo(auth()->user())->where('uuid', $uuid)->select(['id', 'status'])->firstOrFail();

        $error = null;
        if ($remittance->status === ExtractionStatus::Failed) {
            $error = $remittance->runs()->latest('id')->value('error')
                ?: 'We couldn\'t process this document. Please try re-uploading a clearer image.';
        }

        return response()->json(['status' => $remittance->status->value, 'error_message' => $error]);
    }

    public function show(string $uuid)
    {
        return view('extractions.show', ['uuid' => $uuid, 'entry' => $this->find($uuid)->reviewData()]);
    }

    public function update(UpdateExtractionRequest $request, string $uuid): JsonResponse
    {
        $remittance = $this->find($uuid);

        // Only a finished extraction can be confirmed.
        abort_unless($remittance->status === ExtractionStatus::Completed, 409);

        RemittanceWriter::confirm($remittance, $request->validated(), $request->user());

        return response()->json(['confirmed' => true]);
    }

    public function image(string $uuid, int $index): StreamedResponse
    {
        $file = $this->find($uuid)->files()->where('position', $index)->firstOrFail();

        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path);
    }

    private function find(string $uuid): Remittance
    {
        return Remittance::visibleTo(auth()->user())->where('uuid', $uuid)->firstOrFail();
    }
}
