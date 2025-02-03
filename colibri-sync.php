<?php
/**
 * Plugin Name: Colibri Sync
 * Plugin URI: https://
 * Description: Sincroniza productos desde una API externa con WooCommerce y registra las ventas en Colibri.
 * Version: 1.0.0
 * Author: Jose Miguel Menacho
 * Author URI: https://example.com
 * Text Domain: colibri-sync
 */

defined('ABSPATH') || exit;

// 1) Verifica el autoloader de Composer
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    wp_die('El autoloader de Composer no está disponible. Por favor, ejecuta "composer install".');
}

require_once __DIR__ . '/vendor/autoload.php';

// 2) Importa las clases existentes (Productos)
use ColibriSync\Controllers\ProductController;
use ColibriSync\Services\ProductService;

// 3) (NUEVO) Gift Cards: Incluye los archivos si no usas autoload PSR-4
require_once __DIR__ . '/src/Repositories/GiftCardRepository.php';
require_once __DIR__ . '/src/Services/GiftCardService.php';
require_once __DIR__ . '/src/Controllers/GiftCardController.php';

// 4) Importa las clases GiftCard
use ColibriSync\Controllers\GiftCardController;

class ColibriSync
{
    private static $instance = null;

    /** @var GiftCardController */
    private $giftCardController;

    public static function getInstance(): ColibriSync {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Enganchar cuando los plugins estén listos
        add_action('plugins_loaded', [$this, 'init']);

        // Hooks de activación/desactivación de cron
        register_activation_hook(__FILE__, [$this, 'activateCron']);
        register_deactivation_hook(__FILE__, [$this, 'deactivateCron']);

        // Hook recurrente (si usas sync automática)
        add_action('colibri_sync_cron', [$this, 'syncProductsCronJob']);

        // Hook para la secuencia manual
        add_action('colibri_sync_manual_batch', [$this, 'handleManualBatch'], 10, 1);
    }

    public function init() {
        // 1) Controlador principal (productos)
        new ProductController();

        // 2) Hook existente: al llegar a "processing", enviar la venta a la API de Colibri
        add_action('woocommerce_order_status_processing', [$this, 'enviarVentaColibri'], 10, 1);

        // 3) Hooks para sincronizar precio y stock en distintos momentos
        add_action('woocommerce_before_single_product', [$this, 'syncColibriSingleProduct']);
        add_action('woocommerce_before_calculate_totals', [$this, 'syncColibriCartProducts'], 10);
        add_action('woocommerce_before_checkout_process', [$this, 'syncColibriCheckoutProducts']);

        // 4) Cron diario para revisar borradores sin imagen
        add_action('colibri_sync_draft_check', [$this, 'checkDraftNoImageProducts']);
        if (!wp_next_scheduled('colibri_sync_draft_check')) {
            wp_schedule_event(time(), 'daily', 'colibri_sync_draft_check');
        }

        // ─────────────────────────────────────────────────────────
        // (NUEVO) Inicializar GiftCardController para la lógica de Gift Cards
        // ─────────────────────────────────────────────────────────
        $this->giftCardController = new GiftCardController();

        // (Opcional) Hook si quisieras crear la GC al "completed":
        // add_action('woocommerce_order_status_completed',
        //            [$this->giftCardController, 'onOrderCompleted'],
        //            10, 1);

        // Hook para crear la Gift Card en Colibri al pasar a "processing":
        add_action('woocommerce_order_status_processing',
                   [$this->giftCardController, 'onOrderProcessing'],
                   10, 1);

        // Hook para validar Gift Card (cupón) antes de usar
        // (usa la lógica en GiftCardController->validateGiftCardBeforeUse())
        add_filter('woocommerce_coupon_is_valid',
                   [$this->giftCardController, 'validateGiftCardBeforeUse'],
                   10, 2);

        // Cron hook para gift cards (opcional)
        add_action('colibri_sync_giftcards_cron',
                   [$this->giftCardController, 'syncGiftCardsCron']);
    }

    // ─────────────────────────────────────────────────────────
    // Revisión de borradores sin imagen (diario)
    // ─────────────────────────────────────────────────────────

    public function checkDraftNoImageProducts() {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'draft',
            'fields'         => 'ids',
        ];
        $draftProducts = get_posts($args);

        $draftNoImage = [];
        foreach ($draftProducts as $pId) {
            $thumbnailId = get_post_thumbnail_id($pId);
            if (empty($thumbnailId)) {
                $draftNoImage[] = "ProductID=$pId (sin imagen)";
            }
        }

        if (empty($draftNoImage)) {
            return; // No hay borradores sin imagen
        }

