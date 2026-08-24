<?php

namespace App\Support;

use App\Models\Configuracion as ConfiguracionModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Lee config/configuracion.php y la tabla `configuraciones`, y expone los
 * textos y las imágenes de marca ya resueltos.
 *
 * Mismo rol que Documentacion para la clasificación documental: el catálogo
 * vive en config y de acá salen la pantalla de administración, las props que
 * comparte Inertia y los textos del PDF, sin que ninguno duplique la lista.
 *
 * **Los valores por defecto son parte del catálogo, no de la base.** Una clave
 * sin fila en `configuraciones` cae en su `default`, así que agregar una clave
 * nueva no necesita migración de datos: aparece sola, con el texto que estaba
 * hardcodeado antes.
 *
 * Se cachea porque `HandleInertiaRequests` la comparte en **todas** las
 * requests (incluidas las públicas del portal y el login). `olvidar()` limpia
 * la caché al guardar — mismo criterio que `forgetCachedPermissions()`.
 */
class Configuracion
{
    private const CACHE_KEY = 'configuracion.valores';

    /** El catálogo crudo: qué claves existen y cómo se editan. */
    public static function catalogo(): array
    {
        return config('configuracion.claves', []);
    }

    public static function grupos(): array
    {
        return config('configuracion.grupos', []);
    }

    /**
     * Todos los valores vigentes (lo guardado sobre los defaults del catálogo).
     *
     * @return array<string, string|null>
     */
    public static function valores(): array
    {
        $guardados = static::guardados();

        $valores = [];

        foreach (static::catalogo() as $clave => $def) {
            // `??` y no `?:`: un texto que alguien vació a propósito tiene que
            // quedar vacío, no volver al default.
            $valores[$clave] = $guardados[$clave] ?? $def['default'] ?? null;
        }

        return $valores;
    }

    /**
     * Lo guardado en la base, cacheado.
     *
     * ⚠️ Se cachea **solo la lectura de la base**, no el array ya resuelto
     * contra el catálogo. Si se cacheara el resultado final, agregar una clave
     * nueva al catálogo dejaría la caché vieja sin esa clave y todo lo que la
     * leyera reventaría hasta que alguien limpiara la caché a mano — una
     * trampa en cada deploy que sume una clave. Resolver contra el catálogo en
     * cada llamada es en memoria y no cuesta nada.
     *
     * @return array<string, string|null>
     */
    private static function guardados(): array
    {
        try {
            return Cache::rememberForever(
                self::CACHE_KEY,
                fn () => ConfiguracionModel::pluck('valor', 'clave')->all()
            );
        } catch (\Throwable) {
            // La tabla puede no existir todavía (instalación nueva antes de
            // migrar, o el deploy entre el `git pull` y el `migrate`). Esto se
            // comparte en **todas** las requests, así que un fallo acá dejaría
            // la app entera en 500, login incluido: devolviendo "nada guardado"
            // se renderiza con los textos por defecto del catálogo. No se
            // cachea el fallback a propósito, para que se recupere solo en
            // cuanto la tabla exista.
            return [];
        }
    }

    public static function get(string $clave): ?string
    {
        return static::valores()[$clave] ?? null;
    }

    /**
     * Los valores listos para el frontend: los textos tal cual, más la URL
     * pública de cada imagen (la columna guarda el path en el disco `public`).
     */
    public static function paraCompartir(): array
    {
        $valores = static::valores();

        foreach (static::catalogo() as $clave => $def) {
            if (($def['tipo'] ?? null) === 'imagen') {
                $valores[$clave] = ($valores[$clave] ?? null)
                    ? Storage::disk('public')->url($valores[$clave])
                    : null;
            }
        }

        return $valores;
    }

    /** El path en disco de una imagen (no la URL). Lo usa el PDF, que la incrusta en base64. */
    public static function pathImagen(string $clave): ?string
    {
        $valor = static::get($clave);

        if (! $valor || ! Storage::disk('public')->exists($valor)) {
            return null;
        }

        return Storage::disk('public')->path($valor);
    }

    public static function olvidar(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
