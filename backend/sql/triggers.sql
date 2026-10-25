-- Clinica Vida Salud - Triggers (documento PUDS, seccion 6.2.2)
-- Se crean DESPUES de insertar los datos de ejemplo.

-- 1. Codigo unico de la cita (CM-0001)
CREATE OR REPLACE FUNCTION fn_generar_codigo_cita()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.codigo_cita IS NULL THEN
        NEW.codigo_cita := 'CM-' || LPAD(nextval('seq_codigo_cita')::TEXT, 4, '0');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_codigo_cita
BEFORE INSERT ON citas
FOR EACH ROW
EXECUTE FUNCTION fn_generar_codigo_cita();

-- 2. Disponibilidad, horario y duplicados
CREATE OR REPLACE FUNCTION fn_validar_cita()
RETURNS TRIGGER AS $$
DECLARE
    v_dia TEXT;
BEGIN
    -- Solo se valida al crear la cita o al cambiar su fecha, hora o medico
    IF TG_OP = 'UPDATE'
       AND NEW.fecha_cita = OLD.fecha_cita AND NEW.hora_inicio = OLD.hora_inicio
       AND NEW.hora_fin = OLD.hora_fin AND NEW.id_medico = OLD.id_medico THEN
        RETURN NEW;
    END IF;

    -- Las citas canceladas no ocupan disponibilidad (RN09)
    IF NEW.estado = 'Cancelada' THEN
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' AND OLD.estado IN ('Atendida', 'Cancelada', 'Inasistencia') THEN
        RAISE EXCEPTION 'Una cita en estado % no puede reprogramarse directamente', OLD.estado;
    END IF;

    IF NEW.fecha_cita < CURRENT_DATE
       OR (NEW.fecha_cita = CURRENT_DATE AND NEW.hora_inicio < LOCALTIME) THEN
        RAISE EXCEPTION 'No se pueden registrar citas en fechas pasadas (RN06)';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM medicos WHERE id_medico = NEW.id_medico AND habilitado) THEN
        RAISE EXCEPTION 'El médico no está activo para recibir nuevas citas (RN04)';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM medico_especialidad
                   WHERE id_medico = NEW.id_medico AND id_especialidad = NEW.id_especialidad) THEN
        RAISE EXCEPTION 'El médico no atiende la especialidad seleccionada';
    END IF;

    v_dia := (ARRAY['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'])[EXTRACT(ISODOW FROM NEW.fecha_cita)::INT];

    IF NOT EXISTS (
        SELECT 1 FROM horarios_atencion h
        WHERE h.id_medico = NEW.id_medico AND h.habilitado AND h.dia_semana = v_dia
          AND NEW.hora_inicio >= h.hora_inicio AND NEW.hora_fin <= h.hora_fin
          AND EXTRACT(EPOCH FROM (NEW.hora_fin - NEW.hora_inicio))::INT / 60 = h.duracion_cita_min
          AND MOD(EXTRACT(EPOCH FROM (NEW.hora_inicio - h.hora_inicio))::INT / 60, h.duracion_cita_min) = 0) THEN
        RAISE EXCEPTION 'La cita está fuera del horario de atención o no respeta los intervalos (RN05, RN08)';
    END IF;

    IF EXISTS (
        SELECT 1 FROM bloqueos_agenda b
        WHERE (b.id_medico = NEW.id_medico OR b.id_medico IS NULL) AND b.fecha = NEW.fecha_cita
          AND (b.hora_inicio IS NULL OR (b.hora_inicio < NEW.hora_fin AND b.hora_fin > NEW.hora_inicio))) THEN
        RAISE EXCEPTION 'La fecha u hora seleccionada está bloqueada en la agenda';
    END IF;

    IF EXISTS (
        SELECT 1 FROM citas c
        WHERE c.id_medico = NEW.id_medico AND c.fecha_cita = NEW.fecha_cita
          AND c.estado <> 'Cancelada' AND c.id_cita <> COALESCE(NEW.id_cita, -1)
          AND c.hora_inicio < NEW.hora_fin AND c.hora_fin > NEW.hora_inicio) THEN
        RAISE EXCEPTION 'El médico ya tiene una cita en ese horario (RN07)';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_validar_cita
BEFORE INSERT OR UPDATE ON citas
FOR EACH ROW
EXECUTE FUNCTION fn_validar_cita();

-- 3. Transiciones de estado validas
CREATE OR REPLACE FUNCTION fn_validar_transicion_estado()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.estado = OLD.estado THEN
        RETURN NEW;
    END IF;

    IF OLD.estado IN ('Atendida', 'Cancelada', 'Inasistencia') THEN
        RAISE EXCEPTION 'Una cita % no admite cambios de estado', LOWER(OLD.estado);
    END IF;

    IF OLD.estado = 'Confirmada' AND NEW.estado = 'Programada' THEN
        RAISE EXCEPTION 'Una cita confirmada no puede volver a Programada';
    END IF;

    IF NEW.estado = 'Cancelada' AND COALESCE(NEW.motivo_cancelacion, '') = '' THEN
        NEW.motivo_cancelacion := 'Sin motivo registrado';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_transicion_estado
