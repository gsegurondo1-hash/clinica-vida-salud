<?php
declare(strict_types=1);

/** CU-20: convierte un evento de la bitácora en una frase legible para el administrador. */
final class BitacoraTexto
{
    private const TABLAS = [
        'citas' => 'Citas', 'pacientes' => 'Pacientes', 'medicos' => 'Médicos', 'usuarios' => 'Usuarios',
        'especialidades' => 'Especialidades', 'horarios_atencion' => 'Horarios de atención',
        'bloqueos_agenda' => 'Bloqueos de agenda', 'rol_permiso' => 'Permisos por rol',
    ];
    private const ACCIONES = ['INSERT' => 'Alta', 'UPDATE' => 'Cambio', 'DELETE' => 'Baja'];
    private const ETIQUETAS = [
        'estado' => 'estado', 'fecha_cita' => 'fecha', 'hora_inicio' => 'hora de inicio', 'hora_fin' => 'hora de fin',
        'motivo' => 'motivo', 'motivo_cancelacion' => 'motivo de cancelación', 'id_medico' => 'médico',
        'id_especialidad' => 'especialidad', 'id_paciente' => 'paciente', 'telefono' => 'teléfono', 'email' => 'correo',
        'direccion' => 'dirección', 'nombres' => 'nombres', 'apellidos' => 'apellidos', 'ci' => 'CI', 'sexo' => 'sexo',
        'fecha_nacimiento' => 'fecha de nacimiento', 'habilitado' => 'estado', 'nombre' => 'nombre',
        'descripcion' => 'descripción', 'id_rol' => 'rol', 'dia_semana' => 'día', 'duracion_cita_min' => 'duración de la cita (min)',
        'nro_registro_prof' => 'registro profesional', 'codigo' => 'código', 'fecha' => 'fecha',
    ];
    private const IGNORAR = [
        'id_cita', 'id_usuario_registra', 'fecha_creacion', 'fecha_registro', 'nombre_normalizado', 'password_hash',
        'token_recuperacion', 'token_expira', 'ultimo_acceso', 'id_paciente_ref', 'codigo_cita',
    ];
    private static array $memo = [];

    public static function tabla(string $t): string
    {
        return self::TABLAS[$t] ?? $t;
    }

    public static function accion(string $a): string
    {
        return self::ACCIONES[$a] ?? $a;
    }

    public static function describir(string $tabla, string $accion, ?int $registro, $antes, $despues): string
    {
        try {
            $a = self::json($antes);
            $n = self::json($despues);
            switch ($tabla) {
                case 'citas': return self::citas($accion, $a, $n);
                case 'pacientes': return self::persona('paciente', $accion, $a, $n);
                case 'medicos': return self::persona('médico', $accion, $a, $n);
                case 'usuarios': return self::usuarios($accion, (int)$registro, $a, $n);
                case 'especialidades': return self::especialidades($accion, $a, $n);
                case 'horarios_atencion': return self::horarios($accion, (int)$registro, $a, $n);
                case 'bloqueos_agenda': return self::bloqueos($accion, $a, $n);
                case 'rol_permiso': return self::permisos((int)$registro, $a, $n);
            }
        } catch (\Throwable $e) {
            // si algo falla se usa la frase genérica
        }
        return self::accion($accion) . ' en ' . strtolower(self::tabla($tabla)) . '.';
    }

