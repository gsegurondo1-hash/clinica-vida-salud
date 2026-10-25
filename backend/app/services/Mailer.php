<?php
declare(strict_types=1);

/**
 * Envío de correos por API HTTPS (Render gratis bloquea los puertos SMTP).
 * Proveedores, en orden de prioridad:
 *   1. Brevo   -> BREVO_API_KEY + MAIL_FROM_EMAIL (correo remitente verificado en Brevo)
 *   2. Resend  -> RESEND_API_KEY (+ MAIL_FROM)
 * Sin ninguno de los dos el envío se simula (solo se escribe en el log).
 */
final class Mailer
{
    public static function configurado(): bool
    {
        return Env::get('BREVO_API_KEY') !== null || Env::get('RESEND_API_KEY') !== null;
    }

    public static function enviar(string $para, string $asunto, string $texto): bool
    {
        if (Env::get('BREVO_API_KEY') !== null) {
            return self::brevo($para, $asunto, $texto);
        }
        if (Env::get('RESEND_API_KEY') !== null) {
            return self::resend($para, $asunto, $texto);
        }
        error_log("[MAIL simulado] para=$para asunto=$asunto");
        return true;
    }

    private static function brevo(string $para, string $asunto, string $texto): bool
    {
        $remitente = Env::get('MAIL_FROM_EMAIL');
        if ($remitente === null) {
            error_log('[MAIL] Falta MAIL_FROM_EMAIL (correo remitente verificado en Brevo)');
            return false;
        }
        return self::post(
            'https://api.brevo.com/v3/smtp/email',
            ['api-key: ' . Env::get('BREVO_API_KEY'), 'Content-Type: application/json', 'accept: application/json'],
            [
                'sender' => ['name' => Env::get('MAIL_FROM_NAME', 'Clínica Vida Salud'), 'email' => $remitente],
                'to' => [['email' => $para]],
                'subject' => $asunto,
                'textContent' => $texto,
            ]
        );
    }

    private static function resend(string $para, string $asunto, string $texto): bool
    {
        return self::post(
            'https://api.resend.com/emails',
            ['Authorization: Bearer ' . Env::get('RESEND_API_KEY'), 'Content-Type: application/json'],
            [
                'from' => Env::get('MAIL_FROM', 'Clínica Vida Salud <onboarding@resend.dev>'),
                'to' => [$para],
                'subject' => $asunto,
                'text' => $texto,
            ]
        );
    }

    private static function post(string $url, array $cabeceras, array $cuerpo): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_POSTFIELDS => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
        ]);
        $respuesta = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($codigo < 200 || $codigo >= 300) {
            error_log("[MAIL] fallo HTTP $codigo: " . (is_string($respuesta) ? $respuesta : 'sin respuesta'));
            return false;
        }
        return true;
    }
}
