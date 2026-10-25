export const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const $ = (sel, raiz = document) => raiz.querySelector(sel);
export const $$ = (sel, raiz = document) => [...raiz.querySelectorAll(sel)];

export function toast(msg, tipo = 'ok') {
  const t = document.createElement('div');
  t.className = `toast ${tipo === 'error' ? 'error' : ''}`;
  t.textContent = msg;
  $('#toasts').append(t);
  setTimeout(() => t.remove(), tipo === 'error' ? 6000 : 3500);
}

const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
export const ORDEN_DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
export const fmtFecha = (iso) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '');
export function nombreDia(iso) {
  const [y, m, d] = iso.split('-').map(Number);
  return DIAS[new Date(y, m - 1, d).getDay()];
}
export function hoyISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
export const badge = (e) => `<span class="estado ${esc(e)}">${esc(e)}</span>`;
export const can = (u, p) => u.permisos.includes(p);

/** Genera un campo de formulario. */
export function campo({ l, n, t = 'text', v = '', req = false, op = null, ay = '', w = false, extra = '' }) {
  const id = `c_${n}`;
  const r = req ? 'required' : '';
  let ctl;
  if (t === 'select') {
    ctl = `<select id="${id}" name="${n}" ${r} ${extra}>${op.map(([val, txt]) => `<option value="${esc(val)}" ${String(val) === String(v ?? '') ? 'selected' : ''}>${esc(txt)}</option>`).join('')}</select>`;
  } else if (t === 'textarea') {
    ctl = `<textarea id="${id}" name="${n}" ${r} ${extra}>${esc(v)}</textarea>`;
  } else {
    ctl = `<input id="${id}" name="${n}" type="${t}" value="${esc(v)}" ${r} ${extra}>`;
  }
  return `<div class="campo ${w ? 'ancho' : ''}"><label for="${id}">${esc(l)}${req ? ' *' : ''}</label>${ctl}${ay ? `<span class="ayuda">${esc(ay)}</span>` : ''}</div>`;
}

export function leer(form) {
  const o = {};
  new FormData(form).forEach((v, k) => { o[k] = typeof v === 'string' ? v.trim() : v; });
  return o;
}

/** Ventana modal. Cada boton: { texto, clase, accion }. Si accion devuelve false o lanza error, no se cierra. */
export function modal({ titulo, cuerpo, botones = [] }) {
  const velo = document.createElement('div');
  velo.className = 'velo';
  velo.innerHTML = `<div class="modal" role="dialog" aria-modal="true" aria-labelledby="mt">
    <header><h2 id="mt">${esc(titulo)}</h2><button type="button" class="cerrar" aria-label="Cerrar">×</button></header>
    <div class="mcuerpo"></div><div class="maviso"></div><footer></footer></div>`;
  const cont = $('.mcuerpo', velo);
  if (typeof cuerpo === 'string') cont.innerHTML = cuerpo; else cont.append(cuerpo);
  const cerrar = () => { velo.remove(); document.removeEventListener('keydown', alEscape); };
  const alEscape = (e) => { if (e.key === 'Escape') cerrar(); };
  document.addEventListener('keydown', alEscape);
  $('.cerrar', velo).onclick = cerrar;

  const pie = $('footer', velo);
  const aviso = $('.maviso', velo);
  botones.forEach((b) => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `btn ${b.clase || ''}`;
    btn.textContent = b.texto;
    btn.onclick = async () => {
      aviso.innerHTML = '';
      btn.disabled = true;
      try {
        const r = b.accion ? await b.accion({ cuerpo: cont, cerrar }) : undefined;
        if (r !== false) cerrar();
      } catch (e) {
        aviso.innerHTML = `<div class="aviso error">${esc(e.message)}</div>`;
      } finally {
        btn.disabled = false;
      }
    };
    pie.append(btn);
  });
  document.body.append(velo);
  const primero = $('input, select, textarea, button.btn', cont) || $('.cerrar', velo);
  primero?.focus();
  return { cerrar, cuerpo: cont };
}

export function confirmar(texto, { si = 'Aceptar', peligro = false, titulo = 'Confirmar' } = {}) {
  return new Promise((resolver) => {
    let hecho = false;
    const fin = (v) => { if (!hecho) { hecho = true; resolver(v); } };
    const m = modal({
      titulo,
      cuerpo: `<p>${esc(texto)}</p>`,
      botones: [
        { texto: 'Volver', clase: 'sec', accion: () => { fin(false); } },
        { texto: si, clase: peligro ? 'peligro' : '', accion: () => { fin(true); } },
      ],
    });
    $('.cerrar', m.cuerpo.closest('.velo')).addEventListener('click', () => fin(false));
  });
}

/** Tabla simple. cols: [{ t, f(fila) => html, cls }] */
export function tabla(cols, filas, vacio = 'No hay registros para mostrar.') {
  if (!filas.length) return `<div class="vacio">${esc(vacio)}</div>`;
  return `<div class="tabla-wrap"><table><thead><tr>${cols.map((c) => `<th class="${c.cls || ''}">${esc(c.t)}</th>`).join('')}</tr></thead>
    <tbody>${filas.map((f) => `<tr>${cols.map((c) => `<td class="${c.cls || ''}">${c.f(f)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
}

/** Escucha clics en botones con data-acc dentro de un contenedor. */
export function delegar(cont, acciones) {
  cont.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-acc]');
    if (!b || !cont.contains(b)) return;
    const fn = acciones[b.dataset.acc];
    if (!fn) return;
    e.preventDefault();
    try { await fn(b.dataset.id, b); } catch (err) { toast(err.message, 'error'); }
  });
}

export const cargando = (cont) => { cont.innerHTML = '<p class="muted">Cargando…</p>'; };
