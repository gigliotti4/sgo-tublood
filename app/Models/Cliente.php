<?php

namespace App\Models;

use App\Models\Concerns\GuardaAdjuntos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    use GuardaAdjuntos;

    protected $fillable = [
        'numero',
        'razon_social',
        'nombre_fantasia',
        'cuit',
        'codigo_iva',
        'descripcion_iva',
        'telefono',
        'mail',
        'mail_nuevo',
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
        'fecha_vencimiento',
        'synced_at',
    ];

    protected $casts = [
        'porcen_descuen' => 'decimal:2',
        'fecha_vencimiento' => 'date',
        'synced_at' => 'datetime',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(ClienteAttachment::class);
    }

    /** Los adjuntos van a `clientes/{numero}/` — ver GuardaAdjuntos. */
    protected function carpetaDeAdjuntos(): string
    {
        return 'clientes/'.$this->segmentoSeguro($this->numero, 'sin-numero-'.$this->id);
    }
}
