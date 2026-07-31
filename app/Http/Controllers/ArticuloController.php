<?php

namespace App\Http\Controllers;

use App\Models\Articulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda de artículos para el selector de productos.
 *
 * Vive suelto en `Controllers/` (como DashboardController) porque lo usan los
 * dos lados: el portal público y la carga interna del panel.
 *
 * ⚠️ Es un endpoint **público**, porque el portal de carga no tiene login. Por
 * eso devuelve únicamente código y descripción: nunca stock, proveedor ni nada
 * que no esté ya en el catálogo comercial. Va con rate limit en la ruta.
 */
class ArticuloController extends Controller
{
    /** Tope de resultados: es un autocompletado, no un listado. */
    private const LIMITE = 20;

    /** Menos que esto devolvería medio catálogo y no ayuda a completar nada. */
    private const MINIMO = 2;

    public function buscar(Request $request): JsonResponse
    {
        // Un término corto o vacío devuelve lista vacía en vez de 422: para un
        // autocompletado no es un error, es "todavía no hay nada que sugerir".
        // Además evita depender del handler de excepciones, que en este
        // proyecto solo renderiza JSON bajo `api/*` (ver bootstrap/app.php) y
        // acá respondería con un redirect.
        $termino = trim((string) $request->query('q', ''));

        if (mb_strlen($termino) < self::MINIMO) {
            return response()->json([]);
        }

        $articulos = Articulo::query()
            ->buscar(mb_substr($termino, 0, 100))
            ->limit(self::LIMITE)
            ->get(['codigo', 'descripcion']);

        return response()->json($articulos);
    }
}
