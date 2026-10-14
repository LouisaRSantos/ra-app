<?php

namespace App\Http\Controllers;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Http\Requests\StoreExtractionRequest;
use App\Http\Requests\UpdateExtractionRequest;
use App\Jobs\DispatchClaudeExtraction;
use App\Jobs\ExtractRemittanceData;
use App\Models\ExtractionRun;
use App\Models\Remittance;
use App\Models\RemittanceFile;
use App\Models\RemittanceInvoice;
use App\Models\User;
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
    /** Sort keys the table may ask for, mapped to indexed (or cheap) columns. */
    private const SORTABLE = [
        'created_at' => 'created_at',
        'payment_reference_number' => 'payment_reference_number',
        'cr_number' => 'cr_number',
        'deposit_date' => 'deposit_date',
        'total_amount' => 'total_amount',
        'status' => 'status',
    ];

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
            'sort' => ['nullable', Rule::in(array_keys(self::SORTABLE))],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in(['15', '25', '50'])],
        ]);

        // "mine" is the default for everyone; "all" only means something with remittances.view-all.
        $everyone = ($filters['scope'] ?? 'mine') === 'all' && $user->can('remittances.view-all');
        $query = $everyone ? Remittance::visibleTo($user) : Remittance::where('user_id', $user->id);

        $term = isset($filters['q']) ? addcslashes(trim($filters['q']), '%_\\') : '';

        $rows = $query
            ->select(['id', 'uuid', 'reference', 'user_id', 'status', 'payment_reference_number', 'cr_number', 'deposit_date', 'total_amount', 'confirmed_at', 'created_at'])
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
                ->where('reference', 'like', mb_strtoupper($term).'%')
                ->orWhere('payment_reference_number', 'like', $term.'%')
                ->orWhere('cr_number', 'like', $term.'%')
                ->orWhereHas('lines.salesInvoice', fn ($s) => $s->where('si_number', 'like', mb_strtoupper($term).'%'))))
            ->orderBy(self::SORTABLE[$filters['sort'] ?? 'created_at'], $filters['dir'] ?? 'desc')
            ->orderByDesc('id')
            ->simplePaginate((int) ($filters['per_page'] ?? 15));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Remittance $r) => [
                'ref' => $r->reference,
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
            'page' => $rows->currentPage(),
            'per_page' => $rows->perPage(),
        ]);
    }

    public function show(string $ref): JsonResponse
    {
        return response()->json($this->detail($ref));
    }

    public function store(StoreExtractionRequest $request): JsonResponse
    {
        $engine = ExtractionEngine::from($request->validated('engine'));

        $run = RemittanceWriter::createFromUpload($request->user(), $request->file('images'), $engine);

        match ($engine) {
            ExtractionEngine::Claude => DispatchClaudeExtraction::dispatch($run->id),
            ExtractionEngine::Groq => ExtractRemittanceData::dispatch($run->id),
        };

        return response()->json(['ref' => $run->remittance->reference, 'status' => 'pending'], 201);
    }

    public function update(UpdateExtractionRequest $request, string $ref): JsonResponse
    {
        $remittance = $this->find($ref);

        // Only a finished extraction can be edited/confirmed.
        abort_unless($remittance->status === ExtractionStatus::Completed, 409);

        RemittanceWriter::confirm($remittance, $request->validated(), $request->user());

        return response()->json($this->detail($ref));
    }

    public function destroy(string $ref): JsonResponse
    {
        $remittance = $this->find($ref, ['files']);
        $paths = $remittance->files->pluck('path')->all();

        // Rows go first (children cascade); the files are removed only once that has committed.
        DB::transaction(fn () => $remittance->delete());
        Storage::disk('local')->delete($paths);

        return response()->json(['deleted' => true]);
    }

    /** Polled while an extraction runs: one narrow query (two when failed). */
    public function status(string $ref): JsonResponse
    {
        $remittance = Remittance::visibleTo(auth()->user())->where('reference', $ref)->select(['id', 'status'])->firstOrFail();

        $error = null;
        if ($remittance->status === ExtractionStatus::Failed) {
            $error = $remittance->runs()->latest('id')->value('error')
                ?: 'We couldn\'t process this document. Please try re-uploading a clearer image.';
        }

        return response()->json(['status' => $remittance->status->value, 'error_message' => $error]);
    }

    public function visibility(Request $request, string $ref, int $position): JsonResponse
    {
        $data = $request->validate(['is_public' => ['required', 'boolean']]);

        $file = $this->find($ref, [])->files()->where('position', $position)->firstOrFail();
        $file->update(['is_public' => $data['is_public']]);

        return response()->json(['position' => $position, 'is_public' => $file->is_public]);
    }

    private function find(string $ref, array $with = []): Remittance
    {
        return Remittance::visibleTo(auth()->user())->where('reference', $ref)->with($with)->firstOrFail();
    }

    /**
     * Everything the detail modal renders, in three queries: the record (names and latest run come
     * in as sub-selects), its pages, and its invoice lines joined to the SI numbers.
     */
    private function detail(string $ref): array
    {
        $user = auth()->user();
        $latestRun = fn (string $column) => ExtractionRun::select($column)
            ->whereColumn('remittance_id', 'remittances.id')->latest('id')->limit(1);

        $r = Remittance::visibleTo($user)
            ->where('reference', $ref)
            ->addSelect('remittances.*')
            ->addSelect([
                'owner_name' => User::select('name')->whereColumn('users.id', 'remittances.user_id')->limit(1),
                'confirmer_name' => User::select('name')->whereColumn('users.id', 'remittances.confirmed_by')->limit(1),
                'run_engine' => $latestRun('engine'),
                'run_duration' => $latestRun('duration_seconds'),
                'run_error' => $latestRun('error'),
            ])
            ->firstOrFail();

        $files = RemittanceFile::where('remittance_id', $r->id)->orderBy('position')
            ->get(['position', 'original_name', 'is_public']);

        $lines = RemittanceInvoice::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'remittance_invoices.sales_invoice_id')
            ->where('remittance_invoices.remittance_id', $r->id)
            ->orderBy('remittance_invoices.id')
            ->get(['remittance_invoices.amount', 'remittance_invoices.cwt_amount', 'remittance_invoices.low_confidence_fields', 'sales_invoices.si_number']);

        $reviewed = $r->confirmed_at !== null;

        return [
            'ref' => $r->reference,
            'status' => $r->status->value,
            'error_message' => $r->status === ExtractionStatus::Failed
                ? ($r->run_error ?: 'We couldn\'t process this document. Please try re-uploading a clearer image.')
                : null,
            'uploaded_by' => $r->owner_name,
            'uploaded_at' => $r->created_at->toIso8601String(),
            'engine' => $r->run_engine,
            'duration_seconds' => $r->run_duration !== null ? (int) $r->run_duration : null,
            'confirmed_by' => $r->confirmer_name,
            'confirmed_at' => $r->confirmed_at?->toIso8601String(),
            'fields' => [
                'payment_reference_number' => $r->payment_reference_number,
                'cr_number' => $r->cr_number,
                'deposit_date' => $r->deposit_date?->toDateString(),
                'total_amount' => $r->total_amount,
                'total_cwt_amount' => $r->total_cwt_amount,
            ],
            'low_confidence_fields' => $reviewed ? [] : ($r->low_confidence_fields ?? []),
            'invoices' => $lines->map(fn ($l) => [
                'si_number' => $l->si_number,
                'amount' => $l->amount,
                'cwt_amount' => $l->cwt_amount,
                'low_confidence_fields' => $reviewed ? [] : ($l->low_confidence_fields ?? []),
            ])->values(),
            'files' => $files->map(fn ($f) => [
                'position' => $f->position,
                'name' => $f->original_name,
                'is_public' => $f->is_public,
                'url' => route('files.image', ['ref' => $r->reference, 'position' => $f->position]),
            ])->values(),
            'can' => [
                'edit' => $user->can('remittances.confirm') && $r->status === ExtractionStatus::Completed,
                'delete' => $user->can('remittances.delete'),
                'visibility' => $user->can('files.set-visibility'),
            ],
        ];
    }
}
