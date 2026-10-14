<?php

namespace Tests\Feature;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Jobs\ExtractRemittanceData;
use App\Models\ExtractionRun;
use App\Models\Remittance;
use App\Models\User;
use App\Support\RemittanceWriter;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GroqExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        Storage::fake('local');
    }

    private function specialist(): User
    {
        return tap(User::factory()->create())->assignRole('Account Specialist');
    }

    private function groqReplies(array $content): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($content)]]],
        ])]);
    }

    public function test_upload_run_confirm_end_to_end(): void
    {
        $user = $this->specialist();
        $this->groqReplies([
            'payment_reference_number' => '0003862139',
            'cr_number' => '02404',
            'total_amount' => '1,500.00',          // string with a thousands separator
            'total_cwt_amount' => 30,
            'deposit_date' => '10/01/2026',
            'low_confidence_fields' => ['cr_number'],
            'invoices' => [
                ['si_number' => ' madbsi0000001546 ', 'amount' => 1000, 'cwt_amount' => 20, 'low_confidence_fields' => ['amount']],
                ['si_number' => 'MADBSI0000001546', 'amount' => 999, 'cwt_amount' => 0],   // duplicate, dropped
                ['si_number' => 'MADBSI0000001571', 'amount' => 500, 'cwt_amount' => 10],
            ],
        ]);

        // sync queue in tests: the job runs inside the upload request
        $uuid = $this->actingAs($user)->post(route('transactions.store'), ['engine' => 'groq', 
            'images' => [UploadedFile::fake()->image('voucher.jpg', 800, 600)],
        ], ['Accept' => 'application/json'])->assertCreated()->json('uuid');

        $remittance = Remittance::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(ExtractionStatus::Completed, $remittance->status);
        $this->assertSame('1500.00', $remittance->total_amount);
        $this->assertSame('2026-10-01', $remittance->deposit_date->toDateString());
        $this->assertSame(['MADBSI0000001546', 'MADBSI0000001571'], $remittance->lines->map->salesInvoice->pluck('si_number')->all());
        $this->assertSame(['amount'], $remittance->lines[0]->low_confidence_fields);
        $run = $remittance->runs->first();
        $this->assertSame(ExtractionEngine::Groq, $run->engine);
        $this->assertSame(ExtractionStatus::Completed, $run->status);
        $this->assertNotNull($run->raw_result);

        // the review screen and status endpoint render from the database
        $this->actingAs($user)->getJson("/transactions/{$uuid}/status")->assertOk()->assertJson(['status' => 'completed']);
        $this->actingAs($user)->getJson("/transactions/{$uuid}")->assertOk()->assertJsonPath("fields.payment_reference_number", "0003862139")->assertJsonPath("invoices.0.si_number", "MADBSI0000001546");
        $this->actingAs($user)->get("/files/{$uuid}/0")->assertOk();

        // confirm: the user's values replace the extracted ones and clear the review flags
        $this->actingAs($user)->putJson("/transactions/{$uuid}", [
            'payment_reference_number' => '0003862139',
            'cr_number' => '02404',
            'total_amount' => 1470,
            'total_cwt_amount' => 30,
            'deposit_date' => '2026-10-02',
            'invoices' => [['si_number' => 'madbsi0000001546', 'amount' => 1000, 'cwt_amount' => 20]],
        ])->assertOk();

        $remittance = $remittance->fresh();
        $this->assertTrue($remittance->isConfirmed());
        $this->assertSame($user->id, $remittance->confirmed_by);
        $this->assertSame('1470.00', $remittance->total_amount);
        $this->assertSame([], $remittance->low_confidence_fields);
        $this->assertCount(1, $remittance->lines);
    }

    public function test_bad_groq_json_fails_the_run_after_retries(): void
    {
        $user = $this->specialist();
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => '{"nope": true}']]]])]);
        $run = RemittanceWriter::createFromUpload($user, [UploadedFile::fake()->image('a.jpg')], ExtractionEngine::Groq);

        try {
            ExtractRemittanceData::dispatchSync($run->id);
        } catch (\Throwable) {
            // sync driver rethrows; the queue worker would call failed() once tries are exhausted
        }
        (new ExtractRemittanceData($run->id))->failed(null);

        $this->assertSame(ExtractionStatus::Failed, $run->fresh()->status);
        $this->assertSame(ExtractionStatus::Failed, $run->remittance->fresh()->status);
        $this->actingAs($user)->getJson("/transactions/{$run->remittance->uuid}/status")
            ->assertJson(['status' => 'failed'])->assertJsonPath('error_message', fn ($m) => is_string($m) && $m !== '');
    }

    public function test_cannot_confirm_before_completion(): void
    {
        Queue::fake();
        $user = $this->specialist();
        $run = RemittanceWriter::createFromUpload($user, [UploadedFile::fake()->image('a.jpg')], ExtractionEngine::Groq);

        $this->actingAs($user)->putJson("/transactions/{$run->remittance->uuid}", [
            'total_amount' => 1, 'deposit_date' => '2026-10-01',
            'invoices' => [['si_number' => 'SI1', 'amount' => 1, 'cwt_amount' => 0]],
        ])->assertStatus(409);
    }

    public function test_writer_normalises_hostile_values_without_throwing(): void
    {
        $data = RemittanceWriter::normalize([
            'payment_reference_number' => ['array'],
            'cr_number' => '  ',
            'total_amount' => 'PHP 12,345.678',
            'total_cwt_amount' => 'n/a',
            'deposit_date' => 'not a date',
            'low_confidence_fields' => ['x', 5, null, 'x'],
            'invoices' => ['junk', ['si_number' => ''], ['si_number' => str_repeat('a', 80), 'amount' => '1e99']],
        ]);

        $this->assertNull($data['payment_reference_number']);
        $this->assertNull($data['cr_number']);
        $this->assertSame('12345.68', $data['total_amount']);
        $this->assertNull($data['total_cwt_amount']);
        $this->assertNull($data['deposit_date']);
        $this->assertSame(['x'], $data['low_confidence_fields']);
        $this->assertCount(1, $data['invoices']);
        $this->assertSame(64, strlen($data['invoices'][0]['si_number']));
        $this->assertNull($data['invoices'][0]['amount']);
        $this->assertSame('0.00', $data['invoices'][0]['cwt_amount']);
    }

    public function test_another_specialist_cannot_see_or_confirm(): void
    {
        $this->groqReplies(['invoices' => []]);
        $owner = $this->specialist();
        $other = $this->specialist();
        $uuid = $this->actingAs($owner)->post(route('transactions.store'), ['engine' => 'groq', 
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ], ['Accept' => 'application/json'])->json('uuid');

        $this->actingAs($other)->getJson("/transactions/{$uuid}/status")->assertNotFound();
        $this->actingAs($other)->getJson("/transactions/{$uuid}")->assertNotFound();
        $this->actingAs($other)->get("/files/{$uuid}/0")->assertNotFound();
        $this->actingAs($other)->putJson("/transactions/{$uuid}", [
            'total_amount' => 1, 'deposit_date' => '2026-10-01',
            'invoices' => [['si_number' => 'SI1', 'amount' => 1, 'cwt_amount' => 0]],
        ])->assertNotFound();
        $this->assertFalse(Remittance::where('uuid', $uuid)->first()->isConfirmed());
    }
}
