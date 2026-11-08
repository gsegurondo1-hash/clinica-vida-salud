import { api, descargar, qs } from '../api.js';
import { $, esc, campo, leer, modal, tabla, delegar, toast, badge, cargando, fmtFecha } from '../ui.js';

// ---------------------------------------------------------------- usuarios y roles (CU-05)
export async function usuarios(main) {
  main.innerHTML = `<div class="cabecera"><div><h1>Usuarios y roles</h1><p>Cuentas de acceso del personal y de los pacientes.</p></div>
    <button class="btn" id="nuevo" type="button">Nuevo usuario</button></div><div id="t"></div>
    <section id="permisos" style="margin-top:2rem"></section>`;
  const [roles, meds, pacs] = await Promise.all([
    api('GET', '/roles'), api('GET', '/medicos'), api('GET', '/pacientes?estado=todos'),
  ]);
  let datos = [];

  const cargar = async () => {
    cargando($('#t'));
    datos = (await api('GET', '/usuarios')).usuarios;
    $('#t').innerHTML = tabla([
      { t: 'Usuario', f: (x) => `<strong>${esc(x.nombre_usuario)}</strong><br><span class="small muted">${esc(x.email)}</span>` },
      { t: 'Rol', f: (x) => esc(x.rol) },
      { t: 'Persona', f: (x) => esc(x.persona || '') },
      { t: 'Último acceso', f: (x) => esc(x.ultimo_acceso || 'Nunca') },
      { t: 'Estado', f: (x) => badge(x.habilitado ? 'Activo' : 'Inactivo') },
      { t: '', f: (x) => `<button class="btn mini sec" data-acc="editar" data-id="${x.id_usuario}">Editar</button>` },
    ], datos);
  };

  const formulario = (x = {}) => {
    const nuevo = !x.id_usuario;
    modal({
      titulo: nuevo ? 'Nuevo usuario' : `Editar ${x.nombre_usuario}`,
      cuerpo: `<form novalidate><div class="form-grid">
        ${nuevo ? campo({ l: 'Nombre de usuario', n: 'nombre_usuario', req: true, ay: 'Letras, números, punto, guion o @' }) : ''}
        ${campo({ l: 'Correo electrónico', n: 'email', t: 'email', v: x.email, req: true })}
        ${campo({ l: 'Rol', n: 'id_rol', t: 'select', v: x.id_rol, req: true, op: roles.roles.map((r) => [r.id_rol, r.nombre]) })}
        ${campo({ l: nuevo ? 'Contraseña' : 'Nueva contraseña (opcional)', n: 'password', t: 'password', req: nuevo, ay: 'Mínimo 8 caracteres, con letras y números', extra: 'autocomplete="new-password"' })}
        <div id="vm">${campo({ l: 'Médico asociado', n: 'id_medico', t: 'select', v: x.id_medico, op: [['', 'Seleccione'], ...meds.medicos.map((m) => [m.id_medico, `${m.apellidos} ${m.nombres}`])] })}</div>
        <div id="vp">${campo({ l: 'Paciente asociado', n: 'id_paciente', t: 'select', v: x.id_paciente, op: [['', 'Seleccione'], ...pacs.pacientes.map((p) => [p.id_paciente, `${p.apellidos} ${p.nombres}`])] })}</div>
        ${nuevo ? '' : `<label class="campo check"><input type="checkbox" name="habilitado" ${x.habilitado ? 'checked' : ''}> Cuenta activa</label>`}
      </div></form>`,
      botones: [
        { texto: 'Cancelar', clase: 'sec' },
        {
          texto: 'Guardar',
          accion: async ({ cuerpo }) => {
            const f = $('form', cuerpo);
            if (!f.checkValidity()) { f.reportValidity(); return false; }
            const d = leer(f);
            d.id_rol = Number(d.id_rol);
            d.id_medico = d.id_medico ? Number(d.id_medico) : null;
            d.id_paciente = d.id_paciente ? Number(d.id_paciente) : null;
            if (!d.password) delete d.password;
            if (nuevo) await api('POST', '/usuarios', d);
            else await api('PUT', `/usuarios/${x.id_usuario}`, { ...d, habilitado: f.habilitado.checked });
            toast('Usuario guardado');
            await cargar();
          },
        },
      ],
    });
    // Solo se muestra el vínculo que corresponde al rol elegido
    const rolSel = $('#c_id_rol');
    const ajustar = () => {
      const nombre = roles.roles.find((r) => String(r.id_rol) === rolSel.value)?.nombre;
      $('#vm').classList.toggle('hidden', nombre !== 'Médico');
      $('#vp').classList.toggle('hidden', nombre !== 'Paciente');
    };
    rolSel.onchange = ajustar;
    ajustar();
  };

  $('#nuevo').onclick = () => formulario();
  delegar($('#t'), { editar: (id) => formulario(datos.find((x) => String(x.id_usuario) === id)) });

  // Permisos básicos por rol (CU-05)
  const panel = $('#permisos');
  panel.innerHTML = `<h2>Permisos por rol</h2>
    <p class="muted small">Marque lo que puede hacer cada rol. Los cambios se aplican en el siguiente inicio de sesión de sus usuarios.</p>
    <form class="filtros" id="fp">${campo({ l: 'Rol', n: 'rol', t: 'select', op: roles.roles.map((r) => [r.id_rol, r.nombre]) })}</form>
    <div id="pl"></div>`;
  const mostrarPermisos = async () => {
    const id = $('#c_rol').value;
    cargando($('#pl'));
    const r = await api('GET', `/roles/${id}/permisos`);
    $('#pl').innerHTML = `<div class="form-grid">${r.permisos.map((p) => `<label class="campo check">
        <input type="checkbox" value="${esc(p.id_permiso)}" ${p.asignado ? 'checked' : ''}> <span><strong>${esc(p.nombre)}</strong><br><span class="small muted">${esc(p.descripcion || '')}</span></span></label>`).join('')}</div>
      <button class="btn" type="button" id="gp">Guardar permisos de ${esc(r.rol.nombre)}</button>`;
    $('#gp').onclick = async () => {
      const marcados = [...$('#pl').querySelectorAll('input[type=checkbox]:checked')].map((c) => Number(c.value));
      try {
        await api('PUT', `/roles/${id}/permisos`, { permisos: marcados });
        toast('Permisos guardados');
      } catch (err) {
        toast(err.message, 'error');
      }
    };
  };
  $('#c_rol').onchange = () => mostrarPermisos().catch((e) => toast(e.message, 'error'));
  await Promise.all([cargar(), mostrarPermisos()]);
}

