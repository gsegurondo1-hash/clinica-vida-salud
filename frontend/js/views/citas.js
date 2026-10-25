import { api, qs } from '../api.js';
import {
  $, $$, esc, can, campo, leer, modal, confirmar, tabla, delegar, toast, badge, cargando,
  fmtFecha, nombreDia, hoyISO, ORDEN_DIAS,
} from '../ui.js';

const CACHE = new Map(); // citas visibles, para los modales de acciones
const ESTADOS = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];
const opcionesEstado = [['', 'Todos'], ...ESTADOS.map((e) => [e, e])];

// ---------------------------------------------------------------- selector de horarios
function montarSelector(cont, idMedico, alElegir) {
  cont.innerHTML = `<div style="max-width:240px">${campo({ l: 'Fecha de la cita', n: 'fecha_sel', t: 'date', extra: `min="${hoyISO()}"` })}</div><div class="slots" aria-live="polite"></div>`;
  const entrada = $('input', cont);
  const salida = $('.slots', cont);

  const cargar = async () => {
    alElegir(null);
    if (!entrada.value) { salida.innerHTML = '<p class="muted">Elija una fecha para ver los horarios libres.</p>'; return; }
    salida.innerHTML = '<p class="muted">Buscando horarios…</p>';
    try {
      const r = await api('GET', `/disponibilidad${qs({ id_medico: idMedico, fecha: entrada.value })}`);
      if (!r.horarios.length) { salida.innerHTML = `<div class="aviso info">${esc(r.mensaje || 'No hay horarios disponibles.')}</div>`; return; }
      salida.innerHTML = `<p class="small muted">${esc(nombreDia(r.fecha))} ${fmtFecha(r.fecha)}: ${r.horarios.length} horarios libres</p>
        <div class="horas">${r.horarios.map((h) => `<button type="button" class="hora" aria-pressed="false" data-ini="${esc(h.inicio)}">${esc(h.inicio)}<small>hasta ${esc(h.fin)}</small></button>`).join('')}</div>`;
      $$('.hora', salida).forEach((b) => {
        b.onclick = () => {
          $$('.hora', salida).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          alElegir({ fecha: entrada.value, hora_inicio: b.dataset.ini });
        };
      });
    } catch (e) {
      salida.innerHTML = `<div class="aviso error">${esc(e.message)}</div>`;
    }
  };
  entrada.onchange = cargar;
  return cargar;
}

// ---------------------------------------------------------------- acciones sobre una cita
export function accionesCita(c, u) {
  CACHE.set(String(c.id_cita), c);
  const activa = c.estado === 'Programada' || c.estado === 'Confirmada';
  const b = (acc, txt, cls = 'sec') => `<button class="btn mini ${cls}" data-acc="${acc}" data-id="${c.id_cita}">${txt}</button>`;
  let h = b('detalle', 'Detalle');
  if (activa && can(u, 'citas.asistencia')) {
    if (u.rol !== 'Médico' && c.estado === 'Programada') h += b('confirmar', 'Confirmar', '');
    h += b('atendida', 'Atendida', '');
    if (u.rol !== 'Médico') h += b('inasistencia', 'Inasistencia', 'peligro sec');
  }
  if (activa && (can(u, 'citas.programar') || can(u, 'citas.propias'))) h += b('reprogramar', 'Reprogramar');
  if (activa && (can(u, 'citas.cancelar') || can(u, 'citas.propias'))) h += b('cancelar', 'Cancelar', 'peligro sec');
  return `<div class="acciones">${h}</div>`;
}

