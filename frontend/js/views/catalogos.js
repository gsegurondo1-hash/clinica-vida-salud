import { api, qs } from '../api.js';
import { $, $$, esc, campo, leer, modal, confirmar, tabla, delegar, toast, badge, cargando, fmtFecha, ORDEN_DIAS } from '../ui.js';

// ---------------------------------------------------------------- especialidades (CU-06)
export async function especialidades(main) {
  main.innerHTML = `<div class="cabecera"><div><h1>Especialidades</h1><p>Oferta médica de la clínica.</p></div>
    <button class="btn" id="nuevo" type="button">Nueva especialidad</button></div><div id="t"></div>`;
  let datos = [];

  const cargar = async () => {
    cargando($('#t'));
    datos = (await api('GET', '/especialidades')).especialidades;
    $('#t').innerHTML = tabla([
      { t: 'Nombre', f: (e) => `<strong>${esc(e.nombre)}</strong>` },
      { t: 'Descripción', f: (e) => esc(e.descripcion || '') },
      { t: 'Médicos activos', f: (e) => esc(e.medicos_activos), cls: 'num' },
      { t: 'Estado', f: (e) => badge(e.habilitado ? 'Activo' : 'Inactivo') },
      { t: '', f: (e) => `<button class="btn mini sec" data-acc="editar" data-id="${e.id_especialidad}">Editar</button>` },
    ], datos);
  };

  const formulario = (e = {}) => modal({
    titulo: e.id_especialidad ? 'Editar especialidad' : 'Nueva especialidad',
    cuerpo: `<form novalidate><div class="form-grid">
      ${campo({ l: 'Nombre', n: 'nombre', v: e.nombre, req: true })}
      ${campo({ l: 'Descripción', n: 'descripcion', v: e.descripcion })}
      ${e.id_especialidad ? `<label class="campo check"><input type="checkbox" name="habilitado" ${e.habilitado ? 'checked' : ''}> Especialidad activa</label>` : ''}
    </div></form>`,
    botones: [
      { texto: 'Cancelar', clase: 'sec' },
      {
        texto: 'Guardar',
        accion: async ({ cuerpo }) => {
          const f = $('form', cuerpo);
          if (!f.checkValidity()) { f.reportValidity(); return false; }
          const d = leer(f);
          if (e.id_especialidad) await api('PUT', `/especialidades/${e.id_especialidad}`, { ...d, habilitado: f.habilitado.checked });
          else await api('POST', '/especialidades', d);
          toast('Especialidad guardada');
          await cargar();
        },
      },
    ],
  });

  $('#nuevo').onclick = () => formulario();
  delegar($('#t'), { editar: (id) => formulario(datos.find((x) => String(x.id_especialidad) === id)) });
  await cargar();
}

