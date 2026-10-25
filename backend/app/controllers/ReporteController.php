<?php
declare(strict_types=1);

/** CU-19 Reportes, CU-20 Bitácora, notificaciones del paciente y tarea de recordatorios (CU-18). */
final class ReporteController
{
    private const TIPOS = [
        'citas' => 'Citas por fecha, médico, especialidad y estado',
        'por_estado' => 'Cantidad de citas por estado',
        'por_medico' => 'Citas por médico',
        'por_especialidad' => 'Citas por especialidad',
        'pacientes' => 'Pacientes registrados',
        'agenda_diaria' => 'Agenda diaria',
    ];

    public static function tipos(array $p): void
    {
        Auth::need('reportes.ver');
        $lista = [];
        foreach (self::TIPOS as $k => $t) {
            $lista[] = ['tipo' => $k, 'titulo' => $t];
        }
        Http::json(200, ['tipos' => $lista]);
    }

    public static function generar(array $p): void
    {
        Auth::need('reportes.ver');
        $tipo = (string)$p['tipo'];
        if (!isset(self::TIPOS[$tipo])) {
            Http::error(404, 'Tipo de reporte no encontrado');
        }

        $hoy = date('Y-m-d');
        $desde = V::fecha($_GET, 'desde', 'La fecha inicial', false) ?? date('Y-m-01');
        $hasta = V::fecha($_GET, 'hasta', 'La fecha final', false) ?? date('Y-m-t');
        if ($desde > $hasta) {
            Http::error(422, 'Rango de fechas inválido: la fecha inicial es posterior a la final');
        }
        $idMedico = Http::query('id_medico') !== null ? V::int($_GET, 'id_medico', 'El médico') : null;
        $idEsp = Http::query('id_especialidad') !== null ? V::int($_GET, 'id_especialidad', 'La especialidad') : null;
        $estado = Http::query('estado');
        if ($estado !== null && !in_array($estado, CitaController::ESTADOS, true)) {
            Http::error(422, 'El estado indicado no es válido');
        }

        $r = match ($tipo) {
            'citas' => self::detalleCitas(['desde' => $desde, 'hasta' => $hasta, 'id_medico' => $idMedico, 'id_especialidad' => $idEsp, 'estado' => $estado]),
            'agenda_diaria' => self::detalleCitas(['desde' => $desde === date('Y-m-01') ? $hoy : $desde, 'hasta' => $desde === date('Y-m-01') ? $hoy : $desde, 'id_medico' => $idMedico, 'id_especialidad' => $idEsp, 'estado' => $estado]),
            'por_estado' => self::agrupado('c.estado', 'Estado', $desde, $hasta, $idMedico, $idEsp, true),
            'por_medico' => self::agrupado("m.apellidos || ' ' || m.nombres", 'Médico', $desde, $hasta, $idMedico, $idEsp),
            'por_especialidad' => self::agrupado('e.nombre', 'Especialidad', $desde, $hasta, $idMedico, $idEsp),
            'pacientes' => self::pacientes($desde, $hasta),
        };
        $r['tipo'] = $tipo;
        $r['titulo'] = self::TIPOS[$tipo];
        $r['desde'] = $desde;
        $r['hasta'] = $hasta;

        if (Http::query('formato') === 'csv') {
            self::csv($r);
        }
        Http::json(200, $r);
    }

    private static function detalleCitas(array $f): array
    {
        $citas = CitaController::consulta($f, 'c.fecha_cita, c.hora_inicio', 2000);
        $tot = ['Total' => count($citas), 'Atendidas' => 0, 'Canceladas' => 0, 'Inasistencias' => 0];
        $filas = [];
        foreach ($citas as $c) {
            if ($c['estado'] === 'Atendida') {
                $tot['Atendidas']++;
            } elseif ($c['estado'] === 'Cancelada') {
                $tot['Canceladas']++;
            } elseif ($c['estado'] === 'Inasistencia') {
                $tot['Inasistencias']++;
            }
            $filas[] = [
                'codigo' => $c['codigo_cita'], 'fecha' => $c['fecha'], 'hora' => $c['hora_inicio'],
                'paciente' => $c['paciente'], 'medico' => $c['medico'], 'especialidad' => $c['especialidad'], 'estado' => $c['estado'],
            ];
        }
        return [
            'columnas' => [
                ['k' => 'codigo', 'label' => 'Código'], ['k' => 'fecha', 'label' => 'Fecha'], ['k' => 'hora', 'label' => 'Hora'],
                ['k' => 'paciente', 'label' => 'Paciente'], ['k' => 'medico', 'label' => 'Médico'],
                ['k' => 'especialidad', 'label' => 'Especialidad'], ['k' => 'estado', 'label' => 'Estado'],
            ],
            'filas' => $filas,
            'totales' => $tot,
        ];
    }

