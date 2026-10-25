-- Clinica Vida Salud - Datos de ejemplo (documento PUDS, seccion 6.2.2)
-- Se ejecutan ANTES de crear los triggers, porque algunas citas tienen fechas pasadas.
-- Las contrasenas se reemplazan con password_hash() de PHP al ejecutar scripts/setup_db.php

INSERT INTO roles (nombre, descripcion) VALUES
('Administrador', 'Configura y supervisa el sistema'),
('Recepcionista', 'Gestiona pacientes, citas y asistencia'),
('Médico', 'Consulta su agenda de atención'),
('Paciente', 'Gestiona su perfil y sus citas');

INSERT INTO permisos (nombre, descripcion) VALUES
('usuarios.gestionar', 'Registrar y administrar usuarios y roles'),
('especialidades.gestionar', 'Registrar y editar especialidades'),
('medicos.gestionar', 'Registrar y editar médicos'),
('horarios.gestionar', 'Definir horarios y bloqueos de agenda'),
('pacientes.gestionar', 'Registrar, buscar y modificar pacientes'),
('citas.programar', 'Programar y reprogramar citas'),
('citas.cancelar', 'Cancelar citas'),
('citas.asistencia', 'Registrar asistencia, inasistencia y atención'),
('agenda.consultar', 'Consultar agenda de médicos'),
('reportes.ver', 'Generar reportes administrativos'),
('bitacora.ver', 'Consultar la bitácora de auditoría'),
('citas.propias', 'Gestionar las propias citas (paciente)'),
('perfil.editar', 'Editar el propio perfil (paciente)');

-- Ajuste respecto al documento: el Administrador consulta agendas (CU-15) y la Recepcionista genera reportes (CU-19)
INSERT INTO rol_permiso (id_rol, id_permiso) VALUES
(1,1),(1,2),(1,3),(1,4),(1,5),(1,9),(1,10),(1,11),
(2,5),(2,6),(2,7),(2,8),(2,9),(2,10),
(3,8),(3,9),
(4,12),(4,13);

INSERT INTO especialidades (nombre, nombre_normalizado, descripcion) VALUES
('Medicina General', 'medicina general', 'Atención médica integral y primaria'),
('Pediatría', 'pediatria', 'Atención de niños y adolescentes'),
('Ginecología', 'ginecologia', 'Salud de la mujer'),
('Odontología', 'odontologia', 'Salud bucal y dental'),
('Cardiología', 'cardiologia', 'Enfermedades del corazón');

INSERT INTO medicos (codigo, nro_registro_prof, nombres, apellidos, telefono, email, habilitado) VALUES
('MED-001', 'RP-10234', 'Juan', 'Pérez Rojas', '70111111', 'jperez@vidasalud.bo', TRUE),
('MED-002', 'RP-10456', 'Lucía', 'Fernández Soliz', '70222222', 'lfernandez@vidasalud.bo', TRUE),
('MED-003', 'RP-10788', 'Carlos', 'Mendoza Vaca', '70333333', 'cmendoza@vidasalud.bo', TRUE),
('MED-004', 'RP-11002', 'Andrea', 'Salvatierra Cruz', '70444444', 'asalvatierra@vidasalud.bo', TRUE),
('MED-005', 'RP-11345', 'Ricardo', 'Justiniano Peña', '70555555', 'rjustiniano@vidasalud.bo', TRUE),
('MED-006', 'RP-11567', 'Marta', 'Rivero Gómez', '70666666', 'mrivero@vidasalud.bo', FALSE);

INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES
(1,1),(2,2),(3,3),(4,4),(5,5),(5,1),(6,2);

INSERT INTO pacientes (codigo, ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, email, direccion) VALUES
('PAC-0001', '7845123', 'María', 'López Vargas', '1990-05-14', 'Femenino', '71234567', 'mlopez@mail.com', 'Av. Banzer #123'),
('PAC-0002', '6523041', 'Pedro', 'Gutiérrez Rojas', '1985-11-02', 'Masculino', '72345678', 'pgutierrez@mail.com', 'Calle Los Pinos #45'),
('PAC-0003', '9012345', 'Ana', 'Suárez Molina', '2015-03-21', 'Femenino', '73456789', 'asuarez@mail.com', 'Barrio Equipetrol #78'),
('PAC-0004', '5412876', 'Luis', 'Camacho Ortiz', '1978-07-30', 'Masculino', '74567890', 'lcamacho@mail.com', NULL),
('PAC-0005', '8123456', 'Carla', 'Montero Díaz', '1995-09-09', 'Femenino', '75678901', 'cmontero@mail.com', 'Av. Cristo Redentor #310'),
('PAC-0006', '4321987', 'Jorge', 'Peñaranda Vaca', '1960-01-17', 'Masculino', '76789012', NULL, 'Calle Sucre #22'),
('PAC-0007', '9988776', 'Sofía', 'Arteaga Roca', '2001-12-25', 'Femenino', '77890123', 'sarteaga@mail.com', NULL),
('PAC-0008', NULL, 'Daniel', 'Cuéllar Egüez', '2010-06-10', 'Masculino', '78901234', NULL, 'Barrio Hamacas #9');

