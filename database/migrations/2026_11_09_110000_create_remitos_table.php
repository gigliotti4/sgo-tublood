<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Espejo de los remitos de venta del ERP, una fila por remito, para medir los
 * fallos frente al volumen real de entregas (tasa de fallos cada 100 remitos) y
 * para escalar el objetivo de armado, que es "1 % de los pedidos armados".
 *
 * Sale de `powerbi_mov_stock_vista` (vista con contrato) filtrada por las series
 * de remito (`VentaPartida::SERIES_DE_REMITO`: VR8, VRM, VR6) y agrupada por
 * comprobante. Una fila por remito y no por renglón: lo que se cuenta son
 * entregas, no artículos.
 *
 * Medido el 6/11/2026: 18.070 remitos VR8 desde el 01/11/2024 (740 a 1.100 por
 * mes, 217 a 261 clientes); el cliente de 9.218 de 9.225 remitos de 2026 está en
 * `clientes` (99,9 %). La base de la planilla de objetivos para el armado (883
 * pedidos) coincide con el promedio de remitos de septiembre y octubre (878).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remitos', function (Blueprint $table) {
            $table->id();
            $table->string('compro_nro', 30)->unique();
            // VR8, VRM o VR6. VNN ("Remito Prueba") nunca entra.
            $table->string('tipo', 10);
            $table->date('fecha')->index();
            // `clientes.numero`, como texto: es la clave con la que se cruza.
            $table->string('cliente_numero', 20)->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remitos');
    }
};
