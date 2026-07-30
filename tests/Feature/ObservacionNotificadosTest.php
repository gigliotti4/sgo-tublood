<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionSeguimientoNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Usuarios a notificar de una observación: reciben el aviso al ser sumados y
 * pueden comentar en la bitácora, pero no gestionar el caso.
 */
class ObservacionNotificadosTest extends TestCase
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
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            ...$attrs,
        ]);
    }

    /** Payload mínimo de `update`, que valida `estado` como required. */
    private function datosUpdate(Observacion $observacion, array $attrs = []): array
    {
        return [
            'estado' => $observacion->estado,
            'responsable_id' => $observacion->responsable_id,
            'sector_id' => $observacion->sector_id,
            ...$attrs,
        ];
    }

    public function test_el_responsable_puede_sumar_usuarios_a_notificar(): void
    {
        Notification::fake();

        $responsable = $this->userWith('observaciones.view');
        $sumado = User::factory()->create();
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $this->actingAs($responsable)
            ->put("/observaciones/{$observacion->id}", $this->datosUpdate($observacion, [
                'notificados' => [$sumado->id],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($observacion->notificados()->whereKey($sumado->id)->exists());
        Notification::assertSentTo($sumado, ObservacionSeguimientoNotification::class);
    }

    /**
     * El aviso es de "te sumaron", no de "tocaron el caso": quien ya estaba no
     * puede recibir un mail cada vez que alguien guarda el formulario.
     */
    public function test_no_vuelve_a_avisar_a_quien_ya_estaba(): void
    {
        $responsable = $this->userWith('observaciones.view');
        $viejo = User::factory()->create();
        $nuevo = User::factory()->create();

        $observacion = $this->observacion(['responsable_id' => $responsable->id]);
        $observacion->notificados()->sync([$viejo->id]);

        Notification::fake();

        $this->actingAs($responsable)
            ->put("/observaciones/{$observacion->id}", $this->datosUpdate($observacion, [
                'notificados' => [$viejo->id, $nuevo->id],
            ]));

        Notification::assertSentTo($nuevo, ObservacionSeguimientoNotification::class);
        Notification::assertNotSentTo($viejo, ObservacionSeguimientoNotification::class);
    }

    public function test_un_usuario_a_notificar_puede_comentar_en_la_bitacora(): void
    {
        $observacion = $this->observacion();
        $notificado = $this->userWith('observaciones.view');
        $observacion->notificados()->sync([$notificado->id]);

        $this->actingAs($notificado)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'Reviso el lote y aviso.'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('observation_history', [
            'observation_id' => $observacion->id,
            'user_id' => $notificado->id,
            'accion' => 'comentario',
        ]);
    }

    /** Comentar sí, gestionar no: no reasigna, no reclasifica, no cambia estado. */
    public function test_un_usuario_a_notificar_no_puede_editar_la_observacion(): void
    {
        $observacion = $this->observacion();
        $notificado = $this->userWith('observaciones.view');
        $observacion->notificados()->sync([$notificado->id]);

        $this->actingAs($notificado)
            ->put("/observaciones/{$observacion->id}", $this->datosUpdate($observacion, ['estado' => 'cerrada']))
            ->assertStatus(403);

        $this->assertSame('pendiente_clasificacion', $observacion->refresh()->estado);
    }

    public function test_alguien_de_afuera_no_puede_comentar(): void
    {
        $observacion = $this->observacion();
        $ajeno = $this->userWith('observaciones.view');

        $this->actingAs($ajeno)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'No debería poder.'])
            ->assertStatus(403);
    }

    /** Quien gestiona el caso sigue pudiendo comentar sin estar en la lista. */
    public function test_el_responsable_puede_comentar_sin_estar_en_la_lista(): void
    {
        $responsable = $this->userWith('observaciones.view');
        $observacion = $this->observacion(['responsable_id' => $responsable->id]);

        $this->actingAs($responsable)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'Lo tomo yo.'])
            ->assertSessionHasNoErrors();
    }

    public function test_sacar_a_alguien_queda_registrado_en_la_bitacora(): void
    {
        Notification::fake();

        $responsable = $this->userWith('observaciones.view');
        $sacado = User::factory()->create(['name' => 'Juana', 'apellido' => 'Pérez']);

        $observacion = $this->observacion(['responsable_id' => $responsable->id]);
        $observacion->notificados()->sync([$sacado->id]);

        $this->actingAs($responsable)
            ->put("/observaciones/{$observacion->id}", $this->datosUpdate($observacion, ['notificados' => []]));

        $entrada = $observacion->historial()->where('accion', 'notificados')->latest()->first();

        $this->assertNotNull($entrada);
        $this->assertSame(['Juana Pérez'], $entrada->cambios['sacados']);
        $this->assertFalse($observacion->notificados()->whereKey($sacado->id)->exists());
    }

    /** Sin cambios reales no se ensucia la bitácora con una entrada vacía. */
    public function test_guardar_sin_tocar_la_lista_no_deja_entrada(): void
    {
        $responsable = $this->userWith('observaciones.view');
        $ya = User::factory()->create();

        $observacion = $this->observacion(['responsable_id' => $responsable->id]);
        $observacion->notificados()->sync([$ya->id]);

        $this->actingAs($responsable)
            ->put("/observaciones/{$observacion->id}", $this->datosUpdate($observacion, [
                'notificados' => [$ya->id],
            ]));

        $this->assertSame(0, $observacion->historial()->where('accion', 'notificados')->count());
    }

    /** Un usuario del sector de la observación ya podía editar: también comenta. */
    public function test_alguien_del_sector_puede_comentar(): void
    {
        $sector = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);
        $observacion = $this->observacion(['sector_id' => $sector->id]);

        $delSector = $this->userWith('observaciones.view');
        $delSector->update(['sector_id' => $sector->id]);

        $this->actingAs($delSector)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'Lo veo yo.'])
            ->assertSessionHasNoErrors();
    }
}
