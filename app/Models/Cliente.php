<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $fillable = [
        'numero',
        'razon_social',
        'nombre_fantasia',
        'cuit',
        'codigo_iva',
        'descripcion_iva',
        'telefono',
        'mail',
        'contacto',
        'domicilio',
        'localidad',
        'codigo_provincia',
        'descripcion_provincia',
        'porcen_descuen',
        'usuario_web',
        'codigo_vendedor',
        'nombre_vendedor',
        'codigo_postal',
        'synced_at',
    ];

    protected $casts = [
        'porcen_descuen' => 'decimal:2',
        'synced_at'      => 'datetime',
    ];
}
