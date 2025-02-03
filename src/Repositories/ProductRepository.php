<?php

namespace ColibriSync\Repositories;

use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Product_Simple;
use WC_Product_Attribute;
use WP_Query;
use WC_Data_Exception;

class ProductRepository {

    private $uploadBaseUrl = 'https://mediumslateblue-woodpecker-676221.hostingersite.com/wp-content/uploads/';
 /**
     * Crea o actualiza un producto SIMPLE en WooCommerce basándose en los datos del API.
     */
    public function saveSimpleProduct($data)
    {
        $sku = $data['CODIGO_SKU'];
        // 1. Ver si ya existe un producto (cualquiera) con este SKU
        $existingProdIdBySku = wc_get_product_id_by_sku($sku);

        // 2. Si existe y no es "simple", lo mandamos a la papelera
        //    (o podríamos simplemente reutilizarlo, si quisiéramos).
        $product = null;
        if ($existingProdIdBySku) {
            $maybeProd = wc_get_product($existingProdIdBySku);
            // Si no es el mismo tipo o está corrupto, lo enviamos a la papelera
            if (!$maybeProd || !$maybeProd->is_type('simple')) {
                wp_trash_post($existingProdIdBySku);
                $product = new WC_Product_Simple();
            } else {
                // Caso ideal: ya existía y es simple; lo reutilizamos
                $product = $maybeProd;
            }
        } else {
            // No existe => crearlo
            $product = new WC_Product_Simple();
        }

        // 3. Asignar SKU (ya hemos “liberado” el SKU si estaba en otro lado)
        $product->set_sku($sku);

        // 4. Datos básicos
        $product->set_name($data['TITULO']);
        $product->set_description($data['DESCRIPCION_CORTA'] ?? '');

        // 5. Precios
        $precioNormal = floatval($data['PRECIO_NORMAL']);
        $precioDescuento = isset($data['PRECIO_DESCUENTO']) ? floatval($data['PRECIO_DESCUENTO']) : 0.0;
        $product->set_regular_price($precioNormal > 0 ? $precioNormal : '');
        if ($precioDescuento > 0 && $precioDescuento < $precioNormal) {
            $product->set_sale_price($precioDescuento);
        } else {
            $product->set_sale_price('');
        }

        // 6. Stock
        $stockQty = (int)$data['STOCK'];
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stockQty);
        $product->set_low_stock_amount(isset($data['STOCK_MINIMO']) ? (int)$data['STOCK_MINIMO'] : '');

        // 7. Meta: CODIGO_UNICO
        update_post_meta($product->get_id(), '_CODIGO_UNICO', $data['CODIGO_UNICO']);

        // 8. Imágenes y categorías (antes de decidir estado)
        $imagesFound = $this->addProductImages($product, $data);
        $this->setProductCategories($product, $data);

        // 9. Atributos “simples” (si existieran)
        $attributes = $this->prepareAttributesFromData([$data]);
        $product->set_attributes($attributes);

        // 10. Determinar estado final (publish vs draft)
        $status = 'publish';
        if (!$imagesFound || $precioNormal <= 0) {
            $status = 'draft';
        }
        if ($stockQty < 1) {
            $status = 'draft';
        }
        $product->set_status($status);

