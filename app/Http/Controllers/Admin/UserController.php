<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users', ['roles' => Role::orderBy('name')->pluck('name')]);
    }

    /** Async list: paginated JSON so the page shell renders first and rows stream in. */
    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
        ]);

        $users = User::query()
            ->select(['id', 'name', 'email', 'is_active', 'must_change_password', 'created_at'])
            ->with('roles:id,name')
            // Prefix match so the name/email indexes can be used.
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'like', $term.'%')->orWhere('email', 'like', $term.'%')))
            ->when($request->role, fn ($q, $role) => $q->role($role))
            ->when($request->status, fn ($q, $status) => $q->where('is_active', $status === 'active'))
            ->orderBy('name')->orderBy('id')
            ->simplePaginate(15);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->roles->first()?->name,
                'is_active' => $u->is_active,
                'must_change_password' => $u->must_change_password,
                'is_self' => $u->id === auth()->id(),
            ]),
            'has_more' => $users->hasMorePages(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::exists('roles', 'name')],
            'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'must_change_password' => true,
        ]);
        $user->assignRole($data['role']);

        return response()->json(['id' => $user->id], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::exists('roles', 'name')],
            'is_active' => ['required', 'boolean'],
        ]);

        $removesAdmin = $user->hasRole('Admin') && ($data['role'] !== 'Admin' || ! $data['is_active']);

        if ($removesAdmin) {
            if ($user->id === auth()->id()) {
                return response()->json(['message' => 'You cannot remove your own admin access.'], 422);
            }
            if (! $this->otherActiveAdminExists($user)) {
                return response()->json(['message' => 'At least one active admin is required.'], 422);
            }
        }

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => $data['is_active'],
        ])->save();
        $user->syncRoles([$data['role']]);

        if (! $data['is_active']) {
            $this->endSessions($user);
        }

        return response()->json(['ok' => true]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', Password::min(10)->letters()->numbers()],
        ]);

        $user->forceFill(['password' => $data['password'], 'must_change_password' => true])->save();
        $this->endSessions($user);

        return response()->json(['ok' => true]);
    }

    private function otherActiveAdminExists(User $except): bool
    {
        return User::role('Admin')->where('is_active', true)->whereKeyNot($except->id)->exists();
    }

    /** Sign the user out everywhere (database session driver). */
    private function endSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
