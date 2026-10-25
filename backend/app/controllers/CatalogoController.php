<?php
declare(strict_types=1);

/** CU-06 Especialidades, CU-07 Médicos, CU-08 Horarios de atención, CU-17 Bloqueos de agenda. */
final class CatalogoController
{
    private const DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

    // ====================== Especialidades (CU-06) ======================

    public static function especialidades(array $p): void
    {
        $u = Auth::need();
        $soloActivas = !Auth::can($u, 'especialidades.gestionar') || Http::query('activas') === '1';
        $filas = Db::all(
            'SELECT e.id_especialidad, e.nombre, e.descripcion, e.habilitado,
                    (SELECT COUNT(*) FROM medico_especialidad me JOIN medicos m ON m.id_medico = me.id_medico
                     WHERE me.id_especialidad = e.id_especialidad AND m.habilitado) AS medicos_activos
             FROM especialidades e' . ($soloActivas ? ' WHERE e.habilitado' : '') . ' ORDER BY e.nombre'
        );
        Http::json(200, ['especialidades' => $filas]);
    }

    public static function crearEspecialidad(array $p): void
    {
        Auth::need('especialidades.gestionar');
        $d = Http::body();
        $nombre = V::str($d, 'nombre', 'El nombre de la especialidad', 80);
        $desc = V::str($d, 'descripcion', 'La descripción', 200, false);
        // El trigger trg_normalizar_especialidad calcula nombre_normalizado
        $fila = Db::one(
            "INSERT INTO especialidades (nombre, nombre_normalizado, descripcion) VALUES (:n, '', :d) RETURNING id_especialidad",
            ['n' => $nombre, 'd' => $desc]
        );
        Http::json(201, $fila);
    }

    public static function actualizarEspecialidad(array $p): void
    {
        Auth::need('especialidades.gestionar');
        $id = (int)$p['id'];
        $d = Http::body();
        $nombre = V::str($d, 'nombre', 'El nombre de la especialidad', 80);
        $desc = V::str($d, 'descripcion', 'La descripción', 200, false);
        $habilitado = V::bool($d, 'habilitado');

        if (!$habilitado) {
            $activos = (int)Db::val(
                'SELECT COUNT(*) FROM medico_especialidad me JOIN medicos m ON m.id_medico = me.id_medico
                 WHERE me.id_especialidad = :id AND m.habilitado',
                ['id' => $id]
            );
            if ($activos > 0) {
                Http::error(422, 'No se puede desactivar: hay médicos activos asignados a esta especialidad');
            }
        }
        $n = Db::run(
            'UPDATE especialidades SET nombre = :n, descripcion = :d, habilitado = :h WHERE id_especialidad = :id',
            ['n' => $nombre, 'd' => $desc, 'h' => Db::b($habilitado), 'id' => $id]
        );
        if ($n === 0) {
            Http::error(404, 'Especialidad no encontrada');
        }
        Http::json(200, ['id_especialidad' => $id]);
    }

    // ====================== Médicos (CU-07) ======================

    public static function medicos(array $p): void
    {
        $u = Auth::need();
        $gestiona = Auth::can($u, 'medicos.gestionar');

        $cols = 'm.id_medico, m.codigo, m.nombres, m.apellidos, m.habilitado';
        if ($gestiona) {
            $cols .= ', m.nro_registro_prof, m.telefono, m.email';
        }
        $where = [];
        $par = [];
        if (!$gestiona || Http::query('activos') === '1') {
            $where[] = 'm.habilitado';
        }
        $esp = Http::query('especialidad');
        if ($esp !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM medico_especialidad x WHERE x.id_medico = m.id_medico AND x.id_especialidad = :esp)';
            $par['esp'] = (int)$esp;
        }

