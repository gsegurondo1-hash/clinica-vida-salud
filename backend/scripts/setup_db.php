<?php
declare(strict_types=1);

/**
 * Crea las tablas, carga los datos de ejemplo, procedimientos y triggers.
 *   php scripts/setup_db.php           -> inicializa si la base esta vacia
 *   php scripts/setup_db.php --auto    -> igual, pero no falla si no hay conexion (usado por Docker)
 *   php scripts/setup_db.php --reset   -> BORRA todo y vuelve a crear (solo manual)
 */
require __DIR__ . '/../app/core/Env.php';
require __DIR__ . '/../app/bootstrap.php';

$auto = in_array('--auto', $argv, true);
$reset = in_array('--reset', $argv, true) && !$auto;

$pdo = null;
for ($i = 0; $i < 15 && $pdo === null; $i++) {
    try {
        $pdo = Db::pdo();
    } catch (Throwable $e) {
        fwrite(STDERR, "Esperando la base de datos... (" . $e->getMessage() . ")\n");
        sleep(2);
    }
}
if ($pdo === null) {
    fwrite(STDERR, "No se pudo conectar a la base de datos.\n");
    exit($auto ? 0 : 1);
}

$existe = (bool)$pdo->query("SELECT to_regclass('public.roles') IS NOT NULL")->fetchColumn();
if ($existe && !$reset) {
    echo "La base de datos ya esta inicializada.\n";
    exit(0);
}

$leer = static function (string $nombre): string {
    $ruta = __DIR__ . "/../sql/$nombre.sql";
    $sql = file_get_contents($ruta);
    if ($sql === false) {
        throw new RuntimeException("No se pudo leer $ruta");
    }
    return $sql;
};

$clave = Env::get('DEMO_PASSWORD', 'Clinica2026*');

try {
    $pdo->beginTransaction();
    if ($reset) {
        $pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }
    $pdo->exec($leer('esquema'));
    $pdo->exec($leer('datos'));
    $st = $pdo->prepare('UPDATE usuarios SET password_hash = :h');
    $st->execute(['h' => password_hash($clave, PASSWORD_DEFAULT)]);
    $pdo->exec($leer('procedimientos'));
    $pdo->exec($leer('triggers'));
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error al inicializar la base de datos: ' . $e->getMessage() . "\n");
    exit($auto ? 0 : 1);
}

echo "Base de datos inicializada.\n";
echo "Usuarios de prueba (contrasena: $clave): admin, recepcion1, jperez, lfernandez, rjustiniano, mlopez, pgutierrez, asuarez\n";