export function enlazarAcciones(cont, u, recargar) {
  const cambiar = async (id, estado, msg) => {
    await api('POST', `/citas/${id}/estado`, { estado });
    toast(msg);
    await recargar();
  };
  delegar(cont, {
    detalle: (id) => verDetalle(id),
    confirmar: (id) => cambiar(id, 'Confirmada', 'Cita confirmada'),
    atendida: (id) => cambiar(id, 'Atendida', 'Cita marcada como atendida'),
    inasistencia: async (id) => {
      if (await confirmar('¿Registrar que el paciente no asistió a la cita?', { si: 'Registrar inasistencia', peligro: true })) {
        await cambiar(id, 'Inasistencia', 'Inasistencia registrada');
      }
    },
    reprogramar: (id) => modalReprogramar(CACHE.get(id), recargar),
    cancelar: (id) => modalCancelar(CACHE.get(id), recargar),
  });
}

async function verDetalle(id) {
  const c = await api('GET', `/citas/${id}`);
  modal({
    titulo: `Cita ${c.codigo_cita}`,
    cuerpo: `<dl class="datos">
      <dt>Estado</dt><dd>${badge(c.estado)}</dd>
      <dt>Paciente</dt><dd>${esc(c.paciente)}${c.paciente_ci ? ` (CI ${esc(c.paciente_ci)})` : ''}</dd>
      <dt>Médico</dt><dd>${esc(c.medico)}</dd>
      <dt>Especialidad</dt><dd>${esc(c.especialidad)}</dd>
      <dt>Fecha</dt><dd>${esc(nombreDia(c.fecha))} ${fmtFecha(c.fecha)}</dd>
      <dt>Horario</dt><dd>${esc(c.hora_inicio)} a ${esc(c.hora_fin)}</dd>
      <dt>Motivo</dt><dd>${esc(c.motivo || 'No indicado')}</dd>
      ${c.motivo_cancelacion ? `<dt>Cancelación</dt><dd>${esc(c.motivo_cancelacion)}</dd>` : ''}
    </dl>
    <h3 style="margin-top:1rem">Historial de estados</h3>
    <ul class="linea-tiempo">${c.historial_estados.map((h) => `<li><strong>${esc(h.estado_nuevo)}</strong> <span class="muted small">${esc(h.fecha)} por ${esc(h.usuario)}</span>${h.observacion ? `<br><span class="small">${esc(h.observacion)}</span>` : ''}</li>`).join('')}</ul>`,
    botones: [{ texto: 'Cerrar', clase: 'sec' }],
  });
}

function modalReprogramar(c, recargar) {
  let elegido = null;
  const cuerpo = document.createElement('div');
  cuerpo.innerHTML = `<p>Cita <strong>${esc(c.codigo_cita)}</strong> con ${esc(c.medico)}, actualmente el ${fmtFecha(c.fecha)} a las ${esc(c.hora_inicio)}.</p><div id="sel"></div>`;
  montarSelector($('#sel', cuerpo), c.id_medico, (x) => { elegido = x; });
  modal({
    titulo: 'Reprogramar cita',
    cuerpo,
    botones: [
      { texto: 'Volver', clase: 'sec' },
      {
        texto: 'Confirmar nuevo horario',
        accion: async () => {
          if (!elegido) throw new Error('Elija una fecha y un horario disponible.');
          await api('PUT', `/citas/${c.id_cita}/reprogramar`, elegido);
          toast('Cita reprogramada');
          await recargar();
        },
      },
    ],
  });
}

function modalCancelar(c, recargar) {
  modal({
    titulo: 'Cancelar cita',
    cuerpo: `<p>Se cancelará la cita <strong>${esc(c.codigo_cita)}</strong> del ${fmtFecha(c.fecha)} a las ${esc(c.hora_inicio)}. El registro se conserva en el historial.</p>
      <form>${campo({ l: 'Motivo de la cancelación', n: 'motivo', t: 'textarea', req: true })}</form>`,
    botones: [
      { texto: 'Volver', clase: 'sec' },
      {
        texto: 'Cancelar cita',
        clase: 'peligro',
        accion: async ({ cuerpo }) => {
          const motivo = leer($('form', cuerpo)).motivo;
          if (!motivo) throw new Error('Indique el motivo de la cancelación.');
          await api('POST', `/citas/${c.id_cita}/cancelar`, { motivo });
          toast('Cita cancelada');
          await recargar();
        },
      },
    ],
  });
}

