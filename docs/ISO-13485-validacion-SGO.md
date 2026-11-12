# Validación del SGO como software del sistema de gestión (ISO 13485, 4.1.6)

Registro vivo: se actualiza con cada cambio que entra a producción. Lo mantiene quien programa y lo revisa
Calidad. Nació del pedido 20 del cliente ("Dashboard SGO TUBLOOD", 6/11/2026).

> **Estado de este documento:** las secciones 1 a 4 están completas con los datos al 6/11/2026. La sección 5
> (restauración del respaldo) está **pendiente de prueba** y la sección 6 lista lo que falta firmar. No
> debe presentarse a una auditoría como "validación cerrada" hasta completar ambas.

## 1. Uso previsto

El SGO (Sistema de Gestión de Observaciones) registra, clasifica y da seguimiento a observaciones,
reclamos y no conformidades de Tublood SA. Sus resultados se usan para tomar decisiones del sistema de
calidad: plazos de gestión, escalamiento, objetivos por sector y tablero de gestión.

Lo que el sistema **no** hace: no decide por la persona (clasificar, cerrar y aprobar una no conformidad
son acciones humanas), y no reemplaza el registro de los desvíos de producción.

## 2. Control de versiones y de cambios

- Código en Git (`gigliotti4/sgo-tublood`), un commit por cambio con mensaje que dice qué y por qué.
- Despliegue manual con `deploy.sh`, siempre precedido de un respaldo (`sgo-backup`). No hay despliegue
  automático: cada puesta en producción la autoriza una persona.
- Las migraciones de base de datos son aditivas (no borran columnas ni tablas con datos).

### Registro de cambios

| Fecha | Commit | Cambio | Impacto en datos existentes | Cubierto por |
|---|---|---|---|---|
| 6/11/2026 | `2a29d65` | Cambiar el tipo de una observación a cualquier sector (responsable, Calidad y super-admin) | No modifica casos ya cargados; el cambio queda en la bitácora con los datos reemplazados | `CambioDeTipoTest` |
| 6/11/2026 | `88108ce` | Prórroga de plazo con autorización; el plazo ya no se reinicia al reasignar | Casos ya cargados conservan su vencimiento; las alertas pasan a leer el plazo vigente | `ProrrogaObservacionTest`, `AlertasObservacionTest` |
| 6/11/2026 | `88108ce` | Fallos externos E.1, E.2, E.3; 3.5 y 6.2 retirados de las altas | Lo ya cargado como 3.5 y 6.2 se sigue viendo; nada se reclasifica solo | `ObservacionTipoYProductosTest` |
| 6/11/2026 | `88108ce` | Responsable del fallo y personal por sector | Campo nuevo, vacío en lo existente; visible solo para jefes y Calidad | `ResponsableDelFalloTest` |
| 6/11/2026 | `88108ce` | Comando `observaciones:reclasificar` | Simula por defecto; solo escribe con `--aplicar` y deja bitácora de cada cambio | `ReclasificarObservacionesTest` |
| 6/11/2026 | `bc05f16` | Objetivos de gestión (planilla en dos pasos) y tablero en el Dashboard | Tablas nuevas; no toca observaciones | `ObjetivosTableroTest` |
| 6/11/2026 | (este cambio) | Alertas de calidad de carga y Excel/PDF del tablero con fecha de corte | Solo lectura sobre observaciones | `CalidadDeCargaYExportTableroTest` |
| 6/11/2026 | (este cambio) | Remitos de venta del ERP: tasa de fallos cada 100 remitos y escala del objetivo de armado | Tabla nueva `remitos` (espejo de solo lectura del ERP); no toca observaciones | `RemitosTableroTest` |
| 12/11/2026 | (este cambio) | Sync de clientes por SQL: la paginación de `clientes.php` de RP estaba rota y solo se actualizaban 100 de 3.784 clientes por corrida | Los datos de clientes se refrescan (llevaban hasta 20 días sin actualizarse); no borra nada ni toca los campos propios del panel ni las observaciones | `ClienteSyncTest` |

