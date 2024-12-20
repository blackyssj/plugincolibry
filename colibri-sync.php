<?php
/**
 * Plugin Name: Colibri Sync
 * Plugin URI: https://
 * Description: Sincroniza productos desde una API externa con WooCommerce.
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
        // Inicializa tu controlador principal si lo tienes
        new ProductController();
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
            // Si llega hasta aquí, la sincronización se realizó con éxito o con errores controlados.
        } catch (Exception $e) {
            // Error general inesperado
            error_log("Error general en la sincronización: " . $e->getMessage());
            // No detenemos el proceso, simplemente el cron volverá a ejecutarse en la siguiente pasada.
        }
    }
}

// Inicializa el plugin
ColibriSync::getInstance();