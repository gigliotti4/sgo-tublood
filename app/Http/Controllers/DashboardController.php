<?php

namespace App\Http\Controllers;

use App\Models\Observacion;

class DashboardController extends Controller
{
    private const ESTADOS_ABIERTOS = ['pendiente_clasificacion', 'clasificada', 'en_proceso', 'derivada'];

    private const ESTADOS_RESUELTOS = ['resuelta', 'cerrada'];

    public function index()
    {
        $asignadasAMi = Observacion::query()
            ->where('responsable_id', auth()->id())
            ->latest()
            ->limit(8)
            ->get(['id', 'numero', 'tipo', 'estado', 'titulo', 'created_at']);

        return inertia('Dashboard', [
            'stats' => [
                'total' => Observacion::count(),
                'abiertas' => Observacion::whereIn('estado', self::ESTADOS_ABIERTOS)->count(),
                'resueltas' => Observacion::whereIn('estado', self::ESTADOS_RESUELTOS)->count(),
                'asignadasAMi' => Observacion::where('responsable_id', auth()->id())->count(),
                // TODO: requiere tabla non_conformities (pendiente).
                'nc' => 0,
                'ncAbiertas' => 0,
            ],
            'kpis' => [
                // TODO: requiere sla_configs para calcular el % de cumplimiento (pendiente).
                'tiempoSla' => null,
                'tecnovigilancia' => Observacion::where('tecnovigilancia', true)->count(),
                'critica' => Observacion::where('prioridad', 'critica')->count(),
                'sinClasificar' => Observacion::where('estado', 'pendiente_clasificacion')->count(),
            ],
            'porEstado' => collect(Observacion::ESTADOS)
                ->except('cancelada')
                ->map(fn ($label, $estado) => [
                    'estado' => $estado,
                    'label' => $label,
                    'count' => Observacion::where('estado', $estado)->count(),
                ])
                ->values(),
            // TODO: requiere tabla sectors (pendiente).
            'porSector' => [],
            'asignadas' => $asignadasAMi,
            'ultimas' => Observacion::query()
                ->latest()
                ->limit(8)
                ->get(['id', 'numero', 'tipo', 'estado', 'titulo', 'created_at']),
        ]);
    }
}
