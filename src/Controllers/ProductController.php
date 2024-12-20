<?php
namespace ColibriSync\Controllers;

use ColibriSync\Services\ProductService;

class ProductController {
    private $productService;

    public function __construct() {
        $this->productService = new ProductService();
        add_action('admin_menu', [$this, 'addAdminPage']);
    }

    public function addAdminPage() {
        add_menu_page(
            'Colibri Sync',
            'Colibri Sync',
            'manage_options',
            'colibri-sync',
            [$this, 'renderAdminPage'],
            'dashicons-update',
            20
        );
    }

    public function renderAdminPage() {
        echo '<h1>Sincronizar Productos</h1>';
        echo '<form method="post">';
        echo '<button type="submit" name="sync" class="button button-primary">Sincronizar Productos</button>';
        echo '</form>';

        if (isset($_POST['sync'])) {
            try {
                $this->productService->syncProducts();
                echo '<div class="updated"><p>¡Sincronización completada con éxito!</p></div>';
            } catch (\Exception $e) {
                echo '<div class="error"><p>Error: ' . $e->getMessage() . '</p></div>';
            }
        }
    }
}
