<?php

namespace Tests\Feature;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Jobs\DispatchClaudeExtraction;
use App\Jobs\ExpireClaudeExtraction;
use App\Models\ExtractionRun;
use App\Models\Remittance;
use App\Models\RemittanceFile;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ClaudeExtractionTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = [
        'payment_reference_number' => 'CHK-5678',
        'cr_number' => 'CR-1234',
        'total_amount' => 1500.0,
        'total_cwt_amount' => 0,
        'deposit_date' => '2026-10-01',
        'low_confidence_fields' => ['deposit_date'],
        'invoices' => [['si_number' => 'madbsi0000001546', 'amount' => 1500, 'cwt_amount' => 0]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    private function specialist(): User
    {
        return tap(User::factory()->create())->assignRole('Account Specialist');
    }

    /** A remittance with one page and a Claude run, optionally already fired with $token. */
    private function makeRun(string $status = 'pending', ?string $token = null): ExtractionRun
    {
        $remittance = Remittance::factory()->for($this->specialist())->create(['status' => $status]);
        RemittanceFile::factory()->for($remittance)->create(['position' => 0]);

        return $remittance->runs()->create([
            'engine' => ExtractionEngine::Claude,
            'status' => $status,
            'callback_token_hash' => $token ? ExtractionRun::hashToken($token) : null,
            'fired_at' => $status === 'processing' ? now()->subSeconds(42) : null,
        ]);
    }

    private function postResult(ExtractionRun $run, array $body, ?string $token = 'secret')
    {
        return $this->withToken((string) $token)->postJson(route('claude.callback', $run->remittance->uuid), $body);
    }

    public function test_upload_creates_remittance_files_and_run_and_dispatches(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->specialist();

        $uuid = $this->actingAs($user)->post(route('transactions.store'), ['engine' => 'claude', 
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('uuid');

        $remittance = Remittance::where('uuid', $uuid)->firstOrFail();
        $this->assertSame($user->id, $remittance->user_id);
        $this->assertSame(ExtractionStatus::Pending, $remittance->status);
        $this->assertSame(['a.jpg', 'b.png'], $remittance->files->pluck('original_name')->all());
        $this->assertSame(ExtractionEngine::Claude, $remittance->runs->first()->engine);
        Storage::disk('local')->assertExists($remittance->files[0]->path);
        Queue::assertPushed(DispatchClaudeExtraction::class);
    }

    public function test_job_fires_routine_and_stores_only_the_token_hash(): void
    {
        Queue::fake([ExpireClaudeExtraction::class]);
        Http::fake(['api.anthropic.com/*' => Http::response(['claude_code_session_url' => 'https://claude.ai/code/s1'])]);
        $run = $this->makeRun();

        DispatchClaudeExtraction::dispatchSync($run->id);

        $run->refresh();
        $this->assertSame(ExtractionStatus::Processing, $run->status);
        $this->assertSame(ExtractionStatus::Processing, $run->remittance->status);
        $this->assertSame('https://claude.ai/code/s1', $run->session_url);

        Http::assertSent(function ($request) use ($run) {
            $sent = json_decode($request['text'], true);

            // the routine got a signed image URL and a token whose hash is what we stored
            return $request->hasHeader('anthropic-beta')
                && str_contains($sent['image_urls'][0], 'signature=')
                && $run->fresh()->tokenMatches($sent['callback_token'])
                && $sent['callback_token'] !== $run->callback_token_hash;
        });
        Queue::assertPushed(ExpireClaudeExtraction::class);
    }

    public function test_fire_error_marks_failed_without_retry(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);
        $run = $this->makeRun();

        DispatchClaudeExtraction::dispatchSync($run->id);

        $run->refresh();
        $this->assertSame(ExtractionStatus::Failed, $run->status);
        $this->assertSame(ExtractionStatus::Failed, $run->remittance->status);
        $this->assertNull($run->callback_token_hash);
        Http::assertSentCount(1);
    }

    public function test_quota_hit_goes_back_to_pending(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 429, ['Retry-After' => '120'])]);
        $run = $this->makeRun();

        DispatchClaudeExtraction::dispatchSync($run->id);

        $this->assertSame(ExtractionStatus::Pending, $run->fresh()->status);
        $this->assertNull($run->fresh()->callback_token_hash);
    }

    public function test_valid_callback_saves_normalised_rows_and_replay_is_409(): void
    {
        $run = $this->makeRun('processing', 'secret');

        $this->postResult($run, self::PAYLOAD)->assertOk();

        $remittance = $run->remittance->fresh();
        $this->assertSame(ExtractionStatus::Completed, $remittance->status);
        $this->assertSame('CR-1234', $remittance->cr_number);
        $this->assertSame('1500.00', $remittance->total_amount);
        $this->assertSame('2026-10-01', $remittance->deposit_date->toDateString());
        $this->assertSame(['deposit_date'], $remittance->low_confidence_fields);
        $this->assertSame('MADBSI0000001546', $remittance->lines->first()->salesInvoice->si_number);
        $this->assertNull($run->fresh()->callback_token_hash);
        $this->assertSame(42, $run->fresh()->duration_seconds);
        $this->assertSame('CHK-5678', $run->fresh()->raw_result['payment_reference_number']);

        $this->postResult($run, self::PAYLOAD)->assertStatus(409);
    }

    public function test_wrong_token_is_403_and_leaves_run_open(): void
    {
        $run = $this->makeRun('processing', 'secret');

        $this->postResult($run, self::PAYLOAD, 'wrong')->assertForbidden();
        $this->postJson(route('claude.callback', $run->remittance->uuid), self::PAYLOAD)->assertForbidden();

        $this->assertSame(ExtractionStatus::Processing, $run->fresh()->status);
    }

    public function test_callback_on_unfired_or_unknown_run(): void
    {
        $this->postResult($this->makeRun('pending'), self::PAYLOAD)->assertStatus(409);
        $this->withToken('x')->postJson(route('claude.callback', '00000000-0000-0000-0000-000000000000'), [])->assertNotFound();
    }

    public function test_malformed_payload_fails_run_and_spends_token(): void
    {
        $run = $this->makeRun('processing', 'secret');

        $this->postResult($run, ['invoices' => 'nope', 'total_amount' => 'abc'])->assertStatus(422);

        $this->assertSame(ExtractionStatus::Failed, $run->fresh()->status);
        $this->postResult($run, self::PAYLOAD)->assertStatus(409);
    }

    public function test_overlong_si_number_is_rejected_not_a_500(): void
    {
        $run = $this->makeRun('processing', 'secret');

        $this->postResult($run, ['invoices' => [['si_number' => str_repeat('A', 65)]]])->assertStatus(422);
    }

    public function test_routine_error_fails_run(): void
    {
        $run = $this->makeRun('processing', 'secret');

        $this->postResult($run, ['error' => 'unreadable'])->assertOk();

        $this->assertSame(ExtractionStatus::Failed, $run->fresh()->status);
        $this->assertSame(ExtractionStatus::Failed, $run->remittance->fresh()->status);
    }

    public function test_expiry_fails_stuck_run(): void
    {
        $run = $this->makeRun('processing', 'secret');

        ExpireClaudeExtraction::dispatchSync($run->id);

        $this->assertSame(ExtractionStatus::Failed, $run->fresh()->status);
        $this->assertSame(ExtractionStatus::Failed, $run->remittance->fresh()->status);
        $this->postResult($run, self::PAYLOAD)->assertStatus(409);
    }

    public function test_expiry_leaves_finished_run_alone(): void
    {
        $run = $this->makeRun('processing', 'secret');
        $this->postResult($run, self::PAYLOAD)->assertOk();

        ExpireClaudeExtraction::dispatchSync($run->id);

        $this->assertSame(ExtractionStatus::Completed, $run->fresh()->status);
    }

    public function test_signed_image_url_serves_the_page_and_unsigned_is_rejected(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();
        $file = $run->remittance->files->first();
        Storage::disk('local')->put($file->path, 'jpegbytes');
        $uuid = $run->remittance->uuid;

        $signed = URL::temporarySignedRoute('claude.image', now()->addMinutes(5), ['uuid' => $uuid, 'index' => 0]);

        $this->get($signed)->assertOk();
        $this->get("/claude-extractions/{$uuid}/image/0")->assertForbidden();
    }
}
