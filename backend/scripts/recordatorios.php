<?php
declare(strict_types=1);

/** Tarea programada (cron / Programador de tareas): php scripts/recordatorios.php */
require __DIR__ . '/../app/core/Env.php';
require __DIR__ . '/../app/bootstrap.php';

$nuevos = Notificador::generarRecordatorios();
$envio = Notificador::enviarPendientes(100);
echo date('c') . " recordatorios creados: $nuevos, enviados: {$envio['enviadas']}, fallidos: {$envio['fallidas']}\n";
