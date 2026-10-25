<?php
declare(strict_types=1);

/** Acceso a PostgreSQL con PDO. Todas las consultas son parametrizadas. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $url = Env::get('DATABASE_URL');
        if ($url !== null) {
            $p = parse_url($url);
            if ($p === false || empty($p['host'])) {
                throw new RuntimeException('DATABASE_URL no es válida');
            }
            $host = $p['host'];
            $port = (int)($p['port'] ?? 5432);
            $name = ltrim($p['path'] ?? '', '/');
            $user = urldecode($p['user'] ?? '');
            $pass = urldecode($p['pass'] ?? '');
        } else {
            $host = Env::get('DB_HOST', 'localhost');
            $port = (int)Env::get('DB_PORT', '5432');
            $name = Env::get('DB_NAME', 'clinica_vida_salud');
            $user = Env::get('DB_USER', 'postgres');
            $pass = Env::get('DB_PASSWORD', '');
        }

        $ssl = Env::get('DB_SSLMODE', 'prefer');
        $dsn = "pgsql:host=$host;port=$port;dbname=$name;sslmode=$ssl";

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // CURRENT_DATE y LOCALTIME dependen de la zona horaria de la sesion
        $tz = Env::get('APP_TIMEZONE', 'America/La_Paz');
        if (preg_match('#^[A-Za-z0-9_/+\-]+$#', $tz) === 1) {
            $pdo->exec("SET TIME ZONE '$tz'");
        }

        return self::$pdo = $pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $fila = $st->fetch();
        return $fila === false ? null : $fila;
    }

    public static function val(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** Ejecuta $fn dentro de una transaccion. */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Indica a los triggers (historial y bitacora) que usuario ejecuta la operacion. */
    public static function setUser(?int $idUsuario): void
    {
        if ($idUsuario !== null) {
            self::val("SELECT set_config('app.id_usuario', :u, true)", ['u' => (string)$idUsuario]);
        }
    }

    /** PDO no convierte bien los booleanos de PHP: se envian como texto. */
    public static function b(bool $v): string
    {
        return $v ? 'true' : 'false';
    }

    public static function bitacora(?int $idUsuario, string $tabla, int $registro, string $accion, ?array $antes, ?array $despues): void
    {
        self::run(
            'INSERT INTO bitacora (id_usuario, tabla_afectada, registro_afectado, accion, datos_anteriores, datos_nuevos)
             VALUES (:u, :t, :r, :a, :ant, :nue)',
            [
                'u' => $idUsuario,
                't' => $tabla,
                'r' => $registro,
                'a' => $accion,
                'ant' => $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
                'nue' => $despues === null ? null : json_encode($despues, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    public static function jsonCols(array $filas, array $columnas): array
    {
        foreach ($filas as &$f) {
            foreach ($columnas as $c) {
                $f[$c] = isset($f[$c]) ? (json_decode((string)$f[$c], true) ?? []) : [];
            }
        }
        return $filas;
    }
}
