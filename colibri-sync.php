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

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    wp_die('El autoloader de Composer no está disponible. Por favor, ejecuta "composer install".');
}

require_once __DIR__ . '/vendor/autoload.php';

use ColibriSync\Controllers\ProductController;
use ColibriSync\Services\ProductService;

class ColibriSync {
    private static $instance = null;

    public static function getInstance(): ColibriSync {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);

        // Cron activation and deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activateCron']);
        register_deactivation_hook(__FILE__, [$this, 'deactivateCron']);

        // Cron job hook
        add_action('colibri_sync_cron', [$this, 'syncProductsCronJob']);
    }

    public function init() {
        // Inicializa tu controlador principal, si lo tienes para sincronizar productos
        new ProductController();

        // ─────────────────────────────────────────────────────────
        // EXISTENTE: Engancha la creación de venta en Colibri
        // cuando el pedido pase a "procesando"
        // ─────────────────────────────────────────────────────────
        add_action('woocommerce_order_status_processing', [$this, 'enviarVentaColibri'], 10, 1);

        // ─────────────────────────────────────────────────────────
        // NUEVOS HOOKS PARA SINCRONIZAR PRECIO Y STOCK EN 3 MOMENTOS
        // ─────────────────────────────────────────────────────────

        // 1) Vista de producto individual
        add_action('woocommerce_before_single_product', [$this, 'syncColibriSingleProduct']);

        // 2) Vista del carrito (antes de calcular totales)
        add_action('woocommerce_before_calculate_totals', [$this, 'syncColibriCartProducts'], 10);

        // 3) Antes de procesar el checkout
        add_action('woocommerce_before_checkout_process', [$this, 'syncColibriCheckoutProducts']);
           // NUEVO: Agregar un cron para que revise borradores una vez al día
        add_action('colibri_sync_draft_check', [$this, 'checkDraftNoImageProducts']);
            if (!wp_next_scheduled('colibri_sync_draft_check')) {
            wp_schedule_event(time(), 'daily', 'colibri_sync_draft_check');
    }
    }

    /**
 * Método que se ejecuta diariamente para buscar productos en borrador o sin imagen principal.
 * Envía un mail de aviso usando MailService.
 */
public function checkDraftNoImageProducts()
{
    // 1. Buscar productos en borrador o sin imagen
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'draft',
        'fields'         => 'ids',
    ];
    $draftProducts = get_posts($args);

    // Revisa también los publish pero sin imagen si quieres
    // O combinas la lógica; por simplicidad, nos centramos en “borrador”
    // y revisamos si no tienen featured image.
    $draftNoImage = [];
    foreach ($draftProducts as $pId) {
        $thumbnailId = get_post_thumbnail_id($pId);
        if (empty($thumbnailId)) {
            $draftNoImage[] = "ProductID=$pId (sin imagen)";
        } else {
            // Si quieres ver si la imagen en la librería no existe, etc.
        }
    }

    // 2. Si no hay nada, salimos
    if (empty($draftNoImage)) {
        return; // sin aviso
    }

    // 3. Llamamos al MailService
    $mailService = new \ColibriSync\Services\MailService();
    $mailService->sendDraftNoImageProductsEmail($draftNoImage);
}

    public function activateCron() {
        if (!wp_next_scheduled('colibri_sync_cron')) {
            wp_schedule_event(time(), 'hourly', 'colibri_sync_cron');
        }
    }


    public function deactivateCron() {
        wp_clear_scheduled_hook('colibri_sync_cron');
    }

    /**
     * Esta función se ejecutará cada hora (según el cron) para mantener los productos sincronizados.
     */
    public function syncProductsCronJob() {
        $service = new ProductService();
        try {
            $service->syncProducts();
        } catch (Exception $e) {
            error_log("Error general en la sincronización: " . $e->getMessage());
        }
    }

    /**
     * 1) Sincronizar un producto al ver la página individual
     */
    public function syncColibriSingleProduct() {
        global $post;
        // Verifica que estemos en un producto y haya $post
        if (!$post || get_post_type($post->ID) !== 'product') {
            return; 
        }

        $product_id = $post->ID;
        $service = new ProductService();
        // Llama a tu método que obtiene precio/stock desde Colibri
        $service->updateProductPriceAndStock($product_id);
    }

    /**
     * 2) Sincronizar productos del carrito antes de calcular totales
     */
    public function syncColibriCartProducts($cart) {
        if (WC()->cart->is_empty()) {
            return;
        }

        $service = new ProductService();
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $service->updateProductPriceAndStock($product_id);
        }
    }

    /**
     * 3) Sincronizar productos antes de procesar el checkout
     */
    public function syncColibriCheckoutProducts() {
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return;
        }

        $service = new ProductService();
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product_id = $cart_item['product_id'];
            $service->updateProductPriceAndStock($product_id);
        }
    }

    /**
     * Envía los datos del pedido a la API de Colibri
     * una vez que WooCommerce marca el pedido como "procesando".
     */
    public function enviarVentaColibri($order_id) {
        // Obtener el pedido de WooCommerce
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // 1. Mapear datos del cliente
        $nombre   = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $celular  = $order->get_billing_phone();
        $email1   = $order->get_billing_email();
        // Ajusta el meta key si el carnet/NIT está en otro campo, por ejemplo '_billing_company'
        $carnet   = $order->get_meta('_billing_ci'); 

        // 2. Monto total pagado
        $montoPagado = $order->get_total();

        // 3. Obtener información de los productos
        $items = $order->get_items();
        $productos = [];
        foreach($items as $item) {
            $product  = $item->get_product();
            $sku      = $product->get_sku() ?: $product->get_id();
            $cantidad = $item->get_quantity();
            // Subtotal sin impuestos
            $precio   = $order->get_item_subtotal($item, false);

            $productos[] = [
                'sku'      => $sku,
                'precio'   => $precio,
                'cantidad' => $cantidad,
            ];
        }

        // 4. Determinar el tipo de pago (E,T,AB,VR,BO)
        $payment_method = $order->get_payment_method(); 
        // Ajustar a tu lógica:
        $tipoPago = 'E';

        // 5. Crear el array para la API
        $datos_api = [
            'nombre'     => $nombre,
            'celular'    => $celular,
            'email1'     => $email1,
            'carnet'     => $carnet,
            'productos'  => $productos,
            'tipoPago'   => $tipoPago,
            'montoPagado'=> $montoPagado
        ];

        // 6. Llamar a la API de Colibri
        $url = 'https://8802-158-172-224-218.ngrok-free.app/api/ventas/crear';

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body'    => json_encode($datos_api),
            'timeout' => 45
        ]);

        // 7. Manejar la respuesta
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

