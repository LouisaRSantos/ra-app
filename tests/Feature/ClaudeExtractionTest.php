<?php

namespace Tests\Feature;

use App\Jobs\DispatchClaudeExtraction;
use App\Jobs\ExpireClaudeExtraction;
use App\Support\ExtractionStore;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        'low_confidence_fields' => [],
        'invoices' => [['si_number' => 'MADBSI0000001546', 'amount' => 1500, 'cwt_amount' => 0]],
    ];

    private function processing(string $token = 'secret'): string
    {
        ExtractionStore::create('u1', ['disk_paths' => ['extractions/u1-0.jpg']]);
        ExtractionStore::update('u1', ['status' => 'processing', 'callback_token' => $token]);

        return 'u1';
    }

    private function postResult(array $body, string $token = 'secret')
    {
        return $this->withToken($token)->postJson(route('claude.callback', 'u1'), $body);
    }

    public function test_upload_dispatches_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->seed(AccessSeeder::class);
        $user = tap(User::factory()->create())->assignRole('Account Specialist');

        $uuid = $this->actingAs($user)->post(route('claude.store'), ['images' => [UploadedFile::fake()->image('a.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()->json('uuid');

        Queue::assertPushed(DispatchClaudeExtraction::class);
        $this->assertSame('pending', ExtractionStore::get($uuid)['status']);
        $this->assertSame($user->id, ExtractionStore::get($uuid)['user_id']);
    }

    public function test_job_fires_routine_and_sets_processing(): void
    {
        Queue::fake([ExpireClaudeExtraction::class]);
        Http::fake(['api.anthropic.com/*' => Http::response(['claude_code_session_url' => 'https://claude.ai/code/s1'])]);
        ExtractionStore::create('u1', ['disk_paths' => ['extractions/u1-0.jpg']]);

        DispatchClaudeExtraction::dispatchSync('u1');

        $entry = ExtractionStore::get('u1');
        $this->assertSame('processing', $entry['status']);
        $this->assertSame('https://claude.ai/code/s1', $entry['routine_session_url']);
        Http::assertSent(fn ($r) => $r->hasHeader('anthropic-beta') && str_contains($r['text'], 'image_urls'));
        Queue::assertPushed(ExpireClaudeExtraction::class);
    }

    public function test_fire_error_marks_failed_without_retry(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);
        ExtractionStore::create('u1', ['disk_paths' => ['extractions/u1-0.jpg']]);

        DispatchClaudeExtraction::dispatchSync('u1');

        $this->assertSame('failed', ExtractionStore::get('u1')['status']);
        Http::assertSentCount(1);
    }

    public function test_valid_callback_completes_and_replay_is_409(): void
    {
        $this->processing();

        $this->postResult(self::PAYLOAD)->assertOk();
        $this->assertSame('completed', ExtractionStore::get('u1')['status']);
        $this->assertSame('CR-1234', ExtractionStore::get('u1')['cr_number']);

        $this->postResult(self::PAYLOAD)->assertStatus(409);
    }

    public function test_wrong_token_is_403_and_leaves_run_open(): void
    {
        $this->processing();

        $this->postResult(self::PAYLOAD, 'wrong')->assertStatus(403);
        $this->assertSame('processing', ExtractionStore::get('u1')['status']);
    }

    public function test_malformed_payload_fails_run(): void
    {
        $this->processing();

        $this->postResult(['invoices' => 'nope', 'total_amount' => 'abc'])->assertStatus(422);
        $this->assertSame('failed', ExtractionStore::get('u1')['status']);
    }

    public function test_routine_error_fails_run(): void
    {
        $this->processing();

        $this->postResult(['error' => 'unreadable']);
        $this->assertSame('failed', ExtractionStore::get('u1')['status']);
    }

    public function test_expiry_fails_stuck_run(): void
    {
        $this->processing();

        ExpireClaudeExtraction::dispatchSync('u1');

        $this->assertSame('failed', ExtractionStore::get('u1')['status']);
    }

    public function test_unsigned_image_url_is_rejected(): void
    {
        $this->get('/claude-extractions/u1/image/0')->assertStatus(403);
    }
}
