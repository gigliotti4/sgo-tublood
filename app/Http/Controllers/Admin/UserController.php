<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        $this->authorize('users.view');

        return inertia('Admin/Users/Index', [
            'users' => User::with(['roles', 'sector:id,nombre'])
                ->select('id', 'name', 'email', 'sector_id', 'created_at')
                ->latest()
                ->paginate(15),
        ]);
    }

    public function create()
    {
        $this->authorize('users.create');

        return inertia('Admin/Users/Create', [
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'sectors' => Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('users.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', Password::defaults()],
            'sector_id' => ['nullable', 'exists:sectors,id'],
            'roles' => ['array'],
            'roles.*' => ['exists:roles,name'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'sector_id' => $data['sector_id'] ?? null,
        ]);

        $user->syncRoles($data['roles'] ?? []);

        return redirect()->route('users.index')
            ->with('success', 'Usuario creado correctamente.');
    }

    public function edit(User $user)
    {
        $this->authorize('users.edit');

        return inertia('Admin/Users/Edit', [
            'user' => $user->load('roles', 'sector'),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'sectors' => Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('users.edit');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', "unique:users,email,{$user->id}"],
            'password' => ['nullable', Password::defaults()],
            'sector_id' => ['nullable', 'exists:sectors,id'],
            'roles' => ['array'],
            'roles.*' => ['exists:roles,name'],
        ]);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'sector_id' => $data['sector_id'] ?? null,
            ...($data['password'] ? ['password' => $data['password']] : []),
        ]);

        $user->syncRoles($data['roles'] ?? []);

        return redirect()->route('users.index')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    public function destroy(User $user)
    {
        $this->authorize('users.delete');

        if ($user->id === auth()->id()) {
            return back()->with('error', 'No puedes eliminarte a ti mismo.');
        }

        $user->delete();

        return redirect()->route('users.index')
            ->with('success', 'Usuario eliminado correctamente.');
    }
}
