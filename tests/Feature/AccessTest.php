<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ExtractionRun;
use App\Models\Remittance;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    private function userWithRole(string $role, array $attrs = []): User
    {
        return tap(User::factory()->create($attrs), fn (User $u) => $u->assignRole($role));
    }

    public function test_guests_are_sent_to_login_everywhere(): void
    {
        foreach (['/', '/files', '/extractions', '/claude-extractions', '/admin/users', '/admin/roles', '/admin/users/data', '/password'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }

        $this->postJson('/admin/users', [])->assertUnauthorized();
        $this->putJson('/extractions/x', [])->assertUnauthorized();
        $this->getJson('/extractions/x/status')->assertUnauthorized();
    }

    public function test_there_is_no_signup(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'a@b.c'])->assertStatus(404);
    }

    public function test_login_works_and_inactive_or_wrong_password_look_identical(): void
    {
        $user = $this->userWithRole('Admin', ['password' => 'Password123']);
        $off = $this->userWithRole('User', ['password' => 'Password123', 'is_active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'Password123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        auth()->logout();

        $a = $this->post('/login', ['email' => $off->email, 'password' => 'Password123']);
        $b = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->assertGuest();
        $this->assertSame(session('errors')->get('email'), ['These credentials do not match our records.']);
        $a->assertSessionHasErrors('email');
        $b->assertSessionHasErrors('email');
    }

    public function test_deactivated_session_is_signed_out(): void
    {
        $user = $this->userWithRole('Account Specialist');
        $this->actingAs($user)->get('/claude-extractions')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())->get('/claude-extractions')->assertRedirect('/login');
    }

    public function test_temporary_password_forces_change_before_anything_else(): void
    {
        $user = $this->userWithRole('Account Specialist', ['password' => 'Temp-pass-1', 'must_change_password' => true]);

        $this->actingAs($user)->get('/claude-extractions')->assertRedirect('/password');
        $this->actingAs($user)->getJson('/extractions/x/status')->assertStatus(423);

        $this->actingAs($user)->put('/password', [
            'current_password' => 'Temp-pass-1',
            'password' => 'Brand-new-pass-9',
            'password_confirmation' => 'Brand-new-pass-9',
        ])->assertRedirect('/');

        $this->assertFalse($user->fresh()->must_change_password);
        $this->actingAs($user->fresh())->get('/claude-extractions')->assertOk();
    }

    public function test_home_redirects_by_role(): void
    {
        $this->actingAs($this->userWithRole('Admin'))->get('/')->assertRedirect(route('admin.users.index'));
        $this->actingAs($this->userWithRole('Account Specialist'))->get('/')->assertRedirect(route('claude.index'));
        $this->actingAs($this->userWithRole('User'))->get('/')->assertRedirect(route('files.index'));
    }

    public function test_role_boundaries(): void
    {
        $as = $this->userWithRole('Account Specialist');
        $plain = $this->userWithRole('User');

        $this->actingAs($as)->get('/admin/users')->assertForbidden();
        $this->actingAs($as)->get('/admin/roles')->assertForbidden();
        // an AS sees only their own records, not the shared public-files list
        $this->actingAs($as)->get('/files')->assertForbidden();

        $this->actingAs($plain)->get('/claude-extractions')->assertForbidden();
        $this->actingAs($plain)->post('/claude-extractions')->assertForbidden();
        $this->actingAs($plain)->get('/extractions/x/status')->assertForbidden();
        $this->actingAs($plain)->get('/files')->assertOk();
    }

    public function test_specialist_cannot_read_another_specialists_extraction(): void
    {
        $owner = $this->userWithRole('Account Specialist');
        $other = $this->userWithRole('Account Specialist');
        $admin = $this->userWithRole('Admin');
        $remittance = Remittance::factory()->completed()->for($owner)->create();
        $uuid = $remittance->uuid;

        $this->actingAs($owner)->getJson("/extractions/{$uuid}/status")->assertOk();
        $this->actingAs($other)->getJson("/extractions/{$uuid}/status")->assertNotFound();
        $this->actingAs($other)->get("/extractions/{$uuid}")->assertNotFound();
        $this->actingAs($other)->get("/extractions/{$uuid}/image/0")->assertNotFound();
        $this->actingAs($other)->putJson("/extractions/{$uuid}", [
            'total_amount' => 1, 'deposit_date' => '2026-10-01', 'invoices' => [['si_number' => 'SI1', 'amount' => 1, 'cwt_amount' => 0]],
        ])->assertNotFound();
        $this->assertFalse($remittance->fresh()->isConfirmed());
        $this->actingAs($admin)->getJson("/extractions/{$uuid}/status")->assertOk();
    }

    public function test_admin_manages_users(): void
    {
        $admin = $this->userWithRole('Admin');

        $this->actingAs($admin)->postJson('/admin/users', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'Account Specialist', 'password' => 'Temp-pass-12',
        ])->assertCreated();

        $ana = User::where('email', 'ana@example.com')->first();
        $this->assertTrue($ana->hasRole('Account Specialist'));
        $this->assertTrue($ana->must_change_password);
        $this->assertNotSame('Temp-pass-12', $ana->password);

        $this->actingAs($admin)->postJson('/admin/users', ['name' => 'x', 'email' => 'ana@example.com', 'role' => 'User', 'password' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->actingAs($admin)->getJson('/admin/users/data?q=An')->assertOk()->assertJsonFragment(['email' => 'ana@example.com']);

        $this->actingAs($admin)->putJson("/admin/users/{$ana->id}", [
            'name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'User', 'is_active' => false,
        ])->assertOk();
        $this->assertFalse($ana->fresh()->is_active);
        $this->assertTrue($ana->fresh()->hasRole('User'));
    }

    public function test_cannot_lock_everyone_out(): void
    {
        $admin = $this->userWithRole('Admin');

        $this->actingAs($admin)->putJson("/admin/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'User', 'is_active' => true,
        ])->assertStatus(422);

        $this->actingAs($admin)->putJson('/admin/roles/'.\Spatie\Permission\Models\Role::findByName('Admin')->id, ['permissions' => []])
            ->assertStatus(422);
    }

    public function test_role_permissions_can_be_edited(): void
    {
        $admin = $this->userWithRole('Admin');
        $role = \Spatie\Permission\Models\Role::findByName('User');

        $this->actingAs($admin)->putJson("/admin/roles/{$role->id}", ['permissions' => ['files.view-public', 'remittances.view-own']])->assertOk();
        $this->assertEqualsCanonicalizing(['files.view-public', 'remittances.view-own'], $role->fresh()->permissions->pluck('name')->all());

        $this->actingAs($admin)->putJson("/admin/roles/{$role->id}", ['permissions' => ['made.up']])->assertStatus(422);
    }

    public function test_password_reset_ends_the_users_other_sessions(): void
    {
        $user = $this->userWithRole('Account Specialist');

        // a session whose recorded password hash no longer matches (what a reset produces)
        $this->actingAs($user)->withSession(['password_hash_web' => 'hash-from-before-the-reset'])
            ->get('/claude-extractions')->assertRedirect('/login');
    }

    public function test_status_polling_stays_cheap(): void
    {
        $user = $this->userWithRole('Account Specialist');
        $remittance = Remittance::factory()->for($user)->create(['status' => 'processing']);

        $this->actingAs($user)->getJson("/extractions/{$remittance->uuid}/status")->assertOk(); // warm permission cache

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($user)->getJson("/extractions/{$remittance->uuid}/status")
            ->assertOk()->assertJson(['status' => 'processing', 'error_message' => null]);

        // user, roles, remittance. Each query is a network round trip against the shared DB.
        $this->assertLessThanOrEqual(4, $queries, "status endpoint ran {$queries} queries");
    }

    public function test_failed_status_reports_the_run_error(): void
    {
        $user = $this->userWithRole('Account Specialist');
        $remittance = Remittance::factory()->for($user)->create(['status' => 'failed']);
        $remittance->runs()->create(['engine' => 'claude', 'status' => 'failed', 'error' => 'Processing took too long.']);

        $this->actingAs($user)->getJson("/extractions/{$remittance->uuid}/status")
            ->assertOk()->assertJson(['status' => 'failed', 'error_message' => 'Processing took too long.']);
    }

    public function test_claude_callback_stays_public_but_token_protected(): void
    {
        $remittance = Remittance::factory()->create(['status' => 'processing']);
        $remittance->runs()->create([
            'engine' => 'claude',
            'status' => 'processing',
            'callback_token_hash' => ExtractionRun::hashToken('tok'),
        ]);

        $this->postJson("/claude-extractions/{$remittance->uuid}/result", [])->assertForbidden();
        $this->withToken('tok')->postJson("/claude-extractions/{$remittance->uuid}/result", ['invoices' => []])->assertOk();
    }
}