// ---------------------------------------------------------------- nueva cita (CU-10 y CU-11)
export async function nueva(main, u) {
  const staff = can(u, 'citas.programar');
  const est = { paciente: staff ? null : { id: u.id_paciente, nombre: u.nombre }, esp: '', medico: null, slot: null };

  main.innerHTML = `<div class="cabecera"><div><h1>Nueva cita</h1><p>${staff ? 'Elija el paciente, el médico y un horario libre.' : 'Elija especialidad, médico y un horario libre.'}</p></div></div>
    ${staff ? `<div class="panel"><h2>1. Paciente</h2>
      <div class="filtros"><div class="campo"><label for="qpac">Buscar por CI, nombre o apellido</label><input id="qpac" autocomplete="off"></div>
      <button class="btn sec" id="bpac" type="button">Buscar</button></div><div id="rpac"></div></div>` : ''}
    <div class="panel"><h2>${staff ? '2' : '1'}. Especialidad y médico</h2>
      <div style="max-width:320px">${campo({ l: 'Especialidad', n: 'esp', t: 'select', op: [['', 'Cargando…']] })}</div>
      <div id="medicos" class="medico-op"></div></div>
    <div class="panel hidden" id="pdisp"><h2>${staff ? '3' : '2'}. Fecha y hora</h2><div id="disp"></div></div>
    <div class="panel hidden" id="pconf"><h2>${staff ? '4' : '3'}. Confirmar</h2>
      ${campo({ l: 'Motivo de la consulta (opcional)', n: 'motivo', t: 'textarea', ay: 'Máximo 200 caracteres', extra: 'maxlength="200"' })}
      <div id="resumen" class="aviso info"></div><div id="err"></div>
      <p><button class="btn" id="ok" type="button" disabled>Confirmar cita</button></p></div>`;

  const actualizar = () => {
    const listo = est.paciente && est.medico && est.slot;
    $('#ok').disabled = !listo;
    $('#resumen').innerHTML = est.slot
      ? `${esc(est.medico.nombre)}, ${esc(nombreDia(est.slot.fecha))} ${fmtFecha(est.slot.fecha)} a las <strong>${esc(est.slot.hora_inicio)}</strong>${staff && est.paciente ? ` para ${esc(est.paciente.nombre)}` : ''}.`
      : 'Elija un horario para continuar.';
    if (staff && est.slot && !est.paciente) $('#resumen').innerHTML += ' <strong>Falta elegir el paciente.</strong>';
  };

  if (staff) {
    const buscar = async () => {
      const r = await api('GET', `/pacientes${qs({ q: $('#qpac').value, estado: 'activos' })}`);
      const cont = $('#rpac');
      cont.innerHTML = r.pacientes.length
        ? `<div class="medico-op">${r.pacientes.slice(0, 12).map((p) => `<button type="button" aria-pressed="false" data-id="${p.id_paciente}" data-n="${esc(p.nombres + ' ' + p.apellidos)}"><strong>${esc(p.nombres)} ${esc(p.apellidos)}</strong><span class="small muted">${esc(p.codigo)}${p.ci ? ' · CI ' + esc(p.ci) : ''}</span></button>`).join('')}</div>`
        : '<div class="vacio">No se encontraron pacientes activos.</div>';
      $$('button', cont).forEach((b) => {
        b.onclick = () => {
          $$('button', cont).forEach((x) => x.setAttribute('aria-pressed', 'false'));
          b.setAttribute('aria-pressed', 'true');
          est.paciente = { id: Number(b.dataset.id), nombre: b.dataset.n };
          actualizar();
        };
      });
    };
    $('#bpac').onclick = () => buscar().catch((e) => toast(e.message, 'error'));
    $('#qpac').onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#bpac').click(); } };
    buscar().catch(() => {});
  }

  const espSel = $('#c_esp');
  const esps = (await api('GET', '/especialidades')).especialidades;
  espSel.innerHTML = [['', 'Seleccione una especialidad'], ...esps.map((e) => [e.id_especialidad, e.nombre])]
    .map(([v, t]) => `<option value="${esc(v)}">${esc(t)}</option>`).join('');

  espSel.onchange = async () => {
    est.esp = espSel.value;
    est.medico = null;
    est.slot = null;
    $('#pdisp').classList.add('hidden');
    $('#pconf').classList.add('hidden');
    const cont = $('#medicos');
    if (!est.esp) { cont.innerHTML = ''; return; }
    const r = await api('GET', `/medicos${qs({ especialidad: est.esp })}`);
    cont.innerHTML = r.medicos.length
      ? r.medicos.map((m) => {
        const dias = [...m.dias].sort((a, b) => ORDEN_DIAS.indexOf(a) - ORDEN_DIAS.indexOf(b)).map((d) => d.slice(0, 3)).join(', ');
        return `<button type="button" aria-pressed="false" data-id="${m.id_medico}" data-n="${esc('Dr(a). ' + m.nombres + ' ' + m.apellidos)}"><strong>${esc(m.nombres)} ${esc(m.apellidos)}</strong><span class="small muted">${dias ? 'Atiende: ' + esc(dias) : 'Sin horarios definidos'}</span></button>`;
      }).join('')
      : '<div class="vacio">No hay médicos activos en esta especialidad.</div>';
    $$('button', cont).forEach((b) => {
      b.onclick = () => {
        $$('button', cont).forEach((x) => x.setAttribute('aria-pressed', 'false'));
        b.setAttribute('aria-pressed', 'true');
        est.medico = { id: Number(b.dataset.id), nombre: b.dataset.n };
        est.slot = null;
        $('#pdisp').classList.remove('hidden');
        $('#pconf').classList.remove('hidden');
        montarSelector($('#disp'), est.medico.id, (s) => { est.slot = s; actualizar(); });
        actualizar();
      };
    });
  };

  $('#ok').onclick = async () => {
    const btn = $('#ok');
    btn.disabled = true;
    $('#err').innerHTML = '';
    try {
      const cuerpo = {
        id_medico: est.medico.id, id_especialidad: Number(est.esp), fecha: est.slot.fecha,
        hora_inicio: est.slot.hora_inicio, motivo: $('#c_motivo').value.trim(),
      };
      if (staff) cuerpo.id_paciente = est.paciente.id;
      const c = await api('POST', '/citas', cuerpo);
      modal({
        titulo: 'Cita registrada',
        cuerpo: `<div class="aviso ok">Su código de cita es <strong>${esc(c.codigo_cita)}</strong>.</div>
          <dl class="datos"><dt>Paciente</dt><dd>${esc(c.paciente)}</dd><dt>Médico</dt><dd>${esc(c.medico)}</dd>
          <dt>Especialidad</dt><dd>${esc(c.especialidad)}</dd><dt>Fecha</dt><dd>${esc(nombreDia(c.fecha))} ${fmtFecha(c.fecha)}</dd>
          <dt>Hora</dt><dd>${esc(c.hora_inicio)} a ${esc(c.hora_fin)}</dd></dl>`,
        botones: [{ texto: 'Reservar otra', clase: 'sec', accion: () => nueva(main, u) }, { texto: 'Ver citas', accion: () => { location.hash = '#/citas'; } }],
      });
    } catch (e) {
      $('#err').innerHTML = `<div class="aviso error">${esc(e.message)}</div>`;
      $('#c_fecha_sel')?.dispatchEvent(new Event('change')); // refresca los horarios
      btn.disabled = false;
    }
  };
  actualizar();
}

