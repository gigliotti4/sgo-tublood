<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Un sistema externo que consulta la API (`routes/api.php`).
 *
 * ⚠️ Es una entidad propia y **no** un `User` de servicio: un `User` puede
 * loguearse en el panel, recibe roles, aparece en los selectores de
 * responsable y en los avisos. El token de una integración tiene que servir
 * solo para la API.
 */
class Integracion extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'integraciones';

    protected $fillable = ['nombre'];
}
