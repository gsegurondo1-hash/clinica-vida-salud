# Clínica Vida Salud · Aplicación web para gestionar citas médicas

Implementación del documento PUDS-UML (Sistemas 1). Cubre los 20 casos de uso de los dos ciclos.

```
clinica-vida-salud/
├── backend/                 API REST en PHP 8.2 + PostgreSQL (PDO)
│   ├── public/index.php     Punto de entrada y rutas (/api/...)
│   ├── app/core/            Env, Db, Http, V (validaciones), Auth (JWT), Router
│   ├── app/controllers/     Auth, Paciente, Usuario, Catalogo, Cita, Reporte
│   ├── app/services/        Mailer (correo) y Notificador (recordatorios)
│   ├── sql/                 esquema, datos de ejemplo, procedimientos y triggers
│   ├── scripts/             setup_db.php (crea la BD) y recordatorios.php (cron)
│   ├── docker/ + Dockerfile Imagen para Render
│   └── .env.example
├── frontend/                Sitio estático (HTML, CSS y JavaScript sin dependencias)
│   ├── index.html, css/, js/
│   └── build.sh             Genera js/config.js con la URL de la API (Render)
├── docker-compose.yml       Todo en local con un comando
└── render.yaml              Blueprint de Render (BD + API + sitio)
```

Las reglas de negocio críticas (sin citas duplicadas ni superpuestas, estados válidos, historial, bitácora, no borrar
registros con citas) viven en la base de datos: triggers y procedimientos del documento. La API solo los invoca.

## Usuarios de prueba

Contraseña de todos: `Clinica2026*` (se puede cambiar con `DEMO_PASSWORD`).

| Usuario | Rol |
|---|---|
| `admin` | Administrador |
| `recepcion1` | Recepcionista |
| `jperez`, `lfernandez`, `rjustiniano` | Médico |
| `mlopez`, `pgutierrez`, `asuarez` | Paciente |

## Ejecutar en local

### Opción A: Docker (la más simple)

```bash
docker compose up --build
```
Abra http://localhost:8080. La primera vez se crean las tablas y los datos de ejemplo.

### Opción B: sin Docker

Requisitos: PHP 8.1 o superior con las extensiones `pdo_pgsql` y `curl`, y PostgreSQL.
En XAMPP active `extension=pdo_pgsql` y `extension=pgsql` en `php.ini` y reinicie Apache.

```bash
# 1) Cree una base vacía
createdb -U postgres clinica_vida_salud

# 2) Backend
cd backend
cp .env.example .env            # ajuste DB_USER y DB_PASSWORD
php scripts/setup_db.php        # crea tablas, datos, procedimientos y triggers
php -S localhost:8000 -t public public/index.php

# 3) Frontend (otra terminal)
cd frontend
python -m http.server 8080      # o: npx serve -l 8080
```
Abra http://localhost:8080. Los módulos JavaScript necesitan un servidor HTTP: no abra `index.html` con doble clic.
Para recrear la base desde cero: `php scripts/setup_db.php --reset`.

## Desplegar en Render

### 0) Subir el proyecto a GitHub

```bash
git init && git add . && git commit -m "Clinica Vida Salud"
git branch -M main
git remote add origin https://github.com/SU_USUARIO/clinica-vida-salud.git
git push -u origin main
```

### Opción 1: Blueprint (automático)

1. En https://dashboard.render.com elija **New > Blueprint** y seleccione el repositorio. Render lee `render.yaml`.
2. Cuando lo pida, complete las variables marcadas sin valor:
   - `API_URL` = `https://clinica-vida-salud-api.onrender.com/api`
   - `CORS_ORIGIN` y `FRONTEND_URL` = `https://clinica-vida-salud-web.onrender.com`
   (si Render agrega un sufijo a los nombres, use las URLs reales de cada servicio).
3. Pulse **Apply**. Al terminar abra la URL del servicio `clinica-vida-salud-web`.

### Opción 2: Manual

1. **Base de datos**: New > PostgreSQL. Nombre `clinica-db`, plan Free. Copie la **Internal Database URL**.
2. **Backend**: New > Web Service > su repositorio.
   - Language: **Docker**. Root Directory: `backend`. Plan: Free.
   - Variables de entorno:

     | Variable | Valor |
     |---|---|
     | `DATABASE_URL` | Internal Database URL de la base |
     | `APP_SECRET` | texto aleatorio de 32 caracteres o más |
     | `APP_TIMEZONE` | `America/La_Paz` |
     | `DEMO_MODE` | `false` |
     | `CRON_SECRET` | texto aleatorio (para los recordatorios) |
     | `CORS_ORIGIN` | URL del frontend (se completa en el paso 4) |
     | `FRONTEND_URL` | URL del frontend (paso 4) |

   - Health Check Path: `/api/health`. Al primer arranque se crean las tablas y los datos de ejemplo.
   - Compruebe `https://SU-API.onrender.com/api/health`: debe responder `"base_de_datos": true`.
