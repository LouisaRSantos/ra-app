<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ExtractionStore;
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
        $this->actingAs($as)->get('/files')->assertOk();

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
        ExtractionStore::create('abc', ['user_id' => $owner->id, 'disk_paths' => ['extractions/abc-0.jpg']]);

        $this->actingAs($owner)->getJson('/extractions/abc/status')->assertOk();
        $this->actingAs($other)->getJson('/extractions/abc/status')->assertNotFound();
        $this->actingAs($other)->get('/extractions/abc')->assertNotFound();
        $this->actingAs($other)->get('/extractions/abc/image/0')->assertNotFound();
        $this->actingAs($other)->putJson('/extractions/abc', [
            'total_amount' => 1, 'deposit_date' => '2026-10-01', 'invoices' => [['si_number' => 'SI1', 'amount' => 1, 'cwt_amount' => 0]],
        ])->assertNotFound();
        $this->assertFalse(ExtractionStore::get('abc')['confirmed']);
        $this->actingAs($admin)->getJson('/extractions/abc/status')->assertOk();
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

    public function test_claude_callback_stays_public_but_token_protected(): void
    {
        ExtractionStore::create('cb1', ['user_id' => 1]);
        ExtractionStore::update('cb1', ['status' => 'processing', 'callback_token' => 'tok']);

        $this->postJson('/claude-extractions/cb1/result', [])->assertForbidden();
        $this->withToken('tok')->postJson('/claude-extractions/cb1/result', ['invoices' => []])->assertOk();
    }
}
