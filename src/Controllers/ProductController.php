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
        echo '<h1>Sincronizar Productos / Revisar Borradores</h1>';
        echo '<form method="post" style="margin-bottom: 20px;">';
        echo '<button type="submit" name="sync" class="button button-primary">Sincronizar Productos (sub-llamadas)</button> ';
        echo '<button type="submit" name="check_borradores" class="button">Revisar Borradores sin Imagen</button>';
        echo '</form>';

        // Manejo de los POST
        if (isset($_POST['sync'])) {
            try {
                error_log("[ColibriSync][ProductController] Botón 'Sincronizar Productos' pulsado. Iniciando sub-llamadas.");
                $this->startManualSyncSequence();
                echo '<div class="updated"><p>Se inició la sincronización en lotes. Revisa el log para ver el progreso.</p></div>';
            } catch (\Exception $e) {
                echo '<div class="error"><p>Error: ' . $e->getMessage() . '</p></div>';
                error_log("[ColibriSync][ProductController] Error al iniciar sub-llamadas: " . $e->getMessage());
            }
        }

        if (isset($_POST['check_borradores'])) {
            try {
                error_log("[ColibriSync][ProductController] Botón 'Revisar Borradores' pulsado.");
                $this->manualCheckDraftNoImageProducts();
                echo '<div class="updated"><p>¡Verificación de borradores sin imagen completada!</p></div>';
            } catch (\Exception $e) {
                echo '<div class="error"><p>Error en borradores: ' . $e->getMessage() . '</p></div>';
                error_log("[ColibriSync][ProductController] Error en borradores: " . $e->getMessage());
            }
        }
    }

    /**
     * Inicia la secuencia de sub-llamadas (lotes) para la sincronización manual
     */
  private function startManualSyncSequence() {
    // Cambiar el offset inicial a 12500
    $initialOffset = 14000; // ◀️ Valor hardcodeado aquí
    update_option('colibri_sync_manual_offset', $initialOffset);
    
    // Programar primera subllamada con el offset inicial
    wp_schedule_single_event(
        time(),
        'colibri_sync_manual_batch',
        [ [ 'offset' => $initialOffset ] ]
    );
    
    error_log("Sincronización manual iniciada desde offset: $initialOffset");
}

    /**
     * Fuerza la lógica de checkDraftNoImageProducts() manualmente.
     */
    private function manualCheckDraftNoImageProducts() {
        if (class_exists('ColibriSync')) {
            $instance = \ColibriSync::getInstance();
            if (method_exists($instance, 'checkDraftNoImageProducts')) {
                error_log("[ColibriSync][ProductController] Llamando checkDraftNoImageProducts() en ColibriSync.");
                $instance->checkDraftNoImageProducts();
            } else {
                throw new \Exception('No se encontró el método checkDraftNoImageProducts en la clase principal.');
            }
        } else {
            throw new \Exception('No se encontró la clase ColibriSync.');
        }
    }
}
