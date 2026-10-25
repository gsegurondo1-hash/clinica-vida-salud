<?php
declare(strict_types=1);

/** Entrada y salida HTTP en JSON. */
final class Http
{
    public static function json(int $status, $datos): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    public static function error(int $status, string $mensaje, array $extra = []): never
    {
        self::json($status, ['error' => $mensaje] + $extra);
    }

    public static function body(): array
    {
        static $cuerpo = null;
        if ($cuerpo === null) {
            $raw = file_get_contents('php://input');
            $cuerpo = ($raw === false || trim($raw) === '') ? [] : json_decode($raw, true);
            if (!is_array($cuerpo)) {
                self::error(400, 'El cuerpo de la solicitud no es un JSON válido');
            }
        }
        return $cuerpo;
    }

    public static function query(string $clave, ?string $defecto = null): ?string
    {
        $v = $_GET[$clave] ?? null;
        if (!is_string($v) || trim($v) === '') {
            return $defecto;
        }
        return trim($v);
    }

    /** Traduce excepciones de PostgreSQL a mensajes comprensibles. */
    public static function fail(Throwable $e): never
    {
        if ($e instanceof PDOException) {
            $estado = (string)$e->getCode();
            $info = (string)($e->errorInfo[2] ?? $e->getMessage());

            if ($estado === 'P0001') { // RAISE EXCEPTION de triggers y procedimientos
                $msg = preg_match('/ERROR:\s*(.+)/u', $info, $m) === 1 ? $m[1] : $info;
                self::error(422, trim((string)strtok($msg, "\n")));
            }
            if ($estado === '23505') {
                self::error(409, self::mensajeUnico($info));
            }
            if ($estado === '23503') {
                self::error(409, 'La operación no se puede completar porque el registro está relacionado con otros datos');
            }
            if (in_array($estado, ['23514', '22007', '22008', '22P02', '22003'], true)) {
                self::error(422, 'Algún dato tiene un formato o valor inválido');
            }
        }

        error_log('[API] ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
        self::error(500, Env::bool('APP_DEBUG') ? $e->getMessage() : 'Error interno del servidor');
    }

    private static function mensajeUnico(string $info): string
    {
        if (strpos($info, 'uq_cita_medico_horario') !== false) {
            return 'Ese horario acaba de ser reservado por otra persona. Elija otro.';
        }
        $campos = [
            'ci' => 'CI',
            'email' => 'correo electrónico',
            'nombre_usuario' => 'nombre de usuario',
            'nro_registro_prof' => 'registro profesional',
            'codigo' => 'código',
            'nombre_normalizado' => 'nombre',
            'id_medico' => 'médico',
            'id_paciente' => 'paciente',
        ];
        if (preg_match('/Key \(([^)]+)\)=/', $info, $m) === 1) {
            $campo = $m[1];
            return 'Ya existe un registro con el mismo ' . ($campos[$campo] ?? $campo);
        }
        return 'Ya existe un registro con esos datos';
    }
}
