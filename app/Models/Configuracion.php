<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una clave de la configuración de marca y textos.
 *
 * El catálogo (qué claves existen y sus valores por defecto) vive en
 * `config/configuracion.php`; esta tabla solo guarda lo que se cambió desde el
 * panel. Se lee siempre a través de `App\Support\Configuracion`, que cachea y
 * aplica los defaults.
 */
class Configuracion extends Model
{
    /**
     * Explícito: Laravel pluraliza en inglés y buscaría `configuracions`.
     */
    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor'];
}