INSERT INTO contactos_emergencia (id_paciente, nombre, parentesco, telefono) VALUES
(1, 'Roberto López', 'Padre', '71000001'),
(2, 'Elena Rojas', 'Madre', '71000002'),
(3, 'Marcos Suárez', 'Padre', '71000003'),
(5, 'Julia Díaz', 'Madre', '71000005'),
(6, 'Teresa Vaca', 'Esposa', '71000006');

INSERT INTO informacion_medica (id_paciente, tipo_sangre, alergias, enfermedades_cronicas, medicamentos, antecedentes) VALUES
(1, 'O+', 'Penicilina', NULL, NULL, 'Cirugía de apéndice en 2012'),
(2, 'A+', NULL, 'Gastritis', 'Omeprazol', NULL),
(3, 'B+', NULL, NULL, NULL, 'Vacunas al día'),
(5, 'O-', 'Polen', NULL, NULL, NULL),
(6, 'AB+', NULL, 'Hipertensión', 'Enalapril', 'Antecedentes familiares cardíacos');

-- password_hash 'PENDIENTE' se reemplaza en scripts/setup_db.php
INSERT INTO usuarios (id_rol, id_paciente, id_medico, nombre_usuario, email, password_hash) VALUES
(1, NULL, NULL, 'admin', 'admin@vidasalud.bo', 'PENDIENTE'),
(2, NULL, NULL, 'recepcion1', 'recepcion@vidasalud.bo', 'PENDIENTE'),
(3, NULL, 1, 'jperez', 'jperez@vidasalud.bo', 'PENDIENTE'),
(3, NULL, 2, 'lfernandez', 'lfernandez@vidasalud.bo', 'PENDIENTE'),
(4, 1, NULL, 'mlopez', 'mlopez@mail.com', 'PENDIENTE'),
(4, 2, NULL, 'pgutierrez', 'pgutierrez@mail.com', 'PENDIENTE'),
(4, 3, NULL, 'asuarez', 'asuarez@mail.com', 'PENDIENTE'),
(3, NULL, 5, 'rjustiniano', 'rjustiniano@vidasalud.bo', 'PENDIENTE');

INSERT INTO horarios_atencion (id_medico, dia_semana, hora_inicio, hora_fin, duracion_cita_min) VALUES
(1, 'Lunes', '08:00', '12:00', 30),
(1, 'Miércoles', '14:00', '18:00', 30),
(2, 'Martes', '09:00', '13:00', 30),
(2, 'Jueves', '09:00', '13:00', 30),
(3, 'Lunes', '14:00', '18:00', 30),
(3, 'Viernes', '08:00', '12:00', 30),
(4, 'Miércoles', '08:00', '12:00', 30),
(4, 'Viernes', '14:00', '18:00', 30),
(5, 'Martes', '14:00', '18:00', 30),
(5, 'Jueves', '14:00', '18:00', 30);

INSERT INTO bloqueos_agenda (id_medico, fecha, hora_inicio, hora_fin, motivo) VALUES
(NULL, '2026-11-02', NULL, NULL, 'Feriado: Día de los Difuntos'),
(1, '2026-11-16', '08:00', '10:00', 'Capacitación médica'),
(3, '2026-11-20', NULL, NULL, 'Vacaciones');

INSERT INTO citas (codigo_cita, id_paciente, id_medico, id_especialidad, id_usuario_registra, fecha_cita, hora_inicio, hora_fin, motivo, estado, motivo_cancelacion) VALUES
('CM-0001', 1, 1, 1, 2, '2026-10-19', '09:00', '09:30', 'Consulta general', 'Atendida', NULL),
('CM-0002', 3, 2, 2, 2, '2026-10-20', '09:30', '10:00', 'Control de crecimiento', 'Atendida', NULL),
('CM-0003', 5, 3, 3, 2, '2026-10-19', '14:30', '15:00', 'Control ginecológico', 'Atendida', NULL),
('CM-0004', 2, 1, 1, 6, '2026-10-21', '15:00', '15:30', 'Dolor de garganta', 'Inasistencia', NULL),
('CM-0005', 6, 5, 5, 2, '2026-10-20', '14:00', '14:30', 'Chequeo cardiológico', 'Atendida', NULL),
('CM-0006', 1, 1, 1, 5, '2026-11-09', '09:00', '09:30', 'Consulta general', 'Confirmada', NULL),
('CM-0007', 3, 2, 2, 2, '2026-11-03', '10:00', '10:30', 'Vacunación', 'Programada', NULL),
('CM-0008', 7, 4, 4, 2, '2026-11-04', '08:30', '09:00', 'Limpieza dental', 'Programada', NULL),
('CM-0009', 4, 1, 1, 2, '2026-11-09', '09:30', '10:00', 'Control de presión', 'Cancelada', 'El paciente no puede asistir'),
('CM-0010', 6, 5, 5, 2, '2026-11-05', '15:00', '15:30', 'Control cardiológico', 'Programada', NULL),
('CM-0011', 5, 3, 3, 2, '2026-11-09', '14:00', '14:30', 'Consulta ginecológica', 'Programada', NULL),
('CM-0012', 2, 1, 1, 6, '2026-11-11', '14:30', '15:00', 'Dolor de cabeza', 'Programada', NULL),
('CM-0013', 1, 5, 1, 5, '2026-11-10', '14:30', '15:00', 'Consulta general', 'Programada', NULL);