// ---------------------------------------------------------------- médicos (CU-07) y horarios (CU-08)
export async function medicos(main, u) {
  main.innerHTML = `<div class="cabecera"><div><h1>Médicos y horarios</h1><p>Registre médicos, sus especialidades y sus horarios de atención.</p></div>
    <button class="btn" id="nuevo" type="button">Registrar médico</button></div><div id="t"></div>`;
  let datos = [];
  const esps = (await api('GET', '/especialidades?activas=1')).especialidades;
  const puedeHorarios = u.permisos.includes('horarios.gestionar');

  const cargar = async () => {
    cargando($('#t'));
    datos = (await api('GET', '/medicos')).medicos;
    $('#t').innerHTML = tabla([
      { t: 'Código', f: (m) => `<strong>${esc(m.codigo)}</strong>` },
      { t: 'Médico', f: (m) => `${esc(m.nombres)} ${esc(m.apellidos)}<br><span class="small muted">${esc(m.nro_registro_prof)}</span>` },
      { t: 'Especialidades', f: (m) => esc(m.especialidades.map((e) => e.nombre).join(', ')) },
      { t: 'Atiende', f: (m) => esc([...m.dias].sort((a, b) => ORDEN_DIAS.indexOf(a) - ORDEN_DIAS.indexOf(b)).map((d) => d.slice(0, 3)).join(', ') || 'Sin horarios') },
      { t: 'Estado', f: (m) => badge(m.habilitado ? 'Activo' : 'Inactivo') },
      {
        t: 'Acciones',
        f: (m) => `<div class="acciones"><button class="btn mini sec" data-acc="editar" data-id="${m.id_medico}">Editar</button>
          ${puedeHorarios ? `<button class="btn mini" data-acc="horarios" data-id="${m.id_medico}">Horarios</button>` : ''}</div>`,
      },
    ], datos);
  };

  const formulario = (m = {}) => {
    const mias = (m.especialidades || []).map((e) => e.id_especialidad);
    modal({
      titulo: m.id_medico ? 'Editar médico' : 'Registrar médico',
      cuerpo: `<form novalidate><div class="form-grid">
        ${campo({ l: 'Nombres', n: 'nombres', v: m.nombres, req: true })}
        ${campo({ l: 'Apellidos', n: 'apellidos', v: m.apellidos, req: true })}
        ${campo({ l: 'Registro profesional', n: 'nro_registro_prof', v: m.nro_registro_prof, req: true })}
        ${campo({ l: 'Teléfono', n: 'telefono', t: 'tel', v: m.telefono, req: true })}
        ${campo({ l: 'Correo electrónico', n: 'email', t: 'email', v: m.email, req: true })}
        ${m.id_medico ? `<label class="campo check"><input type="checkbox" name="habilitado" ${m.habilitado ? 'checked' : ''}> Médico activo</label>` : ''}
      </div>
      <fieldset><legend>Especialidades *</legend><div class="checks">
        ${esps.map((e) => `<label><input type="checkbox" name="esp" value="${e.id_especialidad}" ${mias.includes(e.id_especialidad) ? 'checked' : ''}> ${esc(e.nombre)}</label>`).join('')}
      </div></fieldset></form>`,
      botones: [
        { texto: 'Cancelar', clase: 'sec' },
        {
          texto: 'Guardar',
          accion: async ({ cuerpo }) => {
            const f = $('form', cuerpo);
            if (!f.checkValidity()) { f.reportValidity(); return false; }
            const d = leer(f);
            d.especialidades = $$('input[name=esp]:checked', f).map((x) => Number(x.value));
            if (!d.especialidades.length) throw new Error('Seleccione al menos una especialidad.');
            delete d.esp;
            let r;
            if (m.id_medico) r = await api('PUT', `/medicos/${m.id_medico}`, { ...d, habilitado: f.habilitado.checked });
            else r = await api('POST', '/medicos', d);
            toast(r.citas_futuras ? `Médico desactivado. Tiene ${r.citas_futuras} cita(s) futuras por reprogramar.` : 'Médico guardado');
            await cargar();
          },
        },
      ],
    });
  };

  const modalHorarios = async (id) => {
    const medico = datos.find((x) => String(x.id_medico) === id);
    const cuerpo = document.createElement('div');
    const pintar = async () => {
      const r = await api('GET', `/medicos/${id}/horarios`);
      $('#lista', cuerpo).innerHTML = tabla([
        { t: 'Día', f: (h) => esc(h.dia_semana) },
        { t: 'Horario', f: (h) => `${esc(h.hora_inicio)} a ${esc(h.hora_fin)}` },
        { t: 'Cita de', f: (h) => `${esc(h.duracion_cita_min)} min` },
        { t: 'Bloques', f: (h) => esc(h.bloques), cls: 'num' },
        { t: '', f: (h) => `<button class="btn mini peligro sec" data-acc="quitar" data-id="${h.id_horario}">Quitar</button>` },
      ], r.horarios, 'Este médico aún no tiene horarios.');
    };
    cuerpo.innerHTML = `<div id="lista"></div>
      <form id="nh" novalidate><fieldset><legend>Agregar horario</legend><div class="form-grid">
        ${campo({ l: 'Día', n: 'dia_semana', t: 'select', op: ORDEN_DIAS.map((d) => [d, d]), req: true })}
        ${campo({ l: 'Hora inicial', n: 'hora_inicio', t: 'time', v: '08:00', req: true })}
        ${campo({ l: 'Hora final', n: 'hora_fin', t: 'time', v: '12:00', req: true })}
        ${campo({ l: 'Duración de cada cita (min)', n: 'duracion_cita_min', t: 'number', v: 30, req: true, extra: 'min="5" max="240" step="5"' })}
      </div><p style="margin-top:.8rem"><button class="btn" type="submit">Agregar</button></p><div id="m"></div></fieldset></form>`;
    delegar(cuerpo, {
      quitar: async (hid) => {
        if (!(await confirmar('¿Quitar este horario? Las citas ya programadas no se eliminan.', { si: 'Quitar', peligro: true }))) return;
        await api('DELETE', `/horarios/${hid}`);
        toast('Horario eliminado');
        await pintar();
        await cargar();
      },
    });
    $('#nh', cuerpo).onsubmit = async (e) => {
      e.preventDefault();
      if (!e.target.checkValidity()) { e.target.reportValidity(); return; }
      try {
        const r = await api('POST', '/horarios', { ...leer(e.target), id_medico: Number(id) });
        $('#m', cuerpo).innerHTML = `<div class="aviso ok">Horario guardado: ${r.bloques} bloques de atención.</div>`;
        await pintar();
        await cargar();
      } catch (err) {
        $('#m', cuerpo).innerHTML = `<div class="aviso error">${esc(err.message)}</div>`;
      }
    };
    modal({ titulo: `Horarios de ${medico.nombres} ${medico.apellidos}`, cuerpo, botones: [{ texto: 'Cerrar', clase: 'sec' }] });
    await pintar();
  };

  $('#nuevo').onclick = () => formulario();
  delegar($('#t'), {
    editar: (id) => formulario(datos.find((x) => String(x.id_medico) === id)),
    horarios: (id) => modalHorarios(id),
  });
  await cargar();
}

