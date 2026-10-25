<?php
declare(strict_types=1);

/** CU-05: usuarios y roles (solo Administrador). */
final class UsuarioController
{
    public static function roles(array $p): void
    {
        Auth::need('usuarios.gestionar');
        Http::json(200, ['roles' => Db::all('SELECT id_rol, nombre, descripcion FROM roles WHERE habilitado ORDER BY id_rol')]);
    }

    public static function listar(array $p): void
    {
        Auth::need('usuarios.gestionar');
        $filas = Db::all(
            "SELECT u.id_usuario, u.nombre_usuario, u.email, u.habilitado, u.id_rol, r.nombre AS rol,
                    u.id_paciente, u.id_medico,
                    COALESCE(pa.nombres || ' ' || pa.apellidos, me.nombres || ' ' || me.apellidos) AS persona,
                    to_char(u.ultimo_acceso, 'YYYY-MM-DD HH24:MI') AS ultimo_acceso
             FROM usuarios u
             JOIN roles r ON r.id_rol = u.id_rol
             LEFT JOIN pacientes pa ON pa.id_paciente = u.id_paciente
             LEFT JOIN medicos me ON me.id_medico = u.id_medico
             ORDER BY r.id_rol, u.nombre_usuario"
        );
        Http::json(200, ['usuarios' => $filas]);
    }

    public static function crear(array $p): void
    {
        $actor = Auth::need('usuarios.gestionar');
        $d = Http::body();

        $nombre = V::str($d, 'nombre_usuario', 'El nombre de usuario', 50);
        if (preg_match('/^[A-Za-z0-9._@\-]{3,50}$/', $nombre) !== 1) {
            Http::error(422, 'El nombre de usuario debe tener entre 3 y 50 caracteres (letras, números, punto, guion o @)');
        }
        $email = V::email($d);
        $pass = V::password($d);
        $idRol = V::int($d, 'id_rol', 'El rol');
        $rol = Db::val('SELECT nombre FROM roles WHERE id_rol = :id AND habilitado', ['id' => $idRol]);
        if ($rol === null) {
            Http::error(422, 'El rol seleccionado no existe');
        }
        [$idPaciente, $idMedico] = self::vinculo($d, (string)$rol, null);

        $id = Db::tx(function () use ($nombre, $email, $pass, $idRol, $idPaciente, $idMedico, $actor) {
            $fila = Db::one(
                'INSERT INTO usuarios (id_rol, id_paciente, id_medico, nombre_usuario, email, password_hash)
                 VALUES (:r, :p, :m, :u, :e, :h) RETURNING id_usuario',
                ['r' => $idRol, 'p' => $idPaciente, 'm' => $idMedico, 'u' => $nombre, 'e' => $email, 'h' => password_hash($pass, PASSWORD_DEFAULT)]
            );
            Db::bitacora((int)$actor['id_usuario'], 'usuarios', (int)$fila['id_usuario'], 'INSERT', null,
                ['nombre_usuario' => $nombre, 'email' => $email, 'id_rol' => $idRol]);
            return (int)$fila['id_usuario'];
        });
        Http::json(201, ['id_usuario' => $id]);
    }

    public static function actualizar(array $p): void
    {
        $actor = Auth::need('usuarios.gestionar');
        $id = (int)$p['id'];
        $d = Http::body();

        $u = Db::one('SELECT u.*, r.nombre AS rol FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol WHERE u.id_usuario = :id', ['id' => $id]);
        if ($u === null) {
            Http::error(404, 'Usuario no encontrado');
        }

        $email = V::email($d);
        $idRol = V::int($d, 'id_rol', 'El rol');
        $rol = Db::val('SELECT nombre FROM roles WHERE id_rol = :id AND habilitado', ['id' => $idRol]);
        if ($rol === null) {
            Http::error(422, 'El rol seleccionado no existe');
        }
        $habilitado = V::bool($d, 'habilitado');
        $nuevaPass = (isset($d['password']) && $d['password'] !== '') ? V::password($d) : null;
        [$idPaciente, $idMedico] = self::vinculo($d, (string)$rol, $id);

        // Siempre debe quedar al menos un administrador activo
        if ($u['rol'] === 'Administrador' && $u['habilitado'] && ($rol !== 'Administrador' || !$habilitado)) {
            $otros = (int)Db::val(
                "SELECT COUNT(*) FROM usuarios x JOIN roles r ON r.id_rol = x.id_rol
                 WHERE r.nombre = 'Administrador' AND x.habilitado AND x.id_usuario <> :id",
                ['id' => $id]
            );
            if ($otros === 0) {
                Http::error(422, 'No se puede desactivar ni cambiar de rol a la última cuenta de administrador');
            }
        }

        Db::tx(function () use ($id, $email, $idRol, $habilitado, $nuevaPass, $idPaciente, $idMedico, $actor, $u) {
            $sql = 'UPDATE usuarios SET email = :e, id_rol = :r, habilitado = :h, id_paciente = :p, id_medico = :m';
            $par = ['e' => $email, 'r' => $idRol, 'h' => Db::b($habilitado), 'p' => $idPaciente, 'm' => $idMedico, 'id' => $id];
            if ($nuevaPass !== null) {
                $sql .= ', password_hash = :hash';
                $par['hash'] = password_hash($nuevaPass, PASSWORD_DEFAULT);
            }
            Db::run($sql . ' WHERE id_usuario = :id', $par);
            Db::bitacora((int)$actor['id_usuario'], 'usuarios', $id, 'UPDATE',
                ['email' => $u['email'], 'id_rol' => $u['id_rol'], 'habilitado' => $u['habilitado']],
                ['email' => $email, 'id_rol' => $idRol, 'habilitado' => $habilitado, 'password_cambiada' => $nuevaPass !== null]);
        });
        Http::json(200, ['id_usuario' => $id]);
    }

    /** Un Médico debe enlazarse a un médico y un Paciente a un paciente. */
    private static function vinculo(array $d, string $rol, ?int $idUsuario): array
    {
        $idPaciente = null;
        $idMedico = null;
        if ($rol === 'Médico') {
            $idMedico = V::int($d, 'id_medico', 'El médico asociado');
            if (Db::val('SELECT 1 FROM medicos WHERE id_medico = :id', ['id' => $idMedico]) === null) {
                Http::error(422, 'El médico asociado no existe');
            }
            if (Db::val('SELECT 1 FROM usuarios WHERE id_medico = :m AND id_usuario <> :u', ['m' => $idMedico, 'u' => $idUsuario ?? 0]) !== null) {
                Http::error(409, 'Ese médico ya tiene una cuenta de usuario');
            }
        } elseif ($rol === 'Paciente') {
            $idPaciente = V::int($d, 'id_paciente', 'El paciente asociado');
            if (Db::val('SELECT 1 FROM pacientes WHERE id_paciente = :id', ['id' => $idPaciente]) === null) {
                Http::error(422, 'El paciente asociado no existe');
            }
            if (Db::val('SELECT 1 FROM usuarios WHERE id_paciente = :p AND id_usuario <> :u', ['p' => $idPaciente, 'u' => $idUsuario ?? 0]) !== null) {
                Http::error(409, 'Ese paciente ya tiene una cuenta de usuario');
            }
        }
        return [$idPaciente, $idMedico];
    }
}