INSERT INTO historial_estado_cita (id_cita, estado_anterior, estado_nuevo, id_usuario, fecha_cambio, observacion) VALUES
(1, NULL, 'Programada', 2, '2026-10-15 10:00:00', 'Cita registrada'),
(1, 'Programada', 'Atendida', 3, '2026-10-19 09:35:00', 'Consulta realizada'),
(2, NULL, 'Programada', 2, '2026-10-16 11:20:00', 'Cita registrada'),
(2, 'Programada', 'Atendida', 4, '2026-10-20 10:05:00', 'Consulta realizada'),
(3, NULL, 'Programada', 2, '2026-10-16 15:00:00', 'Cita registrada'),
(3, 'Programada', 'Atendida', 2, '2026-10-19 15:10:00', 'Atención confirmada por recepción'),
(4, NULL, 'Programada', 6, '2026-10-18 20:30:00', 'Cita registrada por el paciente'),
(4, 'Programada', 'Inasistencia', 2, '2026-10-21 15:45:00', 'El paciente no se presentó'),
(5, NULL, 'Programada', 2, '2026-10-17 09:00:00', 'Cita registrada'),
(5, 'Programada', 'Atendida', 8, '2026-10-20 14:40:00', 'Consulta realizada'),
(6, NULL, 'Programada', 5, '2026-10-22 08:10:00', 'Cita registrada por el paciente'),
(6, 'Programada', 'Confirmada', 2, '2026-10-22 12:00:00', 'Asistencia confirmada por teléfono'),
(7, NULL, 'Programada', 2, '2026-10-22 09:30:00', 'Cita registrada'),
(8, NULL, 'Programada', 2, '2026-10-22 10:00:00', 'Cita registrada'),
(9, NULL, 'Programada', 2, '2026-10-22 10:30:00', 'Cita registrada'),
(9, 'Programada', 'Cancelada', 2, '2026-10-23 08:00:00', 'El paciente no puede asistir'),
(10, NULL, 'Programada', 2, '2026-10-22 11:00:00', 'Cita registrada'),
(11, NULL, 'Programada', 2, '2026-10-22 11:30:00', 'Cita registrada'),
(12, NULL, 'Programada', 6, '2026-10-22 19:00:00', 'Cita registrada por el paciente'),
(13, NULL, 'Programada', 5, '2026-10-22 20:00:00', 'Cita registrada por el paciente');

INSERT INTO notificaciones (id_cita, tipo, mensaje, fecha_envio, estado) VALUES
(6, 'Confirmacion', 'Su cita CM-0006 fue confirmada para el 09/11/2026 a las 09:00', '2026-10-22 12:05:00', 'Enviada'),
(6, 'Recordatorio', 'Recordatorio: mañana tiene la cita CM-0006 a las 09:00', '2026-11-08 09:00:00', 'Pendiente'),
(7, 'Recordatorio', 'Recordatorio: mañana tiene la cita CM-0007 a las 10:00', '2026-11-02 09:00:00', 'Pendiente'),
(8, 'Confirmacion', 'Su cita CM-0008 fue registrada para el 04/11/2026 a las 08:30', '2026-10-22 10:05:00', 'Enviada'),
(9, 'Cancelacion', 'Su cita CM-0009 fue cancelada', '2026-10-23 08:05:00', 'Enviada'),
(10, 'Recordatorio', 'Recordatorio: mañana tiene la cita CM-0010 a las 15:00', '2026-11-04 09:00:00', 'Pendiente');

INSERT INTO bitacora (id_usuario, tabla_afectada, registro_afectado, accion, datos_anteriores, datos_nuevos, fecha_evento) VALUES
(2, 'citas', 6, 'UPDATE', '{"estado":"Programada"}', '{"estado":"Confirmada"}', '2026-10-22 12:00:00'),
(2, 'citas', 9, 'UPDATE', '{"estado":"Programada"}', '{"estado":"Cancelada"}', '2026-10-23 08:00:00'),
(1, 'medicos', 6, 'UPDATE', '{"habilitado":true}', '{"habilitado":false}', '2026-10-10 09:00:00'),
(1, 'especialidades', 5, 'INSERT', NULL, '{"nombre":"Cardiología"}', '2026-10-01 08:30:00'),
(6, 'citas', 12, 'INSERT', NULL, '{"codigo_cita":"CM-0012"}', '2026-10-22 19:00:00');
