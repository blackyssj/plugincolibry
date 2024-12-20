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

    public function saveSimpleProduct($data) {
        $productId = wc_get_product_id_by_sku($data['CODIGO_SKU']);
        if ($productId) {
            $product = wc_get_product($productId);
            if (!$product || $product->is_type('variable')) {
                wp_trash_post($productId);
                $product = new WC_Product_Simple();
            }
        } else {
            $product = new WC_Product_Simple();
        }

        $product->set_name($data['TITULO']);
        $product->set_description($data['DESCRIPCION_CORTA'] ?? '');
        $product->set_sku($data['CODIGO_SKU']);

        $precioNormal = floatval($data['PRECIO_NORMAL']);
        $precioDescuento = isset($data['PRECIO_DESCUENTO']) ? floatval($data['PRECIO_DESCUENTO']) : 0.0;

        $product->set_regular_price($precioNormal > 0 ? $precioNormal : '');
        if ($precioDescuento > 0 && $precioDescuento < $precioNormal) {
            $product->set_sale_price($precioDescuento);
        }

        $stockQty = (int)$data['STOCK'];
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stockQty);
        $product->set_low_stock_amount(isset($data['STOCK_MINIMO']) ? (int)$data['STOCK_MINIMO'] : '');

        // Seteo de meta _CODIGO_UNICO
        update_post_meta($product->get_id(), '_CODIGO_UNICO', $data['CODIGO_UNICO']);

        // Imágenes y categorías antes de decidir estado
        $imagesFound = $this->addProductImages($product, $data);
        $this->setProductCategories($product, $data);

        // Atributos
        $attributes = $this->prepareAttributesFromData([$data]);
        $product->set_attributes($attributes);

        // Determinar estado final del producto
        $status = 'publish';

        // Reglas de borrador:
        // - No hay imagen principal o PRECIO=0
        if (!$imagesFound || $precioNormal <= 0) {
            $status = 'draft';
        }

        // Stock 0 => draft
        if ($stockQty < 1) {
            $status = 'draft';
        }

        $product->set_status($status);

        $product->save();
    }

    public function saveVariableProduct($sku, $variationsData) {
        $first = $variationsData[0];

        $productId = wc_get_product_id_by_sku($sku);
        if ($productId) {
            $product = wc_get_product($productId);
            if (!$product || !$product->is_type('variable')) {
                wp_trash_post($productId);
                $product = new WC_Product_Variable();
            }
        } else {
            $product = new WC_Product_Variable();
        }

        $product->set_name($first['TITULO']);
        $product->set_sku($sku);
        $product->set_description($first['DESCRIPCION_CORTA'] ?? '');
        $product->set_manage_stock(false);

        // CODIGO_UNICO del padre
        update_post_meta($product->get_id(), '_CODIGO_UNICO', $first['CODIGO_UNICO']);

        $imagesFound = $this->addProductImages($product, $first);
        $this->setProductCategories($product, $first);

        $parentAttributes = $this->prepareAttributesFromData($variationsData);
        $product->set_attributes($parentAttributes);
        $productId = $product->save();

        $existingVariations = $this->getExistingVariations($productId);
        $currentVariationSkus = [];
        $publishedVariationsCount = 0;

        foreach ($variationsData as $variationData) {
            try {
                $varSku = $variationData['CODIGO_UNICO'];
                $newVariationId = $this->createOrUpdateVariation($productId, $variationData, $existingVariations);
                $currentVariationSkus[] = $varSku;

                // Verificar si la variación quedó publicada
                $varProduct = wc_get_product($newVariationId);
                if ($varProduct && $varProduct->get_status() === 'publish') {
                    $publishedVariationsCount++;
                }
            } catch (\Exception $e) {
                error_log('Error procesando variación ' . $variationData['CODIGO_UNICO'] . ' del producto ' . $sku . ': ' . $e->getMessage());
                $this->draftVariantByUniqueCode($variationData['CODIGO_UNICO']);
            }
        }

        $this->draftMissingVariations($productId, $currentVariationSkus);

        // Determinar estado del producto variable
        $status = 'publish';

        if (!$imagesFound) {
            $status = 'draft';
        }

        if ($publishedVariationsCount == 0) {
            $status = 'draft';
        }

        $product->set_status($status);
        $product->save();

        do_action('woocommerce_product_set_stock', $product);
    }

    /**
     * Detecta todos los atributos en el array de productos. Un atributo se define por:
     * - NOMBRE_DE_ATRIBUTO_X
     * - VALOR_DE_ATRIBUTO_X
     * - ATRIBUTO_VISIBLE_X
     * - ATRIBUTO_X_ES_VARIABLE o ATRIBUTO_X_VARIABLE
     *
     * Retorna un array de WC_Product_Attribute listo para asignar al producto padre.
     */
    private function prepareAttributesFromData($variationsData) {
        $first = $variationsData[0];
        $attributes = [];

        // Extraer todos los atributos presentes
        // Patrón: NOMBRE_DE_ATRIBUTO_*
        $attrNames = [];
        foreach ($first as $key => $value) {
            if (strpos($key, 'NOMBRE_DE_ATRIBUTO_') === 0) {
                $attrSlug = substr($key, strlen('NOMBRE_DE_ATRIBUTO_')); // Ejemplo: COLOR, TALLA...
                $attrNames[] = $attrSlug;
            }
        }

        foreach ($attrNames as $attrSlug) {
            $attrNameKey = "NOMBRE_DE_ATRIBUTO_{$attrSlug}";
            $attrValueKey = "VALOR_DE_ATRIBUTO_{$attrSlug}";
            $attrVisibleKey = "ATRIBUTO_VISIBLE_{$attrSlug}";
            $attrVarKeyEs = "ATRIBUTO_{$attrSlug}_ES_VARIABLE";
            $attrVarKey = "ATRIBUTO_{$attrSlug}_VARIABLE";

            if (!isset($first[$attrNameKey]) || !isset($first[$attrValueKey])) {
                continue;
            }

            $taxonomyName = 'pa_' . sanitize_title($first[$attrNameKey]);

            $isVariable = false;
            if (isset($first[$attrVarKeyEs])) {
                $isVariable = (strtolower($first[$attrVarKeyEs]) == 'yes');
            } elseif (isset($first[$attrVarKey])) {
                $isVariable = (strtolower($first[$attrVarKey]) == 'yes');
            }

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

            // Obtener todos los valores para este atributo desde todas las variaciones
            $values = [];
            foreach ($variationsData as $vd) {
                if (isset($vd[$attrValueKey]) && !empty($vd[$attrValueKey])) {
                    $values[] = $vd[$attrValueKey];
                }
            }
            $values = array_unique($values);

            $term_ids = [];
            foreach ($values as $value) {
                $term = get_term_by('name', $value, $taxonomyName);
                if (!$term) {
                    $inserted = wp_insert_term($value, $taxonomyName);
                    if (is_wp_error($inserted)) {
                        error_log("Error al insertar el término $value en $taxonomyName: " . $inserted->get_error_message());
                        continue;
                    }
                    $term_id = $inserted['term_id'];
                } else {
                    $term_id = $term->term_id;
                }
                $term_ids[] = $term_id;
            }

            $att_id = wc_attribute_taxonomy_id_by_name($taxonomyName);
            $attribute = new WC_Product_Attribute();
            $attribute->set_id($att_id);
            $attribute->set_name($taxonomyName);
            $attribute->set_options($term_ids);
            $attribute->set_visible($isVisible);
            $attribute->set_variation($isVariable);

            $attributes[] = $attribute;
        }

        return $attributes;
    }

    private function createOrUpdateVariation($parentId, $variationData, $existingVariations) {
        $variationSku = $variationData['CODIGO_UNICO'];
        $variationId = isset($existingVariations[$variationSku]) ? $existingVariations[$variationSku] : null;

        if ($variationId) {
            $variation = wc_get_product($variationId);
        } else {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parentId);
        }

        $variation->set_sku($variationSku);

        $precioNormal = floatval($variationData['PRECIO_NORMAL']);
        $precioDescuento = isset($variationData['PRECIO_DESCUENTO']) ? floatval($variationData['PRECIO_DESCUENTO']) : 0.0;
        $variation->set_regular_price($precioNormal > 0 ? $precioNormal : '');
        if ($precioDescuento > 0 && $precioDescuento < $precioNormal) {
            $variation->set_sale_price($precioDescuento);
        } else {
            $variation->set_sale_price('');
        }

        $stockQty = (int)$variationData['STOCK'];
        $variation->set_manage_stock(true);
        $variation->set_stock_quantity($stockQty);

        if (isset($variationData['STOCK_MINIMO'])) {
            $variation->set_low_stock_amount((int)$variationData['STOCK_MINIMO']);
        }

        $hasStock = ($stockQty > 0);
        // Variación en draft si no tiene precioNormal o si stock 0
        $vstatus = 'publish';
        if ($precioNormal <= 0 || !$hasStock) {
            $vstatus = 'draft';
        }

        $variation->set_stock_status($hasStock ? 'instock' : 'outofstock');
        $variation->set_status($vstatus);

        $attributes = $this->getVariationAttributes($variationData);
        $variation->set_attributes($attributes);

        // Asignación de imagen a la variación
        if (!empty($variationData['IMAGEN_PRINCIPAL'])) {
            $varImageURL = $this->uploadBaseUrl . $variationData['IMAGEN_PRINCIPAL'];
            $varImageId = $this->getImageId($varImageURL);
            if ($varImageId) {
                $variation->set_image_id($varImageId);
            } else {
                // Si requieres que la variación con imagen no encontrada sea draft, puedes hacerlo:
                // $variation->set_status('draft');
            }
        }

        $newVariationId = $variation->save();

        // Asignar CODIGO_UNICO
        update_post_meta($newVariationId, '_CODIGO_UNICO', $variationSku);

        return $newVariationId;
    }

    /**
     * Similar a prepareAttributesFromData, pero para la variación. Sin embargo, aquí solo usamos las claves ya detectadas.
     * En este caso, sólo asignamos las variaciones para los atributos detectados (COLOR, TALLA, MATERIAL, MARCA, etc.).
     */
    private function getVariationAttributes($variationData) {
        // Detectar atributos presentes en esta variación
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

            $term = get_term_by('name', $value, $taxonomyName);
            if (!$term) {
                $inserted = wp_insert_term($value, $taxonomyName);
                if (is_wp_error($inserted)) {
                    error_log("Error al insertar el término $value en $taxonomyName: " . $inserted->get_error_message());
                    continue;
                }
                $term = get_term($inserted['term_id'], $taxonomyName);
            }

            $variationAttributes[$taxonomyName] = $term->slug;
        }

        return $variationAttributes;
    }

    /**
     * Retorna true si se encontró la imagen principal, false si no.
     */
    private function addProductImages($product, $productData) {
        $foundImage1 = false;

        if (!empty($productData['IMAGEN_PRINCIPAL'])) {
            $imageURL = $this->uploadBaseUrl . $productData['IMAGEN_PRINCIPAL'];
            $imageId = $this->getImageId($imageURL);
            if ($imageId) {
                $product->set_image_id($imageId);
                $foundImage1 = true;
            }
        }

        $galleryImages = [];
        if (!empty($productData['OTRAS_IMAGENES'])) {
            $otherImages = explode('|', rtrim($productData['OTRAS_IMAGENES'], '|'));
            foreach ($otherImages as $image) {
                $imgId = $this->getImageId($this->uploadBaseUrl . $image);
                if ($imgId) {
                    $galleryImages[] = $imgId;
                }
            }
        }
        $product->set_gallery_image_ids($galleryImages);

        return $foundImage1;
    }

    private function getImageId($imageUrl) {
        global $wpdb;
        $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid = %s", $imageUrl));
        return $attachment ? (int)$attachment[0] : null;
    }

    private function setProductCategories($product, $productData) {
        if (!empty($productData['CATEGORIAS_CONCATENADAS'])) {
            $categories = explode('>', $productData['CATEGORIAS_CONCATENADAS']);
            $categoryIds = [];

            foreach ($categories as $catName) {
                $catName = trim($catName);
                if (empty($catName)) continue;

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

    private function getExistingVariations($parentId) {
        $product = wc_get_product($parentId);
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

    public function draftVariantByUniqueCode($uniqueCode) {
        $args = [
            'post_type' => 'product_variation',
            'meta_query' => [
                [
                    'key' => '_sku',
                    'value' => $uniqueCode,
                    'compare' => '='
                ]
            ],
            'posts_per_page' => 1
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

    private function draftMissingVariations($parentId, array $currentVariationSkus) {
        $product = wc_get_product($parentId);
        if (!$product) return;

        foreach ($product->get_children() as $varId) {
            $varProduct = wc_get_product($varId);
            if ($varProduct) {
                $varSku = $varProduct->get_sku();
                if ($varSku && !in_array($varSku, $currentVariationSkus)) {
                    $varProduct->set_status('draft');
                    $varProduct->save();
                }
            }
        }
    }

    public function draftProductBySku($sku) {
        $productId = wc_get_product_id_by_sku($sku);
        if ($productId) {
            $product = wc_get_product($productId);
            if ($product) {
                $product->set_status('draft');
                $product->save();
            }
        }
    }

    public function draftMissingProducts(array $syncedSkus) {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => ['publish', 'draft', 'pending', 'private']
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

