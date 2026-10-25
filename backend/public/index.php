<?php
declare(strict_types=1);

require __DIR__ . '/../app/core/Env.php';
require __DIR__ . '/../app/bootstrap.php';

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');

// ---------- CORS ----------
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
$permitidos = array_filter(array_map(
    static fn(string $o) => rtrim(trim($o), '/'),
    explode(',', Env::get('CORS_ORIGIN', '*'))
));
if (in_array('*', $permitidos, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origen !== '' && in_array(rtrim($origen, '/'), $permitidos, true)) {
    header('Access-Control-Allow-Origin: ' . $origen);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    Http::fail($e);
});

// ---------- Rutas ----------
$r = new Router();

$r->add('GET', '/health', 'ReporteController::salud');

$r->add('POST', '/auth/login', 'AuthController::login');
$r->add('POST', '/auth/registro', 'AuthController::registro');
$r->add('POST', '/auth/recuperar', 'AuthController::recuperar');
$r->add('POST', '/auth/restablecer', 'AuthController::restablecer');
$r->add('GET', '/auth/yo', 'AuthController::yo');

$r->add('GET', '/inicio', 'CitaController::inicio');
$r->add('GET', '/perfil', 'PacienteController::perfil');
$r->add('PUT', '/perfil', 'PacienteController::actualizarPerfil');

$r->add('GET', '/roles', 'UsuarioController::roles');
$r->add('GET', '/usuarios', 'UsuarioController::listar');
$r->add('POST', '/usuarios', 'UsuarioController::crear');
$r->add('PUT', '/usuarios/{id}', 'UsuarioController::actualizar');

$r->add('GET', '/especialidades', 'CatalogoController::especialidades');
$r->add('POST', '/especialidades', 'CatalogoController::crearEspecialidad');
$r->add('PUT', '/especialidades/{id}', 'CatalogoController::actualizarEspecialidad');

$r->add('GET', '/medicos', 'CatalogoController::medicos');
$r->add('POST', '/medicos', 'CatalogoController::crearMedico');
$r->add('PUT', '/medicos/{id}', 'CatalogoController::actualizarMedico');
$r->add('GET', '/medicos/{id}/horarios', 'CatalogoController::horarios');

$r->add('POST', '/horarios', 'CatalogoController::crearHorario');
$r->add('PUT', '/horarios/{id}', 'CatalogoController::actualizarHorario');
$r->add('DELETE', '/horarios/{id}', 'CatalogoController::eliminarHorario');

$r->add('GET', '/bloqueos', 'CatalogoController::bloqueos');
$r->add('POST', '/bloqueos', 'CatalogoController::crearBloqueo');
$r->add('DELETE', '/bloqueos/{id}', 'CatalogoController::eliminarBloqueo');

$r->add('GET', '/pacientes', 'PacienteController::listar');
$r->add('POST', '/pacientes', 'PacienteController::crear');
$r->add('GET', '/pacientes/{id}', 'PacienteController::ver');
$r->add('PUT', '/pacientes/{id}', 'PacienteController::actualizar');
$r->add('PATCH', '/pacientes/{id}/estado', 'PacienteController::estado');

$r->add('GET', '/disponibilidad', 'CitaController::disponibilidad');
$r->add('GET', '/citas', 'CitaController::listar');
$r->add('POST', '/citas', 'CitaController::crear');
$r->add('GET', '/citas/{id}', 'CitaController::ver');
$r->add('PUT', '/citas/{id}/reprogramar', 'CitaController::reprogramar');
$r->add('POST', '/citas/{id}/cancelar', 'CitaController::cancelar');
$r->add('POST', '/citas/{id}/estado', 'CitaController::cambiarEstado');
$r->add('GET', '/agenda', 'CitaController::agenda');
$r->add('GET', '/historial', 'CitaController::historial');
$r->add('GET', '/notificaciones', 'ReporteController::notificaciones');

$r->add('GET', '/reportes', 'ReporteController::tipos');
$r->add('GET', '/reportes/{tipo}', 'ReporteController::generar');
$r->add('GET', '/bitacora', 'ReporteController::bitacora');

$r->add('GET', '/cron/recordatorios', 'ReporteController::cronRecordatorios');
$r->add('POST', '/cron/recordatorios', 'ReporteController::cronRecordatorios');

// ---------- Despacho ----------
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($ruta === '/' || $ruta === '/api' || $ruta === '/api/') {
    Http::json(200, ['servicio' => 'API Clínica Vida Salud', 'estado' => 'en línea', 'documentacion' => 'Consulte el README del proyecto']);
}
if (strpos($ruta, '/api/') !== 0) {
    Http::error(404, 'Ruta no encontrada. La API está bajo /api');
}
$r->dispatch($metodo, substr($ruta, 4));
