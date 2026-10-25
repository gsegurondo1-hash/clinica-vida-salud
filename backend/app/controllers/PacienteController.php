<?php
declare(strict_types=1);

/** CU-03, CU-04 y CU-09: pacientes, perfil del paciente y gestión desde recepción. */
final class PacienteController
{
    private const SEXOS = ['Femenino', 'Masculino', 'Otro'];

    // ---------- Perfil del paciente (CU-04) ----------

    public static function perfil(array $p): void
    {
        $u = Auth::need('perfil.editar');
        if (empty($u['id_paciente'])) {
            Http::error(404, 'Su cuenta no tiene un perfil de paciente asociado');
        }
        Http::json(200, self::detalle((int)$u['id_paciente']));
    }

    public static function actualizarPerfil(array $p): void
    {
        $u = Auth::need('perfil.editar');
        if (empty($u['id_paciente'])) {
            Http::error(404, 'Su cuenta no tiene un perfil de paciente asociado');
        }
        self::guardar((int)$u['id_paciente'], Http::body(), false, (int)$u['id_usuario']);
        Http::json(200, self::detalle((int)$u['id_paciente']));
    }

    // ---------- Gestión desde recepción (CU-09) ----------

    public static function listar(array $p): void
    {
        Auth::need('pacientes.gestionar');
        $where = [];
        $par = [];

        $q = Http::query('q');
        if ($q !== null) {
            $where[] = "(p.ci ILIKE :q1 OR p.nombres ILIKE :q2 OR p.apellidos ILIKE :q3 OR p.codigo ILIKE :q4 OR (p.nombres || ' ' || p.apellidos) ILIKE :q5)";
            foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) {
                $par[$k] = '%' . $q . '%';
            }
        }
        $estado = Http::query('estado', 'activos');
        if ($estado === 'activos') {
            $where[] = 'p.habilitado';
        } elseif ($estado === 'inactivos') {
            $where[] = 'NOT p.habilitado';
        }

        $filas = Db::all(
            'SELECT p.id_paciente, p.codigo, p.ci, p.nombres, p.apellidos, p.telefono, p.email, p.habilitado,
                    to_char(p.fecha_nacimiento, \'YYYY-MM-DD\') AS fecha_nacimiento,
                    EXISTS (SELECT 1 FROM usuarios u WHERE u.id_paciente = p.id_paciente) AS tiene_cuenta
             FROM pacientes p' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY p.apellidos, p.nombres LIMIT 100',
            $par
        );
        Http::json(200, ['pacientes' => $filas]);
    }

    public static function ver(array $p): void
    {
        Auth::need('pacientes.gestionar');
        $d = self::detalle((int)$p['id']);
        if ($d === null) {
            Http::error(404, 'Paciente no encontrado');
        }
        Http::json(200, $d);
    }

    public static function crear(array $p): void
    {
        $u = Auth::need('pacientes.gestionar');
        $d = Http::body();
        $conCuenta = !empty($d['password']);
        $r = self::crearPaciente($d, $conCuenta, false, (int)$u['id_usuario']);
        Http::json(201, self::detalle((int)$r['id_paciente']));
    }

    public static function actualizar(array $p): void
    {
        $u = Auth::need('pacientes.gestionar');
        $id = (int)$p['id'];
        if (self::detalle($id) === null) {
            Http::error(404, 'Paciente no encontrado');
        }
        self::guardar($id, Http::body(), true, (int)$u['id_usuario']);
        Http::json(200, self::detalle($id));
    }

    public static function estado(array $p): void
    {
        $u = Auth::need('pacientes.gestionar');
        $id = (int)$p['id'];
        $habilitado = V::bool(Http::body(), 'habilitado');
        Db::tx(function () use ($u, $id, $habilitado) {
            Db::setUser((int)$u['id_usuario']);
            if (Db::run('UPDATE pacientes SET habilitado = :h WHERE id_paciente = :id', ['h' => Db::b($habilitado), 'id' => $id]) === 0) {
                Http::error(404, 'Paciente no encontrado');
            }
        });
        Http::json(200, ['id_paciente' => $id, 'habilitado' => $habilitado]);
    }

    // ---------- Lógica compartida ----------

