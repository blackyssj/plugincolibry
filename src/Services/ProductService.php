<?php

namespace ColibriSync\Services;

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
        $apiUrl = 'https://bcb6-158-172-224-218.ngrok-free.app/api/test-products';
        $response = wp_remote_get($apiUrl, ['timeout' => 30]);

        if (is_wp_error($response)) {
            throw new \Exception('Error al conectar con la API: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            throw new \Exception('El cuerpo de la respuesta de la API está vacío.');
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error al decodificar el JSON: ' . json_last_error_msg());
        }

        return $data;
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