// ---------------------------------------------------------------- reportes (CU-19)
export async function reportes(main) {
  const hoy = new Date();
  const ini = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
  const fin = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0);
  const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  const [tipos, meds, esps] = await Promise.all([api('GET', '/reportes'), api('GET', '/medicos'), api('GET', '/especialidades')]);

  main.innerHTML = `<div class="cabecera"><div><h1>Reportes</h1><p>Genere informes por fecha, médico, especialidad y estado.</p></div></div>
    <form class="filtros" id="f">
      ${campo({ l: 'Reporte', n: 'tipo', t: 'select', op: tipos.tipos.map((t) => [t.tipo, t.titulo]) })}
      ${campo({ l: 'Desde', n: 'desde', t: 'date', v: iso(ini), req: true })}
      ${campo({ l: 'Hasta', n: 'hasta', t: 'date', v: iso(fin), req: true })}
      ${campo({ l: 'Médico', n: 'id_medico', t: 'select', op: [['', 'Todos'], ...meds.medicos.map((m) => [m.id_medico, `${m.apellidos} ${m.nombres}`])] })}
      ${campo({ l: 'Especialidad', n: 'id_especialidad', t: 'select', op: [['', 'Todas'], ...esps.especialidades.map((e) => [e.id_especialidad, e.nombre])] })}
      ${campo({ l: 'Estado', n: 'estado', t: 'select', op: [['', 'Todos'], ...['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'].map((e) => [e, e])] })}
      <button class="btn" type="submit">Generar</button>
      <button class="btn sec" type="button" id="csv" disabled>Exportar CSV</button>
      <button class="btn sec" type="button" id="imprimir" disabled>Imprimir o guardar PDF</button>
    </form><div id="r"></div>`;

  const parametros = () => {
    const f = leer($('#f'));
    const { tipo, ...resto } = f;
    return { tipo, q: qs(resto) };
  };
  $('#f').onsubmit = async (e) => {
    e.preventDefault();
    const { tipo, q } = parametros();
    cargando($('#r'));
    try {
      const r = await api('GET', `/reportes/${tipo}${q}`);
      const tot = Object.entries(r.totales).map(([k, v]) => `<div class="cifra"><b>${esc(v)}</b><span>${esc(k)}</span></div>`).join('');
      $('#r').innerHTML = `<h2>${esc(r.titulo)}</h2><p class="muted">Del ${fmtFecha(r.desde)} al ${fmtFecha(r.hasta)}</p>
        <div class="cifras">${tot}</div>
        ${tabla(r.columnas.map((c) => ({ t: c.label, f: (fila) => esc(fila[c.k]) })), r.filas, 'No hay datos para esos filtros.')}`;
      $('#csv').disabled = false;
      $('#imprimir').disabled = false;
    } catch (err) {
      $('#r').innerHTML = `<div class="aviso error">${esc(err.message)}</div>`;
    }
  };
  $('#csv').onclick = () => {
    const { tipo, q } = parametros();
    descargar(`/reportes/${tipo}${q}${q ? '&' : '?'}formato=csv`, `reporte-${tipo}.csv`).catch((e) => toast(e.message, 'error'));
  };
  $('#imprimir').onclick = () => window.print();
}

