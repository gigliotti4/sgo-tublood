<script setup lang="ts">
import { computed } from 'vue'
import Sparkline from '@/Components/Sparkline.vue'
import { detalleDeItem, type FiltrosCompras } from '@/lib/compras'
import type { ArticuloReposicion } from '@/types'
import { decimal, numero } from '@/lib/formato'

/**
 * Un artículo dentro de un producto unificado.
 *
 * Es un componente y no un bloque más del template de `TablaReposicion` para
 * poder calcular `detalleDeItem()` UNA vez por artículo: inline habría que
 * llamarlo en cada una de las 13 celdas que lo usan.
 *
 * Cobertura, meses y cantidad a comprar quedan vacías: son del grupo. Un
 * artículo suelto de un grupo multimarca no tiene cobertura propia — el cliente
 * puede recibir cualquiera de las marcas.
 */
const props = defineProps<{
    item: ArticuloReposicion
    filtros: FiltrosCompras
    totalMeses: number
    mostrarMeses: boolean
    catName: (codigo: string) => string
}>()

const d = computed(() => detalleDeItem(props.item, props.filtros, props.totalMeses))

const td = 'whitespace-nowrap px-1.5 py-2 text-right text-[11.5px] tabular-nums'
const sep = 'border-l border-gray-200 dark:border-gray-700'

const fueraDelPeriodo = (i: number) => i < props.filtros.desde || i > props.filtros.hasta
</script>

<template>
    <tr class="border-b border-gray-100 bg-gray-50 text-gray-600 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-400">
        <td class="px-1.5 py-2 pl-6 text-left text-[11.5px]">
            <strong>{{ item.c }}</strong> · {{ item.d }}
            <span v-if="!item.a" class="text-gray-400">(inactivo)</span>
        </td>
        <td class="px-1.5 py-2 text-left">
            <span class="rounded-full bg-gray-100 px-1.5 py-px text-[10px] font-semibold text-gray-600 dark:bg-white/[0.06] dark:text-gray-400">
                {{ catName(item.k) }}
            </span>
        </td>
        <td />
        <td :class="td">
            <span v-if="d.envase > 1">{{ numero(d.envase) }}</span>
            <span v-else class="text-gray-400">–</span>
        </td>
        <td :class="td">{{ numero(d.stockU) }}</td>
        <td :class="td">{{ numero(d.stockEnv) }}</td>
        <td :class="[td, sep]">
            <Sparkline :valores="d.ventas" :desde="filtros.desde" :hasta="filtros.hasta" />
        </td>

        <template v-if="mostrarMeses">
            <td
                v-for="(v, i) in d.ventas"
                :key="i"
                :class="td"
                :style="fueraDelPeriodo(i) ? 'opacity:.4' : ''"
            >{{ numero(v) }}</td>
        </template>
        <td v-else :class="td">{{ numero(d.ventaPeriodo) }}</td>

        <td :class="td">{{ decimal(d.promMensual) }}</td>
        <td :class="[td, sep]">{{ numero(d.reservado) }}</td>
        <td :class="td">{{ numero(d.ocPend) }}</td>
        <td :class="td">{{ numero(d.stockTotal) }}</td>
        <td :class="sep" />
        <td />
        <td />
    </tr>
</template>
