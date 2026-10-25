-- Clinica Vida Salud - Procedimientos almacenados y funciones (documento PUDS, seccion 6.2.2)

CREATE OR REPLACE PROCEDURE sp_registrar_paciente(
    p_ci        VARCHAR,
    p_nombres   VARCHAR,
    p_apellidos VARCHAR,
    p_fecha_nac DATE,
    p_sexo      VARCHAR,
    p_telefono  VARCHAR,
    p_email     VARCHAR DEFAULT NULL,
    p_direccion VARCHAR DEFAULT NULL)
LANGUAGE plpgsql
AS $$
DECLARE
    v_codigo VARCHAR(15);
BEGIN
    IF p_ci IS NOT NULL AND EXISTS (SELECT 1 FROM pacientes WHERE ci = p_ci) THEN
        RAISE EXCEPTION 'Ya existe un paciente registrado con el CI %', p_ci;
    END IF;

    v_codigo := 'PAC-' || LPAD(nextval('seq_codigo_paciente')::TEXT, 4, '0');

    INSERT INTO pacientes (codigo, ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, email, direccion)
    VALUES (v_codigo, p_ci, p_nombres, p_apellidos, p_fecha_nac, p_sexo, p_telefono, p_email, p_direccion);

    RAISE NOTICE 'Paciente % % registrado con el código %', p_nombres, p_apellidos, v_codigo;
END;
$$;

CREATE OR REPLACE FUNCTION fn_horarios_disponibles(p_id_medico INT, p_fecha DATE)
RETURNS TABLE (inicio TIME, fin TIME)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT s.t::TIME,
           (s.t + make_interval(mins => h.duracion_cita_min))::TIME
    FROM horarios_atencion h
    CROSS JOIN LATERAL generate_series(
            p_fecha + h.hora_inicio,
            p_fecha + h.hora_fin - make_interval(mins => h.duracion_cita_min),
            make_interval(mins => h.duracion_cita_min)) AS s(t)
    WHERE h.id_medico = p_id_medico
      AND h.habilitado
      AND h.dia_semana = (ARRAY['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'])[EXTRACT(ISODOW FROM p_fecha)::INT]
      AND (p_fecha > CURRENT_DATE OR s.t::TIME > LOCALTIME)
      AND NOT EXISTS (                         -- sin citas activas que se superpongan
            SELECT 1 FROM citas c
            WHERE c.id_medico = p_id_medico AND c.fecha_cita = p_fecha
              AND c.estado <> 'Cancelada'
              AND c.hora_inicio < (s.t + make_interval(mins => h.duracion_cita_min))::TIME
              AND c.hora_fin > s.t::TIME)
      AND NOT EXISTS (                         -- sin feriados ni bloqueos
            SELECT 1 FROM bloqueos_agenda b
            WHERE (b.id_medico = p_id_medico OR b.id_medico IS NULL)
              AND b.fecha = p_fecha
              AND (b.hora_inicio IS NULL
                   OR (b.hora_inicio < (s.t + make_interval(mins => h.duracion_cita_min))::TIME
                       AND b.hora_fin > s.t::TIME)))
    ORDER BY 1;
END;
$$;

CREATE OR REPLACE PROCEDURE sp_programar_cita(
    p_id_paciente     INT,
    p_id_medico       INT,
    p_id_especialidad INT,
    p_fecha           DATE,
    p_hora_inicio     TIME,
    p_id_usuario      INT,
    p_motivo          VARCHAR DEFAULT NULL)
LANGUAGE plpgsql
AS $$
DECLARE
    v_duracion INT;
    v_codigo   VARCHAR(15);
    v_id_cita  INT;
BEGIN
    -- Serializa las reservas simultaneas sobre el mismo medico
    PERFORM 1 FROM medicos WHERE id_medico = p_id_medico FOR UPDATE;

    SELECT h.duracion_cita_min INTO v_duracion
    FROM horarios_atencion h
    WHERE h.id_medico = p_id_medico AND h.habilitado
      AND h.dia_semana = (ARRAY['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'])[EXTRACT(ISODOW FROM p_fecha)::INT]
      AND p_hora_inicio >= h.hora_inicio
      AND p_hora_inicio + make_interval(mins => h.duracion_cita_min) <= h.hora_fin
    LIMIT 1;

    IF v_duracion IS NULL THEN
        RAISE EXCEPTION 'El horario solicitado está fuera de la atención del médico';
    END IF;

    PERFORM set_config('app.id_usuario', p_id_usuario::TEXT, true);

    -- Las demas validaciones (fecha pasada, medico activo, bloqueos y
    -- superposicion) las realiza el trigger trg_validar_cita
    INSERT INTO citas (id_paciente, id_medico, id_especialidad, id_usuario_registra,
                       fecha_cita, hora_inicio, hora_fin, motivo)
    VALUES (p_id_paciente, p_id_medico, p_id_especialidad, p_id_usuario,
            p_fecha, p_hora_inicio, p_hora_inicio + make_interval(mins => v_duracion), p_motivo)
    RETURNING id_cita, codigo_cita INTO v_id_cita, v_codigo;

    INSERT INTO notificaciones (id_cita, tipo, mensaje, fecha_envio, estado)
    VALUES (v_id_cita, 'Confirmacion',
            'Su cita ' || v_codigo || ' fue registrada para el ' || TO_CHAR(p_fecha, 'DD/MM/YYYY')
            || ' a las ' || TO_CHAR(p_hora_inicio, 'HH24:MI'),
            NULL, 'Pendiente');

    RAISE NOTICE 'Cita % registrada correctamente', v_codigo;