// ---------------------------------------------------------------- bitácora (CU-20)
export async function bitacora(main) {
  main.innerHTML = `<div class="cabecera"><div><h1>Bitácora de auditoría</h1><p>Quién hizo qué y cuándo, explicado en palabras. Se muestran los últimos 300 eventos.</p></div></div>
    <form class="filtros" id="f"></form><div id="t"></div>`;

  const NOMBRES = { citas: 'Citas', pacientes: 'Pacientes', medicos: 'Médicos', usuarios: 'Usuarios', especialidades: 'Especialidades', horarios_atencion: 'Horarios de atención', bloqueos_agenda: 'Bloqueos de agenda', rol_permiso: 'Permisos por rol' };

  const cargar = async () => {
    const r = await api('GET', `/bitacora${qs(leer($('#f')))}`);
    if (!$('#f').children.length) {
      $('#f').innerHTML = `${campo({ l: 'Usuario', n: 'id_usuario', t: 'select', op: [['', 'Todos'], ...r.usuarios.map((u) => [u.id_usuario, u.nombre_usuario])] })}
        ${campo({ l: 'Tabla', n: 'tabla', t: 'select', op: [['', 'Todas'], ...r.tablas.map((t) => [t, NOMBRES[t] || t])] })}
        ${campo({ l: 'Acción', n: 'accion', t: 'select', op: [['', 'Todas'], ['INSERT', 'Alta'], ['UPDATE', 'Cambio'], ['DELETE', 'Baja']] })}
        ${campo({ l: 'Desde', n: 'desde', t: 'date' })}${campo({ l: 'Hasta', n: 'hasta', t: 'date' })}
        <button class="btn sec" type="submit">Filtrar</button>
        <button class="btn sec" type="button" id="csv">Exportar CSV</button>`;
      $('#csv').onclick = () => {
        descargar(`/bitacora${qs({ ...leer($('#f')), formato: 'csv' })}`, 'bitacora.csv').catch((e) => toast(e.message, 'error'));
      };
    }
    $('#t').innerHTML = tabla([
      { t: 'Fecha', f: (e) => esc(e.fecha) },
      { t: 'Usuario', f: (e) => esc(e.usuario) },
      { t: 'Sección', f: (e) => esc(e.tabla_nombre) },
      { t: 'Acción', f: (e) => esc(e.accion_nombre) },
      { t: 'Qué hizo', f: (e) => esc(e.descripcion) },
    ], r.eventos, 'No hay eventos para esos filtros.');
  };
  $('#f').onsubmit = (e) => { e.preventDefault(); cargar().catch((x) => toast(x.message, 'error')); };
  await cargar();
}
