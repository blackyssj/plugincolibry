<?php
namespace ColibriSync\Controllers;

use ColibriSync\Services\CheckoutService;

class CheckoutController {
    protected $checkoutService;

    public function __construct() {
        $this->checkoutService = new CheckoutService();
    }

    public function beforeCheckoutProcess() {
        $this->checkoutService->verifyStockBeforeCheckout();
    }

    public function onOrderProcessing($order_id) {
        $this->checkoutService->registerSaleInColibri($order_id);
    }
}
