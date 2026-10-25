<?php
declare(strict_types=1);

/**
 * Envío de correos.
 * - Sin RESEND_API_KEY: modo simulado (solo se escribe en el log).
 * - Con RESEND_API_KEY: se envía por la API HTTPS de Resend (Render gratis bloquea SMTP).
 */
final class Mailer
{
    public static function enviar(string $para, string $asunto, string $texto): bool
    {
        $clave = Env::get('RESEND_API_KEY');
        if ($clave === null) {
            error_log("[MAIL simulado] para=$para asunto=$asunto");
            return true;
        }

        $de = Env::get('MAIL_FROM', 'Clínica Vida Salud <onboarding@resend.dev>');
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $clave, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(
                ['from' => $de, 'to' => [$para], 'subject' => $asunto, 'text' => $texto],
                JSON_UNESCAPED_UNICODE
            ),
        ]);
        $respuesta = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($codigo < 200 || $codigo >= 300) {
            error_log("[MAIL] fallo HTTP $codigo: " . (is_string($respuesta) ? $respuesta : ''));
            return false;
        }
        return true;
    }
}
