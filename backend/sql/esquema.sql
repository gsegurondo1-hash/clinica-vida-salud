-- Clinica Vida Salud - Esquema PostgreSQL (segun documento PUDS, seccion 6.2.2)
-- La base de datos la crea el servidor (Render o local); aqui solo van las tablas.

CREATE TABLE roles (
    id_rol SERIAL PRIMARY KEY,
    nombre VARCHAR(30) NOT NULL UNIQUE,
    descripcion VARCHAR(150),
    habilitado BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE permisos (
    id_permiso SERIAL PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL UNIQUE,
    descripcion VARCHAR(150)
);

CREATE TABLE rol_permiso (
    id_rol INT NOT NULL REFERENCES roles(id_rol),
    id_permiso INT NOT NULL REFERENCES permisos(id_permiso),
    PRIMARY KEY (id_rol, id_permiso)
);

CREATE TABLE pacientes (
    id_paciente SERIAL PRIMARY KEY,
    codigo VARCHAR(15) NOT NULL UNIQUE,
    ci VARCHAR(20) UNIQUE,
    nombres VARCHAR(80) NOT NULL,
    apellidos VARCHAR(80) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    sexo VARCHAR(10) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    direccion VARCHAR(200),
    habilitado BOOLEAN NOT NULL DEFAULT TRUE,
    fecha_registro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE contactos_emergencia (
    id_contacto SERIAL PRIMARY KEY,
    id_paciente INT NOT NULL REFERENCES pacientes(id_paciente),
    nombre VARCHAR(100) NOT NULL,
    parentesco VARCHAR(40) NOT NULL,
    telefono VARCHAR(20) NOT NULL
);

CREATE TABLE informacion_medica (
    id_info SERIAL PRIMARY KEY,
    id_paciente INT NOT NULL UNIQUE REFERENCES pacientes(id_paciente),
    tipo_sangre VARCHAR(5),
    alergias TEXT,
    enfermedades_cronicas TEXT,
    medicamentos TEXT,
    antecedentes TEXT
);

CREATE TABLE especialidades (
    id_especialidad SERIAL PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    nombre_normalizado VARCHAR(80) NOT NULL UNIQUE,
    descripcion VARCHAR(200),
    habilitado BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE medicos (
    id_medico SERIAL PRIMARY KEY,
    codigo VARCHAR(15) NOT NULL UNIQUE,
    nro_registro_prof VARCHAR(30) NOT NULL UNIQUE,
    nombres VARCHAR(80) NOT NULL,
    apellidos VARCHAR(80) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    habilitado BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE medico_especialidad (
    id_medico INT NOT NULL REFERENCES medicos(id_medico),
    id_especialidad INT NOT NULL REFERENCES especialidades(id_especialidad),
    fecha_asignacion DATE NOT NULL DEFAULT CURRENT_DATE,
    PRIMARY KEY (id_medico, id_especialidad)
);

CREATE TABLE usuarios (
    id_usuario SERIAL PRIMARY KEY,
    id_rol INT NOT NULL REFERENCES roles(id_rol),
    id_paciente INT UNIQUE REFERENCES pacientes(id_paciente),
    id_medico INT UNIQUE REFERENCES medicos(id_medico),
    nombre_usuario VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    token_recuperacion VARCHAR(100),
    token_expira TIMESTAMP,
    ultimo_acceso TIMESTAMP,
    habilitado BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE horarios_atencion (
    id_horario SERIAL PRIMARY KEY,
    id_medico INT NOT NULL REFERENCES medicos(id_medico),
    dia_semana VARCHAR(10) NOT NULL CHECK (dia_semana IN ('Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo')),
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    duracion_cita_min INT NOT NULL DEFAULT 30 CHECK (duracion_cita_min > 0),
    habilitado BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK (hora_fin > hora_inicio)
);

CREATE TABLE bloqueos_agenda (
    id_bloqueo SERIAL PRIMARY KEY,
    id_medico INT REFERENCES medicos(id_medico),
    fecha DATE NOT NULL,
    hora_inicio TIME,
    hora_fin TIME,
    motivo VARCHAR(150)
);

CREATE TABLE citas (
    id_cita SERIAL PRIMARY KEY,
    codigo_cita VARCHAR(15) NOT NULL UNIQUE,
    id_paciente INT NOT NULL REFERENCES pacientes(id_paciente),
    id_medico INT NOT NULL REFERENCES medicos(id_medico),
    id_especialidad INT NOT NULL REFERENCES especialidades(id_especialidad),
    id_usuario_registra INT NOT NULL REFERENCES usuarios(id_usuario),
    fecha_cita DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    motivo VARCHAR(200),
    estado VARCHAR(15) NOT NULL DEFAULT 'Programada' CHECK (estado IN ('Programada','Confirmada','Atendida','Cancelada','Inasistencia')),
    motivo_cancelacion VARCHAR(200),
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (hora_fin > hora_inicio)
);

CREATE TABLE historial_estado_cita (
    id_historial SERIAL PRIMARY KEY,
    id_cita INT NOT NULL REFERENCES citas(id_cita),
    estado_anterior VARCHAR(15),
    estado_nuevo VARCHAR(15) NOT NULL,
    id_usuario INT NOT NULL REFERENCES usuarios(id_usuario),
    fecha_cambio TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    observacion VARCHAR(200)
);

CREATE TABLE notificaciones (
    id_notificacion SERIAL PRIMARY KEY,
    id_cita INT NOT NULL REFERENCES citas(id_cita),
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('Confirmacion','Recordatorio','Cancelacion','Reprogramacion')),
    mensaje VARCHAR(255) NOT NULL,
    fecha_envio TIMESTAMP,
    estado VARCHAR(12) NOT NULL DEFAULT 'Pendiente' CHECK (estado IN ('Pendiente','Enviada','Fallida'))
);

CREATE TABLE bitacora (
    id_bitacora SERIAL PRIMARY KEY,
    id_usuario INT REFERENCES usuarios(id_usuario),
    tabla_afectada VARCHAR(50) NOT NULL,
    registro_afectado INT NOT NULL,
    accion VARCHAR(10) NOT NULL CHECK (accion IN ('INSERT','UPDATE','DELETE')),
    datos_anteriores TEXT,
    datos_nuevos TEXT,
    fecha_evento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Control de intentos fallidos de inicio de sesion (bloqueo temporal)
CREATE TABLE IF NOT EXISTS intentos_login (
    clave VARCHAR(150) PRIMARY KEY,
    intentos INT NOT NULL DEFAULT 0,
    bloqueado_hasta TIMESTAMP,
    ultimo_intento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Impide dos citas activas de un medico en el mismo inicio, incluso con reservas simultaneas
CREATE UNIQUE INDEX uq_cita_medico_horario
    ON citas (id_medico, fecha_cita, hora_inicio)
    WHERE estado <> 'Cancelada';

CREATE INDEX idx_citas_paciente ON citas (id_paciente);
CREATE INDEX idx_citas_fecha ON citas (fecha_cita, id_medico);
CREATE INDEX idx_notificaciones_cita ON notificaciones (id_cita);

-- Secuencias para codigos legibles (continuan despues de los datos de ejemplo)
CREATE SEQUENCE seq_codigo_paciente START 9;
CREATE SEQUENCE seq_codigo_cita START 14;