        // 11. Guardar
        $product->save();
    }

    /**
     * Crea o actualiza un producto VARIABLE en WooCommerce basándose en los datos del API.
     */
    public function saveVariableProduct($sku, $variationsData)
    {
        // 1. Revisar si ya hay un producto con este SKU (cualquiera)
        $existingProdIdBySku = wc_get_product_id_by_sku($sku);
        $product = null;
        if ($existingProdIdBySku) {
            $maybeProd = wc_get_product($existingProdIdBySku);
            if (!$maybeProd || !$maybeProd->is_type('variable')) {
                // Si no es variable, lo pasamos a la papelera
                wp_trash_post($existingProdIdBySku);
                $product = new WC_Product_Variable();
            } else {
                // Sí es variable => lo reutilizamos
                $product = $maybeProd;
            }
        } else {
            // No existe => crearlo
            $product = new WC_Product_Variable();
        }

        // 2. Datos del padre
        $first = $variationsData[0];
        $product->set_name($first['TITULO']);
        $product->set_sku($sku);
        $product->set_description($first['DESCRIPCION_CORTA'] ?? '');
        // Para un producto variable se maneja stock en las variaciones
        $product->set_manage_stock(false);

        // 3. Categorías e imágenes del padre
        $this->setProductCategories($product, $first);
        $imagesFound = $this->addProductImages($product, $first);

        // 4. Atributos del padre
        $parentAttributes = $this->prepareAttributesFromData($variationsData);
        $product->set_attributes($parentAttributes);
        $productId = $product->save(); // Guardamos para asegurarnos de tener un ID

        // 5. Variaciones existentes en WooCommerce
        $existingVariations = $this->getExistingVariations($productId);
        $currentVariationSkus = [];
        $publishedVariationsCount = 0;

        // 6. Recorrer cada “sub-producto”/variación de la API
        foreach ($variationsData as $variationData) {
            try {
                $varSku = $variationData['CODIGO_UNICO'];
                // Crear/actualizar la variación
                $newVariationId = $this->createOrUpdateVariation($productId, $variationData, $existingVariations);

                $currentVariationSkus[] = $varSku;

                // Verificar estado publish
                $varProduct = wc_get_product($newVariationId);
                if ($varProduct && $varProduct->get_status() === 'publish') {
                    $publishedVariationsCount++;
                }
            } catch (\Exception $e) {
                error_log('Error procesando variación ' . $variationData['CODIGO_UNICO'] . ' del producto ' . $sku . ': ' . $e->getMessage());
            }
        }

        // 7. Despublicar variaciones “sobrantes” que no vengan en este sync
        $this->draftMissingVariations($productId, $currentVariationSkus);

        // 8. Determinar estado (si al menos una variación está “publish” y tenemos imagen)
        $status = ($imagesFound && $publishedVariationsCount > 0) ? 'publish' : 'draft';
        $product->set_status($status);
        $product->save();
    }

    /**
     * Crea/actualiza una variación con la lógica para evitar “SKU duplicado”.
     */
    private function createOrUpdateVariation($parentId, $variationData, $existingVariations)
    {
        $variationSku = $variationData['CODIGO_UNICO'];

        // 1. ¿Ya tenemos una variación en este mismo producto con ese SKU?
        $variationId = isset($existingVariations[$variationSku]) ? $existingVariations[$variationSku] : null;

        // 2. ¿Existe un producto/cualquier variación en WooCommerce con ese SKU?
        $existingIdBySku = wc_get_product_id_by_sku($variationSku);
        if ($existingIdBySku && $existingIdBySku != $variationId) {
            // Ese SKU estaba en otro post => mandar a papelera para “liberarlo”
            wp_trash_post($existingIdBySku);
        }

        // 3. Crear/obtener la variación
        if ($variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation || !$variation->is_type('variation')) {
                // Si no es variación real, se tritura
                if ($variationId) {
                    wp_trash_post($variationId);
                }
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($parentId);
            }
        } else {
            // No existía => se crea
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parentId);
        }

        // 4. Asignar el SKU a la variación
        $variation->set_sku($variationSku);

        // 5. Precios
        $precioNormal = floatval($variationData['PRECIO_NORMAL']);
        $precioDescuento = isset($variationData['PRECIO_DESCUENTO']) ? floatval($variationData['PRECIO_DESCUENTO']) : 0.0;
        $variation->set_regular_price($precioNormal > 0 ? $precioNormal : '');
        if ($precioDescuento > 0 && $precioDescuento < $precioNormal) {
            $variation->set_sale_price($precioDescuento);
        } else {
            $variation->set_sale_price('');
        }

        // 6. Stock
        $stockQty = (int)$variationData['STOCK'];
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($stockQty);

        $hasStock = ($stockQty > 0);
        // Publicar la variación si hay precio > 0 y stock > 0
        $vstatus = ($precioNormal > 0 && $hasStock) ? 'publish' : 'draft';
        $variation->set_status($vstatus);
        $variation->set_stock_status($hasStock ? 'instock' : 'outofstock');

        // 7. Atributos de la variación
        $attributes = $this->getVariationAttributes($variationData);
        $variation->set_attributes($attributes);

        // 8. Imagen principal de la variación
        if (!empty($variationData['IMAGEN_PRINCIPAL'])) {
            $varImageURL = $this->uploadBaseUrl . $variationData['IMAGEN_PRINCIPAL'];
            $varImageId = $this->getImageId($varImageURL);
            if ($varImageId) {
                $variation->set_image_id($varImageId);
            }
        }

        // 9. Guardar
        $newVariationId = $variation->save();

        // 10. Guardar meta “_CODIGO_UNICO”
        update_post_meta($newVariationId, '_CODIGO_UNICO', $variationSku);

        return $newVariationId;
    }

    // ─────────────────────────────────────────────────────────────
    // Atributos
    // ─────────────────────────────────────────────────────────────

    /**
     * Prepara los atributos para producto “padre” (variable o simple).
     */
    private function prepareAttributesFromData($variationsData)
    {
        $first = $variationsData[0];
        $attributes = [];

        // Buscar claves que empiecen con NOMBRE_DE_ATRIBUTO_
        $attrNames = [];
        foreach ($first as $key => $value) {
            if (strpos($key, 'NOMBRE_DE_ATRIBUTO_') === 0) {
                $attrSlug = substr($key, strlen('NOMBRE_DE_ATRIBUTO_'));
                $attrNames[] = $attrSlug;
            }
        }

        foreach ($attrNames as $attrSlug) {
            $attrNameKey = "NOMBRE_DE_ATRIBUTO_{$attrSlug}";
            $attrValueKey = "VALOR_DE_ATRIBUTO_{$attrSlug}";
            $attrVisibleKey = "ATRIBUTO_VISIBLE_{$attrSlug}";
            $attrVarKeyEs = "ATRIBUTO_{$attrSlug}_ES_VARIABLE";
            $attrVarKey = "ATRIBUTO_{$attrSlug}_VARIABLE";

            // Validar que tenga nombre y valor
            if (!isset($first[$attrNameKey]) || !isset($first[$attrValueKey])) {
                continue;
            }

            $taxonomyName = 'pa_' . sanitize_title($first[$attrNameKey]);

            // Determinar si es variable
            $isVariable = false;
            if (isset($first[$attrVarKeyEs])) {
                $isVariable = (strtolower($first[$attrVarKeyEs]) == 'yes');
            } elseif (isset($first[$attrVarKey])) {
                $isVariable = (strtolower($first[$attrVarKey]) == 'yes');
            }

            // Determinar si es visible
            $isVisible = false;
            if (isset($first[$attrVisibleKey])) {
                $isVisible = (strtolower($first[$attrVisibleKey]) == 'yes');
            }

            // Crear atributo global si no existe
            if (!taxonomy_exists($taxonomyName)) {
                $attrLabel = ucwords(str_replace('-', ' ', str_replace('pa_', '', $taxonomyName)));
                $attribute_data = [
                    'slug'         => str_replace('pa_', '', $taxonomyName),
                    'name'         => $attrLabel,
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ];

                $attribute_id = wc_create_attribute($attribute_data);
                if (is_wp_error($attribute_id)) {
                    error_log("Error al crear el atributo global {$taxonomyName}: " . $attribute_id->get_error_message());
                    continue;
                }

                flush_rewrite_rules();

                if (!taxonomy_exists($taxonomyName)) {
                    error_log("La taxonomía {$taxonomyName} no existe tras intentar crearla.");
                    continue;
                }
            }

            // Recolectar valores de ese atributo entre todas las variaciones
            $values = [];
            foreach ($variationsData as $vd) {
                if (!empty($vd[$attrValueKey])) {
                    $values[] = $vd[$attrValueKey];
                }
            }
            $values = array_unique($values);

            // Insertar/obtener terms
            $term_ids = [];
            foreach ($values as $value) {
                $term = get_term_by('name', $value, $taxonomyName);
                if (!$term) {
                    $inserted = wp_insert_term($value, $taxonomyName);
                    if (is_wp_error($inserted)) {
                        error_log("Error al insertar el término {$value} en {$taxonomyName}: " . $inserted->get_error_message());
                        continue;
                    }
                    $term_id = $inserted['term_id'];
                } else {
                    $term_id = $term->term_id;
                }
                $term_ids[] = $term_id;
            }

            // Crear WC_Product_Attribute
            $att_id = wc_attribute_taxonomy_id_by_name($taxonomyName);
            $attribute = new \WC_Product_Attribute();
            $attribute->set_id($att_id);
            $attribute->set_name($taxonomyName);
            $attribute->set_options($term_ids);
            $attribute->set_visible($isVisible);
            $attribute->set_variation($isVariable);

            $attributes[] = $attribute;
        }

        return $attributes;
    }

    /**
     * Atributos de la variación (más simple).
     */
    private function getVariationAttributes($variationData)
    {
        $variationAttributes = [];
        // Buscar todos los nombres de atributo
        $attrNames = [];
        foreach ($variationData as $key => $value) {
            if (strpos($key, 'NOMBRE_DE_ATRIBUTO_') === 0) {
                $attrSlug = substr($key, strlen('NOMBRE_DE_ATRIBUTO_'));
                $attrNames[] = $attrSlug;
            }
        }

        foreach ($attrNames as $attrSlug) {
            $attrNameKey = "NOMBRE_DE_ATRIBUTO_{$attrSlug}";
            $attrValueKey = "VALOR_DE_ATRIBUTO_{$attrSlug}";
            if (!isset($variationData[$attrNameKey]) || !isset($variationData[$attrValueKey])) {
                continue;
            }

            $taxonomyName = 'pa_' . sanitize_title($variationData[$attrNameKey]);
            $value = $variationData[$attrValueKey];
            if (empty($value)) {
                continue;
            }

            // Insertar/obtener term
            $term = get_term_by('name', $value, $taxonomyName);
            if (!$term) {
                $inserted = wp_insert_term($value, $taxonomyName);
                if (is_wp_error($inserted)) {
                    error_log("Error al insertar el término {$value} en {$taxonomyName}: " . $inserted->get_error_message());
                    continue;
                }
                $term = get_term($inserted['term_id'], $taxonomyName);
            }

            // Asignar al array de atributos: ej. [ 'pa_color' => 'azul' ]
            $variationAttributes[$taxonomyName] = $term->slug;
        }

        return $variationAttributes;
    }

    // ─────────────────────────────────────────────────────────────
    // Imágenes, categorías
    // ─────────────────────────────────────────────────────────────

    /**
     * Retorna true si se encontró la imagen principal, false si no.
     */
