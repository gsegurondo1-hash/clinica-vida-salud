<?php
declare(strict_types=1);

/** Validaciones de entrada del lado del servidor. Responden 422 con un mensaje claro. */
final class V
{
    public static function str(array $d, string $k, string $etiqueta, int $max, bool $obligatorio = true): ?string
    {
        $v = (isset($d[$k]) && is_scalar($d[$k])) ? trim((string)$d[$k]) : '';
        if ($v === '') {
            if ($obligatorio) {
                Http::error(422, "$etiqueta es obligatorio");
            }
            return null;
        }
        if (mb_strlen($v) > $max) {
            Http::error(422, "$etiqueta no puede superar los $max caracteres");
        }
        return $v;
    }

    public static function email(array $d, string $k = 'email', bool $obligatorio = true): ?string
    {
        $v = self::str($d, $k, 'El correo electrónico', 100, $obligatorio);
        if ($v !== null && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
            Http::error(422, 'El correo electrónico no tiene un formato válido');
        }
        return $v === null ? null : strtolower($v);
    }

    public static function telefono(array $d, string $k = 'telefono', bool $obligatorio = true): ?string
    {
        $v = self::str($d, $k, 'El teléfono', 20, $obligatorio);
        if ($v !== null && preg_match('/^[0-9+\-\s()]{6,20}$/', $v) !== 1) {
            Http::error(422, 'El teléfono solo puede contener números, espacios, + y -');
        }
        return $v;
    }

    public static function fecha(array $d, string $k, string $etiqueta, bool $obligatorio = true): ?string
    {
        $v = self::str($d, $k, $etiqueta, 10, $obligatorio);
        if ($v === null) {
            return null;
        }
        $f = DateTime::createFromFormat('Y-m-d', $v);
        if ($f === false || $f->format('Y-m-d') !== $v) {
            Http::error(422, "$etiqueta no es una fecha válida (use AAAA-MM-DD)");
        }
        return $v;
    }

    public static function hora(array $d, string $k, string $etiqueta, bool $obligatorio = true): ?string
    {
        $v = self::str($d, $k, $etiqueta, 8, $obligatorio);
        if ($v === null) {
            return null;
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v) !== 1) {
            Http::error(422, "$etiqueta no es una hora válida (use HH:MM)");
        }
        return substr($v, 0, 5);
    }

    public static function int(array $d, string $k, string $etiqueta, int $min = 1, int $max = 2147483647, bool $obligatorio = true): ?int
    {
        $v = $d[$k] ?? null;
        if ($v === null || $v === '') {
            if ($obligatorio) {
                Http::error(422, "$etiqueta es obligatorio");
            }
            return null;
        }
        if (!is_numeric($v) || floor((float)$v) != (float)$v || (int)$v < $min || (int)$v > $max) {
            Http::error(422, "$etiqueta no es válido");
        }
        return (int)$v;
    }

    public static function enum(array $d, string $k, string $etiqueta, array $permitidos, bool $obligatorio = true): ?string
    {
        $v = self::str($d, $k, $etiqueta, 40, $obligatorio);
        if ($v !== null && !in_array($v, $permitidos, true)) {
            Http::error(422, "$etiqueta no es válido");
        }
        return $v;
    }

    public static function password(array $d, string $k = 'password'): string
    {
        $v = (isset($d[$k]) && is_string($d[$k])) ? $d[$k] : '';
        if ($v === '') {
            Http::error(422, 'La contraseña es obligatoria');
        }
        if (strlen($v) < 8 || preg_match('/[A-Za-z]/', $v) !== 1 || preg_match('/\d/', $v) !== 1) {
            Http::error(422, 'La contraseña debe tener al menos 8 caracteres, con letras y números');
        }
        if (strlen($v) > 72) {
            Http::error(422, 'La contraseña no puede superar los 72 caracteres');
        }
        return $v;
    }

    public static function bool(array $d, string $k, bool $defecto = true): bool
    {
        if (!array_key_exists($k, $d)) {
            return $defecto;
        }
        return filter_var($d[$k], FILTER_VALIDATE_BOOLEAN);
    }
}
