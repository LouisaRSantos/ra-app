<?php

namespace App\Http\Controllers;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Http\Requests\StoreExtractionRequest;
use App\Http\Requests\UpdateExtractionRequest;
use App\Jobs\DispatchClaudeExtraction;
use App\Jobs\ExtractRemittanceData;
use App\Models\Remittance;
use App\Models\RemittanceFile;
use App\Support\RemittanceWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The user's transactions: list, view, edit/confirm, delete, upload, file visibility.
 * Everything is JSON for the AJAX modal UI. The list shows the caller's own uploads
 * (admins can switch to everyone's); every single-record lookup goes through
 * Remittance::visibleTo(), so a record outside the caller's scope is a 404.
 */
class TransactionController extends Controller
{
    public function index()
    {
        return view('transactions.index', [
            'canUpload' => auth()->user()->can('remittances.upload'),
            'canViewAll' => auth()->user()->can('remittances.view-all'),
        ]);
    }

    /** Paginated list. One query (+ owner names only when an admin views everyone). */
    public function data(Request $request): JsonResponse
    {
        $user = $request->user();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ExtractionStatus::class)],
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        // "mine" is the default for everyone; "all" only means something with remittances.view-all.
        $everyone = ($filters['scope'] ?? 'mine') === 'all' && $user->can('remittances.view-all');
        $query = $everyone ? Remittance::visibleTo($user) : Remittance::where('user_id', $user->id);

        $term = isset($filters['q']) ? addcslashes(trim($filters['q']), '%_\\') : '';

        $rows = $query
            ->select(['id', 'uuid', 'user_id', 'status', 'payment_reference_number', 'cr_number', 'deposit_date', 'total_amount', 'confirmed_at', 'created_at'])
            ->addSelect(['first_file' => RemittanceFile::select('original_name')
                ->whereColumn('remittance_id', 'remittances.id')->orderBy('position')->limit(1)])
            ->withCount(['lines', 'files'])
            ->when($everyone, fn ($q) => $q->with('user:id,name'))
            // "processing" in the UI also covers rows still queued
            ->when($filters['status'] ?? null, fn ($q, $s) => $s === 'processing'
                ? $q->whereIn('status', ['pending', 'processing'])
                : $q->where('status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            // prefix matches only, so the reference / CR / SI indexes are usable
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('payment_reference_number', 'like', $term.'%')
                ->orWhere('cr_number', 'like', $term.'%')
                ->orWhereHas('lines.salesInvoice', fn ($s) => $s->where('si_number', 'like', mb_strtoupper($term).'%'))))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->simplePaginate(15);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Remittance $r) => [
                'uuid' => $r->uuid,
                'status' => $r->status->value,
                'confirmed' => $r->confirmed_at !== null,
                'document' => $r->first_file,
                'files' => $r->files_count,
                'invoices' => $r->lines_count,
                'payment_reference_number' => $r->payment_reference_number,
                'cr_number' => $r->cr_number,
                'deposit_date' => $r->deposit_date?->toDateString(),
                'total_amount' => $r->total_amount,
                'owner' => $everyone ? $r->user?->name : null,
                'created_at' => $r->created_at->toIso8601String(),
            ]),
            'has_more' => $rows->hasMorePages(),
        ]);
    }

    public function show(string $uuid): JsonResponse
    {
        return response()->json($this->payload($this->find($uuid)));
    }

    public function store(StoreExtractionRequest $request): JsonResponse
    {
        $engine = ExtractionEngine::from($request->validated('engine'));

        $run = RemittanceWriter::createFromUpload($request->user(), $request->file('images'), $engine);

        match ($engine) {
            ExtractionEngine::Claude => DispatchClaudeExtraction::dispatch($run->id),
            ExtractionEngine::Groq => ExtractRemittanceData::dispatch($run->id),
        };

        return response()->json(['uuid' => $run->remittance->uuid, 'status' => 'pending'], 201);
    }

    public function update(UpdateExtractionRequest $request, string $uuid): JsonResponse
    {
        $remittance = $this->find($uuid);

        // Only a finished extraction can be edited/confirmed.
        abort_unless($remittance->status === ExtractionStatus::Completed, 409);

        RemittanceWriter::confirm($remittance, $request->validated(), $request->user());

        return response()->json($this->payload($this->find($uuid)));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $remittance = $this->find($uuid, ['files']);
        $paths = $remittance->files->pluck('path')->all();

        // Rows go first (children cascade); the files are removed only once that has committed.
        DB::transaction(fn () => $remittance->delete());
        Storage::disk('local')->delete($paths);

        return response()->json(['deleted' => true]);
    }

    /** Polled while an extraction runs: one narrow query (two when failed). */
    public function status(string $uuid): JsonResponse
    {
        $remittance = Remittance::visibleTo(auth()->user())->where('uuid', $uuid)->select(['id', 'status'])->firstOrFail();

        $error = null;
        if ($remittance->status === ExtractionStatus::Failed) {
            $error = $remittance->runs()->latest('id')->value('error')
                ?: 'We couldn\'t process this document. Please try re-uploading a clearer image.';
        }

        return response()->json(['status' => $remittance->status->value, 'error_message' => $error]);
    }

    public function visibility(Request $request, string $uuid, int $position): JsonResponse
    {
        $data = $request->validate(['is_public' => ['required', 'boolean']]);

        $file = $this->find($uuid, [])->files()->where('position', $position)->firstOrFail();
        $file->update(['is_public' => $data['is_public']]);

        return response()->json(['position' => $position, 'is_public' => $file->is_public]);
    }

    private function find(string $uuid, array $with = ['files', 'lines.salesInvoice', 'latestRun', 'user:id,name', 'confirmedBy:id,name']): Remittance
    {
        return Remittance::visibleTo(auth()->user())->where('uuid', $uuid)->with($with)->firstOrFail();
    }

    /** What the detail modal renders from, including what the caller may do with it. */
    private function payload(Remittance $r): array
    {
        $user = auth()->user();
        $run = $r->latestRun;

        return [
            'uuid' => $r->uuid,
            'status' => $r->status->value,
            'error_message' => $r->status === ExtractionStatus::Failed
                ? ($run?->error ?: 'We couldn\'t process this document. Please try re-uploading a clearer image.')
                : null,
            'uploaded_by' => $r->user?->name,
            'uploaded_at' => $r->created_at->toIso8601String(),
            'engine' => $run?->engine->value,
            'duration_seconds' => $run?->duration_seconds,
            'confirmed_by' => $r->confirmedBy?->name,
            'confirmed_at' => $r->confirmed_at?->toIso8601String(),
            'fields' => [
                'payment_reference_number' => $r->payment_reference_number,
                'cr_number' => $r->cr_number,
                'deposit_date' => $r->deposit_date?->toDateString(),
                'total_amount' => $r->total_amount,
                'total_cwt_amount' => $r->total_cwt_amount,
            ],
            'low_confidence_fields' => $r->confirmed_at ? [] : ($r->low_confidence_fields ?? []),
            'invoices' => $r->lines->map(fn ($l) => [
                'si_number' => $l->salesInvoice->si_number,
                'amount' => $l->amount,
                'cwt_amount' => $l->cwt_amount,
                'low_confidence_fields' => $r->confirmed_at ? [] : ($l->low_confidence_fields ?? []),
            ])->values(),
            'files' => $r->files->map(fn ($f) => [
                'position' => $f->position,
                'name' => $f->original_name,
                'is_public' => $f->is_public,
                'url' => route('files.image', ['uuid' => $r->uuid, 'position' => $f->position]),
            ])->values(),
            'can' => [
                'edit' => $user->can('remittances.confirm') && $r->status === ExtractionStatus::Completed,
                'delete' => $user->can('remittances.delete'),
                'visibility' => $user->can('files.set-visibility'),
            ],
        ];
    }
}
