<?php

namespace App\Services;

use App\Models\Sector;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga masiva de usuarios desde el Excel de Tublood, leído por posición
 * (A=Nombre, B=Apellido, C=Mail, D=Sector original, E=Supervisor,
 * F=Gerente aviso final, G=Tiempo de gestión), salteando la fila de encabezado.
 *
 * Upsert por email: las filas nuevas crean el usuario con una contraseña
 * aleatoria y el rol elegido al importar; las filas que matchean un email
 * existente actualizan sus datos, sin tocar contraseña ni roles. Las columnas
 * que vengan vacías no pisan lo que ya había, así que un archivo viejo de tres
 * columnas sigue funcionando igual que antes.
 *
 * Va en dos pasadas porque el archivo tiene referencias hacia adelante: hay
 * filas que nombran como supervisor a alguien que recién aparece más abajo.
 *
 * Las contraseñas generadas se devuelven en texto plano porque es la única vez
 * que se pueden ver: se guardan hasheadas y en dev no hay envío de mails.
 */
class UserImportService
{
    /** Plazos ya fijados en esta corrida, para detectar filas en conflicto. */
    private array $plazosDelArchivo = [];

    private array $advertencias = [];

    public function import(UploadedFile $file, string $rol): array
    {
        $this->plazosDelArchivo = [];
        $this->advertencias = [];

        $filas = IOFactory::load($file->getRealPath())
            ->getActiveSheet()
            ->toArray();

        $creados = [];
        $actualizados = 0;
        $emailsDelArchivo = [];
        $vinculos = [];

        foreach (array_slice($filas, 1) as $index => $fila) {
            $numeroFila = $index + 2;

            $nombre = $this->limpiar($fila[0] ?? null);
            $apellido = $this->limpiar($fila[1] ?? null);
            $email = mb_strtolower((string) $this->limpiar($fila[2] ?? null));
            $sector = $this->limpiar($fila[3] ?? null);
            $supervisor = $this->limpiar($fila[4] ?? null);
            $gerente = $this->limpiar($fila[5] ?? null);
            $dias = $this->diasDeGestion($fila[6] ?? null);

            if (! $nombre && ! $apellido && ! $email) {
                continue;
            }

            if (! $nombre || ! $email) {
                $this->advertencias[] = "Fila {$numeroFila}: falta nombre o mail, se omitió.";

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->advertencias[] = "Fila {$numeroFila}: '{$email}' no es un mail válido, se omitió.";

                continue;
            }

            // Un mismo mail repetido en el archivo crearía y pisaría al mismo
            // usuario dos veces; mejor avisar que dejarlo pasar en silencio.
            if (isset($emailsDelArchivo[$email])) {
                $this->advertencias[] = "Fila {$numeroFila}: el mail '{$email}' ya aparece en la fila {$emailsDelArchivo[$email]}, se omitió.";

                continue;
            }
            $emailsDelArchivo[$email] = $numeroFila;

            $atributos = ['name' => $nombre, 'apellido' => $apellido];

            if ($sector) {
                $sectorModel = $this->sector($sector, $dias, $numeroFila);

                if ($sectorModel) {
                    $atributos['sector_id'] = $sectorModel->id;
                }
            }

            // "si" en la columna Supervisor marca a los gerentes de la empresa:
            // son cabeza de la cadena, no tienen a nadie por encima.
            $esGerente = $supervisor !== null && $this->clave($supervisor) === 'si';

            if ($supervisor !== null) {
                $atributos['es_gerente'] = $esGerente;
            }

            $existente = User::where('email', $email)->first();

            if ($existente) {
                $existente->update($atributos);
                $actualizados++;
            } else {
                $password = Str::password(12, symbols: false);

                $user = User::create([...$atributos, 'email' => $email, 'password' => $password]);
                $user->assignRole($rol);

                $creados[] = [
                    'nombre' => trim("{$nombre} {$apellido}"),
                    'email' => $email,
                    'password' => $password,
                ];
            }

            $vinculos[$email] = [
                'fila' => $numeroFila,
                'supervisor' => $esGerente ? null : $supervisor,
                'gerente' => $gerente,
            ];
        }

        $this->vincular($vinculos);

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'advertencias' => $this->advertencias,
        ];
    }

    /**
     * Segunda pasada: resuelve supervisor y gerente, que en el Excel vienen como
     * texto ("BARBARA NEGRIN", "EMANUEL") y pueden apuntar a filas posteriores.
     */
    private function vincular(array $vinculos): void
    {
        if ($vinculos === []) {
            return;
        }

        $indices = $this->indicesDeNombres();

        foreach ($vinculos as $email => $datos) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                continue;
            }

            $cambios = [];

            foreach (['supervisor' => 'supervisor_id', 'gerente' => 'gerente_id'] as $columna => $campo) {
                if (! $datos[$columna]) {
                    continue;
                }

                [$id, $error] = $this->resolverUsuario($datos[$columna], $indices);

                if ($error) {
                    $this->advertencias[] = "Fila {$datos['fila']}: {$columna} — {$error}.";

                    continue;
                }

                // Solo la cadena de supervisores se recorre al escalar, así que
                // es la única donde un círculo dejaría al motor de alertas girando.
                if ($campo === 'supervisor_id' && $user->generariaCiclo($id)) {
                    $this->advertencias[] = "Fila {$datos['fila']}: '{$datos[$columna]}' como supervisor cerraría un círculo de escalamiento, se dejó sin asignar.";

                    continue;
                }

                $cambios[$campo] = $id;
            }

            if ($cambios !== []) {
                $user->update($cambios);
            }
        }
    }

    /**
     * Índices de búsqueda por nombre completo y por nombre de pila: el Excel
     * referencia de las dos formas ("BARBARA NEGRIN" pero "EMANUEL").
     */
    private function indicesDeNombres(): array
    {
        $indices = ['completos' => [], 'pila' => []];

        foreach (User::get(['id', 'name', 'apellido']) as $usuario) {
            $indices['completos'][$this->clave("{$usuario->name} {$usuario->apellido}")][] = $usuario->id;
            $indices['pila'][$this->clave($usuario->name)][] = $usuario->id;
        }

        return $indices;
    }

    /** @return array{0: ?int, 1: ?string} [id encontrado, motivo del fallo] */
    private function resolverUsuario(string $texto, array $indices): array
    {
        $clave = $this->clave($texto);

        foreach (['completos', 'pila'] as $indice) {
            $coincidencias = $indices[$indice][$clave] ?? [];

            if (count($coincidencias) === 1) {
                return [$coincidencias[0], null];
            }

            if (count($coincidencias) > 1) {
                return [null, "hay más de un usuario llamado '{$texto}', no se puede saber cuál es"];
            }
        }

        return [null, "no existe ningún usuario llamado '{$texto}'"];
    }

    /**
     * Resuelve el "sector original" del Excel contra el catálogo fijo de
     * `sectors` (vía `config('organizacion.alias_sectores')` o por slug
     * directo). El catálogo no se amplía desde acá: un nombre que no matchea
     * ningún sector deja al usuario sin sector, con advertencia.
     *
     * El plazo viene por fila. Dentro de una misma corrida gana la primera
     * fila que lo trae; entre corridas distintas, el archivo nuevo pisa lo
     * que había.
     */
    private function sector(string $nombre, ?int $dias, int $fila): ?Sector
    {
        $slug = config('organizacion.alias_sectores.'.Str::slug($nombre), Str::slug($nombre));

        $sector = Sector::where('slug', $slug)->first();

        if (! $sector) {
            $this->advertencias[] = "Fila {$fila}: el sector '{$nombre}' no está en el catálogo, el usuario quedó sin sector.";

            return null;
        }

        if ($dias === null) {
            return $sector;
        }

        if (! isset($this->plazosDelArchivo[$slug])) {
            $this->plazosDelArchivo[$slug] = $dias;
            $sector->update(['dias_gestion' => $dias]);

            return $sector;
        }

        if ($this->plazosDelArchivo[$slug] !== $dias) {
            $this->advertencias[] = "Fila {$fila}: el sector '{$sector->nombre}' ya venía con {$this->plazosDelArchivo[$slug]} días en este archivo, se ignoró el valor {$dias}.";
        }

        return $sector;
    }

    /** "3 días" → 3. */
    private function diasDeGestion(mixed $valor): ?int
    {
        if (! preg_match('/\d+/', (string) $valor, $match)) {
            return null;
        }

        return (int) $match[0] ?: null;
    }

    /** Clave de comparación de nombres: sin acentos, en minúscula y sin espacios de más. */
    private function clave(string $texto): string
    {
        return (string) Str::of($texto)->ascii()->lower()->squish();
    }

    private function limpiar(mixed $valor): ?string
    {
        $valor = Str::squish((string) $valor);

        return ($valor === '' || $valor === '-') ? null : $valor;
    }
}
