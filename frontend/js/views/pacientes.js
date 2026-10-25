import { api, qs } from '../api.js';
import { $, esc, campo, leer, modal, confirmar, tabla, delegar, toast, badge, cargando, fmtFecha, can } from '../ui.js';

const SANGRE = [['', 'Sin dato'], ...['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((s) => [s, s])];
const SEXOS = [['', 'Seleccione'], ['Femenino', 'Femenino'], ['Masculino', 'Masculino'], ['Otro', 'Otro']];

/** Campos de ficha de paciente: datos personales, información médica y contacto de emergencia. */
function formularioPaciente(p = {}, { staff = false, cuenta = false } = {}) {
  const im = p.info_medica || {};
  const ct = p.contacto || {};
  return `<fieldset><legend>Datos personales</legend><div class="form-grid">
      ${campo({ l: 'Nombres', n: 'nombres', v: p.nombres, req: true })}
      ${campo({ l: 'Apellidos', n: 'apellidos', v: p.apellidos, req: true })}
      ${staff ? campo({ l: 'CI', n: 'ci', v: p.ci, ay: 'Puede quedar vacío en menores sin documento' }) : campo({ l: 'CI', n: 'ci_ver', v: p.ci, extra: 'disabled' })}
      ${campo({ l: 'Fecha de nacimiento', n: 'fecha_nacimiento', t: 'date', v: p.fecha_nacimiento, req: true })}
      ${campo({ l: 'Sexo', n: 'sexo', t: 'select', v: p.sexo, op: SEXOS, req: true })}
      ${campo({ l: 'Teléfono', n: 'telefono', t: 'tel', v: p.telefono, req: true })}
      ${campo({ l: 'Correo electrónico', n: 'email', t: 'email', v: p.email })}
      ${campo({ l: 'Dirección', n: 'direccion', v: p.direccion })}
      ${cuenta ? campo({ l: 'Contraseña de acceso (opcional)', n: 'password', t: 'password', ay: 'Si la completa se crea una cuenta; requiere correo', extra: 'autocomplete="new-password"' }) : ''}
    </div></fieldset>
    <fieldset><legend>Información médica</legend><div class="form-grid">
      ${campo({ l: 'Tipo de sangre', n: 'tipo_sangre', t: 'select', v: im.tipo_sangre, op: SANGRE })}
      ${campo({ l: 'Alergias', n: 'alergias', t: 'textarea', v: im.alergias })}
      ${campo({ l: 'Enfermedades crónicas', n: 'enfermedades_cronicas', t: 'textarea', v: im.enfermedades_cronicas })}
      ${campo({ l: 'Medicamentos de uso habitual', n: 'medicamentos', t: 'textarea', v: im.medicamentos })}
      ${campo({ l: 'Antecedentes relevantes', n: 'antecedentes', t: 'textarea', v: im.antecedentes, w: true })}
    </div></fieldset>
    <fieldset><legend>Contacto de emergencia</legend><div class="form-grid">
      ${campo({ l: 'Nombre', n: 'c_nombre', v: ct.nombre })}
      ${campo({ l: 'Parentesco', n: 'c_parentesco', v: ct.parentesco })}
      ${campo({ l: 'Teléfono', n: 'c_telefono', t: 'tel', v: ct.telefono })}
    </div></fieldset>`;
}

/** Convierte el formulario plano en el JSON anidado que espera la API. */
function aJson(form) {
  const d = leer(form);
  const r = {
    nombres: d.nombres, apellidos: d.apellidos, fecha_nacimiento: d.fecha_nacimiento, sexo: d.sexo,
    telefono: d.telefono, email: d.email, direccion: d.direccion,
    info_medica: {
      tipo_sangre: d.tipo_sangre, alergias: d.alergias, enfermedades_cronicas: d.enfermedades_cronicas,
      medicamentos: d.medicamentos, antecedentes: d.antecedentes,
    },
    contacto: { nombre: d.c_nombre, parentesco: d.c_parentesco, telefono: d.c_telefono },
  };
  if ('ci' in d) r.ci = d.ci;
  if (d.password) r.password = d.password;
  return r;
}

// ---------------------------------------------------------------- perfil del paciente (CU-04)
export async function perfil(main, u) {
  cargando(main);
  const p = await api('GET', '/perfil');
  main.innerHTML = `<div class="cabecera"><div><h1>Mi perfil</h1><p>Código de paciente ${esc(p.codigo)}. Mantenga sus datos al día para recibir sus avisos.</p></div></div>
    <form id="f" class="panel" novalidate>${formularioPaciente(p)}<div id="msg"></div>
    <p style="margin-top:1rem"><button class="btn" type="submit">Guardar cambios</button></p></form>`;
  $('#f').onsubmit = async (e) => {
    e.preventDefault();
    if (!e.target.checkValidity()) { e.target.reportValidity(); return; }
    try {
      await api('PUT', '/perfil', aJson(e.target));
      $('#msg').innerHTML = '<div class="aviso ok">Perfil actualizado.</div>';
      toast('Perfil actualizado');
    } catch (err) {
      $('#msg').innerHTML = `<div class="aviso error">${esc(err.message)}</div>`;
    }
  };
}

// ---------------------------------------------------------------- pacientes en recepción (CU-09)
export async function lista(main, u) {
  main.innerHTML = `<div class="cabecera"><div><h1>Pacientes</h1><p>Busque por CI, nombre, apellido o código.</p></div>
    <button class="btn" id="nuevo" type="button">Registrar paciente</button></div>
    <form class="filtros" id="f">${campo({ l: 'Buscar', n: 'q' })}
      ${campo({ l: 'Estado', n: 'estado', t: 'select', v: 'activos', op: [['activos', 'Activos'], ['inactivos', 'Inactivos'], ['todos', 'Todos']] })}
      <button class="btn sec" type="submit">Buscar</button></form><div id="t"></div>`;

  const cols = [
    { t: 'Código', f: (p) => `<strong>${esc(p.codigo)}</strong>` },
    { t: 'CI', f: (p) => esc(p.ci || 'Sin CI') },
    { t: 'Paciente', f: (p) => `${esc(p.nombres)} ${esc(p.apellidos)}` },
    { t: 'Teléfono', f: (p) => esc(p.telefono) },
    { t: 'Cuenta', f: (p) => (p.tiene_cuenta ? 'Sí' : 'No') },
    { t: 'Estado', f: (p) => badge(p.habilitado ? 'Activo' : 'Inactivo') },
    {
      t: 'Acciones',
      f: (p) => `<div class="acciones">
        <button class="btn mini sec" data-acc="editar" data-id="${p.id_paciente}">Editar</button>
        <a class="btn mini sec" href="#/historial/${p.id_paciente}">Historial</a>
        <button class="btn mini ${p.habilitado ? 'peligro sec' : ''}" data-acc="estado" data-id="${p.id_paciente}" data-h="${p.habilitado ? 1 : 0}">${p.habilitado ? 'Desactivar' : 'Activar'}</button></div>`,
    },
  ];
  const cargar = async () => {
    cargando($('#t'));
    const r = await api('GET', `/pacientes${qs(leer($('#f')))}`);
    $('#t').innerHTML = tabla(cols, r.pacientes, 'No se encontraron pacientes.');
  };

  const formulario = (titulo, paciente, cuenta, guardar) => {
    modal({
      titulo,
      cuerpo: `<form novalidate>${formularioPaciente(paciente, { staff: true, cuenta })}</form>`,
      botones: [
        { texto: 'Cancelar', clase: 'sec' },
        {
          texto: 'Guardar',
          accion: async ({ cuerpo }) => {
            const f = $('form', cuerpo);
            if (!f.checkValidity()) { f.reportValidity(); return false; }
            await guardar(aJson(f));
            toast('Paciente guardado');
            await cargar();
          },
        },
      ],
    });
  };

  $('#nuevo').onclick = () => formulario('Registrar paciente', {}, true, (d) => api('POST', '/pacientes', d));
  $('#f').onsubmit = (e) => { e.preventDefault(); cargar().catch((x) => toast(x.message, 'error')); };
  delegar($('#t'), {
    editar: async (id) => {
      const p = await api('GET', `/pacientes/${id}`);
      formulario(`Editar a ${p.nombres} ${p.apellidos}`, p, false, (d) => api('PUT', `/pacientes/${id}`, d));
    },
    estado: async (id, b) => {
      const desactivar = b.dataset.h === '1';
      const ok = await confirmar(desactivar
        ? 'El paciente dejará de poder reservar citas, pero su historial se conserva. ¿Desactivarlo?'
        : '¿Volver a activar a este paciente?', { si: desactivar ? 'Desactivar' : 'Activar', peligro: desactivar });
      if (!ok) return;
      await api('PATCH', `/pacientes/${id}/estado`, { habilitado: !desactivar });
      toast(desactivar ? 'Paciente desactivado' : 'Paciente activado');
      await cargar();
    },
  });
  await cargar();
}
