<?php

namespace ColibriSync\Services;

use ColibriSync\Repositories\GiftCardRepository;

class GiftCardService
{
    private $repo;

    public function __construct()
    {
        $this->repo = new GiftCardRepository();
    }

    /**
     * Mapea monto -> vacId
     */
    private function getVacIdFromAmount(float $monto): int
    {
        // Ajusta según tu tabla cjtVale (monId=1).
        // Ejemplo:
        switch ((int)$monto) {
            case 500:
                return 9;
            case 1000:
                return 10;
            case 2000:
                return 12;
            default:
                return 1;  // fallback
        }
    }
	public function onOrderProcessing($order_id)
{
    error_log("onOrderProcessing triggered for order ID: $order_id");

    $order = wc_get_order($order_id);
    if (!$order) {
        error_log("No se encontró el pedido con ID: $order_id");
        return;
    }

    // Construir el arreglo con los datos del cliente
    $orderData = [
        'first_name' => $order->get_billing_first_name(),
        'last_name'  => $order->get_billing_last_name(),
        'phone'      => $order->get_billing_phone(),
        'email'      => $order->get_billing_email(),
        'cedula'     => $order->get_meta('_cedula', true), // Si usas un campo personalizado para cédula
    ];

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;

        $isGiftCard = $product->get_meta('_ywgc_is_gift_card', true);
        if ($isGiftCard) {
            $monto = floatval($item->get_total());

            $res = $this->service->createValeInColibri($orderData, $monto, 'A', 'woo-plugin');
            if ($res === false) {
                error_log("Error al crear Vale en Colibri para tarjeta de regalo (monto=$monto)");
            } else {
                error_log("Vale creado en Colibri con monto: $monto");
            }
        }
    }
}
/**
 * Crear Vale en Colibri con un correlativo "WEB-GC-<monto>-<rand>"
 */
public function createValeInColibri(
    array $orderData, 
    float $monto, 
    string $estado = 'A', 
    string $usuario = 'woo-plugin',
    string $codigoYITH // Parámetro nuevo
) {
    error_log("[GiftCardService] Creando vale con código YITH: $codigoYITH");

    $vacId = $this->getVacIdFromAmount($monto);
    
    $payload = [
        'valCorrelativo' => $codigoYITH, // Usar código de YITH
        'monto' => $monto,
        'valEstado' => $estado,
        'valOrigen' => 'WEB',
        'usuario' => $usuario,
        'nombre' => $orderData['first_name'],
        'apellidos' => $orderData['last_name'],
        'whatsapp' => $orderData['phone'],
        'email' => $orderData['email'],
        'cedula' => $orderData['cedula'] ?? null
    ];

    $res = $this->repo->createVale($payload);
    
    if ($res) {
        error_log("[GiftCardService] Vale creado exitosamente");
        return true;
    } else {
        error_log("[!!ERROR] Fallo en la API de Colibri");
        return false;
    }
}


    /**
     * Saber si un Vale (Gift Card) está 'A' en Colibri
     */
    public function isValeActive(string $giftCardCode): bool
    {
        error_log("[GiftCardService][isValeActive] => code=$giftCardCode");
        $vale = $this->repo->getValeByCorrelativo($giftCardCode);
        if (!$vale) {
            error_log("[GiftCardService][isValeActive] => Vale no encontrado en Colibri");
            return false;
        }

        // si valEstado='A' => activo
        return ($vale->valEstado === 'A');
    }

    /**
     * Marcar Vale como inactivo (I) en Colibri
     */
    public function markValeAsInactive(string $giftCardCode, string $motivo = 'Canje en la web')
    {
        error_log("[GiftCardService][markValeAsInactive] => code=$giftCardCode, motivo=$motivo");
        $res = $this->repo->updateValeStatus($giftCardCode, 'I', $motivo);
        if ($res === false) {
            error_log("[GiftCardService][markValeAsInactive] => Falló update en Colibri");
        } else {
            error_log("[GiftCardService][markValeAsInactive] => OK => " . print_r($res, true));
        }
        return $res;
    }

public function syncGiftCardWithColibri($gift_card_id) {
    error_log("[GiftCardService] Sincronizando Gift Card ID: $gift_card_id");
    
    // 1. Obtener Gift Card de YITH
    $gift_card = new YWGC_Gift_Card_Premium($gift_card_id);
    
    // 2. Obtener código Colibri
    $colibri_code = get_post_meta($gift_card_id, '_colibri_correlativo', true);
    
    if (!$colibri_code) {
        error_log("⚠️ No existe código Colibri para esta Gift Card");
        return false;
    }

    // 3. Determinar estado según saldo
    $estado = ($gift_card->get_balance() > 0) ? 'A' : 'I';
    
    // 4. Actualizar en Colibri
    $payload = [
        'valEstado' => $estado,
        'motivo' => "Sincronización manual"
    ];
    
    return $this->repo->updateValeStatus($colibri_code, $payload);
}
}

