<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('numero')->unique()->index(); // id del cliente en RP Sistemas
            $table->string('razon_social');
            $table->string('nombre_fantasia')->nullable();
            $table->string('cuit')->nullable()->index();
            $table->string('codigo_iva', 10)->nullable();
            $table->string('descripcion_iva')->nullable();
            $table->string('telefono')->nullable();
            $table->string('mail')->nullable();
            $table->string('contacto')->nullable();
            $table->string('domicilio')->nullable();
            $table->string('localidad')->nullable();
            $table->string('codigo_provincia', 10)->nullable();
            $table->string('descripcion_provincia')->nullable();
            $table->decimal('porcen_descuen', 8, 2)->nullable();
            $table->string('usuario_web')->nullable();
            $table->string('codigo_vendedor')->nullable();
            $table->string('nombre_vendedor')->nullable();
            $table->string('codigo_postal')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