        $filas = Db::all(
            "SELECT $cols,
                    COALESCE((SELECT json_agg(json_build_object('id_especialidad', e.id_especialidad, 'nombre', e.nombre) ORDER BY e.nombre)
                              FROM medico_especialidad me JOIN especialidades e ON e.id_especialidad = me.id_especialidad
                              WHERE me.id_medico = m.id_medico), CAST('[]' AS json)) AS especialidades,
                    COALESCE((SELECT json_agg(DISTINCT h.dia_semana) FROM horarios_atencion h
                              WHERE h.id_medico = m.id_medico AND h.habilitado), CAST('[]' AS json)) AS dias
             FROM medicos m" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY m.apellidos, m.nombres',
            $par
        );
        Http::json(200, ['medicos' => Db::jsonCols($filas, ['especialidades', 'dias'])]);
    }

    public static function crearMedico(array $p): void
    {
        $u = Auth::need('medicos.gestionar');
        $d = Http::body();
        $datos = self::validarMedico($d);

        $id = Db::tx(function () use ($u, $datos) {
            Db::setUser((int)$u['id_usuario']);
            $fila = Db::one(
                "INSERT INTO medicos (codigo, nro_registro_prof, nombres, apellidos, telefono, email)
                 VALUES (
                    (SELECT 'MED-' || LPAD((COALESCE(MAX(SUBSTRING(codigo FROM 5)::INT), 0) + 1)::TEXT, 3, '0')
                     FROM medicos WHERE codigo ~ '^MED-[0-9]+\$'),
                    :r, :n, :a, :t, :e)
                 RETURNING id_medico",
                ['r' => $datos['registro'], 'n' => $datos['nombres'], 'a' => $datos['apellidos'], 't' => $datos['telefono'], 'e' => $datos['email']]
            );
            foreach ($datos['especialidades'] as $esp) {
                Db::run('INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES (:m, :e)', ['m' => $fila['id_medico'], 'e' => $esp]);
            }
            return (int)$fila['id_medico'];
        });
        Http::json(201, ['id_medico' => $id]);
    }

    public static function actualizarMedico(array $p): void
    {
        $u = Auth::need('medicos.gestionar');
        $id = (int)$p['id'];
        $d = Http::body();
        $datos = self::validarMedico($d);
        $habilitado = V::bool($d, 'habilitado');

        $previo = Db::one('SELECT habilitado FROM medicos WHERE id_medico = :id', ['id' => $id]);
        if ($previo === null) {
            Http::error(404, 'Médico no encontrado');
        }

        Db::tx(function () use ($u, $id, $datos, $habilitado) {
            Db::setUser((int)$u['id_usuario']);
            Db::run(
                'UPDATE medicos SET nro_registro_prof = :r, nombres = :n, apellidos = :a, telefono = :t, email = :e, habilitado = :h WHERE id_medico = :id',
                ['r' => $datos['registro'], 'n' => $datos['nombres'], 'a' => $datos['apellidos'], 't' => $datos['telefono'], 'e' => $datos['email'], 'h' => Db::b($habilitado), 'id' => $id]
            );
            // Sincroniza las especialidades
            $actuales = array_map('intval', array_column(Db::all('SELECT id_especialidad FROM medico_especialidad WHERE id_medico = :id', ['id' => $id]), 'id_especialidad'));
            foreach (array_diff($actuales, $datos['especialidades']) as $quitar) {
                Db::run('DELETE FROM medico_especialidad WHERE id_medico = :m AND id_especialidad = :e', ['m' => $id, 'e' => $quitar]);
            }
            foreach (array_diff($datos['especialidades'], $actuales) as $agregar) {
                Db::run('INSERT INTO medico_especialidad (id_medico, id_especialidad) VALUES (:m, :e)', ['m' => $id, 'e' => $agregar]);
            }
        });

        $resp = ['id_medico' => $id];
        if (!$habilitado) {
            // Se advierte para que recepción reprograme las citas futuras
            $resp['citas_futuras'] = (int)Db::val(
                "SELECT COUNT(*) FROM citas WHERE id_medico = :id AND estado IN ('Programada', 'Confirmada') AND fecha_cita >= CURRENT_DATE",
                ['id' => $id]
            );
        }
        Http::json(200, $resp);
    }

    private static function validarMedico(array $d): array
    {
        $esp = $d['especialidades'] ?? null;
        if (!is_array($esp) || count($esp) === 0) {
            Http::error(422, 'Seleccione al menos una especialidad');
        }
        $esp = array_values(array_unique(array_map('intval', $esp)));
        $existen = (int)Db::val(
            'SELECT COUNT(*) FROM especialidades WHERE id_especialidad IN (' . implode(',', array_map('intval', $esp)) . ')'
        );
        if ($existen !== count($esp)) {
            Http::error(422, 'Alguna de las especialidades seleccionadas no existe');
        }
        return [
            'registro' => V::str($d, 'nro_registro_prof', 'El registro profesional', 30),
            'nombres' => V::str($d, 'nombres', 'Los nombres', 80),
            'apellidos' => V::str($d, 'apellidos', 'Los apellidos', 80),
            'telefono' => V::telefono($d),
            'email' => V::email($d),
            'especialidades' => $esp,
        ];
    }

    // ====================== Horarios de atención (CU-08) ======================

    public static function horarios(array $p): void
    {
        Auth::need();
        $filas = Db::all(
            "SELECT id_horario, id_medico, dia_semana, LEFT(hora_inicio::TEXT, 5) AS hora_inicio,
                    LEFT(hora_fin::TEXT, 5) AS hora_fin, duracion_cita_min, habilitado,
                    (EXTRACT(EPOCH FROM (hora_fin - hora_inicio))::INT / 60) / duracion_cita_min AS bloques
             FROM horarios_atencion WHERE id_medico = :id
             ORDER BY array_position(ARRAY['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo']::VARCHAR[], dia_semana), hora_inicio",
            ['id' => (int)$p['id']]
        );
        Http::json(200, ['horarios' => $filas]);
    }

    public static function crearHorario(array $p): void
    {
        Auth::need('horarios.gestionar');
        $d = Http::body();
        $h = self::validarHorario($d, null);
        $fila = Db::one(
            'INSERT INTO horarios_atencion (id_medico, dia_semana, hora_inicio, hora_fin, duracion_cita_min)
             VALUES (:m, :d, :i, :f, :c) RETURNING id_horario',
            ['m' => $h['medico'], 'd' => $h['dia'], 'i' => $h['inicio'], 'f' => $h['fin'], 'c' => $h['duracion']]
        );
        Http::json(201, ['id_horario' => $fila['id_horario'], 'bloques' => $h['bloques']]);
    }

    public static function actualizarHorario(array $p): void
    {
        Auth::need('horarios.gestionar');
        $id = (int)$p['id'];
        $d = Http::body();
        $h = self::validarHorario($d, $id);
        $n = Db::run(
            'UPDATE horarios_atencion SET dia_semana = :d, hora_inicio = :i, hora_fin = :f, duracion_cita_min = :c, habilitado = :h WHERE id_horario = :id',
            ['d' => $h['dia'], 'i' => $h['inicio'], 'f' => $h['fin'], 'c' => $h['duracion'], 'h' => Db::b(V::bool($d, 'habilitado')), 'id' => $id]
        );
        if ($n === 0) {
            Http::error(404, 'Horario no encontrado');
        }
        Http::json(200, ['id_horario' => $id, 'bloques' => $h['bloques']]);
    }

    public static function eliminarHorario(array $p): void
    {
        Auth::need('horarios.gestionar');
        if (Db::run('DELETE FROM horarios_atencion WHERE id_horario = :id', ['id' => (int)$p['id']]) === 0) {
            Http::error(404, 'Horario no encontrado');
        }
        Http::json(200, ['eliminado' => true]);
    }

    private static function validarHorario(array $d, ?int $idHorario): array
    {
        $medico = $idHorario === null
            ? V::int($d, 'id_medico', 'El médico')
            : (int)Db::val('SELECT id_medico FROM horarios_atencion WHERE id_horario = :id', ['id' => $idHorario]);
        if ($medico === 0 || Db::val('SELECT 1 FROM medicos WHERE id_medico = :id', ['id' => $medico]) === null) {
            Http::error(422, 'El médico no existe');
        }
        $dia = V::enum($d, 'dia_semana', 'El día de atención', self::DIAS);
        $ini = V::hora($d, 'hora_inicio', 'La hora inicial');
        $fin = V::hora($d, 'hora_fin', 'La hora final');
        $dur = V::int($d, 'duracion_cita_min', 'La duración de la cita', 5, 240);
        if ($fin <= $ini) {
            Http::error(422, 'La hora final debe ser posterior a la hora inicial');
        }
        $minutos = (strtotime("1970-01-01 $fin") - strtotime("1970-01-01 $ini")) / 60;
        if ($minutos < $dur) {
            Http::error(422, 'El rango de atención es menor que la duración de una cita');
        }
        $solape = Db::val(
            'SELECT 1 FROM horarios_atencion WHERE id_medico = :m AND dia_semana = :d AND habilitado
               AND hora_inicio < :f AND hora_fin > :i AND id_horario <> :id',
            ['m' => $medico, 'd' => $dia, 'f' => $fin, 'i' => $ini, 'id' => $idHorario ?? 0]
        );
        if ($solape !== null) {
            Http::error(422, "El horario se superpone con otro del mismo día ($dia)");
        }
        return ['medico' => $medico, 'dia' => $dia, 'inicio' => $ini, 'fin' => $fin, 'duracion' => $dur, 'bloques' => intdiv((int)$minutos, $dur)];
    }

    // ====================== Bloqueos de agenda (CU-17) ======================

    public static function bloqueos(array $p): void
    {
        Auth::need('horarios.gestionar');
        $todos = Http::query('todos') === '1';
        $filas = Db::all(
            "SELECT b.id_bloqueo, b.id_medico, to_char(b.fecha, 'YYYY-MM-DD') AS fecha,
                    LEFT(b.hora_inicio::TEXT, 5) AS hora_inicio, LEFT(b.hora_fin::TEXT, 5) AS hora_fin, b.motivo,
                    CASE WHEN b.id_medico IS NULL THEN 'Toda la clínica' ELSE m.nombres || ' ' || m.apellidos END AS alcance
             FROM bloqueos_agenda b LEFT JOIN medicos m ON m.id_medico = b.id_medico" .
            ($todos ? '' : ' WHERE b.fecha >= CURRENT_DATE') . ' ORDER BY b.fecha, b.hora_inicio'
        );
        Http::json(200, ['bloqueos' => $filas]);
    }

    public static function crearBloqueo(array $p): void
    {
        Auth::need('horarios.gestionar');
        $d = Http::body();
        $medico = V::int($d, 'id_medico', 'El médico', 1, 2147483647, false);
        if ($medico !== null && Db::val('SELECT 1 FROM medicos WHERE id_medico = :id', ['id' => $medico]) === null) {
            Http::error(422, 'El médico no existe');
        }
        $fecha = V::fecha($d, 'fecha', 'La fecha');
        if ($fecha < date('Y-m-d')) {
            Http::error(422, 'La fecha del bloqueo no puede ser pasada');
        }
        $ini = V::hora($d, 'hora_inicio', 'La hora inicial', false);
        $fin = V::hora($d, 'hora_fin', 'La hora final', false);
        if (($ini === null) !== ($fin === null)) {
            Http::error(422, 'Indique la hora inicial y la final, o deje ambas vacías para bloquear el día completo');
        }
        if ($ini !== null && $fin <= $ini) {
            Http::error(422, 'La hora final debe ser posterior a la hora inicial');
        }
        $motivo = V::str($d, 'motivo', 'El motivo', 150, false);

        // Citas ya programadas dentro del bloqueo (se advierte para reprogramarlas)
        $where = ["c.fecha_cita = :f", "c.estado IN ('Programada', 'Confirmada')"];
        $par = ['f' => $fecha];
        if ($medico !== null) {
            $where[] = 'c.id_medico = :m';
            $par['m'] = $medico;
        }
        if ($ini !== null) {
            $where[] = 'c.hora_inicio < :fin AND c.hora_fin > :ini';
            $par['fin'] = $fin;
            $par['ini'] = $ini;
        }
        $afectadas = Db::all(
            "SELECT c.codigo_cita, LEFT(c.hora_inicio::TEXT, 5) AS hora, p.nombres || ' ' || p.apellidos AS paciente
             FROM citas c JOIN pacientes p ON p.id_paciente = c.id_paciente
             WHERE " . implode(' AND ', $where) . ' ORDER BY c.hora_inicio',
            $par
        );

        $fila = Db::one(
            'INSERT INTO bloqueos_agenda (id_medico, fecha, hora_inicio, hora_fin, motivo) VALUES (:m, :f, :i, :h, :mo) RETURNING id_bloqueo',
            ['m' => $medico, 'f' => $fecha, 'i' => $ini, 'h' => $fin, 'mo' => $motivo]
        );
        Http::json(201, ['id_bloqueo' => $fila['id_bloqueo'], 'citas_afectadas' => $afectadas]);
    }

    public static function eliminarBloqueo(array $p): void
    {
        Auth::need('horarios.gestionar');
        if (Db::run('DELETE FROM bloqueos_agenda WHERE id_bloqueo = :id', ['id' => (int)$p['id']]) === 0) {
            Http::error(404, 'Bloqueo no encontrado');
        }
        Http::json(200, ['eliminado' => true]);
    }
}
