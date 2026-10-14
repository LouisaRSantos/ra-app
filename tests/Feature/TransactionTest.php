<?php

namespace Tests\Feature;

use App\Models\Remittance;
use App\Models\RemittanceFile;
use App\Models\RemittanceInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        Storage::fake('local');
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(['name' => $role.' '.fake()->unique()->lastName()]))->assignRole($role);
    }

    private function remittance(User $owner, array $attrs = []): Remittance
    {
        return Remittance::factory()->completed()->for($owner)->create($attrs);
    }

    private function uuids($response): array
    {
        return collect($response->json('data'))->pluck('uuid')->all();
    }

    // ------------------------------------------------------------------ list scope

    public function test_list_shows_only_my_uploads_even_for_an_admin(): void
    {
        $me = $this->user('Account Specialist');
        $other = $this->user('Account Specialist');
        $admin = $this->user('Admin');
        $mine = $this->remittance($me);
        $theirs = $this->remittance($other);
        $adminsOwn = $this->remittance($admin);

        $this->assertSame([$mine->uuid], $this->uuids($this->actingAs($me)->getJson('/transactions/data')));

        // an admin sees their own by default, everyone only on request
        $this->assertSame([$adminsOwn->uuid], $this->uuids($this->actingAs($admin)->getJson('/transactions/data')));
        $all = $this->uuids($this->actingAs($admin)->getJson('/transactions/data?scope=all'));
        $this->assertEqualsCanonicalizing([$mine->uuid, $theirs->uuid, $adminsOwn->uuid], $all);

        // a specialist asking for "all" still only gets their own
        $this->assertSame([$mine->uuid], $this->uuids($this->actingAs($me)->getJson('/transactions/data?scope=all')));
    }

    public function test_user_role_cannot_use_transactions_at_all(): void
    {
        $plain = $this->user('User');
        $r = $this->remittance($this->user('Account Specialist'));

        $this->actingAs($plain)->getJson('/transactions/data')->assertForbidden();
        $this->actingAs($plain)->getJson("/transactions/{$r->uuid}")->assertForbidden();
        $this->actingAs($plain)->deleteJson("/transactions/{$r->uuid}")->assertForbidden();
    }

    public function test_list_search_and_filters(): void
    {
        $me = $this->user('Account Specialist');
        $a = $this->remittance($me, ['payment_reference_number' => 'REF-AAA', 'cr_number' => '111']);
        $b = $this->remittance($me, ['payment_reference_number' => 'REF-BBB', 'cr_number' => '222', 'status' => 'failed']);
        RemittanceInvoice::factory()->for($a)->for(SalesInvoice::factory()->create(['si_number' => 'MADBSI0000009999']))->create();

        $get = fn (string $qs) => $this->uuids($this->actingAs($me)->getJson('/transactions/data?'.$qs));

        $this->assertSame([$a->uuid], $get('q=REF-A'));
        $this->assertSame([$b->uuid], $get('q=222'));
        $this->assertSame([$a->uuid], $get('q=madbsi00000099'));   // SI prefix, case-insensitive
        $this->assertSame([$b->uuid], $get('status=failed'));
        $this->assertEmpty($get('from=2999-01-01'));
        $this->assertCount(2, $get('to=2999-01-01'));
        $this->assertEmpty($get('q=%25'));                         // LIKE wildcards are literal
        $this->actingAs($me)->getJson('/transactions/data?status=bogus')->assertUnprocessable();
    }

    public function test_list_paginates_without_extra_cost(): void
    {
        $me = $this->user('Account Specialist');
        Remittance::factory()->count(20)->completed()->for($me)->create();

        $first = $this->actingAs($me)->getJson('/transactions/data')->assertOk();
        $this->assertCount(15, $first->json('data'));
        $this->assertTrue($first->json('has_more'));

        $second = $this->actingAs($me)->getJson('/transactions/data?page=2')->assertOk();
        $this->assertCount(5, $second->json('data'));
        $this->assertFalse($second->json('has_more'));
    }

    public function test_hot_endpoints_stay_inside_their_query_budget(): void
    {
        // each query is a network round trip to the shared DB, so these budgets are the speed contract
        $me = $this->user('Account Specialist');
        $r = $this->remittance($me);
        RemittanceFile::factory()->for($r)->create();
        RemittanceInvoice::factory()->for($r)->create();

        $count = function (callable $request): int {
            $n = 0;
            \Illuminate\Support\Facades\DB::listen(function () use (&$n) { $n++; });
            $request();

            return $n;
        };

        $this->actingAs($me)->getJson('/transactions/data'); // warm the permission cache

        $list = $count(fn () => $this->actingAs($me)->getJson('/transactions/data')->assertOk());
        $detail = $count(fn () => $this->actingAs($me)->getJson("/transactions/{$r->uuid}")->assertOk());
        $image = $count(fn () => $this->actingAs($me)->get("/files/{$r->uuid}/0"));

        $this->assertLessThanOrEqual(4, $list, "list ran {$list} queries");     // user, roles, page
        $this->assertLessThanOrEqual(8, $detail, "detail ran {$detail} queries");
        $this->assertLessThanOrEqual(4, $image, "image ran {$image} queries");
    }

    // ------------------------------------------------------------------ detail / edit / delete

    public function test_detail_payload_has_values_files_and_permissions(): void
    {
        $me = $this->user('Account Specialist');
        $r = $this->remittance($me);
        RemittanceFile::factory()->for($r)->create(['position' => 0, 'original_name' => 'page.jpg']);
        RemittanceInvoice::factory()->for($r)->for(SalesInvoice::factory()->create(['si_number' => 'SI-1']))
            ->create(['amount' => '10.50']);

        $this->actingAs($me)->getJson("/transactions/{$r->uuid}")->assertOk()
            ->assertJsonPath('files.0.name', 'page.jpg')
            ->assertJsonPath('files.0.url', route('files.image', ['uuid' => $r->uuid, 'position' => 0]))
            ->assertJsonPath('invoices.0.si_number', 'SI-1')
            ->assertJsonPath('invoices.0.amount', '10.50')
            ->assertJsonPath('can.edit', true)
            ->assertJsonPath('can.delete', true)
            ->assertJsonPath('can.visibility', true)
            ->assertJsonMissingPath('callback_token_hash');
    }

    public function test_other_specialist_gets_404_on_every_record_action(): void
    {
        $owner = $this->user('Account Specialist');
        $other = $this->user('Account Specialist');
        $r = $this->remittance($owner);
        RemittanceFile::factory()->for($r)->create(['position' => 0]);
        $body = ['total_amount' => 1, 'deposit_date' => '2026-10-01', 'invoices' => [['si_number' => 'SI1', 'amount' => 1, 'cwt_amount' => 0]]];

        $this->actingAs($other)->getJson("/transactions/{$r->uuid}")->assertNotFound();
        $this->actingAs($other)->getJson("/transactions/{$r->uuid}/status")->assertNotFound();
        $this->actingAs($other)->putJson("/transactions/{$r->uuid}", $body)->assertNotFound();
        $this->actingAs($other)->deleteJson("/transactions/{$r->uuid}")->assertNotFound();
        $this->actingAs($other)->putJson("/transactions/{$r->uuid}/files/0/visibility", ['is_public' => true])->assertNotFound();
        $this->actingAs($other)->get("/files/{$r->uuid}/0")->assertNotFound();

        $this->assertNotNull($r->fresh());
        $this->assertFalse($r->files->first()->fresh()->is_public);
    }

    public function test_edit_can_blank_an_amount_and_add_or_remove_lines(): void
    {
        $me = $this->user('Account Specialist');
        $r = $this->remittance($me);

        $this->actingAs($me)->putJson("/transactions/{$r->uuid}", [
            'payment_reference_number' => 'REF-1', 'cr_number' => '77', 'total_amount' => '100', 'total_cwt_amount' => '',
            'deposit_date' => '2026-10-02',
            'invoices' => [
                ['si_number' => 'si-1', 'amount' => '', 'cwt_amount' => ''],
                ['si_number' => 'SI-2', 'amount' => '40.5', 'cwt_amount' => '1'],
            ],
        ])->assertOk()->assertJsonPath('confirmed_by', $me->name)->assertJsonPath('invoices.0.amount', null)->assertJsonPath('invoices.1.amount', '40.50');

        $fresh = $r->fresh();
        $this->assertTrue($fresh->isConfirmed());
        $this->assertCount(2, $fresh->lines);
        $this->assertNull($fresh->total_cwt_amount);
        $this->assertSame('SI-1', $fresh->lines->first()->salesInvoice->si_number);
    }

    public function test_validation_and_state_rules_on_edit(): void
    {
        $me = $this->user('Account Specialist');
        $r = $this->remittance($me);
        $pending = $this->remittance($me, ['status' => 'processing']);
        $ok = ['total_amount' => 1, 'deposit_date' => '2026-10-01', 'invoices' => [['si_number' => 'SI1']]];

        $this->actingAs($me)->putJson("/transactions/{$r->uuid}", [])->assertUnprocessable();
        $this->actingAs($me)->putJson("/transactions/{$r->uuid}", [...$ok, 'total_amount' => 'abc'])->assertUnprocessable();
        $this->actingAs($me)->putJson("/transactions/{$r->uuid}", [...$ok, 'invoices' => []])->assertUnprocessable();
        $this->actingAs($me)->putJson("/transactions/{$pending->uuid}", $ok)->assertStatus(409);
    }

    public function test_delete_removes_rows_and_files(): void
    {
        $me = $this->user('Account Specialist');
        $r = $this->remittance($me);
        $file = RemittanceFile::factory()->for($r)->create();
        Storage::disk('local')->put($file->path, 'bytes');
        RemittanceInvoice::factory()->for($r)->create();

        $this->actingAs($me)->deleteJson("/transactions/{$r->uuid}")->assertOk();

        $this->assertNull(Remittance::find($r->id));
        $this->assertSame(0, RemittanceFile::count() + RemittanceInvoice::count());
        Storage::disk('local')->assertMissing($file->path);
    }

    // ------------------------------------------------------------------ upload

    public function test_upload_validates_engine_and_images(): void
    {
        Queue::fake();
        $me = $this->user('Account Specialist');
        $img = UploadedFile::fake()->image('a.jpg');

        $this->actingAs($me)->postJson('/transactions', ['images' => [$img]])->assertUnprocessable();                          // no engine
        $this->actingAs($me)->postJson('/transactions', ['engine' => 'openai', 'images' => [$img]])->assertUnprocessable();    // unknown engine
        $this->actingAs($me)->postJson('/transactions', ['engine' => 'claude', 'images' => [UploadedFile::fake()->create('x.php', 5, 'text/x-php')]])->assertUnprocessable();
        $this->actingAs($me)->postJson('/transactions', ['engine' => 'claude'])->assertUnprocessable();
        $this->assertSame(0, Remittance::count());
    }

    public function test_only_roles_with_upload_permission_can_upload(): void
    {
        Queue::fake();
        $this->actingAs($this->user('User'))->postJson('/transactions', ['engine' => 'claude', 'images' => [UploadedFile::fake()->image('a.jpg')]])->assertForbidden();
        $this->assertSame(0, Remittance::count());
    }

    // ------------------------------------------------------------------ visibility + public files

    public function test_owner_publishes_a_page_and_the_user_role_can_then_see_only_that_page(): void
    {
        $owner = $this->user('Account Specialist');
        $plain = $this->user('User');
        $r = $this->remittance($owner);
        $private = RemittanceFile::factory()->for($r)->create(['position' => 0, 'original_name' => 'private.jpg']);
        $shared = RemittanceFile::factory()->for($r)->create(['position' => 1, 'original_name' => 'shared.jpg']);
        Storage::disk('local')->put($private->path, 'p');
        Storage::disk('local')->put($shared->path, 's');

        $this->actingAs($plain)->getJson('/files/data')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($plain)->get("/files/{$r->uuid}/1")->assertNotFound();

        $this->actingAs($owner)->putJson("/transactions/{$r->uuid}/files/1/visibility", ['is_public' => true])->assertOk();

        $list = $this->actingAs($plain)->getJson('/files/data')->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'shared.jpg')->assertJsonPath('data.0.uploader', $owner->name);
        // no extracted data leaks into the public listing
        $this->assertSame(['name', 'size', 'uploader', 'uploaded_at', 'url'], array_keys($list->json('data.0')));

        $this->actingAs($plain)->get("/files/{$r->uuid}/1")->assertOk()->assertHeader('Cache-Control', 'max-age=86400, private');
        $this->actingAs($plain)->get("/files/{$r->uuid}/0")->assertNotFound();           // the private page stays private
        $this->actingAs($plain)->getJson("/transactions/{$r->uuid}")->assertForbidden(); // and so does the data

        $this->actingAs($owner)->putJson("/transactions/{$r->uuid}/files/1/visibility", ['is_public' => false])->assertOk();
        $this->actingAs($plain)->get("/files/{$r->uuid}/1")->assertNotFound();
    }

    public function test_specialist_never_sees_another_specialists_public_file(): void
    {
        $a = $this->user('Account Specialist');
        $b = $this->user('Account Specialist');
        $r = $this->remittance($a);
        $f = RemittanceFile::factory()->public()->for($r)->create(['position' => 0]);
        Storage::disk('local')->put($f->path, 'x');

        $this->actingAs($b)->get("/files/{$r->uuid}/0")->assertNotFound();
        $this->actingAs($b)->getJson('/files/data')->assertForbidden(); // the shared list is for the User role / admins
        $this->actingAs($a)->get("/files/{$r->uuid}/0")->assertOk();
    }

    public function test_visibility_input_is_validated(): void
    {
        $me = $this->user('Account Specialist');
        $r = $this->remittance($me);
        RemittanceFile::factory()->for($r)->create(['position' => 0]);

        $this->actingAs($me)->putJson("/transactions/{$r->uuid}/files/0/visibility", [])->assertUnprocessable();
        $this->actingAs($me)->putJson("/transactions/{$r->uuid}/files/5/visibility", ['is_public' => true])->assertNotFound();
        $this->actingAs($me)->putJson('/transactions/not-a-uuid/files/0/visibility', ['is_public' => true])->assertNotFound();
    }
}
