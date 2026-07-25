<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Models\User;
use App\Services\UserImportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        $this->authorize('users.view');

        return inertia('Admin/Users/Index', [
            'users' => User::with(['roles', 'sector:id,nombre', 'supervisor:id,name,apellido', 'gerente:id,name,apellido'])
                ->select('id', 'name', 'apellido', 'email', 'sector_id', 'supervisor_id', 'gerente_id', 'es_gerente', 'created_at')
                ->latest()
                ->paginate(15),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            // Contraseñas de los usuarios recién importados: es la única vez que se
            // pueden ver, así que viajan por flash y se muestran una sola vez.
            'importados' => session('importados'),
        ]);
    }

    public function create()
    {
        $this->authorize('users.create');

        return inertia('Admin/Users/Create', [
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'sectores' => $this->sectores(),
            'usuarios' => $this->usuarios(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('users.create');

        $data = $request->validate([
            ...$this->reglas(),
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', Password::defaults()],
        ]);

        $user = User::create([
            ...$this->atributos($data),
            'email' => $data['email'],
            'password' => $data['password'],
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
            'sectores' => $this->sectores(),
            // El propio usuario no puede ser su supervisor ni su gerente.
            'usuarios' => $this->usuarios()->where('id', '!=', $user->id)->values(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('users.edit');

        $data = $request->validate([
            ...$this->reglas($user),
            'email' => ['required', 'email', "unique:users,email,{$user->id}"],
            'password' => ['nullable', Password::defaults()],
        ]);

        $user->update([
            ...$this->atributos($data),
            'email' => $data['email'],
            ...($data['password'] ? ['password' => $data['password']] : []),
        ]);

        $user->syncRoles($data['roles'] ?? []);

        return redirect()->route('users.index')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    public function import(Request $request, UserImportService $service)
    {
        $this->authorize('users.create');

        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimes:xlsx,xls,csv'],
            'rol' => ['required', 'exists:roles,name'],
        ]);

        $resultado = $service->import($data['archivo'], $data['rol']);

        $creados = count($resultado['creados']);

        $redirect = redirect()->route('users.index')
            ->with('success', "Importación completa: {$creados} creados, {$resultado['actualizados']} actualizados.")
            ->with('importados', $resultado['creados']);

        if (! empty($resultado['advertencias'])) {
            $redirect->with('error', implode(' | ', $resultado['advertencias']));
        }

        return $redirect;
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

    /** Reglas comunes al alta y la edición. En el alta no hay $user todavía. */
    private function reglas(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'apellido' => ['nullable', 'string', 'max:255'],
            'sector_id' => ['nullable', 'exists:sectors,id'],
            'supervisor_id' => [
                'nullable',
                'exists:users,id',
                // Un círculo en la cadena dejaría al motor de alertas escalando
                // en redondo; se corta acá, no solo en el import.
                function (string $attribute, mixed $value, callable $fail) use ($user) {
                    if ($user?->generariaCiclo((int) $value)) {
                        $fail('Ese supervisor cerraría un círculo de escalamiento.');
                    }
                },
            ],
            'gerente_id' => ['nullable', 'exists:users,id'],
            'es_gerente' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => ['exists:roles,name'],
        ];
    }

    private function atributos(array $data): array
    {
        return [
            'name' => $data['name'],
            'apellido' => $data['apellido'] ?? null,
            'sector_id' => $data['sector_id'] ?? null,
            'supervisor_id' => $data['supervisor_id'] ?? null,
            'gerente_id' => $data['gerente_id'] ?? null,
            'es_gerente' => $data['es_gerente'] ?? false,
        ];
    }

    private function sectores()
    {
        return Sector::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'dias_gestion']);
    }

    private function usuarios()
    {
        return User::orderBy('name')->get(['id', 'name', 'apellido', 'es_gerente']);
    }
}
