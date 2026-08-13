export interface User {
    id: number
    name: string
    email: string
    roles: string[]
    permissions: string[]
    sector_id?: number | null
    sector?: Sector | null
    supervisor_id?: number | null
    gerente_id?: number | null
    es_gerente?: boolean
}

/** Sector: dónde trabaja la persona y destino de gestión de una observación. */
export interface Sector {
    id: number
    nombre: string
    slug: string
    dias_gestion: number | null
    activo: boolean
    usuarios_count?: number
}

export interface AlertaNotificacion {
    id: string
    created_at: string
    data: {
        tipo: 'observacion_asignada' | 'observacion_reasignada' | 'observacion_seguimiento' | 'observacion_vencida' | 'observacion_escalada' | 'observacion_finalizada'
        observacion_id: number
        numero: string
        titulo: string
        mensaje: string
        url: string
    }
}

/**
 * Observación pendiente de clasificación, para la sección propia de la campana
 * del equipo de Garantía de Calidad. Sale de una consulta viva contra
 * `observations` (no de una notificación), así que se autolimpia sola en
 * cuanto alguien la clasifica.
 */
export interface ObservacionSinClasificar {
    id: number
    numero: string
    titulo: string
    origen: 'interna' | 'externa'
    contacto_nombre: string | null
    created_at: string
    /** Solo viaja en `asignadas`: los sin clasificar están todos en el mismo estado. */
    estado?: string
}

/** Artículo del catálogo de RP Sistemas. Los primeros campos son del ERP (solo lectura); los últimos cuatro son propios del panel. */
export interface Articulo {
    id: number
    codigo: string
    descripcion: string
    descripcion_adicional: string | null
    codigo_barras: string | null
    unidad_medida: string | null
    codigo_agrupacion_1: string | null
    descripcion_agrupacion_1: string | null
    codigo_agrupacion_2: string | null
    descripcion_agrupacion_2: string | null
    codigo_agrupacion_3: string | null
    descripcion_agrupacion_3: string | null
    stock: string | null
    stock_disponible: string | null
    codigo_proveedor: string | null
    modificado_en: string | null
    synced_at: string | null
    fecha_vencimiento: string | null
    pm: string | null
    legajo: string | null
    observaciones: string | null
    link_registro: string | null
}

export interface ClienteVencimiento {
    id: number
    numero: string
    razon_social: string
    fecha_vencimiento: string
}

export interface PageProps extends Record<string, unknown> {
    auth: {
        user: User | null
    }
    flash: {
        success?: string
        error?: string
    }
    broadcasting: {
        driver: 'pusher' | 'reverb' | 'null'
        key: string | null
        cluster: string | null
        host: string | null
        port: number | null
        scheme: 'http' | 'https' | null
    }
    notificaciones: {
        vencimientos: ClienteVencimiento[]
        /** Alertas de vencimiento/escalamiento de observaciones. No incluye reclamos externos: esos tienen su propia sección. */
        alertas: AlertaNotificacion[]
        /** Todos los reclamos sin clasificar, para la sección "Sin clasificar" de la campana. */
        sinClasificar: ObservacionSinClasificar[]
        /** Subconjunto de `sinClasificar` de los que a este usuario le avisaron. Insiste en el modal (descarte del lado del cliente, ver AppLayout.vue) hasta que se clasifique el caso. */
        externas: ObservacionSinClasificar[]
        /** Casos abiertos donde este usuario es el responsable. Bloque del modal, insiste mientras el caso siga abierto. */
        asignadas: ObservacionSinClasificar[]
        /** Casos abiertos donde lo sumaron como "a notificar". Excluye los que ya están en `asignadas`. */
        seguimiento: ObservacionSinClasificar[]
    }
}

export interface Role {
    id: number
    name: string
    permissions: Permission[]
}

export interface Permission {
    id: number
    name: string
}

/** Permiso con su nombre en español y su grupo (config/permisos.php). */
export interface PermisoEtiquetado {
    id: number
    name: string
    label: string
    grupo: string
}

export interface ClienteAttachment {
    id: number
    original_name: string
    mime_type: string
    size: number
    created_at: string
}