private function addProductImages($product, $productData)
{
    $foundImage1 = false;

    // Imagen principal
    if (!empty($productData['IMAGEN_PRINCIPAL'])) {
        $imageURL = $this->uploadBaseUrl . $productData['IMAGEN_PRINCIPAL'];
        $imageId = $this->getImageId($imageURL);
        if ($imageId) {
            $product->set_image_id($imageId);
            $foundImage1 = true;
        }
    }

    // Galería - Combinar IMAGEN_SECUNDARIA + OTRAS_IMAGENES
    $galleryImages = [];
    
    // 1. Agregar IMAGEN_SECUNDARIA si existe
    if (!empty($productData['IMAGEN_SECUNDARIA'])) {
        $galleryImages[] = $productData['IMAGEN_SECUNDARIA'];
    }
    
    // 2. Agregar OTRAS_IMAGENES si existen
    if (!empty($productData['OTRAS_IMAGENES'])) {
        $others = explode('|', rtrim($productData['OTRAS_IMAGENES'], '|'));
        $galleryImages = array_merge($galleryImages, $others);
    }

    // Procesar todas las imágenes de la galería
    $galleryIds = [];
    foreach ($galleryImages as $imageFile) {
        $imgId = $this->getImageId($this->uploadBaseUrl . $imageFile);
        if ($imgId) {
            $galleryIds[] = $imgId;
        }
    }
    
    $product->set_gallery_image_ids($galleryIds);

    return $foundImage1;
}

    private function getImageId($imageUrl)
    {
        global $wpdb;
        $attachment = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE guid = %s",
            $imageUrl
        ));
        return $attachment ? (int)$attachment[0] : null;
    }

    private function setProductCategories($product, $productData)
    {
        if (!empty($productData['CATEGORIAS_CONCATENADAS'])) {
            $categories = explode('>', $productData['CATEGORIAS_CONCATENADAS']);
            $categoryIds = [];

            foreach ($categories as $catName) {
                $catName = trim($catName);
                if (empty($catName)) {
                    continue;
                }

                $term = get_term_by('name', $catName, 'product_cat');
                if (!$term) {
                    $term = wp_insert_term($catName, 'product_cat');
                    if (is_wp_error($term)) {
                        error_log("Error al insertar categoría $catName: " . $term->get_error_message());
                        continue;
                    }
                    $termId = $term['term_id'];
                } else {
                    $termId = $term->term_id;
                }

                $categoryIds[] = $termId;
            }
            $product->set_category_ids($categoryIds);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Manejo de variaciones existentes / draft
    // ─────────────────────────────────────────────────────────────

    /**
     * Retorna un array [sku => variationId] de las variaciones existentes en el padre.
     */
    private function getExistingVariations($parentId)
    {
        $product = wc_get_product($parentId);
        if (!$product || !$product->is_type('variable')) {
            return [];
        }
        $variations = $product->get_children();
        $map = [];
        foreach ($variations as $variationId) {
            $varProduct = wc_get_product($variationId);
            if ($varProduct) {
                $varSku = $varProduct->get_sku();
                if ($varSku) {
                    $map[$varSku] = $variationId;
                }
            }
        }
        return $map;
    }

    private function draftMissingVariations($parentId, array $currentVariationSkus)
    {
        $product = wc_get_product($parentId);
        if (!$product) {
            return;
        }
        foreach ($product->get_children() as $varId) {
            $varProduct = wc_get_product($varId);
            if ($varProduct) {
                $varSku = $varProduct->get_sku();
                // Si esta variación no llegó en la sincronización actual, la pasamos a draft
                if ($varSku && !in_array($varSku, $currentVariationSkus)) {
                    $varProduct->set_status('draft');
                    $varProduct->save();
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Otros helpers (opcionales)
    // ─────────────────────────────────────────────────────────────

    public function draftVariantByUniqueCode($uniqueCode)
    {
        $args = [
            'post_type'      => 'product_variation',
            'meta_query'     => [
                [
                    'key'     => '_sku',
                    'value'   => $uniqueCode,
                    'compare' => '=',
                ],
            ],
            'posts_per_page' => 1,
        ];
        $query = new \WP_Query($args);
        if ($query->have_posts()) {
            $var_id = $query->posts[0]->ID;
            $variation = wc_get_product($var_id);
            if ($variation) {
                $variation->set_status('draft');
                $variation->save();
            }
        }
    }

    public function draftProductBySku($sku)
    {
        $productId = wc_get_product_id_by_sku($sku);
        if ($productId) {
            $product = wc_get_product($productId);
            if ($product) {
                $product->set_status('draft');
                $product->save();
            }
        }
    }

    /**
     * Pasa a draft los productos que no estén en la lista de $syncedSkus.
     */
    public function draftMissingProducts(array $syncedSkus)
    {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
        ];

        $allProducts = get_posts($args);
        foreach ($allProducts as $pId) {
            $p = wc_get_product($pId);
            if ($p) {
                $sku = $p->get_sku();
                if ($sku && !in_array($sku, $syncedSkus)) {
                    $p->set_status('draft');
                    $p->save();
                }
            }
        }
    }
}
