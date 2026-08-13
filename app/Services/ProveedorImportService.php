<?php

namespace App\Services;

use App\Models\Proveedor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga masiva del padrón de proveedores desde la planilla Excel
 * (NUM_PROV, RAZON, DOMICILIO).
 *
 * Igual que `ArticuloImportService`, el mapeo va **por nombre de columna y no
 * por posición**, así el archivo puede venir con las columnas en otro orden o
 * con columnas de más sin que haya que tocar código.
 *
 * A diferencia de artículos, acá se **crea además de actualizar**: el ERP no
 * expone proveedores, así que el Excel es la única fuente del padrón y un
 * número nuevo tiene que entrar. Los campos propios del panel (CUIT, teléfono,
 * mail, localidad, observaciones) no se tocan: una reimportación no puede
 * borrar lo que alguien cargó a mano.
 *
 * ⚠️ Antes de crear, un número que no existe busca entre los proveedores **sin
 * número** — los que dio de alta `ArticuloImportService` desde la planilla de
 * artículos, que trae la razón social pero no el NUM_PROV. Si la razón social
 * normalizada coincide, se los adopta (se les completa el número) en vez de
 * crear un segundo registro de la misma empresa.
 */
class ProveedorImportService
{
    private array $advertencias = [];

    /** @return array{creados: int, actualizados: int, advertencias: array<int, string>} */
    public function import(UploadedFile $file): array
    {
        $this->advertencias = [];
        $creados = 0;
        $actualizados = 0;

        $filas = IOFactory::load($file->getRealPath())
            ->getActiveSheet()
            ->toArray();

        $indices = $this->indicesDeColumnas($filas[0] ?? []);

        if ($indices['numero'] === null) {
            throw new \InvalidArgumentException(
                'El archivo no tiene una columna de número de proveedor (se esperaba algo como "NUM_PROV").'
            );
        }

        if ($indices['razon_social'] === null) {
            throw new \InvalidArgumentException(
                'El archivo no tiene una columna de razón social (se esperaba algo como "RAZON").'
            );
        }

        $huerfanos = $this->proveedoresSinNumero();

        foreach (array_slice($filas, 1) as $index => $fila) {
            $numeroFila = $index + 2;

            $numero = $this->numero($fila[$indices['numero']] ?? null);

            if ($numero === null) {
                continue;
            }

            $razonSocial = $this->limpiar($fila[$indices['razon_social']] ?? null);
            $domicilio = $indices['domicilio'] !== null
                ? $this->limpiar($fila[$indices['domicilio']] ?? null)
                : null;

            $proveedor = Proveedor::where('numero', $numero)->first();

            // Antes de dar de alta: ¿ya lo había creado el import de artículos
            // sin número? Se lo adopta en vez de duplicar la empresa. Sale del
            // mapa para que dos filas distintas no se lo peleen.
            if (! $proveedor && $razonSocial !== null) {
                $clave = Proveedor::normalizarRazonSocial($razonSocial);

                if (isset($huerfanos[$clave])) {
                    $proveedor = $huerfanos[$clave];
                    $proveedor->update(['numero' => $numero]);
                    unset($huerfanos[$clave]);
                }
            }

            if ($razonSocial === null && ! $proveedor) {
                $this->advertencias[] = "Fila {$numeroFila}: el proveedor '{$numero}' no tiene razón social, se omitió.";

                continue;
            }

            if ($proveedor) {
                $proveedor->update([
                    'razon_social' => $razonSocial ?? $proveedor->razon_social,
                    'domicilio' => $domicilio ?? $proveedor->domicilio,
                ]);

                $actualizados++;

                continue;
            }

            Proveedor::create([
                'numero' => $numero,
                'razon_social' => $razonSocial,
                'domicilio' => $domicilio,
            ]);

            $creados++;
        }

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'advertencias' => $this->advertencias,
        ];
    }

    /**
     * Los proveedores que entraron por el Excel de artículos y todavía no
     * tienen número, indexados por razón social normalizada.
     *
     * Una razón social repetida entre ellos queda afuera: adoptar uno de los
     * dos al azar le pondría el número al que no era.
     *
     * @return array<string, Proveedor>
     */
    private function proveedoresSinNumero(): array
    {
        $huerfanos = [];
        $ambiguos = [];

        foreach (Proveedor::query()->whereNull('numero')->get() as $proveedor) {
            $clave = Proveedor::normalizarRazonSocial($proveedor->razon_social);

            if ($clave === '') {
                continue;
            }

            if (isset($huerfanos[$clave])) {
                $ambiguos[$clave] = true;
            }

            $huerfanos[$clave] = $proveedor;
        }

        return array_diff_key($huerfanos, $ambiguos);
    }

    /**
     * Ubica cada columna por su encabezado normalizado (sin acentos, en
     * minúscula), no por posición. Devuelve el índice de la primera columna
     * que contenga alguna de las palabras clave del campo.
     *
     * @return array{numero: ?int, razon_social: ?int, domicilio: ?int}
     */
    private function indicesDeColumnas(array $encabezado): array
    {
        $normalizado = array_map(
            fn ($h) => (string) Str::of((string) $h)->ascii()->lower()->squish(),
            $encabezado
        );

        // Ojo: acá se recorre por palabra clave y no por columna (al revés que
        // en `ArticuloImportService`). Así "num_prov" le gana a un "num" suelto
        // que esté más a la izquierda, sin depender del orden del archivo.
        $buscar = function (array $palabrasClave) use ($normalizado): ?int {
            foreach ($palabrasClave as $palabra) {
                foreach ($normalizado as $i => $header) {
                    if (str_contains($header, $palabra)) {
                        return $i;
                    }
                }
            }

            return null;
        };

        return [
            // "proveedor" y no "prov" a secas: "prov" también matchea una
            // columna PROVINCIA, que no tiene nada que ver.
            'numero' => $buscar(['num_prov', 'nro_prov', 'proveedor', 'numero', 'num']),
            'razon_social' => $buscar(['razon']),
            'domicilio' => $buscar(['domicilio', 'direccion']),
        ];
    }

    /**
     * NUM_PROV viene como celda numérica, así que PhpSpreadsheet lo devuelve
     * como int o float según cómo esté tipada. Se normaliza a entero sin
     * decimales para que el mismo proveedor no entre dos veces ("933" y
     * "933.0") según de qué archivo venga.
     */
    private function numero(mixed $valor): ?string
    {
        $valor = $this->limpiar($valor);

        if ($valor === null) {
            return null;
        }

        if (is_numeric($valor) && (float) $valor == (int) (float) $valor) {
            return (string) (int) (float) $valor;
        }

        return $valor;
    }

    private function limpiar(mixed $valor): ?string
    {
        $valor = Str::squish((string) $valor);

        return ($valor === '' || $valor === '-') ? null : $valor;
    }
}
