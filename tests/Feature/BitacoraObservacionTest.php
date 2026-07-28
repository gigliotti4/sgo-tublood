<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BitacoraObservacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function observacion(array $atributos = []): Observacion
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
            ...$atributos,
        ]);
    }

    public function test_cambiar_el_estado_deja_su_entrada_con_de_y_a(): void
    {
        $observacion = $this->observacion();

        $observacion->update(['estado' => 'en_proceso']);

        $entrada = $observacion->historial()->latest()->first();
        $this->assertSame(ObservationHistory::ACCION_ESTADO, $entrada->accion);
        $this->assertSame('Pendiente de clasificación', $entrada->cambios['de']);
        $this->assertSame('En proceso', $entrada->cambios['a']);
    }

    public function test_cambiar_el_responsable_deja_su_entrada_con_los_nombres(): void
    {
        $observacion = $this->observacion();
        $nuevo = User::factory()->create(['name' => 'Juana Pérez']);

        $observacion->update(['responsable_id' => $nuevo->id]);

        $entrada = $observacion->historial()->latest()->first();
        $this->assertSame(ObservationHistory::ACCION_RESPONSABLE, $entrada->accion);
        $this->assertSame('Sin asignar', $entrada->cambios['de']);
        $this->assertSame('Juana Pérez', $entrada->cambios['a']);
    }

    public function test_cambiar_el_sector_deja_su_entrada_con_los_nombres(): void
    {
        $sector = Sector::create(['nombre' => 'Logística', 'slug' => 'logistica']);
        $observacion = $this->observacion();

        $observacion->update(['sector_id' => $sector->id]);

        $entrada = $observacion->historial()->latest()->first();
        $this->assertSame(ObservationHistory::ACCION_SECTOR, $entrada->accion);
        $this->assertSame('Sin sector', $entrada->cambios['de']);
        $this->assertSame('Logística', $entrada->cambios['a']);
    }

    public function test_clasificar_prioridad_y_tipo_de_caso_deja_una_sola_entrada(): void
    {
        $observacion = $this->observacion();

        $observacion->update(['prioridad' => 'alta', 'tipo_caso' => 'Producto defectuoso']);

        $entradas = $observacion->historial;
        $this->assertCount(1, $entradas);
        $this->assertSame(ObservationHistory::ACCION_CLASIFICACION, $entradas->first()->accion);
        $this->assertSame('Sin clasificar', $entradas->first()->cambios['prioridad']['de']);
        $this->assertSame('Alta', $entradas->first()->cambios['prioridad']['a']);
        $this->assertSame('Producto defectuoso', $entradas->first()->cambios['tipo_caso']['a']);
    }

    /** No hay entrada al crear: la bitácora arranca en el primer cambio, no en el alta. */
    public function test_crear_la_observacion_no_deja_entradas(): void
    {
        $observacion = $this->observacion(['responsable_id' => User::factory()->create()->id]);

        $this->assertCount(0, $observacion->historial);
    }

    public function test_un_cambio_sin_usuario_autenticado_queda_sin_autor(): void
    {
        $observacion = $this->observacion();

        // Sin actingAs(): simula lo que haría un comando o un job.
        $observacion->update(['estado' => 'cerrada']);

        $this->assertNull($observacion->historial()->latest()->first()->user_id);
    }

    public function test_comentar_crea_una_entrada_con_la_nota(): void
    {
        $user = $this->userWith('observaciones.view');
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'Llamé al cliente, va a reenviar la factura.'])
            ->assertRedirect();

        $entrada = $observacion->historial()->latest()->first();
        $this->assertSame(ObservationHistory::ACCION_COMENTARIO, $entrada->accion);
        $this->assertSame('Llamé al cliente, va a reenviar la factura.', $entrada->nota);
        $this->assertSame($user->id, $entrada->user_id);
    }

    /** Los adjuntos del comentario quedan atados a su entrada, no sueltos en el listado general. */
    public function test_comentar_con_archivos_los_ata_a_la_entrada(): void
    {
        $user = $this->userWith('observaciones.view');
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)->post("/observaciones/{$observacion->id}/bitacora", [
            'nota' => 'Adjunto la nota de crédito.',
            'archivos' => [UploadedFile::fake()->create('nota-credito.pdf', 50, 'application/pdf')],
        ]);

        $entrada = $observacion->historial()->latest()->first();
        $this->assertCount(1, $entrada->adjuntos);
        $this->assertSame('nota-credito.pdf', $entrada->adjuntos->first()->original_name);
        $this->assertSame($user->id, $entrada->adjuntos->first()->user_id);

        // Va a una subcarpeta propia, separado de los adjuntos sueltos de la
        // observación (que quedan directo en observaciones/{numero}/).
        $this->assertSame('observaciones/0001-26/bitacora/nota-credito.pdf', $entrada->adjuntos->first()->path);
        Storage::disk('local')->assertExists($entrada->adjuntos->first()->path);

        // No aparece como adjunto suelto: attachments() sin filtrar los trae a
        // todos, pero el que usa la pantalla (whereNull) no debería incluirlo.
        $this->assertCount(1, $observacion->fresh()->attachments);
    }

    public function test_comentar_sin_nota_ni_archivos_se_rechaza(): void
    {
        $user = $this->userWith('observaciones.view');
        $observacion = $this->observacion(['responsable_id' => $user->id]);

        $this->actingAs($user)
            ->post("/observaciones/{$observacion->id}/bitacora", [])
            ->assertSessionHasErrors('nota');

        $this->assertCount(0, $observacion->fresh()->historial);
    }

    public function test_un_usuario_del_mismo_sector_puede_comentar_aunque_no_sea_el_responsable(): void
    {
        $sector = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial']);
        $colega = $this->userWith('observaciones.view');
        $colega->update(['sector_id' => $sector->id]);

        $observacion = $this->observacion(['sector_id' => $sector->id]);

        $this->actingAs($colega)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'Lo tomo yo.'])
            ->assertRedirect();

        $this->assertCount(1, $observacion->fresh()->historial);
    }

    public function test_un_usuario_de_otro_sector_no_puede_comentar(): void
    {
        $sector = Sector::create(['nombre' => 'Comercial', 'slug' => 'comercial']);
        $otroSector = Sector::create(['nombre' => 'COMEX', 'slug' => 'comex']);

        $ajeno = $this->userWith('observaciones.view');
        $ajeno->update(['sector_id' => $otroSector->id]);

        $observacion = $this->observacion(['sector_id' => $sector->id]);

        $this->actingAs($ajeno)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'No debería poder.'])
            ->assertStatus(403);
    }

    /** Dos observaciones sin sector no "matchean" entre sí solo porque las dos son null. */
    public function test_un_usuario_sin_sector_no_puede_comentar_una_observacion_sin_sector(): void
    {
        $user = $this->userWith('observaciones.view');
        $observacion = $this->observacion();

        $this->actingAs($user)
            ->post("/observaciones/{$observacion->id}/bitacora", ['nota' => 'No debería poder.'])
            ->assertStatus(403);
    }

    public function test_una_entrada_de_historial_no_se_puede_editar(): void
    {
        $observacion = $this->observacion();
        $observacion->update(['estado' => 'en_proceso']);
        $entrada = $observacion->historial()->latest()->first();

        $this->expectException(RuntimeException::class);

        $entrada->nota = 'intento de edición';
        $entrada->save();
    }

    public function test_una_entrada_de_historial_no_se_puede_borrar(): void
    {
        $observacion = $this->observacion();
        $observacion->update(['estado' => 'en_proceso']);
        $entrada = $observacion->historial()->latest()->first();

        $this->expectException(RuntimeException::class);

        $entrada->delete();
    }
}
