<?php

namespace ColibriSync\Services;

use WP_Query;

/**
 * MailService: envía correos ante errores de sincronización
 * y ante productos en borrador o sin imagen.
 */
class MailService
{
    // Correo principal (to) para soporte técnico:
    private $soporteEmail = 'jmenacho@casaelena.com.bo';

    // Copias (CC):
    private $ccEmails = [
        'ventasonline@casaelena.com',
        'ventasonline2@casaelena.com',
        'casaelenaonline@gmail.com'
    ];

    /**
     * Envía un correo cuando ocurre un error en la sincronización de productos.
     *
     * @param string $errorMessage  El mensaje de error que ocurrió
     * @param string $stackTrace    Opcional: el stack trace o detalles
     */
    public function sendSyncErrorEmail($errorMessage, $stackTrace = '')
    {
        $subject = 'Error en la sincronización de productos';
        $body = "Estimado(a) Soporte:\n\n"
              . "Se ha producido un error durante la sincronización de productos.\n\n"
              . "Detalles del error:\n"
              . "$errorMessage\n\n"
              . "Stack Trace:\n"
              . "$stackTrace\n\n"
              . "Saludos,\n"
              . "Plugin ColibriSync (WordPress)\n";

        $this->sendMail($this->soporteEmail, $subject, $body);
    }

    /**
     * Envía un correo notificando qué productos están en borrador o sin imagen.
     *
     * @param array $draftNoImageProducts Lista de IDs de productos en borrador o sin imagen
     */
    public function sendDraftNoImageProductsEmail(array $draftNoImageProducts)
    {
        if (empty($draftNoImageProducts)) {
            // No hay nada que notificar
            return;
        }

        $subject = 'Productos en borrador o sin imagen';
        $body = "Estimado(a) Soporte:\n\n"
              . "Se ha detectado que los siguientes productos se encuentran en borrador o carecen de imagen:\n\n"
              . implode("\n", $draftNoImageProducts)
              . "\n\n"
              . "Saludos,\n"
              . "Plugin ColibriSync (WordPress)\n";

        $this->sendMail($this->soporteEmail, $subject, $body);
    }

    /**
     * Método genérico para enviar correo con CC.
     *
     * @param string $to       - El correo principal de destino
     * @param string $subject  - Asunto
     * @param string $message  - Cuerpo del correo
     */
    private function sendMail($to, $subject, $message)
    {
        // Construir cabeceras con CC
        $headers = [];
        foreach ($this->ccEmails as $cc) {
            // Formato para WP: "Cc: email"
            $headers[] = 'Cc: ' . $cc;
        }

        // Usamos la función nativa de WP
        $sent = wp_mail($to, $subject, $message, $headers);
        if (!$sent) {
            error_log("[ColibriSync][MailService] Error al enviar correo a $to (asunto: $subject)");
        } else {
            error_log("[ColibriSync][MailService] Correo enviado a $to (asunto: $subject)");
        }
    }
}
