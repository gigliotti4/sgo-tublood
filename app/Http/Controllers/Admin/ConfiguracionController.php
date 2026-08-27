<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Configuracion as ConfiguracionModel;
use App\Services\RecorteImagen;
use App\Support\Configuracion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;

class ConfiguracionController extends Controller
{
    /** Dónde van el logo y el favicon dentro del disco `public`. */
    private const CARPETA = 'marca';

    public function index(): Response
    {
        $this->authorize('configuracion.view');

        return inertia('Admin/Configuracion/Index', [
            'catalogo' => Configuracion::catalogo(),
            'grupos' => Configuracion::grupos(),
            // Los textos crudos para editar, y las imágenes como URL para la
            // vista previa. `paraCompartir()` ya resuelve las dos cosas.
            'valores' => Configuracion::paraCompartir(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('configuracion.edit');

        $catalogo = Configuracion::catalogo();

        $data = $request->validate($this->reglas($catalogo));

        foreach ($catalogo as $clave => $def) {
            if (($def['tipo'] ?? null) === 'imagen') {
                $this->guardarImagen($request, $clave, $def);

                continue;
            }

            // Solo las claves que vinieron en la request: así un formulario
            // parcial no borra lo que no mandó.
            //
            // ⚠️ `?? ''` y no `?? null`: el middleware ConvertEmptyStringsToNull
            // convierte un campo vaciado en null, y null es justamente lo que
            // `Configuracion::valores()` interpreta como "sin valor propio" y
            // resuelve con el default del catálogo. Guardando la cadena vacía,
            // un texto que alguien borró a propósito queda borrado.
            if (array_key_exists($clave, $data)) {
                $this->guardar($clave, $data[$clave] ?? '');
            }
        }

        Configuracion::olvidar();

        return redirect()->route('configuracion.index')
            ->with('success', 'Configuración actualizada correctamente.');
    }

    /**
     * Reglas armadas desde el catálogo: una clave que no esté declarada ahí no
     * tiene regla y por lo tanto `validate()` la descarta.
     */
    private function reglas(array $catalogo): array
    {
        $reglas = [];

        foreach ($catalogo as $clave => $def) {
            $reglas[$clave] = match ($def['tipo'] ?? 'texto') {
                // El favicon acepta .ico, que no es un formato de imagen que
                // `image` reconozca, así que se valida por extensión. El peso
                // máximo sale del catálogo: una foto de fondo no entra en los
                // 2 MB que le alcanzan a un logo.
                'imagen' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp,ico', 'max:'.($def['max'] ?? 2048)],
                'textarea' => ['nullable', 'string', 'max:2000'],
                default => ['nullable', 'string', 'max:255'],
            };
        }

        // Tilde para borrar una imagen ya cargada y volver al ícono por defecto.
        $reglas['_borrar'] = ['nullable', 'array'];
        $reglas['_borrar.*'] = ['string'];

        return $reglas;
    }

    private function guardarImagen(Request $request, string $clave, array $def): void
    {
        $borrar = in_array($clave, $request->input('_borrar', []), true);

        if ($borrar) {
            $this->borrarArchivo($clave);
            $this->guardar($clave, null);

            return;
        }

        if (! $request->hasFile($clave)) {
            return;
        }

        // El anterior se borra recién cuando el nuevo ya está guardado: si la
        // subida falla, el que estaba sigue en pie.
        $anterior = Configuracion::get($clave);

        $path = $request->file($clave)->store(self::CARPETA, 'public');

        // Un logo exportado con el lienzo más grande que el dibujo se ve chico
        // en pantalla sin que haya nada mal en el CSS. Recortarlo acá lo
        // arregla para cualquier archivo que suban, sin depender de cómo lo
        // hayan exportado. No aborta la subida si falla.
        //
        // Las claves que declaran `recortar => false` se saltean: en una foto
        // de fondo no hay margen que sacar, y el recorte se guarda re-comprimida.
        if ($def['recortar'] ?? true) {
            app(RecorteImagen::class)->recortar(Storage::disk('public')->path($path));
        }

        $this->guardar($clave, $path);

        if ($anterior && $anterior !== $path) {
            Storage::disk('public')->delete($anterior);
        }
    }

    private function borrarArchivo(string $clave): void
    {
        $path = Configuracion::get($clave);

        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function guardar(string $clave, ?string $valor): void
    {
        ConfiguracionModel::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }
}
