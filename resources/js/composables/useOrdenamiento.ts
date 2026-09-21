import { computed } from 'vue'
import { router } from '@inertiajs/vue3'

export type Direccion = 'asc' | 'desc'

/**
 * El orden que devuelve el backend.
 *
 * `sort` viene **ya validado** contra la whitelist del servidor (ver el trait
 * `OrdenaListados`): es null cuando la pantalla está en su orden por defecto,
 * así la flecha del encabezado no puede mentir cuando la URL trae una clave
 * que esa pantalla no soporta.
 */
export interface OrdenVigente {
    sort: string | null
    dir: Direccion
}

interface Opciones {
    /** Nombre de ruta Ziggy del listado: 'articulos.index', 'bajas.index', … */
    ruta: string
    /** Getter, no valor: tiene que seguir a las props en cada navegación de Inertia. */
    orden: () => OrdenVigente
    /**
     * Los filtros vigentes de la pantalla, también como getter. Viajan de nuevo
     * en cada click: sin esto, ordenar limpiaría la búsqueda.
     */
    parametros: () => Record<string, unknown>
    /**
     * Claves que arrancan ascendentes la primera vez que se las elige. Los
     * textos se leen A→Z; los números y las fechas, de mayor a menor, que es lo
     * que se busca (el más reciente, el más caro). Mismo criterio que el
     * ordenamiento de Compras (`Pages/Admin/Compras/Index.vue`).
     */
    ascendentesPorDefecto?: readonly string[]
}

/**
 * Estado y transporte del ordenamiento por columna de un listado paginado en
 * el servidor.
 *
 * Va separado de `Components/ThOrdenable.vue` a propósito: ese componente es
 * puro (recibe el orden, emite la clave clickeada) y no sabe de Inertia ni de
 * rutas. Gracias a eso, las pantallas que ordenan en el cliente —Sectores, que
 * manda las 9 filas enteras— usan el mismo `<th>` sin arrastrar un `router.get`
 * que ahí no tendría sentido.
 */
export function useOrdenamiento(opciones: Opciones) {
    const orden = computed<OrdenVigente>(opciones.orden)

    const ordenarPor = (campo: string) => {
        const dir: Direccion =
            orden.value.sort === campo
                ? orden.value.dir === 'asc'
                    ? 'desc'
                    : 'asc'
                : opciones.ascendentesPorDefecto?.includes(campo)
                  ? 'asc'
                  : 'desc'

        router.get(
            route(opciones.ruta),
            // `page` queda afuera a propósito: con otro orden, la página 3 ya
            // no contiene las mismas filas, y quedarse ahí se lee como si el
            // click no hubiera hecho nada. Se vuelve a la 1.
            { ...opciones.parametros(), sort: campo, dir },
            { preserveState: true, preserveScroll: true, replace: true },
        )
    }

    /**
     * Los dos parámetros listos para pegarlos en otro `router.get` (el de los
     * filtros) o en la URL del export: cambiar el buscador, o bajar el Excel,
     * no tienen por qué devolver la tabla al orden por defecto.
     *
     * `undefined` y no cadena vacía: Inertia y Ziggy descartan las claves
     * `undefined`, así que la URL queda limpia cuando no hay orden explícito.
     */
    const paramsDeOrden = computed(() => ({
        sort: orden.value.sort ?? undefined,
        dir: orden.value.sort ? orden.value.dir : undefined,
    }))

    return { orden, ordenarPor, paramsDeOrden }
}
