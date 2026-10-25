<?php
declare(strict_types=1);

/** Lee variables de entorno (del sistema o de un archivo .env local). */
final class Env
{
    public static function load(string $ruta): void
    {
        if (!is_file($ruta)) {
            return;
        }
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
                continue;
            }
            [$clave, $valor] = explode('=', $linea, 2);
            $clave = trim($clave);
            $valor = trim($valor, " \t\"'");
            if (getenv($clave) === false) {
                putenv("$clave=$valor");
            }
        }
    }

    public static function get(string $clave, ?string $defecto = null): ?string
    {
        $valor = getenv($clave);
        return ($valor === false || $valor === '') ? $defecto : $valor;
    }

    public static function bool(string $clave, bool $defecto = false): bool
    {
        $v = self::get($clave);
        return $v === null ? $defecto : in_array(strtolower($v), ['1', 'true', 'si', 'yes', 'on'], true);
    }
}