Los cambios anteriores al 6/11/2026 están en el historial de Git (`git log`); este registro empieza acá.

## 3. Pruebas

Las pruebas son automáticas (PHPUnit sobre una base descartable) y corren con `composer test`.

- **Resultado al 6/11/2026:** 1017 pruebas, 1016 pasan. La que falla (`ExampleTest`) es el ejemplo de
  plantilla de Laravel (espera 200 en `/` y el sistema redirige al login); es anterior a estos cambios y
  no corresponde a ninguna función del SGO.
- **Qué se verifica por cambio:** el caso feliz, los límites (p. ej. una prórroga "hasta el 20/11" no
  figura vencida el 20 y sí el 21) y las puertas de acceso (quién puede y quién no).
- **Cálculos del tablero:** las fórmulas del semáforo y del índice por sector están en
  `resources/js/lib/tablero.ts`, escritas como funciones puras. Se verificaron contra los ejemplos que
  dio el cliente en su prototipo (Depósito, octubre = 51 %; septiembre más octubre deja "Demoras" en rojo
  por septiembre). ⚠️ Esa verificación se corre a mano (empaquetando el archivo y ejecutándolo con node):
  todavía no es parte de `composer test`.
- **Lo que las pruebas no cubren:** el aspecto visual de las pantallas se revisa a ojo, y la conexión con
  el ERP de RP Sistemas no se prueba automáticamente (se verifica contra el ERP real).

## 4. Integridad y acceso

- **Bitácora inmutable:** cada cambio de una observación queda registrado con quién, cuándo y qué cambió.
  El modelo impide editar o borrar entradas (no es solo una convención de la pantalla).
- **Permisos por rol y por objeto:** gestionar un caso es solo del responsable asignado (y del super-admin);
  el responsable del fallo solo lo ven jefes y Calidad, y esa restricción está cerrada en cinco puertas
  (pantallas, bitácora, Excel, PDF y filtros), cada una con su prueba.
- **Borrado:** una observación se da de baja con motivo y se puede restaurar; no se destruye.
- **Contraseñas:** se guardan con hash; no se pueden recuperar, solo restablecer.

## 5. Respaldo y restauración — PENDIENTE DE PRUEBA

**Lo que existe hoy**
- El servidor genera un respaldo con `/usr/local/bin/sgo-backup`: un volcado de la base de datos y un
  archivo con los adjuntos privados. Quedan en `/var/backups/sgo/` del mismo servidor.
- Se hace un respaldo antes de cada despliegue (verificado el 6/11/2026: base 9,7 MB y adjuntos 7,9 MB).
- Los snapshots de DigitalOcean cubren la pérdida total del servidor.

**Lo que falta (no está probado y no debe darse por cumplido)**
1. **Probar la restauración:** levantar el volcado más reciente en una base temporal (nunca sobre la de
   producción), comprobar que el sistema arranca contra ella y que los conteos coinciden (observaciones,
   usuarios, bitácora). Registrar acá la fecha, quién la hizo y el resultado.
2. **Copia fuera del servidor:** los respaldos viven en el mismo equipo que la base; si se pierde el
   servidor solo quedan los snapshots. Decidir con el cliente una copia externa y cada cuánto.
3. **Frecuencia y retención:** documentar cada cuánto se respalda de forma automática y cuántos se
   conservan (hoy se confirmó el respaldo previo al despliegue, no la política programada).

## 6. Pendiente de firma / decisión

- [ ] Prueba de restauración (sección 5, punto 1) hecha y registrada.
- [ ] Revisión de este registro por Calidad, con nombre y fecha.
- [ ] Definir quién aprueba un cambio antes de pasar a producción (hoy lo decide quien programa con el
      cliente).
- [ ] Incluir la verificación de las fórmulas del tablero dentro de `composer test`.
