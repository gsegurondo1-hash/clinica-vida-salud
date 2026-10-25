import { api, sesion } from '../api.js';
import { MOSTRAR_DEMO } from '../config.js';
import { $, esc, campo, leer, toast } from '../ui.js';

function marco(contenido) {
  return `<div class="acceso">
    <section class="acceso-hero" aria-hidden="false">
      <div>
        <h1>Reserve su cita sin llamar a la clínica</h1>
        <p>Elija especialidad, médico y fecha. Solo verá los horarios que realmente están libres.</p>
      </div>
      <div class="regla" role="img" aria-label="Ejemplo de agenda: algunos horarios reservados y otros disponibles">
        <div class="h">08:00</div><div class="s ocupado">Reservado</div>
        <div class="h">08:30</div><div class="s libre">Disponible</div>
        <div class="h">09:00</div><div class="s ocupado">Reservado</div>
        <div class="h">09:30</div><div class="s ocupado">Reservado</div>
        <div class="h">10:00</div><div class="s libre">Disponible</div>
      </div>
      <p class="small">Clínica Vida Salud · Medicina general, pediatría, ginecología, odontología y cardiología</p>
    </section>
    <section class="acceso-form"><div class="caja">${contenido}</div></section>
  </div>`;
}

export function login(app) {
  app.innerHTML = marco(`
    <h2>Iniciar sesión</h2>
    <form id="f" novalidate>
      <div class="form-grid" style="grid-template-columns:1fr">
        ${campo({ l: 'Usuario o correo', n: 'usuario', req: true, extra: 'autocomplete="username"' })}
        ${campo({ l: 'Contraseña', n: 'password', t: 'password', req: true, extra: 'autocomplete="current-password"' })}
      </div>
      <div id="msg"></div>
      <p style="margin-top:1rem"><button class="btn" type="submit">Iniciar sesión</button></p>
    </form>
    <div class="enlaces"><a href="#/registro">Crear cuenta de paciente</a><a href="#/recuperar">Olvidé mi contraseña</a></div>
    ${MOSTRAR_DEMO ? `<p class="demo muted">Cuentas de prueba, contraseña <code>Clinica2026*</code>:
      <code>admin</code>, <code>recepcion1</code>, <code>jperez</code> (médico), <code>mlopez</code> (paciente).</p>` : ''}`);

  let reloj = null;
  const bloquear = (segundos) => {
    const btn = $('#f button[type=submit]');
    const fin = Date.now() + segundos * 1000;
    clearInterval(reloj);
    btn.disabled = true;
    const pintar = () => {
      const resta = Math.max(0, Math.ceil((fin - Date.now()) / 1000));
      const mm = String(Math.floor(resta / 60)).padStart(2, '0');
      const ss = String(resta % 60).padStart(2, '0');
      if (resta === 0) {
        clearInterval(reloj);
        $('#msg').innerHTML = '<div class="aviso info">Ya puede intentar nuevamente.</div>';
        btn.disabled = false;
        return;
      }
      $('#msg').innerHTML = `<div class="aviso error" role="alert"><strong>Acceso bloqueado temporalmente</strong><br>
        Se superó el número de intentos fallidos. Por seguridad podrá intentar de nuevo en <strong>${mm}:${ss}</strong>.
        Si olvidó su contraseña, use "Olvidé mi contraseña".</div>`;
    };
    pintar();
    reloj = setInterval(pintar, 1000);
  };

  $('#f').onsubmit = async (e) => {
    e.preventDefault();
    const d = leer(e.target);
    const btn = $('button', e.target);
    $('#msg').innerHTML = '';
    if (!d.usuario || !d.password) { $('#msg').innerHTML = '<div class="aviso error">Ingrese usuario y contraseña.</div>'; return; }
    btn.disabled = true;
    btn.textContent = 'Ingresando…';
    try {
      const r = await api('POST', '/auth/login', d);
      sesion.guardar(r.token);
      window.dispatchEvent(new CustomEvent('sesion-iniciada', { detail: r.usuario }));
    } catch (err) {
      btn.textContent = 'Iniciar sesión';
      if (err.estado === 429 && err.datos.bloqueado_seg) {
        bloquear(err.datos.bloqueado_seg);
        return;
      }
      $('#msg').innerHTML = `<div class="aviso error" role="alert">${esc(err.message)}</div>`;
      btn.disabled = false;
    }
  };
}

