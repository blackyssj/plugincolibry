<?php

namespace ColibriSync\Controllers;

use ColibriSync\Services\GiftCardService;

class GiftCardController
{
    private $service;

    public function __construct()
    {
        $this->service = new GiftCardService();

        // Hook YITH: yith_ywgc_after_gift_card_generation_save
        add_action('yith_ywgc_after_gift_card_generation_save',
                   [$this, 'onGiftCardCreatedFromYITH'],
                   10,
                   1);
	
    add_action('yith_ywgc_gift_card_updated', [$this, 'onGiftCardUpdated'], 10, 1);

      

        // Validar la Gift Card antes de usarla en el checkout
        add_filter('woocommerce_coupon_is_valid',
                   [$this, 'validateGiftCardBeforeUse'],
                   10,
                   2);

   
		   add_action('yith_gift_cards_status_changed', [$this, 'onGiftCardStatusChanged'], 10, 2);
        add_action('woocommerce_order_status_processing', [$this, 'onOrderCompleted'], 10, 1);
        
        error_log("[GiftCardController] Hooks registrados correctamente");
    }

   /**
 * Crear la Gift Card en Colibri al llegar el evento de YITH
 */
public function onGiftCardCreatedFromYITH($gift_card) {
    error_log('onGiftCardCreatedFromYITH (after save)');

    // Verificar si el objeto es válido
    if (!is_a($gift_card, 'YWGC_Gift_Card_Premium')) {
        error_log('Objeto Gift Card inválido');
        return;
    }

    // Obtener datos esenciales desde propiedades (no métodos)
    $gift_card_id = $gift_card->ID; // Propiedad, no método
    $monto = $gift_card->total_amount; // Propiedad
    $codigoYITH = $gift_card->gift_card_number; // Propiedad

    if (!$gift_card_id || $monto <= 0) {
        error_log('Datos inválidos de la Gift Card');
        return;
    }
    // Guardar código YITH en metadatos
    update_post_meta($gift_card_id, '_colibri_correlativo', $codigoYITH);
    error_log("Código YITH guardado: $codigoYITH");

    // Obtener datos del pedido
    $order_id = $gift_card->order_id;
    $orderData = [];
    
    if ($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            $orderData = [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'phone' => $order->get_billing_phone(),
                'email' => $order->get_billing_email(),
                'cedula' => $order->get_meta('_cedula', true)
            ];
        }
    }

    // Crear en Colibri con el código de YITH
    $res = $this->service->createValeInColibri(
        $orderData,
        $monto,
        'A',
        'woo-plugin',
        $codigoYITH // Usar código real de YITH
    );

    if ($res) {
        error_log("Vale creado en Colibri con código YITH: $codigoYITH");
    }
}


 

    /**
     * Validar la Gift Card antes de usarla en el checkout
     */
    public function validateGiftCardBeforeUse($valid, $coupon)
    {
        error_log("Validando Gift Card con código: {$coupon->get_code()}");

        if (!$coupon->get_meta('_ywgc_is_gift_card')) {
            return $valid;
        }

        $code = $coupon->get_code();
        $isActive = $this->service->isValeActive($code);
        if (!$isActive) {
            error_log("La Gift Card con código $code no está activa.");
            return false;
        }

        return $valid;
    }



	 /**
     * Actualizar estado en Colibri cuando se desactiva una Gift Card en YITH
     */
    public function onGiftCardStatusChanged($gift_card, $enabled) {
        // Actuar solo cuando se DESACTIVA la Gift Card
        if (!$enabled) {
            error_log("[GiftCardController] Gift Card desactivada: " . $gift_card->get_code());
            
            // Obtener código Colibri desde metadata
            $colibri_code = get_post_meta($gift_card->get_id(), '_colibri_correlativo', true);
            
            if (!$colibri_code) {
                error_log("No se encontró código Colibri asociado");
                return;
            }
            
            // Verificar si el saldo es 0
            if ($gift_card->get_balance() <= 0) {
                $this->service->markValeAsInactive(
                    $colibri_code, 
                    "Canje completo en YITH. Código: " . $gift_card->get_code()
                );
                error_log("Estado actualizado en Colibri: $colibri_code");
            }
        }
		}
	
/**
 * Cuando un pedido se completa
 */
  public function onOrderCompleted($order_id) 
    {
        error_log("[GiftCardController] Procesando orden completada #$order_id");
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("Orden no encontrada");
            return;
        }

        // 1. Obtener todas las gift cards usadas en la orden
        $gift_cards = $this->getGiftCardsFromOrder($order);
        
     foreach ($gift_cards as $gift_card_data) {
    // Llamar a la API siempre
    $this->service->markValeAsInactive(
        $gift_card_data['colibri_code'],
        "Sincronización de vale en orden #$order_id"
    );
    error_log("📡 Sincronizado vale Colibri: {$gift_card_data['colibri_code']}");
}

    }

    /**
     * Obtener gift cards y sus códigos Colibri desde la orden
     */
private function getGiftCardsFromOrder(\WC_Order $order): array 
{
    $gift_cards = [];

    // Opción 1: Obtener gift cards desde los cupones
    $coupon_codes = $order->get_coupon_codes();
    error_log("🔍 Cupones en la orden: " . print_r($coupon_codes, true));

    foreach ($coupon_codes as $coupon_code) {
        $coupon = new \WC_Coupon($coupon_code);
        if ($coupon->get_meta('_ywgc_is_gift_card')) {
            error_log("✅ El cupón $coupon_code es una gift card.");
            $gift_card = YITH_YWGC()->get_gift_card_by_code($coupon_code);

            if ($gift_card && $gift_card->exists()) {
                $colibri_code = get_post_meta($gift_card->ID, '_colibri_correlativo', true) ?: $coupon_code;
                $gift_cards[] = [
                    'code' => $coupon_code,
                    'balance' => $gift_card->get_balance(),
                    'colibri_code' => $colibri_code,
                    'id' => $gift_card->ID
                ];
            } else {
                error_log("⚠️ No se encontró la gift card en YITH para el cupón $coupon_code.");
            }
        } else {
            error_log("❌ El cupón $coupon_code no es una gift card.");
        }
    }

    // Opción 2: Revisar los metadatos de la orden por gift cards canjeadas
    $gift_cards_meta = $order->get_meta('_ywgc_applied_gift_cards', true);
    error_log("🔍 Gift Cards en metadatos: " . print_r($gift_cards_meta, true));

    if (!empty($gift_cards_meta) && is_array($gift_cards_meta)) {
        foreach ($gift_cards_meta as $gift_card_number => $amount) {
            $colibri_code = get_post_meta($gift_card_number, '_colibri_correlativo', true) ?: $gift_card_number;
            $gift_cards[] = [
                'code' => $gift_card_number,
                'balance' => $amount,
                'colibri_code' => $colibri_code,
                'id' => $gift_card_number
            ];
        }
    }

    error_log("✅ Gift Cards encontradas: " . print_r($gift_cards, true));
    return $gift_cards;
}

}


