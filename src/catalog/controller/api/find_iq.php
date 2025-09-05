<?php

class ControllerApiFindIq extends Controller {
    public function checkProductInCart(){
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode(
            ['product_in_cart' => $this->cart->checkProductInCartExistance($this->request->get['product_id'] ?? 0)]
        ));
    }
}