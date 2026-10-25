<?php
declare(strict_types=1);

/** Autenticacion con token firmado (JWT HS256) y control de acceso por roles y permisos. */
final class Auth
{
    private static ?array $actual = null;

    private static function secreto(): string
    {
        $s = Env::get('APP_SECRET');
        if ($s === null || strlen($s) < 16) {
            throw new RuntimeException('APP_SECRET no está configurada (minimo 16 caracteres)');
        }
        return $s;
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function deb64(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'));
    }

    public static function emitir(int $idUsuario): string
    {
        $horas = (int)Env::get('TOKEN_HORAS', '8');
        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64(json_encode(['sub' => $idUsuario, 'iat' => time(), 'exp' => time() + $horas * 3600]));
        $firma = self::b64(hash_hmac('sha256', "$h.$p", self::secreto(), true));
        return "$h.$p.$firma";
    }

    private static function idDesdeToken(): ?int
    {
        $cab = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($cab === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower((string)$k) === 'authorization') {
                    $cab = (string)$v;
                }
            }
        }
        if (stripos($cab, 'Bearer ') !== 0) {
            return null;
        }
        $partes = explode('.', trim(substr($cab, 7)));
        if (count($partes) !== 3) {
            return null;
        }
        [$h, $p, $f] = $partes;
        $esperada = self::b64(hash_hmac('sha256', "$h.$p", self::secreto(), true));
        if (!hash_equals($esperada, $f)) {
            return null;
        }
        $datos = json_decode(self::deb64($p), true);
        if (!is_array($datos) || ($datos['exp'] ?? 0) < time() || !isset($datos['sub'])) {
            return null;
        }
        return (int)$datos['sub'];
    }

    /** Carga el usuario con su rol y permisos desde la base de datos. */
    public static function cargar(int $id): ?array
    {
        $u = Db::one(
            "SELECT u.id_usuario, u.nombre_usuario, u.email, u.id_rol, u.id_paciente, u.id_medico, u.habilitado,
                    r.nombre AS rol,
                    COALESCE(p.nombres || ' ' || p.apellidos, m.nombres || ' ' || m.apellidos, u.nombre_usuario) AS nombre
             FROM usuarios u
             JOIN roles r ON r.id_rol = u.id_rol
             LEFT JOIN pacientes p ON p.id_paciente = u.id_paciente
             LEFT JOIN medicos m ON m.id_medico = u.id_medico
             WHERE u.id_usuario = :id",
            ['id' => $id]
        );
        if ($u === null) {
            return null;
        }
        $filas = Db::all(
            'SELECT p.nombre FROM rol_permiso rp JOIN permisos p ON p.id_permiso = rp.id_permiso
             WHERE rp.id_rol = :r ORDER BY p.nombre',
            ['r' => $u['id_rol']]
        );
        $u['permisos'] = array_column($filas, 'nombre');
        return $u;
    }

    /** Exige sesion valida y, si se indican permisos, al menos uno de ellos. */
    public static function need(string ...$permisos): array
    {
        if (self::$actual === null) {
            $id = self::idDesdeToken();
            $u = $id === null ? null : self::cargar($id);
            if ($u === null || !$u['habilitado']) {
                Http::error(401, 'Sesión no válida o expirada. Inicie sesión nuevamente.');
            }
            self::$actual = $u;
        }
        if ($permisos !== []) {
            $ok = false;
            foreach ($permisos as $p) {
                if (self::can(self::$actual, $p)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                Http::error(403, 'No tiene permiso para realizar esta operación');
            }
        }
        return self::$actual;
    }

    public static function can(array $usuario, string $permiso): bool
    {
        return in_array($permiso, $usuario['permisos'], true);
    }
}
