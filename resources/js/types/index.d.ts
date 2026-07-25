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
        tipo: 'observacion_vencida' | 'observacion_escalada' | 'observacion_finalizada'
        observacion_id: number
        numero: string
        titulo: string
        mensaje: string
    }
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
    notificaciones: {
        vencimientos: ClienteVencimiento[]
        alertas: AlertaNotificacion[]
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
    synced_at: string | null
    attachments?: ClienteAttachment[]
}

export interface ObservationProduct {
    id: number
    producto: string
    codigo: string | null
    cantidad_afectada: number
    lote: string
    fecha_vencimiento: string
    numero_remito: string
    tipo_comprobante: string
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
    tecnovigilancia: boolean
    datos_especificos: Record<string, string | number | null> | null
    cliente: { id: number; numero: string; razon_social: string; mail: string | null; telefono: string | null } | null
    productos: ObservationProduct[]
    created_at: string
}

export interface PaginatedData<T> {
    data: T[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
}