    private static function agrupado(string $expr, string $etiqueta, string $desde, string $hasta, ?int $idMedico, ?int $idEsp, bool $soloEstado = false): array
    {
        $w = ['c.fecha_cita BETWEEN :d AND :h'];
        $par = ['d' => $desde, 'h' => $hasta];
        if ($idMedico !== null) {
            $w[] = 'c.id_medico = :m';
            $par['m'] = $idMedico;
        }
        if ($idEsp !== null) {
            $w[] = 'c.id_especialidad = :e';
            $par['e'] = $idEsp;
        }
        $filas = Db::all(
            "SELECT $expr AS grupo, COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE c.estado = 'Atendida') AS atendidas,
                    COUNT(*) FILTER (WHERE c.estado = 'Cancelada') AS canceladas,
                    COUNT(*) FILTER (WHERE c.estado = 'Inasistencia') AS inasistencias
             FROM citas c
             JOIN medicos m ON m.id_medico = c.id_medico
             JOIN especialidades e ON e.id_especialidad = c.id_especialidad
             WHERE " . implode(' AND ', $w) . " GROUP BY 1 ORDER BY 2 DESC, 1",
            $par
        );
        $tot = ['Total' => 0, 'Atendidas' => 0, 'Canceladas' => 0, 'Inasistencias' => 0];
        foreach ($filas as &$f) {
            $tot['Total'] += (int)$f['total'];
            $tot['Atendidas'] += (int)$f['atendidas'];
            $tot['Canceladas'] += (int)$f['canceladas'];
            $tot['Inasistencias'] += (int)$f['inasistencias'];
        }
        unset($f);

        $cols = [['k' => 'grupo', 'label' => $etiqueta], ['k' => 'total', 'label' => 'Total']];
        if (!$soloEstado) {
            array_push($cols, ['k' => 'atendidas', 'label' => 'Atendidas'], ['k' => 'canceladas', 'label' => 'Canceladas'], ['k' => 'inasistencias', 'label' => 'Inasistencias']);
        }
        return ['columnas' => $cols, 'filas' => $filas, 'totales' => $tot];
    }

    private static function pacientes(string $desde, string $hasta): array
    {
        $filas = Db::all(
            "SELECT codigo, COALESCE(ci, '') AS ci, nombres || ' ' || apellidos AS nombre, telefono,
                    to_char(fecha_registro, 'YYYY-MM-DD') AS registro, CASE WHEN habilitado THEN 'Activo' ELSE 'Inactivo' END AS estado
             FROM pacientes WHERE fecha_registro::DATE BETWEEN :d AND :h ORDER BY fecha_registro DESC",
            ['d' => $desde, 'h' => $hasta]
        );
        return [
            'columnas' => [
                ['k' => 'codigo', 'label' => 'Código'], ['k' => 'ci', 'label' => 'CI'], ['k' => 'nombre', 'label' => 'Paciente'],
                ['k' => 'telefono', 'label' => 'Teléfono'], ['k' => 'registro', 'label' => 'Registro'], ['k' => 'estado', 'label' => 'Estado'],
            ],
            'filas' => $filas,
            'totales' => ['Total' => count($filas)],
        ];
    }

