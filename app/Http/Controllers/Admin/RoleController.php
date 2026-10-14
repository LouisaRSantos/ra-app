<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return view('admin.roles');
    }

    public function data(): JsonResponse
    {
        $roles = Role::with('permissions:id,name')->withCount('users')->orderBy('id')->get();

        return response()->json([
            'permissions' => collect(config('ra.permissions'))
                ->map(fn ($label, $name) => ['name' => $name, 'label' => $label])->values(),
            'roles' => $roles->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'users' => $r->users_count,
                'locked' => $r->name === 'Admin',
                'permissions' => $r->permissions->pluck('name'),
            ]),
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        // Admin always keeps everything, so a mistake here can never lock everyone out.
        abort_if($role->name === 'Admin', 422, 'The Admin role cannot be changed.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $role->syncPermissions($data['permissions']);

        return response()->json(['ok' => true]);
    }
}
