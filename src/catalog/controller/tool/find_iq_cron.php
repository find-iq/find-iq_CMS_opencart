<?php

class ControllerToolFindIQCron extends Controller
{
    private $now;

    public function index()
    {

        // {1: 'uk', 2: 'en', 3: 'ru', 4: 'pl', 5: 'de'}

        if ($this->config->get('module_find_iq_integration_status') == '1') {

            $this->now = (new DateTime())->getTimestamp();

            $mode = $this->request->get['mode'] ?? 'fast';

            $config = $this->config->get('module_find_iq_integration_config');

            $this->load->model('tool/image');
            $this->load->model('tool/find_iq_cron');

            $this->model_tool_find_iq_cron->prepareTempTable();

            if ($mode == 'fast') {
                $offset_hours = $config['fast_reindex_timeout'] ?? 4;
            } else {
                $offset_hours = $config['full_reindex_timeout'] ?? 24;
            }

            $have_products_to_update = false;

            $to_update = $this->getProductsListToSync($mode, 10, $offset_hours);

            $have_products_to_update = count($to_update['products']) > 0;

            if (!$have_products_to_update) {
                exit('no products to update');
            }


            $products = $this->model_tool_find_iq_cron->getProductsBatchOptimized(
                $to_update['products'],
                1
            );

            foreach ($products as &$product) {
                $product['image'] = $this->model_tool_image->resize($product['image'], $config['resize-width'] ?? '200', $config['resize-height'] ?? '200');
                $product['url_product'] = $this->url->link('product/product', 'product_id=' . $product['product_id_ext']);
            }


//            echo json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            print_r($products);


        } else {
            echo 'disabled';
        }


    }


    private function getProductsListToSync($mode = 'fast', $limit = 100, int $offset_hours = 2)
    {
        if ($offset_hours < 1) {
            trigger_error('offset_hours must be greater than 0', E_USER_ERROR);
            if ($mode == 'fast') {
                $offset_hours = 2;
            } else {
                $offset_hours = 24;
            }
        }

        if ($limit < 1) {
            trigger_error('limit must be greater than 0', E_USER_ERROR);
            $limit = 100;
        }
        if ($mode == 'fast') {
            $limit *= 10;
        }

        if (!in_array($mode, ['fast', 'full'])) {
            trigger_error('mode must be either "fast" or "full"', E_USER_ERROR);
            $mode = 'fast';
        }

        $this->now = (new DateTime())->getTimestamp();

        // тут ми маємо межу за якою можемо визначити які товари брати, а які ні. Згідно часу їх останнього оновлення
        $time_limit = $this->now - ($offset_hours * 60 * 60);

        $this->load->model('tool/find_iq_cron');
        return $this->model_tool_find_iq_cron->getProductsListToSync($time_limit, $limit, $mode);


    }
}