    private static function csv(array $r): never
    {
        $celda = static function ($v): string {
            $v = (string)$v;
            // Evita que Excel interprete el texto como una fórmula
            return ($v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
        };
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="reporte-' . $r['tipo'] . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM para que Excel respete los acentos
        fputcsv($out, array_map(static fn($c) => $c['label'], $r['columnas']));
        foreach ($r['filas'] as $fila) {
            fputcsv($out, array_map(static fn($c) => $celda($fila[$c['k']] ?? ''), $r['columnas']));
        }
        fclose($out);
        exit;
    }

    // ====================== Bitácora (CU-20) ======================

    public static function bitacora(array $p): void
    {
        Auth::need('bitacora.ver');
        $w = [];
        $par = [];
        if (Http::query('id_usuario') !== null) {
            $w[] = 'b.id_usuario = :u';
            $par['u'] = V::int($_GET, 'id_usuario', 'El usuario');
        }
        if (Http::query('tabla') !== null) {
            $w[] = 'b.tabla_afectada = :t';
            $par['t'] = Http::query('tabla');
        }
        if (Http::query('accion') !== null) {
            $w[] = 'b.accion = :a';
            $par['a'] = V::enum($_GET, 'accion', 'La acción', ['INSERT', 'UPDATE', 'DELETE']);
        }
        $desde = V::fecha($_GET, 'desde', 'La fecha inicial', false);
        $hasta = V::fecha($_GET, 'hasta', 'La fecha final', false);
        if ($desde !== null) {
            $w[] = 'b.fecha_evento::DATE >= :d';
            $par['d'] = $desde;
        }
        if ($hasta !== null) {
            $w[] = 'b.fecha_evento::DATE <= :h';
            $par['h'] = $hasta;
        }
        $eventos = Db::all(
            "SELECT b.id_bitacora, to_char(b.fecha_evento, 'YYYY-MM-DD HH24:MI:SS') AS fecha, COALESCE(u.nombre_usuario, 'sistema') AS usuario,
                    b.tabla_afectada AS tabla, b.registro_afectado AS registro, b.accion, b.datos_anteriores, b.datos_nuevos
             FROM bitacora b LEFT JOIN usuarios u ON u.id_usuario = b.id_usuario" .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY b.fecha_evento DESC, b.id_bitacora DESC LIMIT 300',
            $par
        );
        $tablas = array_column(Db::all('SELECT DISTINCT tabla_afectada FROM bitacora ORDER BY 1'), 'tabla_afectada');
        $usuarios = Db::all('SELECT id_usuario, nombre_usuario FROM usuarios ORDER BY nombre_usuario');
        Http::json(200, ['eventos' => $eventos, 'tablas' => $tablas, 'usuarios' => $usuarios]);
    }

    // ====================== Notificaciones ======================

    public static function notificaciones(array $p): void
    {
        $u = Auth::need('citas.propias', 'citas.programar', 'agenda.consultar');
        $w = [];
        $par = [];
        if ($u['rol'] === 'Paciente') {
            $w[] = 'c.id_paciente = :p';
            $par['p'] = (int)($u['id_paciente'] ?? 0);
        }
        $filas = Db::all(
            "SELECT n.id_notificacion, n.tipo, n.mensaje, n.estado, c.codigo_cita,
                    to_char(n.fecha_envio, 'YYYY-MM-DD HH24:MI') AS fecha_envio
             FROM notificaciones n JOIN citas c ON c.id_cita = n.id_cita" .
            ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY n.id_notificacion DESC LIMIT 100',
            $par
        );
        Http::json(200, ['notificaciones' => $filas]);
    }

    /** Tarea programada: POST o GET /api/cron/recordatorios con la cabecera X-Cron-Key. */
    public static function cronRecordatorios(array $p): void
    {
        $clave = Env::get('CRON_SECRET');
        if ($clave === null) {
            Http::error(503, 'CRON_SECRET no está configurada en el servidor');
        }
        $recibida = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
        if (!hash_equals($clave, (string)$recibida)) {
            Http::error(401, 'Clave de tarea programada incorrecta');
        }
        $nuevos = Notificador::generarRecordatorios();
        $envio = Notificador::enviarPendientes(100);
        Http::json(200, ['recordatorios_creados' => $nuevos] + $envio);
    }

    public static function salud(array $p): void
    {
        $db = true;
        try {
            Db::val('SELECT 1');
        } catch (Throwable $e) {
            $db = false;
        }
        Http::json(200, ['ok' => true, 'base_de_datos' => $db, 'hora' => date('c')]);
    }
}
