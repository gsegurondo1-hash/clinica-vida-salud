import { api, sesion } from './api.js';
import { $, $$, esc, can, toast } from './ui.js';
import * as auth from './views/auth.js';
import * as inicio from './views/inicio.js';
import * as citas from './views/citas.js';
import * as pacientes from './views/pacientes.js';
import * as catalogos from './views/catalogos.js';
import * as admin from './views/admin.js';

const app = $('#app');
let usuario = null;

const tieneCitas = (u) => can(u, 'citas.programar') || can(u, 'citas.propias');
const veNotif = (u) => tieneCitas(u) || can(u, 'agenda.consultar');

// Rutas privadas: ok decide quién puede entrar; vista pinta en <main>.
const RUTAS = {
  '/inicio': { t: 'Inicio', ok: () => true, vista: inicio.vista },
  '/nueva-cita': { t: 'Nueva cita', ok: tieneCitas, vista: citas.nueva },
  '/citas': { t: (u) => (u.rol === 'Paciente' ? 'Mis citas' : 'Citas'), ok: (u) => tieneCitas(u) || can(u, 'agenda.consultar'), vista: citas.lista },
  '/agenda': { t: 'Agenda médica', ok: (u) => can(u, 'agenda.consultar'), vista: citas.agenda },
  '/historial': { t: 'Mi historial', ok: (u) => u.rol === 'Paciente', vista: citas.historial, oculto: false },
  '/historial/:id': { t: 'Historial', ok: (u) => can(u, 'citas.programar'), vista: citas.historial, oculto: true },
  '/notificaciones': { t: 'Notificaciones', ok: veNotif, vista: citas.notificaciones },
  '/perfil': { t: 'Mi perfil', ok: (u) => can(u, 'perfil.editar'), vista: pacientes.perfil },
  '/pacientes': { t: 'Pacientes', ok: (u) => can(u, 'pacientes.gestionar'), vista: pacientes.lista, grupo: 'Administración' },
  '/medicos': { t: 'Médicos y horarios', ok: (u) => can(u, 'medicos.gestionar'), vista: catalogos.medicos, grupo: 'Administración' },
  '/especialidades': { t: 'Especialidades', ok: (u) => can(u, 'especialidades.gestionar'), vista: catalogos.especialidades, grupo: 'Administración' },
  '/bloqueos': { t: 'Bloqueos de agenda', ok: (u) => can(u, 'horarios.gestionar'), vista: catalogos.bloqueos, grupo: 'Administración' },
  '/usuarios': { t: 'Usuarios y roles', ok: (u) => can(u, 'usuarios.gestionar'), vista: admin.usuarios, grupo: 'Administración' },
  '/reportes': { t: 'Reportes', ok: (u) => can(u, 'reportes.ver'), vista: admin.reportes, grupo: 'Informes' },
  '/bitacora': { t: 'Bitácora', ok: (u) => can(u, 'bitacora.ver'), vista: admin.bitacora, grupo: 'Informes' },
};

const PUBLICAS = {
  '/login': auth.login,
  '/registro': auth.registro,
  '/recuperar': auth.recuperar,
  '/restablecer/:token': auth.restablecer,
};

function coincide(tabla, ruta) {
  for (const patron of Object.keys(tabla)) {
    const nombres = [];
    const rx = new RegExp('^' + patron.replace(/:(\w+)/g, (_, n) => { nombres.push(n); return '([^/]+)'; }) + '$');
    const m = ruta.match(rx);
    if (m) return { patron, params: Object.fromEntries(nombres.map((n, i) => [n, decodeURIComponent(m[i + 1])])) };
  }
  return null;
}

async function navegar() {
  const ruta = (location.hash.slice(1) || '/inicio').split('?')[0];
  const publica = coincide(PUBLICAS, ruta);

  if (!sesion.token) {
    usuario = null;
    if (!publica) { location.hash = '#/login'; return; }
    app.innerHTML = '';
    PUBLICAS[publica.patron](app, publica.params);
    return;
  }

  if (!usuario) {
    try {
      usuario = (await api('GET', '/auth/yo')).usuario;
    } catch (e) {
      sesion.borrar();
      location.hash = '#/login';
      return;
    }
  }
  if (publica) { location.hash = '#/inicio'; return; }

  const priv = coincide(RUTAS, ruta);
  if (!priv || !RUTAS[priv.patron].ok(usuario)) {
    toast('No tiene acceso a esa página', 'error');
    location.hash = '#/inicio';
    return;
  }
  pintarShell(ruta);
  const main = $('#main');
  main.focus({ preventScroll: true });
  try {
    await RUTAS[priv.patron].vista(main, usuario, priv.params);
  } catch (e) {
    main.innerHTML = `<div class="aviso error">${esc(e.message)}</div>`;
  }
}

function pintarShell(ruta) {
  if (!$('.shell')) {
    app.innerHTML = `<div class="shell">
      <aside class="side" id="side">
        <div class="marca">Vida Salud<small>Gestión de citas médicas</small></div>
        <button class="btn sec menu-movil" id="menuMovil" type="button" aria-expanded="false">Menú</button>
        <nav class="nav" id="nav" aria-label="Principal"></nav>
        <div class="usuario"><strong>${esc(usuario.nombre)}</strong><span class="muted" style="color:#9dbab6">${esc(usuario.rol)}</span>
          <button class="btn" id="salir" type="button">Cerrar sesión</button></div>
      </aside>
      <main class="main" id="main" tabindex="-1"></main></div>`;
    $('#salir').onclick = cerrarSesion;
    $('#menuMovil').onclick = (e) => {
      const abierto = $('#side').classList.toggle('abierto');
      e.currentTarget.setAttribute('aria-expanded', abierto);
    };
  }
  let html = '';
  let grupo = null;
  for (const [patron, r] of Object.entries(RUTAS)) {
    if (r.oculto || !r.ok(usuario)) continue;
    if (r.grupo && r.grupo !== grupo) { html += `<div class="grupo">${esc(r.grupo)}</div>`; grupo = r.grupo; }
    const texto = typeof r.t === 'function' ? r.t(usuario) : r.t;
    const activo = ruta === patron || (patron === '/historial' && ruta.startsWith('/historial/')) ? 'activo' : '';
    html += `<a href="#${patron}" class="${activo}" ${activo ? 'aria-current="page"' : ''}>${esc(texto)}</a>`;
  }
  $('#nav').innerHTML = html;
  $('#side').classList.remove('abierto');
}

async function cerrarSesion() {
  sesion.borrar();
  usuario = null;
  app.innerHTML = '';
  location.hash = '#/login';
}

window.addEventListener('hashchange', navegar);
window.addEventListener('sesion-expirada', () => {
  usuario = null;
  toast('Su sesión expiró. Inicie sesión nuevamente.', 'error');
  location.hash = '#/login';
});
window.addEventListener('sesion-iniciada', (e) => { usuario = e.detail; location.hash = '#/inicio'; });
navegar();
