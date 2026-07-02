export interface User {
    id: number
    name: string
    email: string
    roles: string[]
    permissions: string[]
}

export interface PageProps extends Record<string, unknown> {
    auth: {
        user: User | null
    }
    flash: {
        success?: string
        error?: string
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
    synced_at: string | null
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
    contacto_telefono: string | null
    responsable_id: number | null
    responsable: { id: number; name: string } | null
    cliente: { id: number; numero: string; razon_social: string; mail: string | null; telefono: string | null } | null
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
