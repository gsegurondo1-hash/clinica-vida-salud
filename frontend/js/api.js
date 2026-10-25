import { API_URL } from './config.js';

const CLAVE = 'cvs_token';

export const sesion = {
  get token() { return localStorage.getItem(CLAVE); },
  guardar(t) { localStorage.setItem(CLAVE, t); },
  borrar() { localStorage.removeItem(CLAVE); },
};

export class ApiError extends Error {
  constructor(mensaje, estado = 0, datos = {}) {
    super(mensaje);
    this.estado = estado;
    this.datos = datos;
  }
}

async function pedir(metodo, ruta, cuerpo) {
  const headers = {};
  if (cuerpo !== undefined) headers['Content-Type'] = 'application/json';
  if (sesion.token) headers.Authorization = `Bearer ${sesion.token}`;
  let res;
  try {
    res = await fetch(API_URL + ruta, {
      method: metodo,
      headers,
      body: cuerpo !== undefined ? JSON.stringify(cuerpo) : undefined,
    });
  } catch {
    throw new ApiError('No se pudo conectar con el servidor. Si acaba de abrir la página, espere un minuto e intente de nuevo.');
  }
  if (!res.ok && res.status === 401 && sesion.token) {
    sesion.borrar();
    window.dispatchEvent(new Event('sesion-expirada'));
  }
  return res;
}

export async function api(metodo, ruta, cuerpo) {
  const res = await pedir(metodo, ruta, cuerpo);
  const datos = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(datos.error || `Error ${res.status}`, res.status, datos);
  return datos;
}

export async function descargar(ruta, nombre) {
  const res = await pedir('GET', ruta);
  if (!res.ok) {
    const d = await res.json().catch(() => ({}));
    throw new ApiError(d.error || `Error ${res.status}`, res.status);
  }
  const url = URL.createObjectURL(await res.blob());
  const a = Object.assign(document.createElement('a'), { href: url, download: nombre });
  document.body.append(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

export const qs = (obj) => {
  const p = new URLSearchParams();
  Object.entries(obj).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') p.set(k, v); });
  const s = p.toString();
  return s ? `?${s}` : '';
};
