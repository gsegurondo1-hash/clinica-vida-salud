<?php
declare(strict_types=1);

/**
 * CU-10 a CU-16: disponibilidad, programar, reprogramar, cancelar, asistencia,
 * agenda médica e historial. Las reglas fuertes viven en los triggers y procedimientos de PostgreSQL.
 */
final class CitaController
{
    public const ESTADOS = ['Programada', 'Confirmada', 'Atendida', 'Cancelada', 'Inasistencia'];
    private const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    private const BASE = "SELECT c.id_cita, c.codigo_cita,
            to_char(c.fecha_cita, 'YYYY-MM-DD') AS fecha,
            LEFT(c.hora_inicio::TEXT, 5) AS hora_inicio,
            LEFT(c.hora_fin::TEXT, 5) AS hora_fin,
            c.estado, c.motivo, c.motivo_cancelacion,
            to_char(c.fecha_creacion, 'YYYY-MM-DD HH24:MI') AS fecha_creacion,
            c.id_paciente, p.nombres || ' ' || p.apellidos AS paciente, p.ci AS paciente_ci,
            c.id_medico, m.nombres || ' ' || m.apellidos AS medico,
            c.id_especialidad, e.nombre AS especialidad
        FROM citas c
        JOIN pacientes p ON p.id_paciente = c.id_paciente
        JOIN medicos m ON m.id_medico = c.id_medico
        JOIN especialidades e ON e.id_especialidad = c.id_especialidad";

    // ====================== Disponibilidad (CU-10) ======================

    public static function disponibilidad(array $p): void
    {
        Auth::need();
        $idMedico = V::int($_GET, 'id_medico', 'El médico');
        $fecha = V::fecha($_GET, 'fecha', 'La fecha');

        if ($fecha < date('Y-m-d')) {
            Http::error(422, 'La fecha seleccionada ya pasó');
        }
        $medico = Db::one('SELECT habilitado FROM medicos WHERE id_medico = :id', ['id' => $idMedico]);
        if ($medico === null || !$medico['habilitado']) {
            Http::error(422, 'El médico no está activo para recibir citas');
        }

        $resp = ['id_medico' => $idMedico, 'fecha' => $fecha, 'horarios' => [], 'mensaje' => null];

        $bloqueo = Db::one(
            'SELECT motivo FROM bloqueos_agenda WHERE fecha = :f AND (id_medico = :m OR id_medico IS NULL) AND hora_inicio IS NULL LIMIT 1',
            ['f' => $fecha, 'm' => $idMedico]
        );
        if ($bloqueo !== null) {
            $resp['mensaje'] = 'Fecha no disponible' . (!empty($bloqueo['motivo']) ? ': ' . $bloqueo['motivo'] : '');
            Http::json(200, $resp);
        }

        $resp['horarios'] = Db::all(
            "SELECT LEFT(inicio::TEXT, 5) AS inicio, LEFT(fin::TEXT, 5) AS fin
             FROM fn_horarios_disponibles(CAST(:m AS INT), CAST(:f AS DATE))",
            ['m' => $idMedico, 'f' => $fecha]
        );

        if ($resp['horarios'] === []) {
            $dia = self::DIAS[(int)(new DateTime($fecha))->format('N')];
            $atiende = Db::val('SELECT 1 FROM horarios_atencion WHERE id_medico = :m AND dia_semana = :d AND habilitado', ['m' => $idMedico, 'd' => $dia]);
            $resp['mensaje'] = $atiende === null
                ? "El médico no atiende los días $dia"
                : 'No quedan horarios disponibles para esa fecha';
        }
        Http::json(200, $resp);
    }

    // ====================== Consultas ======================

