<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_requiere_autenticacion(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_muestra_estadisticas_reales_de_observaciones(): void
    {
        $user = User::factory()->create();

        Observacion::create([
            'numero' => '0001-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
            'tecnovigilancia' => true,
            'responsable_id' => $user->id,
        ]);

        Observacion::create([
            'numero' => '0002-26',
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'resuelta',
            'contacto_nombre' => 'Cliente Test 2',
            'contacto_email' => 'cliente2@example.com',
            'titulo' => 'Título de prueba 2',
            'descripcion' => 'Descripción de prueba 2',
            'prioridad' => 'critica',
        ]);

        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('stats.total', 2)
                ->where('stats.abiertas', 1)
                ->where('stats.resueltas', 1)
                ->where('stats.asignadasAMi', 1)
                ->where('kpis.tecnovigilancia', 1)
                ->where('kpis.critica', 1)
                ->has('ultimas', 2)
                ->has('asignadas', 1)
            );
    }
}
