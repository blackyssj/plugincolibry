<?php
namespace ColibriSync\Services;

class CheckoutService {
    protected $colibriApiBase;

    public function __construct() {
        // URL base de tu API Laravel que registra ventas, por ejemplo:
        $this->colibriApiBase = 'https://0d61-190-181-62-165.ngrok-free.app';
    }

    public function verifyStockBeforeCheckout() {
        $cart = \WC()->cart->get_cart();
        if (empty($cart)) {
            return;
        }

        foreach ($cart as $item) {
            $product = $item['data'];
            $quantity = $item['quantity'];

            $sku = $product->get_sku() ?: $product->get_id();

            // Verificar stock en Colibri
            $stockDisponible = $this->checkStockColibri($sku);

            if ($stockDisponible < $quantity) {
                \wc_add_notice('No hay stock suficiente para el producto: ' . $product->get_name(), 'error');
                return; // Esto evita continuar con el checkout
            }
        }
    }

    protected function checkStockColibri($sku) {
        // Ajusta el endpoint según tu API
        $url = $this->colibriApiBase . '/checkStock?sku=' . urlencode($sku);
        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            return 0; // Asume sin stock si error
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['stock']) ? (int)$data['stock'] : 0;
    }

    public function registerSaleInColibri($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Mapear campos
        $nombre = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $celular = $order->get_billing_phone();
        $email1 = $order->get_billing_email();
        $carnet = $order->get_meta('_billing_ci'); 
        // Ajusta según donde guardes el NIT o CI: si lo guardas en billing_company o billing_ci

        $montoPagado = $order->get_total();
        $productos = [];

        foreach($order->get_items() as $item) {
            $product = $item->get_product();
            $sku = $product->get_sku() ?: $product->get_id();
            $cantidad = $item->get_quantity();
            $precio = $order->get_item_subtotal($item, false);

            $productos[] = [
                'sku' => $sku,
                'precio' => $precio,
                'cantidad' => $cantidad,
            ];
        }

        // tipoPago: mapea el método de pago de WooCommerce a lo que la API espera
        // Ejemplo:
        $payment_method = $order->get_payment_method(); // ej: 'cod', 'stripe', 'cheque'
        $tipoPago = 'E'; // Ajustar según tu lógica (E=efectivo, T=tarjeta, etc.)

        $datos_api = [
            'nombre' => $nombre,
            'celular' => $celular,
            'email1' => $email1,
            'carnet' => $carnet,
            'productos' => $productos,
            'tipoPago' => $tipoPago,
            'montoPagado' => $montoPagado
        ];

        $url = $this->colibriApiBase . '/createSale';
        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($datos_api),
            'timeout' => 45
        ]);

        if (is_wp_error($response)) {
            $order->add_order_note('Error al conectar con Colibri: ' . $response->get_error_message());
        } else {
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($code == 200 || $code == 201) {
                $order->add_order_note('Venta registrada en Colibri exitosamente. Respuesta: ' . $body);
            } else {
                $order->add_order_note('Error al registrar venta en Colibri. Código: ' . $code . ' Respuesta: ' . $body);
            }
        }
    }
}
