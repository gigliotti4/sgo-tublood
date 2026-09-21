<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\OrdenaListados;
use App\Http\Controllers\Concerns\VuelveAlListado;
use App\Http\Controllers\Controller;
use App\Models\Sector;
use App\Models\User;
use App\Services\UserImportService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use OrdenaListados, VuelveAlListado;

    /**
     * Qué columnas se pueden ordenar.
     *
     * "Roles" queda afuera: es una relación muchos-a-muchos, una fila puede
     * tener tres, y "el primero alfabético" no es una propiedad de la fila.
     *
     * ⚠️ Supervisor y gerente van con un **self-join aliaseado**: sin el alias,
     * `whereColumn('users.id', 'users.supervisor_id')` compara la tabla consigo
     * misma y devuelve el nombre del propio usuario en vez del de su
     * supervisor. Es el bug que no da error.
     *
     * @return array<string, string|array<int, string>|Closure>
     */
    private function ordenables(): array
    {
        return [
            // La celda muestra nombre y apellido juntos: el orden tiene que
            // seguir los dos, o dos "Juan" salen en cualquier orden.
            'nombre' => ['name', 'apellido'],
            'email' => 'email',
            'sector' => fn (Builder $q, string $dir) => $q->orderBy(
                Sector::query()->select('nombre')->whereColumn('sectors.id', 'users.sector_id'),
                $dir,
            ),
            'supervisor' => fn (Builder $q, string $dir) => $q->orderBy(
                User::query()->from('users as supervisores')
                    ->select('supervisores.name')
                    ->whereColumn('supervisores.id', 'users.supervisor_id'),
                $dir,
            ),
            'gerente' => fn (Builder $q, string $dir) => $q->orderBy(
                User::query()->from('users as gerentes')
                    ->select('gerentes.name')
                    ->whereColumn('gerentes.id', 'users.gerente_id'),
                $dir,
            ),
        ];
    }

    public function index(Request $request)
    {
        $this->authorize('users.view');

        $users = User::with(['roles', 'sector:id,nombre', 'supervisor:id,name,apellido', 'gerente:id,name,apellido'])
            ->select('id', 'name', 'apellido', 'email', 'sector_id', 'supervisor_id', 'gerente_id', 'es_gerente', 'created_at');

        return inertia('Admin/Users/Index', [
            // `withQueryString()` es nuevo: sin él, pasar a la página 2 perdía
            // el orden elegido.
            'users' => $this->aplicarOrden(
                $users,
                $request,
                $this->ordenables(),
                fn (Builder $q) => $q->latest(),
                'users.id',
            )->paginate(15)->withQueryString(),
            'orden' => $this->orden($request, $this->ordenables()),
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

        // Al listado, pero **con los filtros y la página** que traía: ver el
        // trait `VuelveAlListado`.
        return $this->alListado($request, 'users.index')
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

        // `back()`: el import se dispara desde un modal del listado y el
        // redirect pelado sacaba al usuario de donde estaba trabajando.
        $redirect = back(fallback: route('users.index'))
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

        // `back()`, igual que la rama de error de arriba: el borrado se
        // confirma en un modal del listado y las dos salidas del mismo método
        // tienen que dejar al usuario en el mismo lugar.
        return back(fallback: route('users.index'))
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