BEFORE UPDATE OF estado ON citas
FOR EACH ROW
EXECUTE FUNCTION fn_validar_transicion_estado();

-- 4. Historial de estados
CREATE OR REPLACE FUNCTION fn_historial_estado_cita()
RETURNS TRIGGER AS $$
DECLARE
    v_usuario INT;
BEGIN
    v_usuario := COALESCE(NULLIF(current_setting('app.id_usuario', true), '')::INT,
                          NEW.id_usuario_registra);

    IF TG_OP = 'INSERT' THEN
        INSERT INTO historial_estado_cita (id_cita, estado_anterior, estado_nuevo, id_usuario, observacion)
        VALUES (NEW.id_cita, NULL, NEW.estado, v_usuario, 'Cita registrada');
    ELSIF NEW.estado <> OLD.estado THEN
        INSERT INTO historial_estado_cita (id_cita, estado_anterior, estado_nuevo, id_usuario, observacion)
        VALUES (NEW.id_cita, OLD.estado, NEW.estado, v_usuario,
                COALESCE(NEW.motivo_cancelacion, 'Cambio de estado'));
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_historial_cita
AFTER INSERT OR UPDATE ON citas
FOR EACH ROW
EXECUTE FUNCTION fn_historial_estado_cita();

-- 5. No eliminar registros con historial
CREATE OR REPLACE FUNCTION fn_proteger_con_citas()
RETURNS TRIGGER AS $$
DECLARE
    v_tiene BOOLEAN;
BEGIN
    IF TG_TABLE_NAME = 'pacientes' THEN
        v_tiene := EXISTS (SELECT 1 FROM citas WHERE id_paciente = OLD.id_paciente);
    ELSE
        v_tiene := EXISTS (SELECT 1 FROM citas WHERE id_medico = OLD.id_medico);
    END IF;

    IF v_tiene THEN
        RAISE EXCEPTION 'No se puede eliminar: el registro tiene citas asociadas. Desactívelo (RN11)';
    END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_proteger_paciente BEFORE DELETE ON pacientes
FOR EACH ROW EXECUTE FUNCTION fn_proteger_con_citas();

CREATE TRIGGER trg_proteger_medico BEFORE DELETE ON medicos
FOR EACH ROW EXECUTE FUNCTION fn_proteger_con_citas();

CREATE OR REPLACE FUNCTION fn_no_eliminar_cita()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'Las citas no se eliminan físicamente. Use el estado Cancelada (RF09)';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_no_eliminar_cita BEFORE DELETE ON citas
FOR EACH ROW EXECUTE FUNCTION fn_no_eliminar_cita();

-- 6. Bitacora de auditoria
CREATE OR REPLACE FUNCTION fn_bitacora()
RETURNS TRIGGER AS $$
DECLARE
    v_usuario INT := NULLIF(current_setting('app.id_usuario', true), '')::INT;
    v_pk      TEXT := TG_ARGV[0];
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO bitacora (id_usuario, tabla_afectada, registro_afectado, accion, datos_anteriores, datos_nuevos)
        VALUES (v_usuario, TG_TABLE_NAME, (to_jsonb(NEW) ->> v_pk)::INT, TG_OP, NULL, to_jsonb(NEW)::TEXT);
    ELSIF TG_OP = 'UPDATE' THEN
        INSERT INTO bitacora (id_usuario, tabla_afectada, registro_afectado, accion, datos_anteriores, datos_nuevos)
        VALUES (v_usuario, TG_TABLE_NAME, (to_jsonb(NEW) ->> v_pk)::INT, TG_OP, to_jsonb(OLD)::TEXT, to_jsonb(NEW)::TEXT);
    ELSE
        INSERT INTO bitacora (id_usuario, tabla_afectada, registro_afectado, accion, datos_anteriores, datos_nuevos)
        VALUES (v_usuario, TG_TABLE_NAME, (to_jsonb(OLD) ->> v_pk)::INT, TG_OP, to_jsonb(OLD)::TEXT, NULL);
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_bitacora_citas AFTER INSERT OR UPDATE OR DELETE ON citas
FOR EACH ROW EXECUTE FUNCTION fn_bitacora('id_cita');

CREATE TRIGGER trg_bitacora_pacientes AFTER INSERT OR UPDATE OR DELETE ON pacientes
FOR EACH ROW EXECUTE FUNCTION fn_bitacora('id_paciente');

CREATE TRIGGER trg_bitacora_medicos AFTER INSERT OR UPDATE OR DELETE ON medicos
FOR EACH ROW EXECUTE FUNCTION fn_bitacora('id_medico');

-- 7. Normalizar el nombre de las especialidades
CREATE OR REPLACE FUNCTION fn_normalizar_especialidad()
RETURNS TRIGGER AS $$
BEGIN
    NEW.nombre := TRIM(REGEXP_REPLACE(NEW.nombre, '\s+', ' ', 'g'));
    NEW.nombre_normalizado := LOWER(TRANSLATE(NEW.nombre, 'ÁÉÍÓÚÜÑáéíóúüñ', 'AEIOUUNaeiouun'));
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_normalizar_especialidad
BEFORE INSERT OR UPDATE OF nombre ON especialidades
FOR EACH ROW
EXECUTE FUNCTION fn_normalizar_especialidad();
