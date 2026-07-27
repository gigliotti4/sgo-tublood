<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'apellido', 'email', 'password', 'sector_id', 'supervisor_id', 'gerente_id', 'es_gerente'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * ¿Forma parte del equipo que clasifica los reclamos externos?
     *
     * Vale tanto por el rol como por el sector: son dos formas de decir lo
     * mismo que conviven mientras se termina de cargar la estructura de
     * usuarios, y un reclamo de cliente no puede quedar sin ver porque a
     * alguien le falte una de las dos.
     */
    public function esDeCalidad(): bool
    {
        return $this->hasRole(Sector::GARANTIA_CALIDAD)
            || $this->sector?->slug === Sector::GARANTIA_CALIDAD;
    }

    /** A quién se escala si este usuario no gestiona a tiempo. */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /** Gerente que recibe el aviso al finalizar y el último escalón. */
    public function gerente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerente_id');
    }

    public function subordinados(): HasMany
    {
        return $this->hasMany(User::class, 'supervisor_id');
    }

    /**
     * Cadena de escalamiento hacia arriba (supervisor del supervisor, y así).
     * Lleva un set de visitados por si los datos quedaron armados en círculo:
     * la validación lo impide, pero un import viejo o un UPDATE a mano no.
     *
     * @return Collection<int, User>
     */
    public function cadenaEscalamiento(): Collection
    {
        $cadena = new Collection;
        $visitados = [$this->id => true];
        $actual = $this->supervisor;

        while ($actual && ! isset($visitados[$actual->id])) {
            $cadena->push($actual);
            $visitados[$actual->id] = true;
            $actual = $actual->supervisor;
        }

        return $cadena;
    }

    /**
     * ¿Poner a $supervisorId como supervisor de este usuario cerraría un círculo?
     * Sube por la cadena del candidato buscándose a sí mismo.
     */
    public function generariaCiclo(?int $supervisorId): bool
    {
        if ($supervisorId === null) {
            return false;
        }

        if ($this->id !== null && $supervisorId === $this->id) {
            return true;
        }

        $visitados = [];
        $actual = static::find($supervisorId);

        while ($actual && ! isset($visitados[$actual->id])) {
            if ($actual->id === $this->id) {
                return true;
            }

            $visitados[$actual->id] = true;
            $actual = $actual->supervisor;
        }

        return false;
    }

    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn () => trim($this->name.' '.$this->apellido));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'es_gerente' => 'boolean',
        ];
    }
}
