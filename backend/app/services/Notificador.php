<?php
declare(strict_types=1);

/** CU-18: genera recordatorios y envía las notificaciones pendientes. */
final class Notificador
{
    /** Crea un recordatorio para cada cita de mañana que aún no tenga uno. */
    public static function generarRecordatorios(): int
    {
        return Db::run(
            "INSERT INTO notificaciones (id_cita, tipo, mensaje, fecha_envio, estado)
             SELECT c.id_cita, 'Recordatorio',
                    'Recordatorio: mañana tiene la cita ' || c.codigo_cita || ' a las ' || LEFT(c.hora_inicio::TEXT, 5),
                    NULL, 'Pendiente'
             FROM citas c
             WHERE c.fecha_cita = CURRENT_DATE + 1
               AND c.estado IN ('Programada', 'Confirmada')
               AND NOT EXISTS (SELECT 1 FROM notificaciones n
                               WHERE n.id_cita = c.id_cita AND n.tipo = 'Recordatorio' AND n.estado <> 'Fallida')"
        );
    }

    /** @return array{enviadas:int,fallidas:int} */
    public static function enviarPendientes(int $limite = 50): array
    {
        $limite = max(1, min($limite, 200));
        $filas = Db::all(
            "SELECT n.id_notificacion, n.mensaje, n.tipo, c.codigo_cita, p.email
             FROM notificaciones n
             JOIN citas c ON c.id_cita = n.id_cita
             JOIN pacientes p ON p.id_paciente = c.id_paciente
             WHERE n.estado IN ('Pendiente', 'Fallida')
               AND (n.fecha_envio IS NULL OR n.fecha_envio <= CURRENT_TIMESTAMP)
               AND c.fecha_cita >= CURRENT_DATE
               AND (n.tipo <> 'Recordatorio' OR c.estado IN ('Programada', 'Confirmada'))
             ORDER BY n.id_notificacion
             LIMIT $limite"
        );

        $enviadas = 0;
        $fallidas = 0;
        foreach ($filas as $f) {
            $ok = false;
            if (!empty($f['email'])) {
                $ok = Mailer::enviar((string)$f['email'], 'Clínica Vida Salud - ' . $f['tipo'] . ' de cita ' . $f['codigo_cita'], (string)$f['mensaje']);
            }
            if ($ok) {
                Db::run("UPDATE notificaciones SET estado = 'Enviada', fecha_envio = CURRENT_TIMESTAMP WHERE id_notificacion = :id", ['id' => $f['id_notificacion']]);
                $enviadas++;
            } else {
                Db::run("UPDATE notificaciones SET estado = 'Fallida' WHERE id_notificacion = :id", ['id' => $f['id_notificacion']]);
                $fallidas++;
            }
        }
        return ['enviadas' => $enviadas, 'fallidas' => $fallidas];
    }

    /** Envío inmediato sin interrumpir la operación principal si falla. */
    public static function intentar(): void
    {
        try {
            self::enviarPendientes(20);
        } catch (Throwable $e) {
            error_log('[NOTIFICADOR] ' . $e->getMessage());
        }
    }
}
