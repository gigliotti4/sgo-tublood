<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos de prórroga del plazo de una observación.
 *
 * Hay casos que no se pueden cerrar en plazo aunque se esté trabajando
 * (ensayos de laboratorio, esperar al proveedor o las muestras del cliente).
 * Hasta ahora se vencían igual, o se reasignaban para "reiniciar" el plazo, y
 * eso escondía las demoras. Ahora el responsable pide la prórroga y la autoriza
 * una persona puntual (permiso `observaciones.prorrogas.autorizar`).
 *
 * Una fila por pedido, con su resolución adentro. Se guardan TODOS —también los
 * rechazados— porque el tablero cuenta cuántas prórrogas hubo y por qué motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
            $table->foreignId('solicitada_por')->constrained('users');
            $table->date('fecha_pedida');
            $table->string('motivo');
            $table->string('que_se_hace');
            $table->text('justificacion');
            // Plazo vigente al momento de pedir: "Vencimiento actual: se completa solo".
            $table->dateTime('vencimiento_previo')->nullable();

            $table->string('estado')->default('pendiente');
            $table->foreignId('decidida_por')->nullable()->constrained('users');
            $table->dateTime('decidida_at')->nullable();
            // Puede ser distinta de la pedida: quien autoriza puede cambiarla.
            $table->date('fecha_autorizada')->nullable();
            $table->text('comentario')->nullable();

            $table->timestamps();

            $table->index(['observation_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_extensions');
    }
};
