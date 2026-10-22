// Lado TS de la comparación: corre filtrar() de lib/compras.ts (empaquetado por esbuild en la carpeta
// de salida) sobre el mismo dataset y los mismos filtros que usó PHP, y compara fila por fila.
//
//   node comparar.mjs <carpeta-de-salida>
//
// Sale con código 1 si hay cualquier diferencia: cantidad de filas, un campo, o el orden.

import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'

const out = process.argv[2]
const { filtrar, filtrosPorDefecto } = await import(pathToFileURL(join(out, 'compras.mjs')).href)
const d = JSON.parse(readFileSync(join(out, 'dataset.json'), 'utf8'))
const php = JSON.parse(readFileSync(join(out, 'php.json'), 'utf8'))
const n = d.meses.length

const igual = (a, b) =>
  typeof a === 'number' && typeof b === 'number'
    ? Math.abs(a - b) < 1e-6 * Math.max(1, Math.abs(a))
    : JSON.stringify(a) === JSON.stringify(b)

let ok = true
for (const [nombre, { f: fphp, filas: filasPhp }] of Object.entries(php)) {
  const f = { ...filtrosPorDefecto(fphp.mesesObjetivo, n - 1), ...fphp, q: '' }
  const { filas } = filtrar(d.groups, f, n, (c) => c)
  const ts = filas.map((r) => ({
    id: r.grupo.id, stockU: r.stockU, reservado: r.reservado, ocPend: r.ocPend, stockTotal: r.stockTotal,
    ventaPeriodo: r.ventaPeriodo, promMensual: r.promMensual, meses: r.meses === Infinity ? 'INF' : r.meses,
    estado: r.estado, cantComprar: r.cantComprar, importe: r.importe, pareto: r.pareto, anomalia: r.anomalia,
    estimada: r.estimada, proximaEntrega: r.proximaEntrega, proveedores: r.proveedores, proveedor: r.proveedor, items: r.items.length,
  }))

  let difs = 0
  if (ts.length !== filasPhp.length) {
    console.log(`${nombre}: cantidad distinta — TS ${ts.length} vs PHP ${filasPhp.length}`)
    ok = false
  }
  const porId = new Map(filasPhp.map((r) => [r.id, r]))
  for (const r of ts) {
    const p = porId.get(r.id)
    if (!p) {
      if (++difs < 4) console.log(`${nombre}: ${r.id} está en TS y no en PHP`)
      continue
    }
    for (const k of Object.keys(r)) {
      if (!igual(r[k], p[k]) && ++difs < 6) {
        console.log(`${nombre}: ${r.id}.${k}  TS=${JSON.stringify(r[k])}  PHP=${JSON.stringify(p[k])}`)
      }
    }
  }
  const mismoOrden = ts.map((r) => r.id).join() === filasPhp.map((r) => r.id).join()
  console.log(`${nombre}: ${ts.length} filas · ${difs} diferencias · ${mismoOrden ? 'mismo orden' : 'ORDEN DISTINTO'}`)
  if (difs || !mismoOrden) ok = false
}

console.log(ok ? '\nPARIDAD OK' : '\nHAY DIFERENCIAS')
process.exit(ok ? 0 : 1)