// ---------------------------------------------------------------- listado de citas
export async function lista(main, u) {
  const staff = u.rol !== 'Paciente';
  main.innerHTML = `<div class="cabecera"><div><h1>${staff ? 'Citas' : 'Mis citas'}</h1><p>${staff ? 'Consulte, confirme, reprograme o cancele citas.' : 'Consulte y administre sus citas.'}</p></div>
    ${can(u, 'citas.programar') || can(u, 'citas.propias') ? '<a class="btn" href="#/nueva-cita">Nueva cita</a>' : ''}</div>
    <form class="filtros" id="f">
      ${staff ? campo({ l: 'Paciente, CI o código', n: 'q' }) : ''}
      ${campo({ l: 'Estado', n: 'estado', t: 'select', op: opcionesEstado })}
      ${campo({ l: 'Desde', n: 'desde', t: 'date', v: staff ? hoyISO() : '' })}
      ${campo({ l: 'Hasta', n: 'hasta', t: 'date' })}
      <button class="btn sec" type="submit">Filtrar</button></form>
    <div id="t"></div>`;

  const cols = [
    { t: 'Código', f: (c) => `<strong>${esc(c.codigo_cita)}</strong>` },
    { t: 'Fecha', f: (c) => `${esc(nombreDia(c.fecha))} ${fmtFecha(c.fecha)}` },
    { t: 'Hora', f: (c) => esc(c.hora_inicio) },
    ...(staff ? [{ t: 'Paciente', f: (c) => esc(c.paciente) }] : []),
    ...(u.rol === 'Médico' ? [] : [{ t: 'Médico', f: (c) => esc(c.medico) }]),
    { t: 'Especialidad', f: (c) => esc(c.especialidad) },
    { t: 'Estado', f: (c) => badge(c.estado) },
    { t: 'Acciones', f: (c) => accionesCita(c, u) },
  ];
  const cargar = async () => {
    const f = leer($('#f'));
    cargando($('#t'));
    const r = await api('GET', `/citas${qs(f)}`);
    $('#t').innerHTML = tabla(cols, r.citas, 'No hay citas con esos filtros.');
  };
  $('#f').onsubmit = (e) => { e.preventDefault(); cargar().catch((x) => toast(x.message, 'error')); };
  enlazarAcciones($('#t'), u, cargar);
  await cargar();
}

