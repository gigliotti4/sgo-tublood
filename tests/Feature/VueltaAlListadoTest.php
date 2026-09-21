<?php

namespace Tests\Feature;

use App\Models\Articulo;
use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * No perder los filtros ni la página al escribir.
 *
 * Los filtros, el orden y `?page=` viven en la URL del listado, pero el
 * redirect posterior a una escritura los descartaba y devolvía a la página 1.
 * Hay dos mecanismos, y estos tests fijan los dos:
 *
 * - **`back(fallback: …)`** para lo que se dispara desde el propio listado
 *   (modales, import, sync, borrado). Resuelve por el header Referer.
 * - **El parámetro `volver`** para las fichas de edición, que son otra URL —
 *   ver el trait `App\Http\Controllers\Concerns\VuelveAlListado`.
 *
 * ⚠️ `->from(...)` es lo que simula "el usuario venía de acá": escribe
 * `_previous.url` en la sesión, que es la otra pata de `UrlGenerator::previous()`
 * además del Referer.
 */
class VueltaAlListadoTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function observacion(array $attrs = []): Observacion
    {
        return Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'clasificada',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Observación de prueba',
            'descripcion' => 'Descripción',
            ...$attrs,
        ]);
    }

    // ── Familia A: la acción sale del listado ───────────────────────────────

    public function test_guardar_una_observacion_desde_el_modal_vuelve_al_listado_filtrado(): void
    {
        $user = $this->userWith('observaciones.view', 'observaciones.edit');
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $listado = '/observaciones?page=2&prioridad=critica&sort=numero&dir=asc';

        $this->actingAs($user)
            ->from($listado)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => $user->id,
                'estado' => 'en_proceso',
            ])
            ->assertRedirect($listado);
    }

    /**
     * El `fallback` de `back()`. Sin él, un request sin Referer ni sesión
     * previa (los tests, un cliente que no lo manda) terminaría en `/` — y es
     * lo que mantiene verdes las decenas de assertions que ya existían.
     */
    public function test_sin_referer_el_guardado_cae_en_el_listado_pelado(): void
    {
        $user = $this->userWith('observaciones.view', 'observaciones.edit');
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->put("/observaciones/{$observacion->id}", [
                'responsable_id' => $user->id,
                'estado' => 'en_proceso',
            ])
            ->assertRedirect(route('observaciones.index'));
    }

    public function test_borrar_una_observacion_vuelve_a_la_misma_pagina(): void
    {
        $user = $this->userWith('observaciones.view', 'observaciones.delete');
        $observacion = $this->observacion();

        $listado = '/observaciones?page=3&origen=externa';

        $this->actingAs($user)
            ->from($listado)
            ->delete("/observaciones/{$observacion->id}", ['motivo' => 'Duplicada del 0002.'])
            ->assertRedirect($listado);
    }

    public function test_restaurar_una_baja_vuelve_al_listado_filtrado(): void
    {
        $user = $this->userWith('observaciones.delete');
        $observacion = $this->observacion();
        $observacion->delete();

        $listado = '/bajas?page=2&tipo=borrada';

        $this->actingAs($user)
            ->from($listado)
            ->post(route('bajas.restore', $observacion->id))
            ->assertRedirect($listado);
    }

    /**
     * El caso que rompe la regla intuitiva: es un `store()` y aun así usa
     * `back()`, porque el alta de un sector es un modal sobre el listado. Lo
     * que manda es de dónde sale la acción, no si crea o edita.
     */
    public function test_crear_un_sector_desde_el_modal_no_saca_del_listado(): void
    {
        $user = $this->userWith('users.view', 'users.edit');

        $listado = '/sectores?orden=nombre';

        $this->actingAs($user)
            ->from($listado)
            ->post(route('sectores.store'), ['nombre' => 'Compras', 'dias_gestion' => 5, 'activo' => true])
            ->assertRedirect($listado);
    }

    public function test_borrar_un_usuario_vuelve_al_listado_filtrado(): void
    {
        $user = $this->userWith('users.view', 'users.delete');
        $otro = User::factory()->create();

        $listado = '/users?page=2&sort=email&dir=asc';

        $this->actingAs($user)
            ->from($listado)
            ->delete(route('users.destroy', $otro->id))
            ->assertRedirect($listado);
    }

    // ── Familia B: la ficha devuelve `volver` ───────────────────────────────

    public function test_guardar_un_cliente_vuelve_al_listado_con_los_filtros_que_traia(): void
    {
        $user = $this->userWith('clientes.view', 'clientes.edit');
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Acme SA']);

        $volver = 'page=3&search=acme&sort=razon_social&dir=asc';

        $this->actingAs($user)
            ->put(route('clientes.update', $cliente).'?volver='.urlencode($volver), [
                'notas' => 'Llamar el lunes.',
            ])
            ->assertRedirect(route('clientes.index', [
                'page' => 3, 'search' => 'acme', 'sort' => 'razon_social', 'dir' => 'asc',
            ]));
    }

    /** Entrar a la ficha por URL directa no trae `volver`: se cae al listado pelado. */
    public function test_sin_volver_el_guardado_cae_en_el_listado_pelado(): void
    {
        $user = $this->userWith('clientes.view', 'clientes.edit');
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Acme SA']);

        $this->actingAs($user)
            ->put(route('clientes.update', $cliente), ['notas' => 'x'])
            ->assertRedirect(route('clientes.index'));
    }

    /**
     * La URL base sale siempre de `route()` y `volver` se reparsea como query:
     * por más que traiga una URL entera, el usuario termina en nuestro listado.
     */
    public function test_un_volver_absurdo_no_saca_al_usuario_del_sitio(): void
    {
        $user = $this->userWith('clientes.view', 'clientes.edit');
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Acme SA']);

        $respuesta = $this->actingAs($user)
            ->put(route('clientes.update', $cliente).'?volver='.urlencode('https://evil.com/phishing'), [
                'notas' => 'x',
            ]);

        $this->assertStringStartsWith(url('/clientes'), $respuesta->headers->get('Location'));
    }

    public function test_un_volver_larguisimo_se_ignora_entero(): void
    {
        $user = $this->userWith('clientes.view', 'clientes.edit');
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Acme SA']);

        // Recortarlo dejaría una URL a medias; ignorarlo deja el listado pelado,
        // que es una degradación usable.
        $this->actingAs($user)
            ->put(route('clientes.update', $cliente).'?volver='.str_repeat('a', 2000), ['notas' => 'x'])
            ->assertRedirect(route('clientes.index'));
    }

    /** Barato, y blinda contra un `$fillable` más laxo en el futuro. */
    public function test_volver_no_se_guarda_como_campo_del_modelo(): void
    {
        $user = $this->userWith('clientes.view', 'clientes.edit');
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Acme SA']);

        $this->actingAs($user)
            ->put(route('clientes.update', $cliente).'?volver=page%3D3', ['notas' => 'x']);

        $this->assertArrayNotHasKey('volver', $cliente->fresh()->getAttributes());
    }

    /** El trait cubierto en un segundo consumidor, no en uno solo. */
    public function test_guardar_un_articulo_tambien_vuelve_con_sus_filtros(): void
    {
        $user = $this->userWith('articulos.view', 'articulos.edit');
        $articulo = Articulo::create(['codigo' => 'RE-1', 'descripcion' => 'AGUJA', 'activo' => true]);

        $volver = 'page=2&estado=activos';

        $this->actingAs($user)
            ->put(route('articulos.update', $articulo).'?volver='.urlencode($volver), ['pm' => '236-80'])
            ->assertRedirect(route('articulos.index', ['page' => 2, 'estado' => 'activos']));
    }

    // ── Acciones internas de la ficha ───────────────────────────────────────

    /**
     * Subir un adjunto tiene que dejar al usuario **en la ficha** y conservar
     * su `?volver=`. Es el test que impide que alguien lo "arregle" volviéndolo
     * a `redirect()->route('clientes.edit', $cliente)`, que perdería el
     * parámetro y rompería la vuelta al listado en silencio.
     */
    public function test_subir_un_archivo_deja_al_usuario_en_la_ficha_con_su_volver(): void
    {
        $user = $this->userWith('clientes.view', 'clientes.edit');
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Acme SA']);

        $ficha = route('clientes.edit', $cliente).'?volver=page%3D3';

        $this->actingAs($user)
            ->from($ficha)
            ->post(route('clientes.archivos.store', $cliente), [
                'archivos' => [UploadedFile::fake()->create('legajo.pdf', 10, 'application/pdf')],
            ])
            ->assertRedirect($ficha);
    }

    // ── Sincronizar tampoco puede tirar los filtros ─────────────────────────

    public function test_sincronizar_deja_al_usuario_donde_estaba(): void
    {
        // Sin el fake, `sync()` despacha el job en la conexión `sync` de los
        // tests y este sale a pegarle de verdad al ERP: 70 segundos de timeouts
        // y reintentos para probar un redirect.
        Queue::fake();

        $user = $this->userWith('clientes.view', 'clientes.sync');

        $listado = '/clientes?page=4&search=lab&sort=cuit&dir=desc';

        $this->actingAs($user)
            ->from($listado)
            ->post(route('clientes.sync'))
            ->assertRedirect($listado);
    }
}
