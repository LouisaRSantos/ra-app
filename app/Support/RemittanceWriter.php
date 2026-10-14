<?php

namespace App\Support;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Models\ExtractionRun;
use App\Models\Remittance;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only code that writes remittances, their files/lines and extraction runs.
 * Both engines (Groq, Claude routine) and the confirm step go through here, so the
 * rules for normalising and saving extracted values live in exactly one place.
 */
class RemittanceWriter
{
    /**
     * Stores the uploaded pages and creates the remittance plus its first (pending) run.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public static function createFromUpload(User $user, array $files, ExtractionEngine $engine): ExtractionRun
    {
        return DB::transaction(function () use ($user, $files, $engine) {
            $remittance = Remittance::create(['user_id' => $user->id, 'status' => ExtractionStatus::Pending]);

            foreach (array_values($files) as $position => $file) {
                $remittance->files()->create([
                    'position' => $position,
                    // extension() is guessed from the file content, not from the client-supplied name
                    'path' => $file->storeAs('extractions', "{$remittance->uuid}-{$position}.".$file->extension(), 'local'),
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            return $remittance->runs()->create(['engine' => $engine, 'status' => ExtractionStatus::Pending]);
        });
    }

    public static function markProcessing(ExtractionRun $run, ?string $tokenHash = null): void
    {
        DB::transaction(function () use ($run, $tokenHash) {
            $run->update([
                'status' => ExtractionStatus::Processing,
                'fired_at' => $run->fired_at ?? now(),
                'callback_token_hash' => $tokenHash,
            ]);
            $run->remittance()->update(['status' => ExtractionStatus::Processing]);
        });
    }

    /** Back to pending, e.g. when the routine quota is hit and the job is released. */
    public static function markPending(ExtractionRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run->update(['status' => ExtractionStatus::Pending, 'fired_at' => null, 'callback_token_hash' => null]);
            $run->remittance()->update(['status' => ExtractionStatus::Pending]);
        });
    }

    public static function fail(ExtractionRun $run, string $message): void
    {
        DB::transaction(function () use ($run, $message) {
            $run->update([
                'status' => ExtractionStatus::Failed,
                'error' => Str::limit($message, 500, ''),
                'completed_at' => now(),
                'duration_seconds' => self::elapsed($run),
                'callback_token_hash' => null, // the token is spent whatever happened
            ]);
            $run->remittance()->update(['status' => ExtractionStatus::Failed]);
        });
    }

    /**
     * Saves an engine result. $raw is the engine's decoded JSON; it is normalised
     * leniently (bad values become null rather than failing the whole run) and kept as-is on the run.
     */
    public static function complete(ExtractionRun $run, array $raw): void
    {
        $data = self::normalize($raw);

        DB::transaction(function () use ($run, $raw, $data) {
            $remittance = $run->remittance;

            self::saveFields($remittance, $data);
            $remittance->update(['status' => ExtractionStatus::Completed]);

            $run->update([
                'status' => ExtractionStatus::Completed,
                'error' => null,
                'completed_at' => now(),
                'duration_seconds' => self::elapsed($run),
                'raw_result' => $raw,
                'callback_token_hash' => null,
            ]);
        });
    }

    /**
     * The user's confirmed values replace the extracted ones and clear the review flags.
     * Same normalisation as an engine result, so SI numbers and money are stored one way only.
     */
    public static function confirm(Remittance $remittance, array $input, User $user): void
    {
        $data = self::normalize($input);
        $data['low_confidence_fields'] = [];

        DB::transaction(function () use ($remittance, $data, $user) {
            self::saveFields($remittance, $data);
            $remittance->update(['confirmed_by' => $user->id, 'confirmed_at' => now()]);
        });
    }

    /** Header fields on the remittance; the invoice lines are replaced wholesale. */
    private static function saveFields(Remittance $remittance, array $data): void
    {
        $remittance->update([
            'payment_reference_number' => $data['payment_reference_number'],
            'cr_number' => $data['cr_number'],
            'deposit_date' => $data['deposit_date'],
            'total_amount' => $data['total_amount'],
            'total_cwt_amount' => $data['total_cwt_amount'],
            'low_confidence_fields' => $data['low_confidence_fields'],
        ]);

        $remittance->lines()->delete();

        foreach ($data['invoices'] as $line) {
            $remittance->lines()->create([
                'sales_invoice_id' => SalesInvoice::firstOrCreate(['si_number' => $line['si_number']])->id,
                'amount' => $line['amount'],
                'cwt_amount' => $line['cwt_amount'],
                'low_confidence_fields' => $line['low_confidence_fields'],
            ]);
        }
    }

    /**
     * Turns whatever an engine (or the review form) sent into values the schema accepts.
     * Never throws on bad data: unusable values become null, junk lines are dropped.
     *
     * @return array{payment_reference_number: ?string, cr_number: ?string, deposit_date: ?string, total_amount: ?string, total_cwt_amount: ?string, low_confidence_fields: array<int, string>, invoices: array<int, array{si_number: string, amount: ?string, cwt_amount: string, low_confidence_fields: array<int, string>}>}
     */
    public static function normalize(array $raw): array
    {
        $invoices = [];

        foreach ((array) ($raw['invoices'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $si = self::si($line['si_number'] ?? null);

            // blank SI numbers carry no meaning; a repeated SI keeps its first line (the table has one per remittance)
            if ($si === null || isset($invoices[$si])) {
                continue;
            }

            $invoices[$si] = [
                'si_number' => $si,
                'amount' => self::money($line['amount'] ?? null),
                'cwt_amount' => self::money($line['cwt_amount'] ?? null) ?? '0.00',
                'low_confidence_fields' => self::names($line['low_confidence_fields'] ?? []),
            ];
        }

        return [
            'payment_reference_number' => self::text($raw['payment_reference_number'] ?? null),
            'cr_number' => self::text($raw['cr_number'] ?? null),
            'deposit_date' => self::date($raw['deposit_date'] ?? null),
            'total_amount' => self::money($raw['total_amount'] ?? null),
            'total_cwt_amount' => self::money($raw['total_cwt_amount'] ?? null),
            'low_confidence_fields' => self::names($raw['low_confidence_fields'] ?? []),
            'invoices' => array_values($invoices),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 255, '');
    }

    private static function si(mixed $value): ?string
    {
        $value = self::text($value);

        return $value === null ? null : mb_substr(mb_strtoupper($value), 0, 64);
    }

    /** Exact 2-decimal string; accepts "1,500.00" and "PHP 1500", rejects the rest. */
    private static function money(mixed $value): ?string
    {
        if (is_string($value)) {
            // drop thousands separators/spaces and a leading currency marker, nothing else
            $value = preg_replace('/^[^\d.\-]+/u', '', str_replace([',', ' '], '', $value));
        }

        if (! is_numeric($value) || abs((float) $value) >= 1e12) {
            return null;
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private static function date(mixed $value): ?string
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int, string> */
    private static function names(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->map(fn (string $v) => Str::limit($v, 100, ''))
            ->unique()->values()->all();
    }

    private static function elapsed(ExtractionRun $run): ?int
    {
        return $run->fired_at ? (int) abs(now()->diffInSeconds($run->fired_at)) : null;
    }
}