// ---------------------------------------------------------------- agenda médica (CU-15)
export async function agenda(main, u) {
  const esMedico = u.rol === 'Médico';
  let medicos = [];
  if (!esMedico) medicos = (await api('GET', '/medicos')).medicos;

  main.innerHTML = `<div class="cabecera"><div><h1>Agenda médica</h1><p>${esMedico ? 'Sus citas por día.' : 'Citas de un médico por día.'}</p></div></div>
    <form class="filtros" id="f">
      ${esMedico ? '' : campo({ l: 'Médico', n: 'id_medico', t: 'select', op: [['', 'Todos los médicos'], ...medicos.map((m) => [m.id_medico, `${m.apellidos} ${m.nombres}`])] })}
      ${campo({ l: 'Fecha', n: 'fecha', t: 'date', v: hoyISO(), req: true })}
      ${campo({ l: 'Estado', n: 'estado', t: 'select', op: opcionesEstado })}
      <button class="btn sec" type="submit">Consultar</button></form>
    <div id="cifras"></div><div id="t"></div>`;

  const cols = [
    { t: 'Hora', f: (c) => `<strong>${esc(c.hora_inicio)}</strong>` },
    { t: 'Código', f: (c) => esc(c.codigo_cita) },
    { t: 'Paciente', f: (c) => esc(c.paciente) },
    ...(esMedico ? [] : [{ t: 'Médico', f: (c) => esc(c.medico) }]),
    { t: 'Especialidad', f: (c) => esc(c.especialidad) },
    { t: 'Motivo', f: (c) => esc(c.motivo || '') },
    { t: 'Estado', f: (c) => badge(c.estado) },
    { t: 'Acciones', f: (c) => accionesCita(c, u) },
  ];
  const cargar = async () => {
    const r = await api('GET', `/agenda${qs(leer($('#f')))}`);
    const t = r.totales;
    $('#cifras').innerHTML = `<div class="cifras"><div class="cifra"><b>${t.pendientes}</b><span>Pendientes</span></div>
      <div class="cifra"><b>${t.atendidas}</b><span>Atendidas</span></div><div class="cifra"><b>${t.canceladas}</b><span>Canceladas</span></div>
      <div class="cifra"><b>${t.inasistencias}</b><span>Inasistencias</span></div></div>`;
    $('#t').innerHTML = tabla(cols, r.citas, 'No hay citas para esa fecha y filtros.');
  };
  $('#f').onsubmit = (e) => { e.preventDefault(); cargar().catch((x) => toast(x.message, 'error')); };
  enlazarAcciones($('#t'), u, cargar);
  await cargar();
}

