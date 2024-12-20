<?php

namespace ColibriSync\Services;
use ColibriSync\Controllers\CheckoutController;

use ColibriSync\Repositories\ProductRepository;

class ProductService {
    private $productRepository;

    public function __construct() {
        $this->productRepository = new ProductRepository();
    }

    /**
     * Sincroniza los productos desde la API externa.
     */
    public function syncProducts() {
        $response = $this->fetchExternalProducts();

        // Si response es error, lanzará excepción
        if (is_wp_error($response)) {
            throw new \Exception('Error al consumir la API externa: ' . $response->get_error_message());
        }

        // Agrupar productos por SKU
        $groupedProducts = $this->groupProductsBySku($response);

        // Lista de SKUs sincronizados
        $syncedSkus = [];

        foreach ($groupedProducts as $sku => $variations) {
            try {
                $firstItem = $variations[0];
                $productType = strtolower($firstItem['TIPO_DE_PRODUCTO']);
                $syncedSkus[] = $sku; // Registramos el SKU como sincronizado

                if ($productType === 'variable') {
                    // Producto variable y sus variaciones
                    $this->productRepository->saveVariableProduct($sku, $variations);
                } else {
                    // Producto simple
                    $this->productRepository->saveSimpleProduct($firstItem);
                }
            } catch (\Exception $e) {
                // Error con este producto específico
                error_log('Error al procesar el producto con SKU ' . $sku . ': ' . $e->getMessage());
                
                // Poner en borrador si existe
                $this->productRepository->draftProductBySku($sku);

                // Continuar con el siguiente producto
                continue;
            }
        }

        // Desactivar productos en WooCommerce que no están en la respuesta de la API
        // Esto se asume que se basa en los SKUs sincronizados. 
        $this->productRepository->draftMissingProducts($syncedSkus);
    }

    /**
     * Obtiene productos desde la API externa.
     */
    private function fetchExternalProducts() {
        $apiUrl = 'https://8802-158-172-224-218.ngrok-free.app/api/test-products';
        $response = wp_remote_get($apiUrl, ['timeout' => 60]);

        if (is_wp_error($response)) {
            throw new \Exception('Error al conectar con la API: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            throw new \Exception('El cuerpo de la respuesta de la API está vacío.');
        }

     $data = json_decode($body, true);

// Aquí modificas para que $data contenga directamente la lista de productos
$data = $data['original'];

if (json_last_error() !== JSON_ERROR_NONE) {
    throw new \Exception('Error al decodificar el JSON: ' . json_last_error_msg());
}

return $data;
    }

    /**
     * Agrupa productos por SKU.
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
