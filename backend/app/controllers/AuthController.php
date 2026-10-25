<?php
declare(strict_types=1);

/** CU-01 Iniciar sesión, CU-02 Recuperar contraseña, CU-03 Registro de paciente. */
final class AuthController
{
    public static function login(array $p): void
    {
        $d = Http::body();
        $login = V::str($d, 'usuario', 'El usuario o correo', 100);
        $pass = (isset($d['password']) && is_string($d['password'])) ? $d['password'] : '';
        if ($pass === '') {
            Http::error(422, 'Ingrese su contraseña');
        }

        // Control de intentos fallidos (se cuenta por lo escrito en "usuario", exista o no la cuenta)
        $clave = mb_strtolower(mb_substr($login, 0, 150));
        $max = max(3, (int)Env::get('LOGIN_MAX_INTENTOS', '5'));
        $minutos = max(1, (int)Env::get('LOGIN_BLOQUEO_MIN', '15'));
        $control = self::tablaIntentos();

        if ($control) {
            $seg = (int)Db::val(
                'SELECT EXTRACT(EPOCH FROM (bloqueado_hasta - CURRENT_TIMESTAMP))::INT FROM intentos_login WHERE clave = :c',
                ['c' => $clave]
            );
            if ($seg > 0) {
                self::bloqueado($seg);
            }
        }

        $u = Db::one(
            'SELECT id_usuario, password_hash, habilitado FROM usuarios
             WHERE LOWER(nombre_usuario) = LOWER(:a) OR LOWER(email) = LOWER(:b)',
            ['a' => $login, 'b' => $login]
        );
        if ($u === null || !$u['habilitado'] || !password_verify($pass, $u['password_hash'])) {
            if ($control) {
                $restantes = self::registrarFallo($clave, $max, $minutos);
                if ($restantes === 0) {
                    self::bloqueado($minutos * 60);
                }
                Http::error(401, "Credenciales incorrectas o cuenta deshabilitada. Le quedan $restantes intento(s) antes del bloqueo temporal.");
            }
            Http::error(401, 'Credenciales incorrectas o cuenta deshabilitada');
        }

        if ($control) {
            Db::run('DELETE FROM intentos_login WHERE clave = :c', ['c' => $clave]);
        }
        Db::run('UPDATE usuarios SET ultimo_acceso = CURRENT_TIMESTAMP WHERE id_usuario = :id', ['id' => $u['id_usuario']]);
        Http::json(200, [
            'token' => Auth::emitir((int)$u['id_usuario']),
            'usuario' => Auth::cargar((int)$u['id_usuario']),
        ]);
    }

    private static function bloqueado(int $segundos): never
    {
        $min = (int)ceil($segundos / 60);
        Http::error(
            429,
            "Demasiados intentos fallidos. Por seguridad su acceso está bloqueado temporalmente. Intente de nuevo en $min minuto(s).",
            ['bloqueado_seg' => $segundos]
        );
    }

    /** Suma un intento fallido y devuelve cuántos quedan antes del bloqueo (0 = queda bloqueado). */
    private static function registrarFallo(string $clave, int $max, int $minutos): int
    {
        $f = Db::one(
            'SELECT intentos, (ultimo_intento < CURRENT_TIMESTAMP - make_interval(mins => CAST(:m AS INT))) AS vencido
             FROM intentos_login WHERE clave = :c',
            ['m' => $minutos, 'c' => $clave]
        );
        $n = ($f !== null && !$f['vencido']) ? (int)$f['intentos'] + 1 : 1;
        $bloquear = $n >= $max ? $minutos : 0;

        Db::run(
            'INSERT INTO intentos_login (clave, intentos, bloqueado_hasta, ultimo_intento)
             VALUES (:c, :n, CASE WHEN CAST(:b1 AS INT) > 0 THEN CURRENT_TIMESTAMP + make_interval(mins => CAST(:b2 AS INT)) END, CURRENT_TIMESTAMP)
             ON CONFLICT (clave) DO UPDATE SET intentos = EXCLUDED.intentos,
                 bloqueado_hasta = EXCLUDED.bloqueado_hasta, ultimo_intento = EXCLUDED.ultimo_intento',
            ['c' => $clave, 'n' => $n, 'b1' => $bloquear, 'b2' => $bloquear]
        );
        return max(0, $max - $n);
    }