// ---------------------------------------------------------------- historial (CU-16)
export async function historial(main, u, params = {}) {
  const idPaciente = params.id;
  main.innerHTML = `<div class="cabecera"><div><h1 id="titulo">Historial de citas</h1><p>Incluye citas pasadas, canceladas e inasistencias.</p></div>
    ${idPaciente ? '<a class="btn sec" href="#/pacientes">Volver a pacientes</a>' : ''}</div>
    <form class="filtros" id="f">${campo({ l: 'Estado', n: 'estado', t: 'select', op: opcionesEstado })}
      ${campo({ l: 'Desde', n: 'desde', t: 'date' })}${campo({ l: 'Hasta', n: 'hasta', t: 'date' })}
      <button class="btn sec" type="submit">Filtrar</button></form><div id="t"></div>`;
  const cols = [
    { t: 'Fecha', f: (c) => `${esc(nombreDia(c.fecha))} ${fmtFecha(c.fecha)} ${esc(c.hora_inicio)}` },
    { t: 'Código', f: (c) => esc(c.codigo_cita) },
    { t: 'Médico', f: (c) => esc(c.medico) },
    { t: 'Especialidad', f: (c) => esc(c.especialidad) },
    { t: 'Estado', f: (c) => badge(c.estado) },
    { t: 'Registrada', f: (c) => esc(c.fecha_creacion) },
    { t: '', f: (c) => `<button class="btn mini sec" data-acc="detalle" data-id="${c.id_cita}">Detalle</button>` },
  ];
  const cargar = async () => {
    const r = await api('GET', `/historial${qs({ ...leer($('#f')), id_paciente: idPaciente })}`);
    if (idPaciente) $('#titulo').textContent = `Historial de ${r.paciente.nombre}`;
    $('#t').innerHTML = tabla(cols, r.citas, 'No hay citas registradas.');
  };
  $('#f').onsubmit = (e) => { e.preventDefault(); cargar().catch((x) => toast(x.message, 'error')); };
  enlazarAcciones($('#t'), u, cargar);
  await cargar();
}

// ---------------------------------------------------------------- notificaciones (CU-18)
export async function notificaciones(main, u) {
  main.innerHTML = '<div class="cabecera"><div><h1>Notificaciones</h1><p>Confirmaciones, cambios y recordatorios de citas.</p></div></div><div id="t"></div>';
  cargando($('#t'));
  const r = await api('GET', '/notificaciones');
  $('#t').innerHTML = tabla([
    { t: 'Tipo', f: (n) => esc(n.tipo) },
    { t: 'Mensaje', f: (n) => esc(n.mensaje) },
    { t: 'Cita', f: (n) => esc(n.codigo_cita) },
    { t: 'Estado', f: (n) => badge(n.estado) },
    { t: 'Fecha de envío', f: (n) => esc(n.fecha_envio || 'Pendiente') },
  ], r.notificaciones, 'Aún no tiene notificaciones.');
}