export function registro(app) {
  app.innerHTML = marco(`
    <h2>Crear cuenta de paciente</h2>
    <form id="f" novalidate>
      <div class="form-grid">
        ${campo({ l: 'Nombres', n: 'nombres', req: true })}
        ${campo({ l: 'Apellidos', n: 'apellidos', req: true })}
        ${campo({ l: 'CI', n: 'ci', req: true })}
        ${campo({ l: 'Fecha de nacimiento', n: 'fecha_nacimiento', t: 'date', req: true })}
        ${campo({ l: 'Sexo', n: 'sexo', t: 'select', req: true, op: [['', 'Seleccione'], ['Femenino', 'Femenino'], ['Masculino', 'Masculino'], ['Otro', 'Otro']] })}
        ${campo({ l: 'Teléfono', n: 'telefono', t: 'tel', req: true })}
        ${campo({ l: 'Correo electrónico', n: 'email', t: 'email', req: true, w: true })}
        ${campo({ l: 'Contraseña', n: 'password', t: 'password', req: true, ay: 'Mínimo 8 caracteres, con letras y números', extra: 'autocomplete="new-password"' })}
        ${campo({ l: 'Repetir contraseña', n: 'password2', t: 'password', req: true, extra: 'autocomplete="new-password"' })}
      </div>
      <div id="msg"></div>
      <p style="margin-top:1rem"><button class="btn" type="submit">Crear cuenta</button></p>
    </form>
    <div class="enlaces"><a href="#/login">Ya tengo cuenta</a></div>`);

  $('#f').onsubmit = async (e) => {
    e.preventDefault();
    const d = leer(e.target);
    const aviso = (m) => { $('#msg').innerHTML = `<div class="aviso error">${esc(m)}</div>`; };
    if (!e.target.checkValidity()) { e.target.reportValidity(); return; }
    if (d.password !== d.password2) { aviso('Las contraseñas no coinciden.'); return; }
    delete d.password2;
    const btn = $('button', e.target);
    btn.disabled = true;
    try {
      await api('POST', '/auth/registro', d);
      toast('Cuenta creada. Ya puede iniciar sesión.');
      location.hash = '#/login';
    } catch (err) {
      aviso(err.message);
      btn.disabled = false;
    }
  };
}

export function recuperar(app) {
  app.innerHTML = marco(`
    <h2>Recuperar contraseña</h2>
    <p class="muted">Escriba el correo de su cuenta y le enviaremos un enlace temporal.</p>
    <form id="f" novalidate>
      ${campo({ l: 'Correo electrónico', n: 'email', t: 'email', req: true })}
      <div id="msg"></div>
      <p style="margin-top:1rem"><button class="btn" type="submit">Enviar enlace</button></p>
    </form>
    <div class="enlaces"><a href="#/login">Volver a iniciar sesión</a></div>`);

  $('#f').onsubmit = async (e) => {
    e.preventDefault();
    if (!e.target.checkValidity()) { e.target.reportValidity(); return; }
    try {
      const r = await api('POST', '/auth/recuperar', leer(e.target));
      let html = `<div class="aviso ok">${esc(r.mensaje)}</div>`;
      if (r.enlace_demo) html += `<div class="aviso info">Modo demostración: use <a href="${esc(r.enlace_demo)}">este enlace</a> para continuar.</div>`;
      $('#msg').innerHTML = html;
    } catch (err) {
      $('#msg').innerHTML = `<div class="aviso error">${esc(err.message)}</div>`;
    }
  };
}

export function restablecer(app, { token }) {
  app.innerHTML = marco(`
    <h2>Nueva contraseña</h2>
    <form id="f" novalidate>
      <div class="form-grid" style="grid-template-columns:1fr">
        ${campo({ l: 'Nueva contraseña', n: 'password', t: 'password', req: true, ay: 'Mínimo 8 caracteres, con letras y números', extra: 'autocomplete="new-password"' })}
        ${campo({ l: 'Repetir contraseña', n: 'password2', t: 'password', req: true, extra: 'autocomplete="new-password"' })}
      </div>
      <div id="msg"></div>
      <p style="margin-top:1rem"><button class="btn" type="submit">Guardar contraseña</button></p>
    </form>`);

  $('#f').onsubmit = async (e) => {
    e.preventDefault();
    const d = leer(e.target);
    if (!e.target.checkValidity()) { e.target.reportValidity(); return; }
    if (d.password !== d.password2) { $('#msg').innerHTML = '<div class="aviso error">Las contraseñas no coinciden.</div>'; return; }
    try {
      await api('POST', '/auth/restablecer', { token, password: d.password });
      toast('Contraseña actualizada. Inicie sesión.');
      location.hash = '#/login';
    } catch (err) {
      $('#msg').innerHTML = `<div class="aviso error">${esc(err.message)}</div>`;
    }
  };
}
