<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to re-run after adding a permission or role in config/ra.php.
 */
class AccessSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(config('ra.permissions')) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('ra.roles') as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            // Admin keeps every permission; other roles only get re-synced when the role is new,
            // so permissions an admin changed in the UI are not overwritten by a re-seed.
            if ($permissions === '*') {
                $role->syncPermissions(Permission::all());
            } elseif ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            }
        }

        $this->createFirstAdmin();
    }

    private function createFirstAdmin(): void
    {
        $email = config('ra.admin.email');
        $password = config('ra.admin.password');

        if (! $email || ! $password || User::where('email', $email)->exists()) {
            return;
        }

        User::create([
            'name' => config('ra.admin.name'),
            'email' => $email,
            'password' => $password,
            'must_change_password' => true,
        ])->assignRole('Admin');
    }
}
