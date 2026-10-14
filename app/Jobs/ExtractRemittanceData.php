<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Models\ExtractionRun;
use App\Support\RemittanceWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractRemittanceData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(private readonly int $runId)
    {
    }

    public function backoff(): int
    {
        return 5;
    }

    public function handle(): void
    {
        $run = ExtractionRun::with('remittance.files')->find($this->runId);

        // 'processing' is expected here on a queue retry attempt (this same job re-running
        // after a transient failure); only completed/failed are terminal states to bail on.
        if ($run === null || in_array($run->status, [ExtractionStatus::Completed, ExtractionStatus::Failed], true)) {
            return;
        }

        RemittanceWriter::markProcessing($run);

        try {
            $extracted = $this->extract($run->remittance->files->pluck('path')->all());

            RemittanceWriter::complete($run, $extracted);
        } catch (Throwable $e) {
            Log::error('Remittance extraction attempt failed', [
                'uuid' => $run->remittance->uuid,
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]);

            // Rethrow so the queue's own retry/backoff ($tries/backoff() above) actually
            // kicks in; failed() marks the user-facing status once retries are exhausted.
            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = ExtractionRun::find($this->runId);

        if ($run !== null && in_array($run->status, [ExtractionStatus::Pending, ExtractionStatus::Processing], true)) {
            RemittanceWriter::fail($run, 'We couldn\'t process this document. Please try re-uploading a clearer image.');
        }
    }

    /**
     * @param  array<int, string>  $diskPaths
     * @return array{payment_reference_number: ?string, cr_number: ?string, total_amount: ?float, total_cwt_amount: ?float, deposit_date: ?string, low_confidence_fields: array<int, string>, invoices: array<int, array<string, mixed>>}
     */
    private function extract(array $diskPaths): array
    {
        // Kept as short as accuracy allows — every image in a request costs a large fixed
        // token amount regardless of resolution (measured: ~1,800 tokens/image on Groq's
        // qwen/qwen3.8-27b, flat from 512px to 1600px), so the prompt text is the only
        // remaining lever against the daily token quota. Each rule below was added to fix a
        // real observed extraction error — don't trim below what's needed to keep them working.
        $prompt = <<<'PROMPT'
            1+ images document one remittance payment (multi-page voucher, maybe plus a BIR 2307 for
            CWT). Combine all images into one result. Reply with ONLY this JSON (no markdown/commentary):

            {"payment_reference_number":string,"cr_number":string,"total_amount":number,
            "total_cwt_amount":number,"deposit_date":string (ISO 8601),"low_confidence_fields":string[],
            "invoices":[{"si_number":string,"amount":number,"cwt_amount":number,"low_confidence_fields":string[]}]}

            Rules:
            - payment_reference_number = check/bank reference from the main voucher (not the collection receipt).
            - cr_number: ONLY from a "COLLECTION RECEIPT" doc's "Invoice No." field — there, that field is the
              CR number, NOT a sales invoice. Never put it in "invoices" or treat it as an si_number.
            - total_cwt_amount: the "Tax Withheld For the Quarter" figure from a BIR Form 2307
              ("CERTIFICATE OF CREDITABLE TAX WITHHELD") doc — a cross-check against summed invoice cwt_amounts.
            - invoices: one per sales invoice. amount = pre-tax invoice amount; cwt_amount = tax withheld for
              that line (0 if none shown, but check every image first). Report both as positive magnitudes
              even if a document shows CWT as a negative/deducted figure.
            - SI numbers: any code containing "SI" + digits counts, regardless of prefix (not just "MADBSI").
              If a document spells a full code once then lists only trailing digits per subsequent line
              (e.g. "MADBSI0000,1546" then "1545","1571"...), reconstruct the FULL code for every line using
              that prefix + digit length. Never output a bare suffix like "1545" as a final si_number.
            - Flag any value you're unsure of (unclear/ambiguous/not found in any image) in low_confidence_fields
              (header-level, or per-invoice); leave empty when confident.
            PROMPT;

        $imageParts = [];

        foreach ($diskPaths as $diskPath) {
            $imageBase64 = base64_encode($this->downscale(Storage::disk('local')->path($diskPath)));
            $imageParts[] = ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$imageBase64}"]];
        }

        $response = Http::timeout(60)
            ->withToken(config('services.groq.key'))
            ->post(config('services.groq.endpoint'), [
                'model' => config('services.groq.model'),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => $prompt], ...$imageParts],
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Groq request failed with status '.$response->status());
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text)) {
            throw new \RuntimeException('Groq response did not contain the expected text payload.');
        }

        $parsed = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($parsed) || ! array_key_exists('invoices', $parsed) || ! is_array($parsed['invoices'])) {
            throw new \RuntimeException('Groq response JSON did not match the expected shape.');
        }

        return $parsed;
    }

    /**
     * Full-resolution phone photos (4000px+, several MB each) push a multi-image request past
     * Groq's payload size limit (observed 413 on 3 combined images ~10MB). Downscaling to a
     * width that's still comfortably legible for document text keeps requests well under that
     * limit without hurting extraction accuracy.
     */
    private function downscale(string $absolutePath, int $maxWidth = 1600): string
    {
        [$width, $height] = getimagesize($absolutePath);

        $source = imagecreatefromstring(file_get_contents($absolutePath));

        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) round($height * ($maxWidth / $width));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($source);
            $source = $resized;
        }

        ob_start();
        imagejpeg($source, null, 82);
        $jpeg = ob_get_clean();
        imagedestroy($source);

        return $jpeg;
    }
}
