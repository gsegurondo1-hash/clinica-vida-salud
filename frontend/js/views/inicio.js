import { api } from '../api.js';
import { esc, fmtFecha, badge, tabla, cargando, nombreDia } from '../ui.js';
import { accionesCita, enlazarAcciones } from './citas.js';

const filas = (u) => [
  { t: 'Fecha', f: (c) => `${esc(nombreDia(c.fecha))} ${fmtFecha(c.fecha)}` },
  { t: 'Hora', f: (c) => esc(c.hora_inicio) },
  ...(u.rol === 'Paciente' ? [] : [{ t: 'Paciente', f: (c) => esc(c.paciente) }]),
  ...(u.rol === 'Médico' ? [] : [{ t: 'Médico', f: (c) => esc(c.medico) }]),
  { t: 'Especialidad', f: (c) => esc(c.especialidad) },
  { t: 'Estado', f: (c) => badge(c.estado) },
];

export async function vista(main, u) {
  cargando(main);
  const d = await api('GET', '/inicio');
  const hora = new Date().getHours();
  const saludo = hora < 12 ? 'Buenos días' : hora < 19 ? 'Buenas tardes' : 'Buenas noches';
  const nombre = u.nombre.split(' ')[0];

  if (u.rol === 'Paciente') {
    main.innerHTML = `<div class="cabecera"><div><h1>${saludo}, ${esc(nombre)}</h1><p>Estas son sus próximas citas.</p></div>
      <a class="btn" href="#/nueva-cita">Reservar una cita</a></div>
      <div id="t">${tabla([...filas(u), { t: '', f: (c) => `<a class="btn sec mini" href="#/citas">Ver</a>` }], d.proximas, 'No tiene citas próximas. Reserve una cuando la necesite.')}</div>`;
    return;
  }

  if (u.rol === 'Médico') {
    main.innerHTML = `<div class="cabecera"><div><h1>${saludo}, ${esc(nombre)}</h1><p>Su agenda de hoy, ${fmtFecha(d.hoy)}.</p></div>
      <a class="btn sec" href="#/agenda">Abrir agenda completa</a></div><div id="t"></div>`;
    const pintar = async () => {
      const r = await api('GET', '/inicio');
      document.getElementById('t').innerHTML = tabla(
        [...filas(u), { t: 'Acciones', f: (c) => accionesCita(c, u) }], r.agenda_hoy, 'No tiene citas para hoy.');
    };
    await pintar();
    enlazarAcciones(document.getElementById('t'), u, pintar);
    return;
  }

  const e = d.hoy_por_estado;
  const hoyTotal = Object.values(e).reduce((a, b) => a + b, 0);
  main.innerHTML = `<div class="cabecera"><div><h1>${saludo}, ${esc(nombre)}</h1><p>Resumen del día ${fmtFecha(d.hoy)}.</p></div>
    <div class="acciones">${u.permisos.includes('citas.programar') ? '<a class="btn" href="#/nueva-cita">Programar cita</a>' : ''}
    ${u.permisos.includes('agenda.consultar') ? '<a class="btn sec" href="#/agenda">Ver agenda</a>' : ''}</div></div>
    <div class="cifras">
      <div class="cifra"><b>${hoyTotal}</b><span>Citas de hoy</span></div>
      <div class="cifra"><b>${e.Atendida}</b><span>Atendidas hoy</span></div>
      <div class="cifra"><b>${d.por_confirmar}</b><span>Por confirmar</span></div>
      <div class="cifra"><b>${d.pacientes_activos}</b><span>Pacientes activos</span></div>
      <div class="cifra"><b>${d.medicos_activos}</b><span>Médicos activos</span></div>
    </div>
    <h2>Próximas citas</h2>${tabla(filas(u), d.proximas, 'No hay citas próximas.')}`;
}
