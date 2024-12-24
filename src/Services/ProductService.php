<?php

namespace ColibriSync\Services;

use ColibriSync\Repositories\ProductRepository;

class ProductService {
    private $productRepository;

    public function __construct() {
        $this->productRepository = new ProductRepository();
    }
  /**
     * Actualiza el precio y el stock de un producto en WooCommerce
     * consultando la API de Colibri en /api/producto-detalles?codigo_unico=SKU
     */
    public function updateProductPriceAndStock($product_id) {
        // 1. Obtener SKU del producto (asumiendo que guardas artId en _sku)
        $sku = get_post_meta($product_id, '_sku', true);
        if (empty($sku)) {
            // No hay SKU => no podemos llamar la API
            return;
        }

        // 2. Construir la URL de la API
        $api_url = 'https://8802-158-172-224-218.ngrok-free.app/api/producto-detalles?codigo_unico=' . urlencode($sku);

        // 3. Llamar
        $response = wp_remote_get($api_url, [
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[ColibriSync] Error al conectar con la API: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code != 200) {
            error_log("[ColibriSync] API devolvió código=$code. Body=$body");
            return;
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("[ColibriSync] Error al decodificar JSON: " . json_last_error_msg());
            return;
        }

        // 4. data debería tener: "PRECIO_NORMAL", "PRECIO_DESCUENTO", "STOCK"
        if (!isset($data['PRECIO_NORMAL']) || !isset($data['STOCK'])) {
            error_log("[ColibriSync] Faltan campos en la respuesta (PRECIO_NORMAL, STOCK)");
            return;
        }

        // Convertir a valores float/int
        $precioNormal    = (float)$data['PRECIO_NORMAL'];
        $precioDescuento = isset($data['PRECIO_DESCUENTO']) ? (float)$data['PRECIO_DESCUENTO'] : 0.0;
        $stock           = (int)$data['STOCK'];

        // 5. Actualizar en WooCommerce
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Actualizar stock
        $product->set_stock_quantity($stock);
        $product->set_manage_stock(true);

        // Actualizar precios
        if ($precioDescuento > 0 && $precioDescuento < $precioNormal) {
            $product->set_regular_price($precioNormal);
            $product->set_sale_price($precioDescuento);
        } else {
            // Sin oferta
            $product->set_regular_price($precioNormal);
            $product->set_sale_price('');
        }

        // Guardar cambios
        $product->save();

        // Log
        error_log("[ColibriSync] Actualizado SKU=$sku => price=$precioNormal, sale=$precioDescuento, stock=$stock, product_id=$product_id");
    }

    /**
     * Sincroniza los productos desde la API externa.
     */
    public function syncProducts() {
        $response = $this->fetchExternalProducts();

        if (is_wp_error($response)) {
            throw new \Exception('Error al consumir la API externa: ' . $response->get_error_message());
        }

        // Agrupar productos por SKU
        $groupedProducts = $this->groupProductsBySku($response);

        foreach ($groupedProducts as $sku => $variations) {
            try {
                $firstItem = $variations[0];
                $productType = strtolower($firstItem['TIPO_DE_PRODUCTO']);

                if ($productType === 'variable') {
                    // Si es variable, usar saveVariableProduct
                    $this->productRepository->saveVariableProduct($sku, $variations);
                } else {
                    // Si es simple
                    $this->productRepository->saveSimpleProduct($firstItem);
                }
            } catch (\Exception $e) {
                error_log('Error al procesar el producto con SKU ' . $sku . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Obtiene productos desde la API externa.
     *
     * @return array
     */
private function fetchExternalProducts() {
    $apiUrl = 'https://8802-158-172-224-218.ngrok-free.app/api/test-products';

    $response = wp_remote_get($apiUrl, ['timeout' => 30]);

    // 1. Revisar si es error de conexión
    if (is_wp_error($response)) {
        throw new \Exception('Error al conectar con la API: ' . $response->get_error_message());
    }

    // 2. Revisar código HTTP
    $statusCode = wp_remote_retrieve_response_code($response);
    if ($statusCode != 200) {
        throw new \Exception("La API devolvió un código HTTP no esperado: $statusCode");
    }

    // 3. Obtener cuerpo
    $body = wp_remote_retrieve_body($response);

    // Log para debugging
    error_log('--- Respuesta de la API (test-products) ---');
    error_log($body);
    error_log('--- Fin respuesta de la API ---');

    if (empty($body)) {
        throw new \Exception('El cuerpo de la respuesta de la API está vacío.');
    }

    // 4. Decodificar JSON
    $data = json_decode($body, true);

    // 5. Revisar si hay error de JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('Error al decodificar el JSON: ' . json_last_error_msg());
    }

    // 6. Revisar si data['original'] existe y es un array
    if (isset($data['original']) && is_array($data['original'])) {
        return $data['original']; 
    }

    // 7. Si no hay 'original', quizás retorna un array directo
    if (is_array($data)) {
        return $data;
    }

    throw new \Exception('La respuesta de la API no contiene el array de productos esperado.');
}




    /**
     * Agrupa productos por SKU.
     *
     * @param array $products
     * @return array
     */
    private function groupProductsBySku(array $products) {
        $grouped = [];

        foreach ($products as $product) {
            $sku = $product['CODIGO_SKU'];
            if (!isset($grouped[$sku])) {
                $grouped[$sku] = [];
            }
            $grouped[$sku][] = $product;
        }

        return $grouped;
    }
}