export interface Cliente {
    id: number
    numero: string
    razon_social: string
    nombre_fantasia: string | null
    cuit: string | null
    codigo_iva: string | null
    descripcion_iva: string | null
    telefono: string | null
    mail: string | null
    /** Mail cargado por el propio cliente desde el portal. La sync con RP no lo pisa. */
    mail_nuevo: string | null
    contacto: string | null
    domicilio: string | null
    localidad: string | null
    codigo_provincia: string | null
    descripcion_provincia: string | null
    porcen_descuen: string | null
    usuario_web: string | null
    codigo_vendedor: string | null
    nombre_vendedor: string | null
    codigo_postal: string | null
    fecha_vencimiento: string | null
    categoria: string | null
    synced_at: string | null
    attachments?: ClienteAttachment[]
}

export interface ObservationProduct {
    id: number
    producto: string
    codigo: string | null
    cantidad_afectada: number
    tipo_presentacion: string | null
    lote: string
    fecha_vencimiento: string
    numero_remito: string
    tipo_comprobante: string
    /** Solo si `codigo` matchea un artículo del catálogo sincronizado. */
    articulo?: { descripcion: string; pm: string | null } | null
}

export interface Observacion {
    id: number
    numero: string
    tipo: string
    estado: string
    origen: string
    titulo: string
    descripcion: string
    contacto_nombre: string
    contacto_email: string
    contacto_numero_cliente: string | null
    contacto_telefono: string | null
    responsable_id: number | null
    responsable: { id: number; name: string } | null
    responsable_asignado_at: string | null
    vence_at: string | null
    alerta_nivel: number
    sector_id: number | null
    sector: { id: number; nombre: string } | null
    prioridad: string | null
    tipo_caso: string | null
    datos_especificos: Record<string, string | number | null> | null
    cliente: { id: number; numero: string; razon_social: string; mail: string | null; telefono: string | null } | null
    productos: ObservationProduct[]
    /** Adjuntos sueltos (subidos con el botón de "Archivos adjuntos"), sin los de la bitácora. */
    attachments?: ObservationAttachment[]
    /** Bitácora del caso: comentarios y cambios, más reciente primero. */
    historial?: ObservationHistoryEntry[]
    /** Usuarios a notificar: reciben el aviso y pueden comentar, pero no gestionar el caso. */
    notificados?: { id: number; name: string; apellido: string | null }[]
    created_at: string
    /** Solo tiene valor si está borrada (soft delete). El motivo de la baja está en `baja.nota`. */
    deleted_at?: string | null
    /** La última entrada de bitácora de tipo "baja" (cancelación o borrado), con motivo y autor. */
    baja?: ObservationHistoryEntry | null
}

export interface ObservationAttachment {
    id: number
    original_name: string
    size: number
    /** Presentes cuando el adjunto se subió desde un comentario de bitácora, no desde el botón suelto. */
    observation_history_id?: number | null
    user_id?: number | null
}

/**
 * Entrada de la bitácora del caso: un cambio (estado, responsable, sector,
 * clasificación) registrado por ObservacionObserver, o un comentario manual
 * con sus adjuntos. Inmutable — no hay endpoint de edición ni de borrado.
 */
export interface ObservationHistoryEntry {
    id: number
    accion: 'comentario' | 'estado' | 'responsable' | 'sector' | 'clasificacion' | 'adjunto' | 'notificados' | 'sistema' | 'baja' | 'restauracion'
    nota: string | null
    /** Forma según `accion`: `{de, a}` para estado/responsable/sector, `{prioridad: {de,a}, tipo_caso: {de,a}}` para clasificacion. */
    cambios: Record<string, unknown> | null
    created_at: string
    /** Null en las entradas automáticas: no tienen usuario detrás. */
    user: { id: number; name: string; apellido: string | null } | null
    adjuntos: ObservationAttachment[]
    /** Solo viene cargada en Admin/Bitacora/Index: ahí la entrada se ve fuera del contexto de un caso puntual. */
    observacion?: { id: number; numero: string; titulo: string; sector: { id: number; nombre: string } | null }
}

export interface PaginatedData<T> {
    data: T[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
}
