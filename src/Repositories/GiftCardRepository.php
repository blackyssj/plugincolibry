<?php

namespace ColibriSync\Repositories;

class GiftCardRepository
{
    private $baseUrl;

    public function __construct()
    {
        // Ajusta la URL base de tu API Laravel (Colibri)
        $this->baseUrl = 'https://40ca-158-172-224-218.ngrok-free.app/api'; // <--- Ajusta
    }

    /**
     * Crea un Vale en Colibri (POST /api/vales).
     */
    public function createVale(array $payload)
    {
        $url = $this->baseUrl . '/vales';

        error_log("[GiftCardRepository] createVale() => POST $url");
        error_log("[GiftCardRepository][createVale] Payload=" . print_r($payload, true));

        $response = wp_remote_post($url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log("[GiftCardRepository][createVale] ERROR => " . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);
        error_log("[GiftCardRepository][createVale] status=$status, body=$body");

        if ($status === 201) {
            // Suponemos que la API retorna: { "message": "Vale creado...", "valId":123 }
            return json_decode($body);
        } else {
            error_log("[GiftCardRepository][createVale] Unexpected status=$status");
            return false;
        }
    }

    /**
     * GET /api/vales/{valCorrelativo}
     */
    public function getValeByCorrelativo(string $correlativo)
    {
        $url = $this->baseUrl . '/vales/' . urlencode($correlativo);

        error_log("[GiftCardRepository] getValeByCorrelativo() => GET $url");

        $response = wp_remote_get($url, [ 'timeout' => 30 ]);
        if (is_wp_error($response)) {
            error_log("[GiftCardRepository][getValeByCorrelativo] ERROR => " . $response->get_error_message());
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);

        error_log("[GiftCardRepository][getValeByCorrelativo] status=$status, body=$body");

        if ($status === 200) {
            return json_decode($body);
        } else {
            error_log("[GiftCardRepository][getValeByCorrelativo] Unexpected status=$status");
            return null;
        }
    }

    /**
     * PUT /api/vales/{valCorrelativo}/status
     */
    public function updateValeStatus(string $correlativo, string $nuevoEstado, string $motivo = '', string $usuario = 'api')
    {
        $url = $this->baseUrl . '/vales/' . urlencode($correlativo) . '/status';

        $payload = [
            'nuevoEstado' => $nuevoEstado,
            'motivo'      => $motivo,
            'usuario'     => $usuario,
        ];

        error_log("[GiftCardRepository] updateValeStatus() => PUT $url");
        error_log("[GiftCardRepository][updateValeStatus] Payload=" . print_r($payload, true));

        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log("[GiftCardRepository][updateValeStatus] ERROR => " . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);

        error_log("[GiftCardRepository][updateValeStatus] status=$status, body=$body");

        if ($status === 200) {
            return json_decode($body);
        } else {
            error_log("[GiftCardRepository][updateValeStatus] Unexpected status=$status");
            return false;
        }
    }
}