    /** Consulta de citas con filtros. Los filtros ya vienen restringidos según el rol. */
    public static function consulta(array $f, string $orden, int $limite = 300): array
    {
        $w = [];
        $par = [];
        if (!empty($f['id_paciente'])) {
            $w[] = 'c.id_paciente = :pac';
            $par['pac'] = (int)$f['id_paciente'];
        }
        if (!empty($f['id_medico'])) {
            $w[] = 'c.id_medico = :med';
            $par['med'] = (int)$f['id_medico'];
        }
        if (!empty($f['id_especialidad'])) {
            $w[] = 'c.id_especialidad = :esp';
            $par['esp'] = (int)$f['id_especialidad'];
        }
        if (!empty($f['desde'])) {
            $w[] = 'c.fecha_cita >= :desde';
            $par['desde'] = $f['desde'];
        }
        if (!empty($f['hasta'])) {
            $w[] = 'c.fecha_cita <= :hasta';
            $par['hasta'] = $f['hasta'];
        }
        if (!empty($f['estado'])) {
            $estados = array_values(array_intersect(explode(',', (string)$f['estado']), self::ESTADOS));
            if ($estados === []) {
                Http::error(422, 'El estado indicado no es válido');
            }
            $marcas = [];
            foreach ($estados as $i => $e) {
                $marcas[] = ":est$i";
                $par["est$i"] = $e;
            }
            $w[] = 'c.estado IN (' . implode(',', $marcas) . ')';
        }
        if (!empty($f['q'])) {
            $w[] = "(p.ci ILIKE :q1 OR (p.nombres || ' ' || p.apellidos) ILIKE :q2 OR c.codigo_cita ILIKE :q3)";
            foreach (['q1', 'q2', 'q3'] as $k) {
                $par[$k] = '%' . $f['q'] . '%';
            }
        }
        $limite = max(1, min($limite, 2000));
        return Db::all(self::BASE . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY $orden LIMIT $limite", $par);
    }

    /** Filtros enviados por la URL, validados. */
    private static function filtrosUrl(): array
    {
        $f = [];
        foreach (['id_paciente', 'id_medico', 'id_especialidad'] as $k) {
            $v = Http::query($k);
            if ($v !== null) {
                $f[$k] = V::int($_GET, $k, $k);
            }
        }
        foreach (['desde' => 'La fecha inicial', 'hasta' => 'La fecha final'] as $k => $et) {
            $v = V::fecha($_GET, $k, $et, false);
            if ($v !== null) {
                $f[$k] = $v;
            }
        }
        if (isset($f['desde'], $f['hasta']) && $f['desde'] > $f['hasta']) {
            Http::error(422, 'El rango de fechas no es válido');
        }
        $f['estado'] = Http::query('estado');
        $f['q'] = Http::query('q');
        return $f;
    }

    /** Pacientes ven solo lo suyo y médicos solo su agenda. */
    private static function restringir(array $u, array $f): array
    {
        if ($u['rol'] === 'Paciente') {
            $f['id_paciente'] = (int)($u['id_paciente'] ?? 0);
            if ($f['id_paciente'] === 0) {
                Http::json(200, ['citas' => []]);
            }
        }
        if ($u['rol'] === 'Médico') {
            $f['id_medico'] = (int)($u['id_medico'] ?? 0);
            if ($f['id_medico'] === 0) {
                Http::json(200, ['citas' => []]);
            }
        }
        return $f;
    }

    public static function listar(array $p): void
    {
        $u = Auth::need('citas.propias', 'citas.programar', 'agenda.consultar');
        $f = self::restringir($u, self::filtrosUrl());
        Http::json(200, ['citas' => self::consulta($f, 'c.fecha_cita DESC, c.hora_inicio DESC')]);
    }

    public static function ver(array $p): void
    {
        $u = Auth::need('citas.propias', 'citas.programar', 'agenda.consultar');
        $c = self::cargarPropia($u, (int)$p['id']);
        Http::json(200, self::detalle((int)$c['id_cita']));
    }

    public static function agenda(array $p): void
    {
        $u = Auth::need('agenda.consultar');
        $fecha = V::fecha($_GET, 'fecha', 'La fecha', false) ?? date('Y-m-d');
        $f = ['desde' => $fecha, 'hasta' => $fecha, 'estado' => Http::query('estado')];
        $idMedico = Http::query('id_medico');
        if ($idMedico !== null) {
            $f['id_medico'] = V::int($_GET, 'id_medico', 'El médico');
        }
        if ($u['rol'] === 'Médico') {
            $f['id_medico'] = (int)($u['id_medico'] ?? 0);
        }
        $citas = self::consulta($f, 'c.hora_inicio, c.codigo_cita');

        $tot = ['pendientes' => 0, 'atendidas' => 0, 'canceladas' => 0, 'inasistencias' => 0];
        foreach ($citas as $c) {
            match ($c['estado']) {
                'Programada', 'Confirmada' => $tot['pendientes']++,
                'Atendida' => $tot['atendidas']++,
                'Cancelada' => $tot['canceladas']++,
                default => $tot['inasistencias']++,
            };
        }
        Http::json(200, ['fecha' => $fecha, 'citas' => $citas, 'totales' => $tot]);
    }

    public static function historial(array $p): void
    {
        $u = Auth::need('citas.propias', 'citas.programar');
        $f = self::filtrosUrl();
        if ($u['rol'] === 'Paciente') {
            $f['id_paciente'] = (int)($u['id_paciente'] ?? 0);
        } elseif (empty($f['id_paciente'])) {
            Http::error(422, 'Seleccione un paciente');
        }
        $paciente = PacienteController::detalle((int)($f['id_paciente'] ?? 0));
        if ($paciente === null) {
            Http::error(404, 'Paciente no encontrado');
        }
        Http::json(200, [
            'paciente' => ['id_paciente' => $paciente['id_paciente'], 'codigo' => $paciente['codigo'], 'nombre' => $paciente['nombres'] . ' ' . $paciente['apellidos']],
            'citas' => self::consulta($f, 'c.fecha_cita DESC, c.hora_inicio DESC', 500),
        ]);
    }

    public static function inicio(array $p): void
    {
        $u = Auth::need();
        $hoy = date('Y-m-d');
        $resp = ['rol' => $u['rol'], 'hoy' => $hoy];

        if ($u['rol'] === 'Paciente') {
            $resp['proximas'] = empty($u['id_paciente']) ? [] : self::consulta(
                ['id_paciente' => $u['id_paciente'], 'desde' => $hoy, 'estado' => 'Programada,Confirmada'],
                'c.fecha_cita, c.hora_inicio', 5
            );
        } elseif ($u['rol'] === 'Médico') {
            $resp['agenda_hoy'] = empty($u['id_medico']) ? [] : self::consulta(
                ['id_medico' => $u['id_medico'], 'desde' => $hoy, 'hasta' => $hoy], 'c.hora_inicio'
            );
        } else {
            $filas = Db::all("SELECT estado, COUNT(*) AS total FROM citas WHERE fecha_cita = CURRENT_DATE GROUP BY estado");
            $porEstado = array_fill_keys(self::ESTADOS, 0);
            foreach ($filas as $f) {
                $porEstado[$f['estado']] = (int)$f['total'];
            }
            $resp['hoy_por_estado'] = $porEstado;
            $resp['por_confirmar'] = (int)Db::val("SELECT COUNT(*) FROM citas WHERE estado = 'Programada' AND fecha_cita >= CURRENT_DATE");
            $resp['pacientes_activos'] = (int)Db::val('SELECT COUNT(*) FROM pacientes WHERE habilitado');
            $resp['medicos_activos'] = (int)Db::val('SELECT COUNT(*) FROM medicos WHERE habilitado');
            $resp['proximas'] = self::consulta(['desde' => $hoy, 'estado' => 'Programada,Confirmada'], 'c.fecha_cita, c.hora_inicio', 6);
        }
        Http::json(200, $resp);
    }

    // ====================== Programar, reprogramar, cancelar (CU-11 a CU-13) ======================

    public static function crear(array $p): void
    {
        $u = Auth::need('citas.programar', 'citas.propias');
        $d = Http::body();

        if (Auth::can($u, 'citas.programar')) {
            $idPaciente = V::int($d, 'id_paciente', 'El paciente');
        } else {
            $idPaciente = (int)($u['id_paciente'] ?? 0);
            if ($idPaciente === 0) {
                Http::error(403, 'Su cuenta no tiene un perfil de paciente asociado');
            }
        }
        $idMedico = V::int($d, 'id_medico', 'El médico');
        $idEsp = V::int($d, 'id_especialidad', 'La especialidad');
        $fecha = V::fecha($d, 'fecha', 'La fecha');
        $hora = V::hora($d, 'hora_inicio', 'La hora');
        $motivo = V::str($d, 'motivo', 'El motivo', 200, false);

        $pac = Db::one('SELECT habilitado FROM pacientes WHERE id_paciente = :id', ['id' => $idPaciente]);
        if ($pac === null) {
            Http::error(422, 'El paciente no existe');
        }
        if (!$pac['habilitado']) {
            Http::error(422, 'El paciente está inactivo y no puede recibir nuevas citas');
        }

        $idCita = Db::tx(function () use ($idPaciente, $idMedico, $idEsp, $fecha, $hora, $u, $motivo) {
            Db::run(
                'CALL sp_programar_cita(CAST(:pac AS INT), CAST(:med AS INT), CAST(:esp AS INT), CAST(:f AS DATE), CAST(:h AS TIME), CAST(:u AS INT), CAST(:mo AS VARCHAR))',
                ['pac' => $idPaciente, 'med' => $idMedico, 'esp' => $idEsp, 'f' => $fecha, 'h' => $hora, 'u' => $u['id_usuario'], 'mo' => $motivo]
            );
            return (int)Db::val(
                "SELECT id_cita FROM citas WHERE id_medico = :m AND fecha_cita = :f AND hora_inicio = :h AND estado <> 'Cancelada'",
                ['m' => $idMedico, 'f' => $fecha, 'h' => $hora]
            );
        });

        Notificador::intentar();
        Http::json(201, self::detalle($idCita));
    }

    public static function reprogramar(array $p): void
    {
        $u = Auth::need('citas.programar', 'citas.propias');
        $c = self::cargarPropia($u, (int)$p['id']);
        $d = Http::body();
        $fecha = V::fecha($d, 'fecha', 'La fecha');
        $hora = V::hora($d, 'hora_inicio', 'La hora');

        Db::tx(function () use ($c, $fecha, $hora, $u) {
            Db::run(
                'CALL sp_reprogramar_cita(CAST(:id AS INT), CAST(:f AS DATE), CAST(:h AS TIME), CAST(:u AS INT))',
                ['id' => $c['id_cita'], 'f' => $fecha, 'h' => $hora, 'u' => $u['id_usuario']]
            );
        });
        Notificador::intentar();
        Http::json(200, self::detalle((int)$c['id_cita']));
    }

    public static function cancelar(array $p): void
    {
        $u = Auth::need('citas.cancelar', 'citas.propias');
        $c = self::cargarPropia($u, (int)$p['id']);
        $motivo = V::str(Http::body(), 'motivo', 'El motivo de la cancelación', 200);

        Db::tx(function () use ($c, $motivo, $u) {
            Db::run(
                'CALL sp_cancelar_cita(CAST(:id AS INT), CAST(:mo AS VARCHAR), CAST(:u AS INT))',
                ['id' => $c['id_cita'], 'mo' => $motivo, 'u' => $u['id_usuario']]
            );
        });
        Notificador::intentar();
        Http::json(200, self::detalle((int)$c['id_cita']));
    }

    // ====================== Asistencia y estado (CU-14) ======================

    public static function cambiarEstado(array $p): void
    {
        $u = Auth::need('citas.asistencia');
        $c = self::cargarPropia($u, (int)$p['id']);
        $estado = V::enum(Http::body(), 'estado', 'El estado', ['Confirmada', 'Atendida', 'Inasistencia']);

        if ($u['rol'] === 'Médico' && $estado !== 'Atendida') {
            Http::error(403, 'El médico solo puede marcar una cita como atendida');
        }
        Db::tx(function () use ($c, $estado, $u) {
            Db::run(
                'CALL sp_cambiar_estado_cita(CAST(:id AS INT), CAST(:e AS VARCHAR), CAST(:u AS INT))',
                ['id' => $c['id_cita'], 'e' => $estado, 'u' => $u['id_usuario']]
            );
        });
        Http::json(200, self::detalle((int)$c['id_cita']));
    }

    // ====================== Auxiliares ======================

    /** Carga la cita y verifica que el usuario pueda tocarla. */
    private static function cargarPropia(array $u, int $id): array
    {
        $c = Db::one('SELECT id_cita, id_paciente, id_medico FROM citas WHERE id_cita = :id', ['id' => $id]);
        if ($c === null) {
            Http::error(404, 'Cita no encontrada');
        }
        if ($u['rol'] === 'Paciente' && (int)$c['id_paciente'] !== (int)($u['id_paciente'] ?? 0)) {
            Http::error(403, 'No puede gestionar citas de otro paciente');
        }
        if ($u['rol'] === 'Médico' && (int)$c['id_medico'] !== (int)($u['id_medico'] ?? 0)) {
            Http::error(403, 'No puede gestionar citas de otro médico');
        }
        return $c;
    }

    public static function detalle(int $id): array
    {
        $c = Db::one(self::BASE . ' WHERE c.id_cita = :id', ['id' => $id]);
        if ($c === null) {
            Http::error(404, 'Cita no encontrada');
        }
        $c['historial_estados'] = Db::all(
            "SELECT h.estado_anterior, h.estado_nuevo, to_char(h.fecha_cambio, 'YYYY-MM-DD HH24:MI') AS fecha,
                    u.nombre_usuario AS usuario, h.observacion
             FROM historial_estado_cita h JOIN usuarios u ON u.id_usuario = h.id_usuario
             WHERE h.id_cita = :id ORDER BY h.id_historial",
            ['id' => $id]
        );
        return $c;
    }
}