// ---------------------------------------------------------------- bloqueos de agenda (CU-17)
export async function bloqueos(main) {
  const meds = (await api('GET', '/medicos?activos=1')).medicos;
  main.innerHTML = `<div class="cabecera"><div><h1>Bloqueos de agenda</h1><p>Feriados, vacaciones y capacitaciones que quitan horarios disponibles.</p></div>
    <button class="btn" id="nuevo" type="button">Nuevo bloqueo</button></div>
    <label class="check small" style="display:flex;gap:.4rem;margin-bottom:.8rem"><input type="checkbox" id="todos"> Incluir bloqueos pasados</label><div id="t"></div>`;

  const cargar = async () => {
    cargando($('#t'));
    const r = await api('GET', `/bloqueos${qs({ todos: $('#todos').checked ? 1 : '' })}`);
    $('#t').innerHTML = tabla([
      { t: 'Fecha', f: (b) => fmtFecha(b.fecha) },
      { t: 'Alcance', f: (b) => esc(b.alcance) },
      { t: 'Horas', f: (b) => (b.hora_inicio ? `${esc(b.hora_inicio)} a ${esc(b.hora_fin)}` : 'Día completo') },
      { t: 'Motivo', f: (b) => esc(b.motivo || '') },
      { t: '', f: (b) => `<button class="btn mini peligro sec" data-acc="quitar" data-id="${b.id_bloqueo}">Quitar</button>` },
    ], r.bloqueos, 'No hay bloqueos registrados.');
  };

  $('#todos').onchange = () => cargar();
  $('#nuevo').onclick = () => modal({
    titulo: 'Nuevo bloqueo',
    cuerpo: `<form novalidate><div class="form-grid">
      ${campo({ l: 'Aplica a', n: 'id_medico', t: 'select', op: [['', 'Toda la clínica'], ...meds.map((m) => [m.id_medico, `${m.apellidos} ${m.nombres}`])] })}
      ${campo({ l: 'Fecha', n: 'fecha', t: 'date', req: true })}
      ${campo({ l: 'Hora inicial', n: 'hora_inicio', t: 'time', ay: 'Deje las horas vacías para bloquear el día completo' })}
      ${campo({ l: 'Hora final', n: 'hora_fin', t: 'time' })}
      ${campo({ l: 'Motivo', n: 'motivo', w: true })}
    </div></form>`,
    botones: [
      { texto: 'Cancelar', clase: 'sec' },
      {
        texto: 'Guardar bloqueo',
        accion: async ({ cuerpo }) => {
          const f = $('form', cuerpo);
          if (!f.checkValidity()) { f.reportValidity(); return false; }
          const d = leer(f);
          d.id_medico = d.id_medico ? Number(d.id_medico) : null;
          const r = await api('POST', '/bloqueos', d);
          await cargar();
          if (r.citas_afectadas.length) {
            modal({
              titulo: 'Bloqueo guardado: hay citas por reprogramar',
              cuerpo: `<div class="aviso info">Estas citas ya estaban programadas dentro del bloqueo. Reprográmelas o cancélelas desde Citas.</div>
                ${tabla([{ t: 'Código', f: (c) => esc(c.codigo_cita) }, { t: 'Hora', f: (c) => esc(c.hora) }, { t: 'Paciente', f: (c) => esc(c.paciente) }], r.citas_afectadas)}`,
              botones: [{ texto: 'Entendido' }],
            });
          } else toast('Bloqueo guardado');
        },
      },
    ],
  });
  delegar($('#t'), {
    quitar: async (id) => {
      if (!(await confirmar('¿Quitar este bloqueo? Los horarios volverán a estar disponibles.', { si: 'Quitar', peligro: true }))) return;
      await api('DELETE', `/bloqueos/${id}`);
      toast('Bloqueo eliminado');
      await cargar();
    },
  });
  await cargar();
}
