# PROMPT — Dashboard de Compras / Reposición de Stock

> Copiá todo este texto y pegalo en el otro sistema. Es autocontenido: incluye
> las fuentes de datos, las reglas de negocio ya validadas y las trampas de
> datos que hay que evitar.

---

## CONTEXTO

Trabajo en una empresa que distribuye y fabrica insumos médicos de laboratorio
(tubos de extracción de sangre, agujas, apósitos, hisopos). Necesito un
**dashboard para el sector de Compras** que responda una sola pregunta:
**¿qué tengo que comprar, y cuánto?**

El ERP es RP Sistemas, sobre **SQL Server** (base `factu_tublood`).

## ENTREGABLE

Un **único archivo HTML autocontenido**, sin dependencias externas:
- Todo el CSS y el JS embebidos en el mismo archivo.
- **Sin librerías de CDN** (se abre desde una carpeta de red, muchas veces sin
  internet). Los gráficos se dibujan con HTML/CSS o SVG inline.
- **Sin `localStorage`** ni almacenamiento del navegador.
- Los datos van embebidos como JSON dentro del HTML.
- Se abre con doble clic y funciona offline.

---

## FUENTES DE DATOS — 4 vistas de SQL Server

### 1) `ARTICULOS` — maestro de artículos (~5.200 filas, 142 columnas)

| Columna | Uso |
|---|---|
| `COD_ARTICULO` | código del artículo (clave) |
| `DESCRIP_ARTI` | descripción |
| `CANT_STOCK` | stock actual, **en envases** |
| `AGRU_1` | código de categoría (ver tabla de mapeo abajo) |
| `SIN_STOCK` | 'S' = el producto NO mueve stock (servicios) |
| `ACTIVO` | 'S' / 'N' |
| `GTIN` | clasificación de texto para unificar multimarca |
| `CODIGO_REFERENCIA` | **unidades por envase** |

### 2) `powerbi_ordenescompra_pend_vista` — OC pendientes (~21.500 filas)
`ARTICULO`, `CANT_PEND` (cantidad pendiente de entrega), `TIPO` (OC = nacional,
OCI = importado), `UM_COMPRA` (unidad de compra).

### 3) `powerbi_pedidos_vista` — pedidos de clientes (~122.000 filas)
`articulo`, `cant_pend`, `reser` ('S'/'N'), `descrip_depo_reserva` (depósito de
la reserva), `estado`, `comprobante`, `fecha`.

### 4) `powerbi_ventas_vista` — ventas por artículo (~122.000 filas)
`articulo`, `fecha`, `cantidad` (**en envases**), `precio_neto` (precio
unitario), `sub_total` (total de la línea = cantidad × precio_neto, neto sin
IVA), `compro_nro` (número de comprobante, su prefijo indica el tipo).

### Mapeo de categorías (`AGRU_1`)

```
CARA=CARESTAINER ABIERTO     AGUJ=CARESTAINER AGUJAS    CAR=CARESTAINER VACIO
DIS=DISTRIBUCIÓN             FABAA=FABRICACIÓN APÓSITOS FAB=FABRICACIÓN TUBOS
HIS=HISOPOS RONGYE           IMP=IMPORTACIÓN            HEP=JERINGA CON HEPARINA
MAP=MATERIA PRIMA            NUT=NUTRICION              SERV=SERVICIOS PRESTADOS
ALLT=TEST RÁPIDOS            WEI=WEIGAO                 EQ=ZZZ EQUIPAMIENTO
JER=ZZZ JERINGA AYSET        RE=ZZZ NO SE USA           REAC=ZZZ REACTIVOS
9=ZZZ TIPO DE ARTICULO       BOL=ZZZBOLSAS DE SANGRE    VAR=ZZZVARIOS
```
Categoría vacía → mostrar "SIN CATEGORÍA".

---

## REGLAS DE NEGOCIO (ya validadas — respetarlas al pie de la letra)

### R1. Unidades por envase: se MULTIPLICA, nunca se divide
El ERP cuenta en **envases**. `CODIGO_REFERENCIA` dice cuántas unidades trae
cada envase. Si está vacío o es 0, vale **1** y hay que mostrar **"–"** en vez
de "1".

```
Stock (env.) = CANT_STOCK                        (el número crudo del ERP)
Stock (u.)   = CANT_STOCK × unidades_por_envase
```

**Ventas, promedio mensual, reservado, OC pendiente, stock total disponible y
cantidad a comprar también se multiplican** por el envase: todo el dashboard
queda expresado en **unidades**.

### R2. El envase es POR ARTÍCULO, no por grupo
Un grupo unificado puede mezclar envases (un artículo x500 y otro x150). Cada
artículo convierte con **su propio** `CODIGO_REFERENCIA` y el grupo suma en
unidades. En la columna de envase del grupo mostrar **"varios"** cuando no
coinciden.

### R3. Unificación multimarca por GTIN
Los artículos con el mismo `GTIN` se unifican en **un solo renglón** (es el
mismo producto de distintas marcas). El renglón muestra los totales y se
despliega para ver el detalle de cada artículo con **código y descripción**.

