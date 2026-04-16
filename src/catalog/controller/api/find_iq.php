<?php

class ControllerApiFindIq extends Controller {
    public function getProductsFromCart(){
        $json['products_in_cart'] = $this->cart->getProductsFromCart();

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}