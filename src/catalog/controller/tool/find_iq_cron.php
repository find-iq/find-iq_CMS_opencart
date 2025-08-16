<?php

class ControllerToolFindIQCron extends Controller
{
    private $now;

    private $categories;

    // TODO: Ця херня має бути в паблік апі, і взагалі чого там айді, а не коди мов?
    private $FindIQLanguages = [
        'uk' => 1,
        'en' => 2,
        'ru' => 3,
        'pl' => 4,
        'de' => 5,
    ];

    public function index()
    {

        if ($this->config->get('module_find_iq_integration_status') == '1') {

            $this->load->library('FindIQ');

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

            // Streaming mode detection (SSE)
            $is_stream = false;
            if (isset($this->request->get['stream']) && (int)$this->request->get['stream'] === 1) {
                $is_stream = true;
            } elseif (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'text/event-stream') !== false) {
                $is_stream = true;
            }

            $processed = 0;
            $initial_info = $this->getProductsListToSync($mode, 1, $offset_hours); // limit doesn't change total
            $total = isset($initial_info['total']) ? (int)$initial_info['total'] : 0;

            $batch_limit = 100;

            if ($is_stream) {
                // Setup headers for SSE
                if (!headers_sent()) {
                    header('Content-Type: text/event-stream');
                    header('Cache-Control: no-cache');
                    header('Connection: keep-alive');
                    header('X-Accel-Buffering: no'); // disable Nginx buffering if used
                }
                @ini_set('output_buffering', 'off');
                @ini_set('zlib.output_compression', '0');
                @set_time_limit(0);
                @ignore_user_abort(true);

                $this->sendSseEvent('start', [
                    'mode' => $mode,
                    'total' => $total,
                    'batch_limit' => $batch_limit,
                    'offset_hours' => $offset_hours,
                ]);
            }

            $this->categories = $this->model_tool_find_iq_cron->getAllCategories();


            $have_products_to_update = true;
            $products = [];


            while ($have_products_to_update === true) {
                $to_update = $this->getProductsListToSync($mode, $batch_limit, $offset_hours);
                $have_products_to_update = isset($to_update['products']) && count($to_update['products']) > 0;

                if (!$have_products_to_update) {
                    break;
                }

                $products = $this->model_tool_find_iq_cron->getProductsBatchOptimized(
                    $to_update['products'],
                    $mode
                );

                if($mode == 'full'){
                    foreach ($products as &$product) {

                        $this->swapLanguageId($products);

                        $product['image'] = $this->model_tool_image->resize($product['image'], $config['resize-width'] ?? '200', $config['resize-height'] ?? '200');
                        $product['url_product'] = $this->url->link('product/product', 'product_id=' . $product['product_id_ext']);

                        foreach ($this->getCategoryPath($product['category_id']) as $categoryId){
                            $category = $this->categories[$categoryId];
                            $category['url'] = $this->url->link('product/category', 'path=' . $category['id']);
                            unset($category['parent_id']);
                            unset($category['id']);

                            $product['categories'][] = $category;
                        }
                        unset($product['category_id']);
                    }

                    unset($product);
                }



                $this->FindIQ->postProductsBatch($products);

                $this->model_tool_find_iq_cron->updateProductsFindIqIds($this->FindIQ->getProductFindIqIds(array_column($products, 'product_id_ext')));

                $this->model_tool_find_iq_cron->markProductsAsSynced(array_column($products, 'product_id_ext'), $mode, $this->now);

                $processed += count($products);

                if ($is_stream) {
                    $progress = ($total > 0) ? min(100, round(($processed / $total) * 100, 2)) : null;
                    $this->sendSseEvent('progress', [
                        'processed' => $processed,
                        'last_batch' => count($products),
                        'total' => $total,
                        'progress' => $progress,
                    ]);
                }



                // Optionally output progress per batch to logs
                // echo 'Processed batch of ' . count($products) . PHP_EOL;
            }



            if ($is_stream) {
                $this->sendSseEvent('complete', [
                    'processed' => $processed,
                    'total' => $total,
                    'last_response' => $this->FindIQ->getLastResponse(),
                ]);
                // SSE connections should not send standard output footer
                return;
            }



        } else {
            echo 'disabled';
        }


    }

    private function getCategoryPath($categoryId) {
        $path = [];
        $currentId = $categoryId;

        // Рекурсивно піднімаємося по ієрархії до кореневої категорії
        while ($currentId && isset($this->categories[$currentId])) {
            $category = $this->categories[$currentId];

            // Додаємо поточний ID в початок масиву
            array_unshift($path, $currentId);

            // Якщо це коренева категорія (parent_id = 0), зупиняємося
            if ($category['parent_id'] == '0') {
                break;
            }

            // Переходимо до батьківської категорії
            $currentId = $category['parent_id'];
        }

        return $path;
    }


    private function swapLanguageId($products)
    {

        $this->load->model('tool/find_iq_cron');


        $site_languages = $this->model_tool_find_iq_cron->getAllLanguages();

        foreach ($site_languages as  &$language){
            $language['code'] = substr($language['code'] ,0, -3);
        }

        foreach ($products as &$product){
            foreach ($product['descriptions'] as &$description){
                foreach ($site_languages as $site_language){
                    if($site_language['language_id'] == $description['language_id']){
                        $description['language_id'] = $site_language['code'];
                    }
                }
            }

            foreach ($product['attributes'] as &$attribute){
                foreach ($site_languages as $site_language){
                    if($site_language['language_id'] == $attribute['language_id']){
                        $attribute['language_id'] = $site_language['code'];
                    }
                }
            }

            foreach ($product['descriptions'] as &$description){
                $description['language_id'] = $this->FindIQLanguages[$description['language_id']];
            }

            foreach ($product['attributes'] as &$attribute){
                $attribute['language_id'] = $this->FindIQLanguages[$attribute['language_id']];
            }
        }
    }

    private function sendSseEvent(string $event, $data): void
    {
        // $data can be any serializable type; we encode to JSON
        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo "event: {$event}\n";
        echo "data: {$payload}\n\n";
        // Explicitly flush output buffers
        if (function_exists('ob_flush')) { @ob_flush(); }
        flush();
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