3. **Frontend**: New > Static Site > mismo repositorio.
   - Root Directory: `frontend`. Build Command: `sh build.sh`. Publish Directory: `.`
   - Variable: `API_URL` = `https://SU-API.onrender.com/api`
4. Vuelva al backend y complete `CORS_ORIGIN` y `FRONTEND_URL` con la URL del sitio estático (sin `/` final). Render reinicia el servicio.

### Recordatorios automáticos (CU-18)

Los cron jobs de Render son de pago. Opciones gratuitas: crear un trabajo diario en https://cron-job.org que llame a
`https://SU-API.onrender.com/api/cron/recordatorios` con la cabecera `X-Cron-Key: <valor de CRON_SECRET>`.
Genera los recordatorios de las citas de mañana y envía las notificaciones pendientes.

### Correos reales: recuperación de contraseña y notificaciones

El enlace para restablecer la contraseña **solo debe llegar al correo del usuario**. Por eso en la nube use
`DEMO_MODE=false`: así la pantalla nunca muestra el enlace. (`DEMO_MODE=true` sin proveedor de correo es solo para pruebas locales.)

Render gratis bloquea SMTP, de modo que el backend envía por API HTTPS con [Brevo](https://www.brevo.com) (plan gratis,
300 correos/día, no necesita dominio propio):

1. Cree una cuenta en Brevo y verifique su correo Gmail en **Senders, domains & dedicated IPs → Senders**.
2. En **SMTP & API → API keys** genere una clave.
3. En el backend de Render agregue: `BREVO_API_KEY` = la clave, `MAIL_FROM_EMAIL` = su Gmail verificado, `DEMO_MODE` = `false`.
4. Si Brevo muestra "transactional platform has not been activated", pida la activación a su soporte.

Los correos enviados desde una dirección Gmail pueden caer en spam; para uso real conviene un dominio propio.
Alternativa: Resend (`RESEND_API_KEY`), que sin dominio verificado solo envía al correo de su propia cuenta.
Sin ningún proveedor los correos se simulan y el paciente ve la notificación dentro de la aplicación.

### Límites del plan gratuito de Render

- El backend se duerme tras 15 minutos sin uso; la primera petición tarda cerca de un minuto en despertar.
- La base PostgreSQL gratuita **expira a los 30 días** de su creación (con unos días de gracia antes de borrarse) y no tiene
  copias de seguridad. Para una demostración sirve; para uso real conviene un plan de pago.
- Para dejar la demo "limpia" ponga `MOSTRAR_DEMO=false` en el frontend y cambie `DEMO_PASSWORD`.

## API (resumen)

Autenticación con `Authorization: Bearer <token>`. Todas las rutas cuelgan de `/api`.

| Módulo | Rutas |
|---|---|
| Acceso | `POST /auth/login`, `/auth/registro`, `/auth/recuperar`, `/auth/restablecer`, `GET /auth/yo` |
| Perfil | `GET/PUT /perfil` |
| Usuarios | `GET/POST /usuarios`, `PUT /usuarios/{id}`, `GET /roles` |
| Catálogos | `/especialidades`, `/medicos`, `GET /medicos/{id}/horarios`, `/horarios`, `/bloqueos` |
| Pacientes | `GET/POST /pacientes`, `GET/PUT /pacientes/{id}`, `PATCH /pacientes/{id}/estado` |
| Citas | `GET /disponibilidad`, `GET/POST /citas`, `PUT /citas/{id}/reprogramar`, `POST /citas/{id}/cancelar`, `POST /citas/{id}/estado`, `GET /agenda`, `GET /historial` |
| Informes | `GET /reportes/{tipo}` (`&formato=csv`), `GET /bitacora`, `GET /notificaciones` |

## Notas sobre el documento

- El Administrador recibe `agenda.consultar` y la Recepcionista `reportes.ver`, porque CU-15 y CU-19 los listan como actores
  y la tabla de permisos de ejemplo no los incluía.
- La paciente PAC-0003 tiene correo `asuarez@mail.com` (igual que su cuenta) para poder recibir notificaciones.
- La exportación a PDF se resuelve con "Imprimir o guardar PDF" del navegador (mejora opcional del documento).