    // ---------------- utilidades ----------------
    private static function json($v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (!is_string($v) || $v === '') {
            return [];
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : [];
    }

    private static function buscar(string $clave, string $sql, int $id): ?array
    {
        $k = $clave . ':' . $id;
        if (!array_key_exists($k, self::$memo)) {
            self::$memo[$k] = Db::one($sql, ['i' => $id]);
        }
        return self::$memo[$k];
    }

    private static function rol($id): string
    {
        $f = $id === null ? null : self::buscar('rol', 'SELECT nombre FROM roles WHERE id_rol = :i', (int)$id);
        return $f['nombre'] ?? 'sin rol';
    }

    private static function medico($id): string
    {
        $f = $id === null ? null : self::buscar('med', 'SELECT nombres, apellidos FROM medicos WHERE id_medico = :i', (int)$id);
        return $f ? trim($f['nombres'] . ' ' . $f['apellidos']) : 'toda la clínica';
    }

    private static function paciente($id): string
    {
        $f = $id === null ? null : self::buscar('pac', 'SELECT nombres, apellidos FROM pacientes WHERE id_paciente = :i', (int)$id);
        return $f ? trim($f['nombres'] . ' ' . $f['apellidos']) : 'un paciente';
    }

    private static function especialidad($id): string
    {
        $f = $id === null ? null : self::buscar('esp', 'SELECT nombre FROM especialidades WHERE id_especialidad = :i', (int)$id);
        return $f['nombre'] ?? 'sin especialidad';
    }

    private static function usuario(int $id): string
    {
        $f = self::buscar('usu', 'SELECT nombre_usuario FROM usuarios WHERE id_usuario = :i', $id);
        return $f['nombre_usuario'] ?? 'usuario eliminado';
    }

    private static function valor(string $campo, $v): string
    {
        if ($v === null || $v === '') {
            return 'vacío';
        }
        if ($campo === 'habilitado') {
            return $v ? 'Activo' : 'Inactivo';
        }
        if (is_bool($v)) {
            return $v ? 'Sí' : 'No';
        }
        switch ($campo) {
            case 'id_rol': return self::rol($v);
            case 'id_medico': return self::medico($v);
            case 'id_paciente': return self::paciente($v);
            case 'id_especialidad': return self::especialidad($v);
        }
        $s = (string)$v;
        if (strpos($campo, 'hora_') === 0 && preg_match('/^(\d{2}:\d{2})/', $s, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return $s;
    }

    private static function cambios(array $a, array $n): string
    {
        $out = [];
        foreach ($n as $k => $v) {
            if (in_array($k, self::IGNORAR, true) || strpos($k, 'id_') === 0 && !isset(self::ETIQUETAS[$k])) {
                continue;
            }
            $antes = $a[$k] ?? null;
            if ($antes === $v || (string)json_encode($antes) === (string)json_encode($v)) {
                continue;
            }
            $et = self::ETIQUETAS[$k] ?? $k;
            $out[] = sprintf('%s de «%s» a «%s»', $et, self::valor($k, $antes), self::valor($k, $v));
        }
        return implode('; ', $out);
    }

    // ---------------- por tabla ----------------
    private static function citas(string $accion, array $a, array $n): string
    {
        $c = $n ?: $a;
        $cod = (string)($c['codigo_cita'] ?? 'sin código');
        if ($accion === 'INSERT') {
            return sprintf('Programó la cita %s de %s con %s (%s) para el %s a las %s.', $cod,
                self::paciente($c['id_paciente'] ?? null), self::medico($c['id_medico'] ?? null),
                self::especialidad($c['id_especialidad'] ?? null), self::valor('fecha_cita', $c['fecha_cita'] ?? null),
                self::valor('hora_inicio', $c['hora_inicio'] ?? null));
        }
        if ($accion === 'DELETE') {
            return "Eliminó la cita $cod.";
        }
        if (($a['estado'] ?? null) !== ($n['estado'] ?? null)) {
            $t = sprintf('Cambió el estado de la cita %s de «%s» a «%s»', $cod, $a['estado'] ?? '—', $n['estado'] ?? '—');
            if (($n['estado'] ?? '') === 'Cancelada' && !empty($n['motivo_cancelacion'])) {
                $t .= '. Motivo: ' . $n['motivo_cancelacion'];
            }
            return $t . '.';
        }
        if (($a['fecha_cita'] ?? null) !== ($n['fecha_cita'] ?? null) || ($a['hora_inicio'] ?? null) !== ($n['hora_inicio'] ?? null)) {
            return sprintf('Reprogramó la cita %s del %s a las %s al %s a las %s.', $cod,
                self::valor('fecha_cita', $a['fecha_cita'] ?? null), self::valor('hora_inicio', $a['hora_inicio'] ?? null),
                self::valor('fecha_cita', $n['fecha_cita'] ?? null), self::valor('hora_inicio', $n['hora_inicio'] ?? null));
        }
        $cam = self::cambios($a, $n);
        return $cam !== '' ? "Modificó la cita $cod ($cam)." : "Actualizó la cita $cod.";
    }

    private static function persona(string $tipo, string $accion, array $a, array $n): string
    {
        $p = $n ?: $a;
        $nombre = trim(($p['nombres'] ?? '') . ' ' . ($p['apellidos'] ?? '')) . (isset($p['codigo']) ? ' (' . $p['codigo'] . ')' : '');
        $art = $tipo === 'paciente' ? 'al paciente' : 'al médico';
        if ($accion === 'INSERT') {
            return "Registró $art $nombre.";
        }
        if ($accion === 'DELETE') {
            return "Eliminó $art $nombre.";
        }
        if (($a['habilitado'] ?? null) !== ($n['habilitado'] ?? null) && array_key_exists('habilitado', $n)) {
            return ($n['habilitado'] ? 'Reactivó ' : 'Desactivó ') . "$art $nombre.";
        }
        $cam = self::cambios($a, $n);
        return $cam !== '' ? "Actualizó los datos $art".' '."$nombre: $cam." : "Actualizó los datos $art $nombre.";
    }

    private static function usuarios(string $accion, int $id, array $a, array $n): string
    {
        if ($accion === 'INSERT') {
            return sprintf('Creó el usuario «%s» con el rol «%s» y el correo %s.', $n['nombre_usuario'] ?? self::usuario($id),
                self::rol($n['id_rol'] ?? null), $n['email'] ?? '—');
        }
        $nombre = self::usuario($id);
        $p = [];
        if (array_key_exists('id_rol', $n) && ($a['id_rol'] ?? null) !== $n['id_rol']) {
            $p[] = sprintf('cambió el rol de «%s» a «%s»', self::rol($a['id_rol'] ?? null), self::rol($n['id_rol']));
        }
        if (array_key_exists('habilitado', $n) && ($a['habilitado'] ?? null) !== $n['habilitado']) {
            $p[] = $n['habilitado'] ? 'reactivó la cuenta' : 'desactivó la cuenta';
        }
        if (array_key_exists('email', $n) && ($a['email'] ?? null) !== $n['email']) {
            $p[] = sprintf('cambió el correo de «%s» a «%s»', $a['email'] ?? '—', $n['email']);
        }
        if (!empty($n['password_cambiada'])) {
            $p[] = 'cambió la contraseña';
        }
        return $p ? sprintf('Actualizó el usuario «%s»: %s.', $nombre, implode('; ', $p)) : sprintf('Actualizó el usuario «%s».', $nombre);
    }

    private static function especialidades(string $accion, array $a, array $n): string
    {
        $nombre = $n['nombre'] ?? $a['nombre'] ?? 'sin nombre';
        if ($accion === 'INSERT') {
            return "Creó la especialidad «$nombre».";
        }
        if ($accion === 'DELETE') {
            return "Eliminó la especialidad «$nombre».";
        }
        $est = array_key_exists('habilitado', $n) ? ($n['habilitado'] ? ' Quedó activa.' : ' Quedó inactiva.') : '';
        return "Actualizó la especialidad «$nombre».$est";
    }

    private static function horarios(string $accion, int $id, array $a, array $n): string
    {
        $h = $n ?: $a;
        $idMedico = $h['id_medico'] ?? null;
        if ($idMedico === null) {
            $f = Db::one('SELECT id_medico FROM horarios_atencion WHERE id_horario = :i', ['i' => $id]);
            $idMedico = $f['id_medico'] ?? null;
        }
        $med = self::medico($idMedico);
        $dia = $h['dia_semana'] ?? '';
        $rango = self::valor('hora_inicio', $h['hora_inicio'] ?? null) . ' a ' . self::valor('hora_fin', $h['hora_fin'] ?? null);
        if ($accion === 'INSERT') {
            return sprintf('Registró el horario de %s: %s, de %s (citas de %s min).', $med, $dia, $rango, $h['duracion_cita_min'] ?? '—');
        }
        if ($accion === 'DELETE') {
            return sprintf('Eliminó el horario de %s: %s, de %s.', $med, $dia, $rango);
        }
        $cam = self::cambios($a, $n);
        return $cam !== '' ? sprintf('Modificó el horario de %s (%s): %s.', $med, $dia, $cam) : sprintf('Actualizó el horario de %s (%s).', $med, $dia);
    }

    private static function bloqueos(string $accion, array $a, array $n): string
    {
        $b = $n ?: $a;
        $quien = self::medico($b['id_medico'] ?? null);
        $cuando = self::valor('fecha', $b['fecha'] ?? null);
        $horas = !empty($b['hora_inicio']) ? sprintf(', de %s a %s', self::valor('hora_inicio', $b['hora_inicio']), self::valor('hora_fin', $b['hora_fin'] ?? null)) : ', durante todo el día';
        $mot = !empty($b['motivo']) ? ' Motivo: ' . $b['motivo'] . '.' : '';
        if ($accion === 'DELETE') {
            return sprintf('Quitó el bloqueo de agenda de %s del %s%s.%s', $quien, $cuando, $horas, $mot);
        }
        return sprintf('Bloqueó la agenda de %s el %s%s.%s', $quien, $cuando, $horas, $mot);
    }

    private static function permisos(int $idRol, array $a, array $n): string
    {
        $rol = self::rol($idRol);
        $antes = (array)($a['permisos'] ?? []);
        $despues = (array)($n['permisos'] ?? []);
        $desc = function (string $nombre): string {
            $f = Db::one('SELECT descripcion FROM permisos WHERE nombre = :i', ['i' => $nombre]);
            return $f['descripcion'] ?? $nombre;
        };
        $mas = array_map($desc, array_values(array_diff($despues, $antes)));
        $menos = array_map($desc, array_values(array_diff($antes, $despues)));
        $p = [];
        if ($mas) {
            $p[] = 'agregó: ' . implode(', ', $mas);
        }
        if ($menos) {
            $p[] = 'quitó: ' . implode(', ', $menos);
        }
        return $p ? sprintf('Cambió los permisos del rol «%s» (%s).', $rol, implode('; ', $p)) : sprintf('Guardó los permisos del rol «%s» sin cambios.', $rol);
    }
}