    /** Crea la tabla si no existe (así funciona también en bases ya desplegadas). */
    private static function tablaIntentos(): bool
    {
        static $listo = null;
        if ($listo !== null) {
            return $listo;
        }
        try {
            Db::pdo()->exec(
                'CREATE TABLE IF NOT EXISTS intentos_login (
                    clave VARCHAR(150) PRIMARY KEY,
                    intentos INT NOT NULL DEFAULT 0,
                    bloqueado_hasta TIMESTAMP,
                    ultimo_intento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)'
            );
            return $listo = true;
        } catch (Throwable $e) {
            error_log('[AUTH] No se pudo preparar el control de intentos: ' . $e->getMessage());
            return $listo = false;
        }
    }

    public static function yo(array $p): void
    {
        Http::json(200, ['usuario' => Auth::need()]);
    }

    public static function registro(array $p): void
    {
        $r = PacienteController::crearPaciente(Http::body(), true, true, null);
        Http::json(201, ['mensaje' => 'Cuenta creada. Ya puede iniciar sesión.', 'codigo' => $r['codigo']]);
    }

    public static function recuperar(array $p): void
    {
        $email = V::email(Http::body());
        $mensaje = 'Si el correo está registrado, recibirá un enlace para restablecer su contraseña.';
        $respuesta = ['mensaje' => $mensaje];

        $u = Db::one('SELECT id_usuario, email FROM usuarios WHERE LOWER(email) = :e AND habilitado', ['e' => $email]);
        if ($u !== null) {
            $token = bin2hex(random_bytes(32));
            Db::run(
                "UPDATE usuarios SET token_recuperacion = :t, token_expira = CURRENT_TIMESTAMP + INTERVAL '1 hour' WHERE id_usuario = :id",
                ['t' => hash('sha256', $token), 'id' => $u['id_usuario']]
            );

            $base = rtrim(Env::get('FRONTEND_URL', 'http://localhost:8080'), '/');
            $enlace = "$base/#/restablecer/$token";
            $enviado = Mailer::enviar(
                (string)$u['email'],
                'Clínica Vida Salud - Restablecer contraseña',
                "Para crear una nueva contraseña abra este enlace (vence en 1 hora):\n$enlace"
            );
            // Solo en modo demostración y SIN proveedor de correo configurado se muestra el enlace.
            // Con un proveedor configurado el enlace viaja únicamente por correo.
            if (!$enviado) {
                error_log('[AUTH] No se pudo enviar el correo de recuperación a un usuario');
            }
            if (Env::bool('DEMO_MODE') && !Mailer::configurado()) {
                $respuesta['enlace_demo'] = $enlace;
            }
        }
        Http::json(200, $respuesta);
    }

    public static function restablecer(array $p): void
    {
        $d = Http::body();
        $token = V::str($d, 'token', 'El enlace', 100);
        $pass = V::password($d);

        $u = Db::one(
            'SELECT id_usuario, nombre_usuario, email FROM usuarios WHERE token_recuperacion = :t AND token_expira > CURRENT_TIMESTAMP AND habilitado',
            ['t' => hash('sha256', $token)]
        );
        if ($u === null) {
            Http::error(422, 'El enlace no es válido o ya expiró. Solicite uno nuevo.');
        }
        Db::run(
            'UPDATE usuarios SET password_hash = :h, token_recuperacion = NULL, token_expira = NULL WHERE id_usuario = :id',
            ['h' => password_hash($pass, PASSWORD_DEFAULT), 'id' => $u['id_usuario']]
        );
        if (self::tablaIntentos()) {
            Db::run('DELETE FROM intentos_login WHERE clave IN (:a, :b)', ['a' => mb_strtolower((string)$u['nombre_usuario']), 'b' => mb_strtolower((string)$u['email'])]);
        }
        Http::json(200, ['mensaje' => 'Contraseña actualizada. Ya puede iniciar sesión.']);
    }
}