**Trampa:** el campo `GTIN` es una **clasificación de texto** (ej. "AGUJA 25/6
(23GX1)", "GUANTES DE LATEX M"), **no** un código de barras. Y está lleno de
comodines cargados a mano:

```
"NO APLICA" (557 artículos), "N/A" (27), "0" (27),
"NO APLCIA" (typo), "NO APLICO" (typo)
```

Hay que descartarlos **por patrón, no por lista fija** (van a aparecer typos
nuevos):

```regex
^(no ?apl\w*|n/?a|s/?d|null|none|ninguno|sin ?gtin|0+|-+|\.+)$   (case-insensitive)
```

Los artículos con GTIN comodín o vacío van **cada uno en su propio renglón**.
El GTIN puede llegar como número desde SQL, no solo como texto: convertir a
string antes de limpiar.

### R4. La categoría también es POR ARTÍCULO
Un grupo puede mezclar categorías (un artículo DISTRIBUCIÓN y otro
IMPORTACIÓN). La columna de tipo debe mostrar **todas** ("DISTRIBUCIÓN /
IMPORTACIÓN") y el grupo debe aparecer cuando **cualquiera** de sus categorías
está seleccionada en el filtro.

### R5. Notas de crédito: ya vienen en negativo
En `powerbi_ventas_vista`, los comprobantes cuyo prefijo empieza con **"C"**
(CEA, CEB, CA1) son notas de crédito y **ya tienen cantidad e importe
negativos**. Se suman **tal cual**.

> **NO negarlas.** Si se las niega, pasan a positivo y *suman* ventas en lugar
> de restarlas. Este error infló la facturación un 35% antes de detectarlo.

### R6. Reservado
```sql
WHERE reser = 'S'
  AND descrip_depo_reserva = 'Deposito unico'
  AND cant_pend > 0
```
Los depósitos CUARENTENA y PROGRAMADAS **no** comprometen stock.

> **PENDIENTE:** este número todavía no coincide con el ERP en algunos
> artículos. Las líneas que el ERP cuenta y las que no son idénticas en `reser`,
> depósito y `estado`, así que el campo que decide la reserva **no está en la
> vista**. Dejar la consulta aislada y comentada para poder ajustarla.

### R7. Stock negativo = error del ERP
Hay ~14 artículos con `CANT_STOCK` negativo (el peor: −4.296.450, mano de obra
de etiquetado). Un stock físico negativo es imposible: **contar como 0** y
marcar el producto con un ícono de advertencia.

Sin esto, al ordenar por "cantidad a comprar" el tope se llena de basura.

### R8. Productos que no mueven stock
`SIN_STOCK = 'S'` (~42 artículos) marca productos que no mueven stock
(servicios, mano de obra). Van a una categoría propia **"SERVICIOS"** y quedan
**fuera del cálculo** por defecto, con un checkbox para incluirlos.

Un grupo se excluye solo si **todos** sus artículos son de esa categoría.

### R9. Fórmulas del cálculo

```
Stock total disponible = Stock (u.) − Reservado + OC pendiente

Promedio mensual = ventas (u.) del período seleccionado ÷ cantidad de meses

Meses que cubro   = Stock total disponible ÷ Promedio mensual
                    (si el disponible es ≤ 0 → 0; nunca "meses negativos")

Cantidad a comprar = (Promedio mensual × meses objetivo) − Stock total disponible
                     (si da ≤ 0 → 0, no hay que comprar)
```

### R10. Estado de cobertura: TRES valores, no dos
```
SIN VENTA  → no hubo ventas en el período (promedio = 0). Gris.
SÍ         → meses que cubro ≥ meses objetivo. Verde.
NO         → meses que cubro < meses objetivo. Rojo.
```

> Crítico: un producto sin ventas **no es** un "NO cubre". Meterlo en "NO"
> ensucia la lista de urgencias. En el caso real, los "NO" bajaron de 342 a 166
> reales al separar los 276 "sin venta".

En "meses que cubro", los SIN VENTA muestran **"–"**, no 0.

### R11. Pareto (ABC) sobre facturación
Usar `sub_total` (columna del total de línea, **no** el precio unitario).

- Ordenar por facturación del período, descendente.
- Clase **A**: entra en el 80% acumulado. El artículo que cruza el 80% va en A.
- Clase **B**: el 20% restante.
- Sin facturación en el período: **sin clase**, y fuera de ambos filtros.
- Se recalcula **sobre el conjunto ya filtrado** (si se filtra una categoría,
  da el 80/20 de esa categoría).

En el caso real: 56 productos (11,7%) generan el 80,06% de la facturación.

---

## LAYOUT — tabla principal

**Un renglón por producto unificado**, con estas 15 columnas, en este orden:

```
1  Multimarca GTIN        (nombre; hasta 2 renglones; con el chip A/B de Pareto)
2  Tipo                   (todas las categorías del grupo, como chips)
3  Filas                  (cuántos artículos unifica)
4  U. x Envase            (número, "–" si es 1, "varios" si difieren)
5  Stock (u.)
6  Stock (env.)           (el número comparable contra el ERP)
7  Tendencia venta        (mini-gráfico de barras de los meses)
8  Ventas del período
9  Prom. mensual
10 Reservado
11 OC pend.
12 Stock total disp.      (en rojo si es negativo)
13 ¿Cubre?                (SÍ / NO / SIN VENTA)
14 Meses cubro            (coloreado según el estado)
15 Cant. a comprar        (rojo si > 0, verde si 0)
```

**Requisito de ancho:** las 15 columnas tienen que **entrar en pantalla sin
scroll horizontal** (probado a 1280px). No sacar ni fusionar columnas: los
encabezados largos van en dos renglones y se achican los anchos con espacio de
sobra. Usar `table-layout: fixed` con anchos por columna.

Fila de **TOTAL** al pie, que sume **todos** los productos filtrados, no solo
la página visible.

## INTERACCIONES

- **Clic en la fila** → despliega el detalle de artículos (código +
  descripción + su categoría + su envase). Nada más.
- **Clic en el mini-gráfico de "Tendencia venta"** → despliega el gráfico de
  ventas mes a mes de ese producto. Independiente del anterior: se puede tener
  uno abierto y el otro cerrado.
- **Clic en cualquier encabezado** → ordena de mayor a menor; otro clic
  invierte. Con indicador ▲/▼ en la columna activa. Funciona con texto,
  números, el semáforo y cada mes individual.

## FILTROS (una sola fila de controles, compacta)

- **Meses de stock objetivo**: 1 / 1,5 / 2 / 3 (botonera)
- **Período de venta**: desde–hasta sobre todos los meses disponibles, con
  atajos "Últ. 3 / Últ. 6 / Últ. 12 / Todo"
- **Categorías**: multi-selección con checkboxes, "Seleccionar todas" y
  "Limpiar", y contador en el botón
- **Producto activo**: Todos / Sí / No
- **Con stock**: Todos / Sí / No (usa `CANT_STOCK > 0`)
- **Cubre el mes**: Todos / Sí / No / Sin venta
- **Pareto**: Total / 80% / 20%
- **Buscar** por descripción o código
- Checkbox **"Incluir servicios (no mueven stock)"**, desmarcado por defecto
- Botón **Limpiar filtros**

## KPIs (arriba, en tarjetas)

Multimarca GTIN (total) · A comprar ya · Stock OK · Sin venta ·
**Cantidad de meses en stock** (promedio de "Meses cubro", solo sobre productos
con venta; mostrar también la mediana porque el promedio se dispara con
productos de stock alto y venta mínima) · Facturación del período ·
Objetivo de cobertura.

Todos responden a los filtros activos.

## OTROS REQUISITOS

- **Volumen**: ~4.800 productos. Buscador + **paginado** (50/100/250/500 por
  página). Los KPIs y el TOTAL siempre calculan sobre el conjunto completo
  filtrado.
- **Exportar a Excel**: dos botones, "resumen" (un renglón por producto) y
  "detalle" (un renglón por artículo). Respetan filtros y orden. CSV con **BOM
  UTF-8, separador `;` y coma decimal** para que Excel en español lo abra con
  doble clic.
- **Sello de actualización** visible arriba: "Actualizado dd/mm/aaaa hh:mm".
- Avisar si el último mes está incompleto (baja el promedio).
- Interfaz en **español**. Números con formato es-AR.
- Modo claro y oscuro.

## AUTOMATIZACIÓN

Script que corre **una vez por día** desde el Programador de tareas de Windows:
lee las 4 vistas, transforma y regenera el HTML.

- Credenciales en un archivo de configuración aparte, nunca en el código.
- Log por día con filas leídas y duración.
- **Si el SQL falla, no tocar el HTML anterior.** Escribir a un temporal y
  reemplazar el definitivo solo al terminar bien.
- El script debe correr **dentro de la red** (donde llega al SQL). Si el
  dashboard se publica en un sitio externo, solo se sincroniza el HTML
  generado: la base nunca se expone.

---

## CRITERIOS DE ACEPTACIÓN

1. Ningún grupo mezcla artículos que no comparten GTIN real (los comodines no
   agrupan).
2. Un artículo con envase x150 dentro de un grupo x500 convierte por 150.
3. La facturación total no incluye las notas de crédito sumadas en positivo.
4. Ordenar por "Cant. a comprar" descendente pone arriba productos reales, no
   artículos con stock negativo del ERP.
5. Los tres estados de cobertura suman exactamente el total de productos
   filtrados.
6. Pareto: clase A + clase B = 100% de la facturación, y la suma de sus
   productos = todos los que tienen facturación.
7. Sin scroll horizontal a 1280px de ancho.
8. El paginado no altera los KPIs ni el TOTAL.

---

## NOTA FINAL

Antes de dar por cerrado cualquier número, **compararlo contra la pantalla del
ERP para 4 o 5 artículos concretos**. Casi todos los errores de este proyecto
aparecieron así y ninguno era evidente leyendo el código: el multiplicador
invertido, las notas de crédito, el envase por grupo, la categoría por grupo y
los comodines de GTIN.
