<?php

namespace ColibriSync\Services;

use ColibriSync\Repositories\ProductRepository;

/**
 * ProductService: Clase principal para sincronizar productos con la API de Colibri,
 *                 incluyendo manejo de excepciones y envío de correo de error cuando
 *                 ocurre una excepción en cualquier punto.
 */
class ProductService
{
    private $productRepository;
    private $mailService; // Para enviar correos en caso de error

    public function __construct()
    {
        // Instanciar repositorio y servicio de correo
        $this->productRepository = new ProductRepository();
        $this->mailService = new MailService();
    }

    /**
     * Actualiza el precio y el stock de un producto en WooCommerce
     * consultando la API de Colibri en /api/producto-detalles?codigo_unico=SKU.
     *
     * @param int $product_id  El ID del producto en WooCommerce
     */

 public function updateProductPriceAndStock($product_id) {
        // 1. Obtener SKU del producto (asumiendo que guardas artId en _sku)
        $sku = get_post_meta($product_id, '_sku', true);
        if (empty($sku)) {
            // No hay SKU => no podemos llamar la API
            return;
        }

        // 2. Construir la URL de la API
        $api_url = 'https://7843-158-172-224-218.ngrok-free.app/api/producto-detalles?codigo_unico=' . urlencode($sku);
	 error_log( 'Este es el codigo skuuu'. urlencode($sku));

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
     * Sincroniza los productos desde la API externa de Colibri.
     * - Llama a fetchExternalProducts() (que puede lanzar excepción).
     * - Recorre los productos agrupados por SKU.
     * - Usa ProductRepository para guardarlos en WooCommerce.
     * - Cualquier error envía correo a soporte (mailService->sendSyncErrorEmail).
     */
public function syncProducts(int $offset = 12500, int $batchSize = 900)
{
    // Este $batchSize representará la cantidad total de productos
    // que queremos procesar en este "sub-lote".
    // Sabemos que la API solo tolera limit=100, así que hacemos
    // chunk de 100 en 100 hasta llegar a $batchSize total.

    error_log("[ColibriSync][syncProducts] Iniciando sub-lote. offset=$offset, batchSize=$batchSize");

    // Determina cuántas llamadas de 100 en 100 necesitamos
    // Por ejemplo, 900 / 100 = 9
    $limitApi = 100;
    $numChunks = ceil($batchSize / $limitApi);

    $itemsProcessed = 0; // Para contar cuántos ítems totales procesamos
    try {
        for ($i = 0; $i < $numChunks; $i++) {
            // Offset parcial
            // Ej: si offset=900 y i=2 => currentOffset=900+(2*100)=1100
            $currentOffset = $offset + ($i * $limitApi);

            error_log("[ColibriSync][syncProducts] => Llamando fetchExternalProducts(currentOffset=$currentOffset, limit=100)");

            // 1) Llamada a la API con limit=100
            $response = $this->fetchExternalProducts($currentOffset, $limitApi);

            // 2) Manejo de error
            if (is_wp_error($response)) {
                $msg = "[ColibriSync][syncProducts] Error al consumir la API: " . $response->get_error_message();
                error_log($msg);
                throw new \Exception($msg);
            }

            // 3) Si la API no devolvió nada, significa que ya no hay más productos
            if (empty($response)) {
                // Regresamos "NO_MORE_PRODUCTS" para que no programe más subllamadas
                error_log("[ColibriSync][syncProducts] => NO_MORE_PRODUCTS (API vacía) en offset=$currentOffset");
                return 'NO_MORE_PRODUCTS';
            }

            // 4) Procesar este chunk
            error_log("[ColibriSync][syncProducts] => Recibidos " . count($response) . " productos en offset=$currentOffset");

            // Agrupar y procesar
            $grouped = $this->groupProductsBySku($response);
            error_log("[ColibriSync][syncProducts] => Agrupados en " . count($grouped) . " SKU(s).");

            foreach ($grouped as $sku => $variations) {
                $countVars = count($variations);
                error_log("[ColibriSync][syncProducts] => SKU=$sku ($countVars variaciones).");

                try {
                    $firstItem   = $variations[0];
                    $productType = strtolower($firstItem['TIPO_DE_PRODUCTO']);
                    if ($productType === 'variable') {
                        $this->productRepository->saveVariableProduct($sku, $variations);
                    } else {
                        $this->productRepository->saveSimpleProduct($firstItem);
                    }
                    error_log("[ColibriSync][syncProducts] => SKU=$sku procesado OK.");
                } catch (\Exception $ex) {
                    $errorSku = "[ColibriSync][syncProducts] Error procesando SKU=$sku (offset=$currentOffset): " . $ex->getMessage();
                    error_log($errorSku);

                    // Enviar mail de error
                    $this->mailService->sendSyncErrorEmail(
                        "Error procesando SKU=$sku en syncProducts() offset=$currentOffset",
                        $ex->getMessage() . "\n\nTrace:\n" . $ex->getTraceAsString()
                    );
                    // Continuar con el siguiente SKU
                }
            }

            $itemsProcessed += count($response);

            // 5) Verificar si ya hemos llegado a 900 items en este sub-lote
            //    (o $batchSize si no es 900)
            if ($itemsProcessed >= $batchSize) {
                // Ya procesamos 900, por ejemplo, salimos de la sub-lógica
                error_log("[ColibriSync][syncProducts] => Se alcanzó $itemsProcessed items => Fin de este sub-lote.");
                break;
            }
        }

        // Si llegamos aquí sin "NO_MORE_PRODUCTS", devolvemos 'OK'
        error_log("[ColibriSync][syncProducts] Sub-lote completado. offsetBase=$offset, itemsProcessed=$itemsProcessed.");

        return 'OK';

    } catch (\Exception $e) {
        $errMsg = "[ColibriSync][syncProducts] Excepción fatal offset=$offset => " . $e->getMessage();
        error_log($errMsg);

        $this->mailService->sendSyncErrorEmail(
            "Error general en syncProducts() offset=$offset",
            $e->getMessage() . "\n\nTrace:\n" . $e->getTraceAsString()
        );
        throw $e;
    }
}


    /**
     * Obtiene productos desde la API externa de Colibri.
     * Retorna array con los datos o lanza excepción si algo falla.
     *
     * @return array
     * @throws \Exception
     */
   private function fetchExternalProducts(int $offset = 0, int $limit = 500): array
    {
        $apiUrl = "https://7843-158-172-224-218.ngrok-free.app/api/test-products?offset={$offset}&limit={$limit}";

        error_log("[SYNC] Llamando a la API: {$apiUrl}");

        $response = wp_remote_get($apiUrl, ['timeout' => 25000]);
        if (is_wp_error($response)) {
            throw new \Exception('Error al conectar con la API: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            throw new \Exception("La API devolvió un código HTTP no esperado: {$statusCode}");
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error al decodificar JSON: ' . json_last_error_msg());
        }

        error_log("[SYNC] Productos recibidos: " . count($data));
        return $data;
    }


    /**
     * Agrupa productos por SKU.
     *
     * @param array $products
     * @return array
     */
   private function groupProductsBySku(array $products): array
    {
        $grouped = [];
        foreach ($products as $product) {
            $sku = $product['CODIGO_SKU'] ?? null;

            if (empty($sku)) {
                error_log("[SYNC] Producto sin SKU detectado. Ignorando.");
                continue;
            }

            if (!isset($grouped[$sku])) {
                $grouped[$sku] = [];
            }
            $grouped[$sku][] = $product;
        }
        return $grouped;
    }    
}