    public static function detalle(int $id): ?array
    {
        $p = Db::one(
            'SELECT id_paciente, codigo, ci, nombres, apellidos, telefono, email, direccion, sexo, habilitado,
                    to_char(fecha_nacimiento, \'YYYY-MM-DD\') AS fecha_nacimiento,
                    to_char(fecha_registro, \'YYYY-MM-DD HH24:MI\') AS fecha_registro
             FROM pacientes WHERE id_paciente = :id',
            ['id' => $id]
        );
        if ($p === null) {
            return null;
        }
        $p['info_medica'] = Db::one(
            'SELECT tipo_sangre, alergias, enfermedades_cronicas, medicamentos, antecedentes
             FROM informacion_medica WHERE id_paciente = :id',
            ['id' => $id]
        ) ?? ['tipo_sangre' => null, 'alergias' => null, 'enfermedades_cronicas' => null, 'medicamentos' => null, 'antecedentes' => null];
        $p['contacto'] = Db::one(
            'SELECT nombre, parentesco, telefono FROM contactos_emergencia WHERE id_paciente = :id ORDER BY id_contacto LIMIT 1',
            ['id' => $id]
        ) ?? ['nombre' => null, 'parentesco' => null, 'telefono' => null];
        return $p;
    }

    /** Valida y registra un paciente (y opcionalmente su cuenta de acceso). */
    public static function crearPaciente(array $d, bool $conCuenta, bool $ciObligatorio, ?int $actor): array
    {
        $ci = V::str($d, 'ci', 'El CI', 20, $ciObligatorio);
        $nombres = V::str($d, 'nombres', 'Los nombres', 80);
        $apellidos = V::str($d, 'apellidos', 'Los apellidos', 80);
        $nacimiento = V::fecha($d, 'fecha_nacimiento', 'La fecha de nacimiento');
        if ($nacimiento > date('Y-m-d')) {
            Http::error(422, 'La fecha de nacimiento no puede ser futura');
        }
        $sexo = V::enum($d, 'sexo', 'El sexo', self::SEXOS);
        $telefono = V::telefono($d);
        $email = V::email($d, 'email', $conCuenta);
        $direccion = V::str($d, 'direccion', 'La dirección', 200, false);
        $pass = $conCuenta ? V::password($d) : null;

        if ($ci !== null && Db::val('SELECT 1 FROM pacientes WHERE ci = :ci', ['ci' => $ci]) !== null) {
            Http::error(409, 'Ya existe un paciente registrado con ese CI');
        }
        if ($conCuenta) {
            $usuario = substr((string)$email, 0, 50);
            if (Db::val('SELECT 1 FROM usuarios WHERE LOWER(email) = :e OR LOWER(nombre_usuario) = :u', ['e' => $email, 'u' => strtolower($usuario)]) !== null) {
                Http::error(409, 'Ya existe una cuenta con ese correo electrónico');
            }
        }

        return Db::tx(function () use ($actor, $ci, $nombres, $apellidos, $nacimiento, $sexo, $telefono, $email, $direccion, $conCuenta, $pass) {
            Db::setUser($actor);
            $fila = Db::one(
                "INSERT INTO pacientes (codigo, ci, nombres, apellidos, fecha_nacimiento, sexo, telefono, email, direccion)
                 VALUES ('PAC-' || LPAD(nextval('seq_codigo_paciente')::TEXT, 4, '0'), :ci, :n, :a, :f, :s, :t, :e, :d)
                 RETURNING id_paciente, codigo",
                ['ci' => $ci, 'n' => $nombres, 'a' => $apellidos, 'f' => $nacimiento, 's' => $sexo, 't' => $telefono, 'e' => $email, 'd' => $direccion]
            );
            if ($conCuenta) {
                Db::run(
                    "INSERT INTO usuarios (id_rol, id_paciente, nombre_usuario, email, password_hash)
                     VALUES ((SELECT id_rol FROM roles WHERE nombre = 'Paciente'), :p, :u, :e, :h)",
                    ['p' => $fila['id_paciente'], 'u' => substr((string)$email, 0, 50), 'e' => $email, 'h' => password_hash((string)$pass, PASSWORD_DEFAULT)]
                );
            }
            return $fila;
        });
    }

    /** Actualiza datos personales, información médica y contacto de emergencia. */
    private static function guardar(int $id, array $d, bool $esStaff, int $actor): void
    {
        $nombres = V::str($d, 'nombres', 'Los nombres', 80);
        $apellidos = V::str($d, 'apellidos', 'Los apellidos', 80);
        $nacimiento = V::fecha($d, 'fecha_nacimiento', 'La fecha de nacimiento');
        if ($nacimiento > date('Y-m-d')) {
            Http::error(422, 'La fecha de nacimiento no puede ser futura');
        }
        $sexo = V::enum($d, 'sexo', 'El sexo', self::SEXOS);
        $telefono = V::telefono($d);
        $email = V::email($d, 'email', false);
        $direccion = V::str($d, 'direccion', 'La dirección', 200, false);

        $ci = null;
        $cambiaCi = $esStaff && array_key_exists('ci', $d);
        if ($cambiaCi) {
            $ci = V::str($d, 'ci', 'El CI', 20, false);
            if ($ci !== null && Db::val('SELECT 1 FROM pacientes WHERE ci = :ci AND id_paciente <> :id', ['ci' => $ci, 'id' => $id]) !== null) {
                Http::error(409, 'Ya existe un paciente registrado con ese CI');
            }
        }

        $im = is_array($d['info_medica'] ?? null) ? $d['info_medica'] : [];
        $sangre = V::enum($im, 'tipo_sangre', 'El tipo de sangre', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], false);
        $alergias = V::str($im, 'alergias', 'Las alergias', 1000, false);
        $cronicas = V::str($im, 'enfermedades_cronicas', 'Las enfermedades crónicas', 1000, false);
        $medicamentos = V::str($im, 'medicamentos', 'Los medicamentos', 1000, false);
        $antecedentes = V::str($im, 'antecedentes', 'Los antecedentes', 1000, false);

        $c = is_array($d['contacto'] ?? null) ? $d['contacto'] : [];
        $cNombre = V::str($c, 'nombre', 'El nombre del contacto de emergencia', 100, false);
        $cParentesco = null;
        $cTelefono = null;
        if ($cNombre !== null) {
            $cParentesco = V::str($c, 'parentesco', 'El parentesco del contacto', 40);
            $cTelefono = V::telefono($c, 'telefono');
        }

        Db::tx(function () use ($id, $actor, $cambiaCi, $ci, $nombres, $apellidos, $nacimiento, $sexo, $telefono, $email, $direccion, $sangre, $alergias, $cronicas, $medicamentos, $antecedentes, $cNombre, $cParentesco, $cTelefono) {
            Db::setUser($actor);
            $par = ['n' => $nombres, 'a' => $apellidos, 'f' => $nacimiento, 's' => $sexo, 't' => $telefono, 'e' => $email, 'd' => $direccion, 'id' => $id];
            $sql = 'UPDATE pacientes SET nombres = :n, apellidos = :a, fecha_nacimiento = :f, sexo = :s, telefono = :t, email = :e, direccion = :d';
            if ($cambiaCi) {
                $sql .= ', ci = :ci';
                $par['ci'] = $ci;
            }
            Db::run($sql . ' WHERE id_paciente = :id', $par);

            if ($email !== null) {
                Db::run('UPDATE usuarios SET email = :e WHERE id_paciente = :id', ['e' => $email, 'id' => $id]);
            }

            Db::run(
                'INSERT INTO informacion_medica (id_paciente, tipo_sangre, alergias, enfermedades_cronicas, medicamentos, antecedentes)
                 VALUES (:id, :s, :al, :en, :me, :an)
                 ON CONFLICT (id_paciente) DO UPDATE SET tipo_sangre = EXCLUDED.tipo_sangre, alergias = EXCLUDED.alergias,
                     enfermedades_cronicas = EXCLUDED.enfermedades_cronicas, medicamentos = EXCLUDED.medicamentos,
                     antecedentes = EXCLUDED.antecedentes',
                ['id' => $id, 's' => $sangre, 'al' => $alergias, 'en' => $cronicas, 'me' => $medicamentos, 'an' => $antecedentes]
            );

            if ($cNombre !== null) {
                $existe = Db::val('SELECT id_contacto FROM contactos_emergencia WHERE id_paciente = :id ORDER BY id_contacto LIMIT 1', ['id' => $id]);
                if ($existe !== null) {
                    Db::run('UPDATE contactos_emergencia SET nombre = :n, parentesco = :p, telefono = :t WHERE id_contacto = :c',
                        ['n' => $cNombre, 'p' => $cParentesco, 't' => $cTelefono, 'c' => $existe]);
                } else {
                    Db::run('INSERT INTO contactos_emergencia (id_paciente, nombre, parentesco, telefono) VALUES (:id, :n, :p, :t)',
                        ['id' => $id, 'n' => $cNombre, 'p' => $cParentesco, 't' => $cTelefono]);
                }
            }
        });
    }
}