END;
$$;

CREATE OR REPLACE PROCEDURE sp_cancelar_cita(
    p_id_cita    INT,
    p_motivo     VARCHAR,
    p_id_usuario INT)
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM set_config('app.id_usuario', p_id_usuario::TEXT, true);

    UPDATE citas
    SET estado = 'Cancelada', motivo_cancelacion = p_motivo
    WHERE id_cita = p_id_cita;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'La cita % no existe', p_id_cita;
    END IF;

    INSERT INTO notificaciones (id_cita, tipo, mensaje, estado)
    VALUES (p_id_cita, 'Cancelacion', 'Su cita fue cancelada. Motivo: ' || COALESCE(p_motivo, 'no indicado'), 'Pendiente');
END;
$$;

CREATE OR REPLACE PROCEDURE sp_reprogramar_cita(
    p_id_cita           INT,
    p_nueva_fecha       DATE,
    p_nueva_hora_inicio TIME,
    p_id_usuario        INT)
LANGUAGE plpgsql
AS $$
DECLARE
    v_duracion INTERVAL;
BEGIN
    SELECT hora_fin - hora_inicio INTO v_duracion FROM citas WHERE id_cita = p_id_cita;
    IF v_duracion IS NULL THEN
        RAISE EXCEPTION 'La cita % no existe', p_id_cita;
    END IF;

    PERFORM set_config('app.id_usuario', p_id_usuario::TEXT, true);

    -- trg_validar_cita comprueba estado, horario, bloqueos y superposicion
    UPDATE citas
    SET fecha_cita = p_nueva_fecha,
        hora_inicio = p_nueva_hora_inicio,
        hora_fin = p_nueva_hora_inicio + v_duracion
    WHERE id_cita = p_id_cita;

    INSERT INTO notificaciones (id_cita, tipo, mensaje, estado)
    VALUES (p_id_cita, 'Reprogramacion',
            'Su cita fue reprogramada para el ' || TO_CHAR(p_nueva_fecha, 'DD/MM/YYYY')
            || ' a las ' || TO_CHAR(p_nueva_hora_inicio, 'HH24:MI'), 'Pendiente');
END;
$$;

CREATE OR REPLACE PROCEDURE sp_cambiar_estado_cita(
    p_id_cita      INT,
    p_nuevo_estado VARCHAR,
    p_id_usuario   INT)
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM set_config('app.id_usuario', p_id_usuario::TEXT, true);

    -- trg_transicion_estado valida que la transicion sea permitida
    UPDATE citas SET estado = p_nuevo_estado WHERE id_cita = p_id_cita;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'La cita % no existe', p_id_cita;
    END IF;
END;
$$;

CREATE OR REPLACE PROCEDURE sp_desactivar_medico(p_id_medico INT)
LANGUAGE plpgsql
AS $$
DECLARE
    v_pendientes INT;
BEGIN
    UPDATE medicos SET habilitado = FALSE WHERE id_medico = p_id_medico;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'El médico % no existe', p_id_medico;
    END IF;

    SELECT COUNT(*) INTO v_pendientes
    FROM citas
    WHERE id_medico = p_id_medico
      AND estado IN ('Programada', 'Confirmada')
      AND fecha_cita >= CURRENT_DATE;

    RAISE NOTICE 'Médico desactivado. Citas futuras por reprogramar: %', v_pendientes;
END;
$$;

CREATE OR REPLACE FUNCTION fn_reporte_citas_por_estado(p_desde DATE, p_hasta DATE)
RETURNS TABLE (estado_cita VARCHAR, total BIGINT)
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN QUERY
    SELECT c.estado::VARCHAR, COUNT(*)
    FROM citas c
    WHERE c.fecha_cita BETWEEN p_desde AND p_hasta
    GROUP BY c.estado
    ORDER BY 2 DESC;
END;
$$;