        $mailService = new \ColibriSync\Services\MailService();
        $mailService->sendDraftNoImageProductsEmail($draftNoImage);
    }

    // ─────────────────────────────────────────────────────────
    // Hooks de activación / desactivación cron
    // ─────────────────────────────────────────────────────────

    public function activateCron() {
        // Cron general para sync de productos
        if (!wp_next_scheduled('colibri_sync_cron')) {
            wp_schedule_event(time(), 'hourly', 'colibri_sync_cron');
        }
        // Cron específico para gift cards (opcional)
        if (!wp_next_scheduled('colibri_sync_giftcards_cron')) {
            wp_schedule_event(time(), 'hourly', 'colibri_sync_giftcards_cron');
        }
    }

    public function deactivateCron() {
        wp_clear_scheduled_hook('colibri_sync_cron');
        wp_clear_scheduled_hook('colibri_sync_giftcards_cron');
    }

    // ─────────────────────────────────────────────────────────
    // Sincronización automática (cron job)
    // ─────────────────────────────────────────────────────────

    /**
     * Se ejecuta cada hora si deseas una sync automática (productos)
     */
    public function syncProductsCronJob() {
        error_log("[ColibriSync] syncProductsCronJob() iniciado (sync automática).");

        $service = new ProductService();
        try {
            $service->syncProducts(); 
        } catch (\Exception $e) {
            error_log("[ColibriSync] Error en syncProductsCronJob: " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────
    // Sincronización manual en subllamadas
    // ─────────────────────────────────────────────────────────

    /**
     * Maneja un sublote de la sincronización MANUAL (de productos).
     * Recibe un array con 'offset' => int
     */
    public function handleManualBatch($args) {
        $offset = isset($args['offset']) ? (int)$args['offset'] : 0;
        $batchSize = 900;  // Ajusta tu tamaño de lote
        $delay = 3 * 60;   // Retraso (en segundos) para programar la siguiente subllamada

        error_log("[ColibriSync] handleManualBatch() => offset=$offset, batchSize=$batchSize");

        try {
            $service = new ProductService();
            // Llamamos la sincronización para ESTE lote
            $result = $service->syncProducts($offset, $batchSize);

            if ($result === 'NO_MORE_PRODUCTS') {
                error_log("[ColibriSync] Sincronización manual terminada. offset=$offset => NO_MORE_PRODUCTS");
                update_option('colibri_sync_manual_offset', 0);
                return;
            }

            // Programar siguiente subllamada
            $newOffset = $offset + $batchSize;
            update_option('colibri_sync_manual_offset', $newOffset);

            wp_schedule_single_event(
                time() + $delay,
                'colibri_sync_manual_batch',
                [ [ 'offset' => $newOffset ] ]
            );

            error_log("[ColibriSync] Lote offset=$offset procesado. Próximo offset=$newOffset en 3 minutos.");

        } catch (\Exception $e) {
            error_log("[ColibriSync] Error en handleManualBatch offset=$offset: " . $e->getMessage());
        }
    }


    // ─────────────────────────────────────────────────────────
    // Hooks de sincronización de precio/stock
    // ─────────────────────────────────────────────────────────

    public function syncColibriSingleProduct() {
        global $post;
        if (!$post || get_post_type($post->ID) !== 'product') {
            return;
        }
        $product_id = $post->ID;

        error_log("[ColibriSync] syncColibriSingleProduct() => product_id=$product_id");

        $service = new ProductService();
        $service->updateProductPriceAndStock($product_id);
    }

    public function syncColibriCartProducts($cart) {
        if (WC()->cart->is_empty()) {
            return;
        }
        error_log("[ColibriSync] syncColibriCartProducts() => " . count($cart->get_cart()) . " items.");

        $service = new ProductService();
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $service->updateProductPriceAndStock($product_id);
        }
    }

    public function syncColibriCheckoutProducts() {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return;
        }
        error_log("[ColibriSync] syncColibriCheckoutProducts() => " . count($cart->get_cart()) . " items.");

        $service = new ProductService();
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $service->updateProductPriceAndStock($product_id);
        }
    }

    // ─────────────────────────────────────────────────────────
    // Lógica para enviar la venta a la API de Colibri (existe)
    // ─────────────────────────────────────────────────────────

    public function enviarVentaColibri($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $nombre   = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $celular  = $order->get_billing_phone();
        $email1   = $order->get_billing_email();
        $carnet   = $order->get_meta('_billing_ci');
        $montoPagado = $order->get_total();

        $items = $order->get_items();
        $productos = [];
        foreach ($items as $item) {
            $product  = $item->get_product();
            $sku      = $product->get_sku() ?: $product->get_id();
            $cantidad = $item->get_quantity();
            $precio   = $order->get_item_subtotal($item, false);

            $productos[] = [
                'sku'      => $sku,
                'precio'   => $precio,
                'cantidad' => $cantidad,
            ];
        }

        $tipoPago = 'E';  // Ajusta si tu API maneja otro tipo
        $datos_api = [
            'nombre'      => $nombre,
            'celular'     => $celular,
            'email1'      => $email1,
            'carnet'      => $carnet,
            'productos'   => $productos,
            'tipoPago'    => $tipoPago,
            'montoPagado' => $montoPagado
        ];

        $url = 'https://8802-158-172-224-218.ngrok-free.app/api/ventas/crear';

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($datos_api),
            'timeout' => 45
        ]);

        if (is_wp_error($response)) {
            $mensaje = 'Error al conectar con la API Colibri: ' . $response->get_error_message();
            $order->add_order_note($mensaje);
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            if ($code == 200 || $code == 201) {
                $order->add_order_note('Venta registrada en Colibri con éxito. Respuesta: ' . $body);
            } else {
                $order->add_order_note('Error al registrar venta en Colibri. Código: ' . $code . ' Respuesta: ' . $body);
            }
        }
    }
}

// Inicializa el plugin
ColibriSync::getInstance();
