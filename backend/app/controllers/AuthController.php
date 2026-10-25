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

        $u = Db::one(
            'SELECT id_usuario, password_hash, habilitado FROM usuarios
             WHERE LOWER(nombre_usuario) = LOWER(:a) OR LOWER(email) = LOWER(:b)',
            ['a' => $login, 'b' => $login]
        );
        if ($u === null || !$u['habilitado'] || !password_verify($pass, $u['password_hash'])) {
            Http::error(401, 'Credenciales incorrectas o cuenta deshabilitada');
        }

        Db::run('UPDATE usuarios SET ultimo_acceso = CURRENT_TIMESTAMP WHERE id_usuario = :id', ['id' => $u['id_usuario']]);
        Http::json(200, [
            'token' => Auth::emitir((int)$u['id_usuario']),
            'usuario' => Auth::cargar((int)$u['id_usuario']),
        ]);
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
            'SELECT id_usuario FROM usuarios WHERE token_recuperacion = :t AND token_expira > CURRENT_TIMESTAMP AND habilitado',
            ['t' => hash('sha256', $token)]
        );
        if ($u === null) {
            Http::error(422, 'El enlace no es válido o ya expiró. Solicite uno nuevo.');
        }
        Db::run(
            'UPDATE usuarios SET password_hash = :h, token_recuperacion = NULL, token_expira = NULL WHERE id_usuario = :id',
            ['h' => password_hash($pass, PASSWORD_DEFAULT), 'id' => $u['id_usuario']]
        );
        Http::json(200, ['mensaje' => 'Contraseña actualizada. Ya puede iniciar sesión.']);
    }
}
