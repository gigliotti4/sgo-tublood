<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Padrón de proveedores.
 *
 * A diferencia de `clientes` y `articulos`, esta tabla **no se sincroniza con
 * RP Sistemas**: la API del ERP no expone proveedores, así que la única fuente
 * es la planilla Excel que se importa a mano (`ProveedorImportService`).
 *
 * El Excel real solo trae tres columnas (NUM_PROV, RAZON, DOMICILIO); el resto
 * son campos propios del panel, se cargan a mano desde `Admin/Proveedores/Edit`
 * y el import **no los pisa a propósito** — mismo cuidado que con las columnas
 * editables de `clientes`, que la sincronización tampoco toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();

            // Clave del import (NUM_PROV). String y no integer, igual que
            // `clientes.numero`: soporta códigos con prefijo o ceros a la
            // izquierda si algún día aparecen.
            $table->string('numero')->unique();
            $table->string('razon_social');
            $table->string('domicilio')->nullable();

            // Campos propios del panel: no vienen en el Excel.
            $table->string('cuit')->nullable();
            $table->string('telefono')->nullable();
            $table->string('mail')->nullable();
            $table->string('localidad')->nullable();
            $table->text('observaciones')->nullable();

            $table->timestamps();

            // El listado ordena y busca por razón social.
            $table->index('razon_social');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedores');
    }
};
