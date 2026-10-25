<?php
declare(strict_types=1);

final class Router
{
    private array $rutas = [];

    public function add(string $metodo, string $patron, string $manejador): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $patron) . '$#';
        $this->rutas[] = [$metodo, $regex, $manejador];
    }

    public function dispatch(string $metodo, string $ruta): void
    {
        $ruta = '/' . trim($ruta, '/');
        $existe = false;
        foreach ($this->rutas as [$m, $regex, $manejador]) {
            if (preg_match($regex, $ruta, $coincide) !== 1) {
                continue;
            }
            $existe = true;
            if ($m !== $metodo) {
                continue;
            }
            $params = array_filter($coincide, 'is_string', ARRAY_FILTER_USE_KEY);
            $manejador($params);
            return;
        }
        if ($existe) {
            Http::error(405, 'Método no permitido');
        }
        Http::error(404, 'Ruta no encontrada');
    }
}
