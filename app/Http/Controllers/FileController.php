<?php

namespace App\Http\Controllers;

use App\Models\RemittanceFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files, always through RemittanceFile::visibleTo(): the owner and admins see their own/all,
 * the read-only User role sees public files only. Nothing here exposes extracted data.
 */
class FileController extends Controller
{
    public function index()
    {
        return view('files.index');
    }

    /** Public files, newest first, for the "Public files" grid (one joined query). */
    public function data(): JsonResponse
    {
        $files = RemittanceFile::public()
            ->visibleTo(auth()->user())
            ->join('remittances', 'remittances.id', '=', 'remittance_files.remittance_id')
            ->join('users', 'users.id', '=', 'remittances.user_id')
            ->select([
                'remittance_files.position', 'remittance_files.original_name', 'remittance_files.mime_type',
                'remittance_files.size', 'remittance_files.created_at',
                'remittances.uuid', 'users.name as uploader',
            ])
            ->orderByDesc('remittance_files.created_at')->orderByDesc('remittance_files.id')
            ->simplePaginate(24);

        return response()->json([
            'data' => $files->getCollection()->map(fn ($f) => [
                'name' => $f->original_name,
                'size' => $f->size,
                'uploader' => $f->uploader,
                'uploaded_at' => $f->created_at->toIso8601String(),
                'url' => route('files.image', ['uuid' => $f->uuid, 'position' => $f->position]),
            ]),
            'has_more' => $files->hasMorePages(),
        ]);
    }

    public function image(string $uuid, int $position): StreamedResponse
    {
        $file = RemittanceFile::visibleTo(auth()->user())
            ->join('remittances', 'remittances.id', '=', 'remittance_files.remittance_id')
            ->where('remittances.uuid', $uuid)
            ->where('remittance_files.position', $position)
            ->select('remittance_files.*')
            ->firstOrFail();

        abort_unless(Storage::disk('local')->exists($file->path), 404);

        // A page image never changes once stored, so let the browser keep it (private: per user).
        return Storage::disk('local')->response($file->path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
