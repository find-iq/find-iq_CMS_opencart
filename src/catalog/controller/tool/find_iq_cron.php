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

            // Dedicated cron log for per-portion timeline
            $cron_log = new Log('find_iq_integration_cron.log');
            $run_id = 'run-' . date('Ymd\THis') . '-' . substr(uniqid('', true), -6);

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

            // Parallel sending of fixed-size batches
            $batch_size = 100; // items per batch (API batch size)
            $parallel_batches = 4; // how many batches to send in parallel

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
                    'batch_size' => $batch_size,
                    'parallel_batches' => $parallel_batches,
                    'offset_hours' => $offset_hours,
                ]);
            }

            $this->categories = $this->model_tool_find_iq_cron->getAllCategories();


            $have_products_to_update = true;
            $products = [];


            while ($have_products_to_update === true) {
                $desired = $batch_size * $parallel_batches;
                $request_limit = ($mode === 'fast') ? max(1, (int)ceil($desired / 10)) : $desired;
                $to_update = $this->getProductsListToSync($mode, $request_limit, $offset_hours);
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

                foreach ($products as &$product) {
                    $product = $this->removeNullValues($product);
                }

                $forming_started_ts = microtime(true);
                $chunks = array_chunk($products, $batch_size);
                if (count($chunks) > $parallel_batches) {
                    $chunks = array_slice($chunks, 0, $parallel_batches);
                }

                // Log per-portion forming and ready states
                $portion_count = count($chunks) > 0 ? count($chunks) : 1;
                if ($portion_count > 1) {
                    foreach ($chunks as $idx => $chunk) {
                        // forming started (for this portion)
                        $cron_log->write(json_encode([
                            'event' => 'portion_forming_started',
                            'run_id' => $run_id,
                            'portion_index' => $idx + 1,
                            'portion_count' => $portion_count,
                            'batch_size' => count($chunk),
                            'time_iso' => date('c', (int)$forming_started_ts),
                            'time_unix_ms' => (int)round($forming_started_ts * 1000),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        // ready to send
                        $ready_ts = microtime(true);
                        $cron_log->write(json_encode([
                            'event' => 'portion_ready_to_send',
                            'run_id' => $run_id,
                            'portion_index' => $idx + 1,
                            'portion_count' => $portion_count,
                            'batch_size' => count($chunk),
                            'time_iso' => date('c'),
                            'time_unix_ms' => (int)round($ready_ts * 1000),
                            'delta_ms_since_forming' => (int)round(($ready_ts - $forming_started_ts) * 1000),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                } else {
                    // Single portion in this iteration (no parallel split)
                    $cron_log->write(json_encode([
                        'event' => 'portion_forming_started',
                        'run_id' => $run_id,
                        'portion_index' => 1,
                        'portion_count' => 1,
                        'batch_size' => count($products),
                        'time_iso' => date('c', (int)$forming_started_ts),
                        'time_unix_ms' => (int)round($forming_started_ts * 1000),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $ready_ts = microtime(true);
                    $cron_log->write(json_encode([
                        'event' => 'portion_ready_to_send',
                        'run_id' => $run_id,
                        'portion_index' => 1,
                        'portion_count' => 1,
                        'batch_size' => count($products),
                        'time_iso' => date('c'),
                        'time_unix_ms' => (int)round($ready_ts * 1000),
                        'delta_ms_since_forming' => (int)round(($ready_ts - $forming_started_ts) * 1000),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                if (empty($chunks)) {
                    $chunks = [$products];
                }
                // Always use multi-sender, even for a single portion
                $this->FindIQ->postProductsBatchMulti($chunks);

                $can_mark_synced = false;
                try {
                    $ids_map = $this->FindIQ->getProductFindIqIds(array_column($products, 'product_id_ext'));
                    $this->model_tool_find_iq_cron->updateProductsFindIqIds($ids_map);
                    $can_mark_synced = true;
                } catch (Exception $e) {
                    // Log and skip marking synced for this iteration; continue processing
                    if (isset($cron_log)) {
                        $cron_log->write(json_encode([
                            'event' => 'mapping_failed',
                            'error' => $e->getMessage(),
                            'time_iso' => date('c'),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                }

                if ($can_mark_synced) {
                    $this->model_tool_find_iq_cron->markProductsAsSynced(array_column($products, 'product_id_ext'), $mode, $this->now);
                }

                $processed += count($products);

                if ($is_stream) {
                    $progress = ($total > 0) ? min(100, round(($processed / $total) * 100, 2)) : null;
                    $this->sendSseEvent('progress', [
                        'processed' => $processed,
                        'last_batch' => count($products),
                        'chunks' => count($chunks),
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

    private function removeNullValues($array)
    {
        foreach ($array as $key => $value) {
            if (is_null($value)) {
                unset($array[$key]);
            } elseif (is_array($value)) {
                $array[$key] = $this->removeNullValues($value);
            }
        }
        return $array;
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
        // Ensure we have an array of products
        if (!is_array($products) || empty($products)) {
            return;
        }

        $this->load->model('tool/find_iq_cron');

        $site_languages = $this->model_tool_find_iq_cron->getAllLanguages();
        if (!is_array($site_languages)) {
            $site_languages = [];
        }

        // Normalize site language codes (e.g., 'uk-ukr' -> 'uk')
        foreach ($site_languages as &$language) {
            if (isset($language['code']) && is_string($language['code'])) {
                $language['code'] = substr($language['code'], 0, -3);
            }
        }
        unset($language);

        foreach ($products as &$product) {
            // Descriptions: map language_id from numeric to code, then to FindIQ ID
            if (isset($product['descriptions']) && is_array($product['descriptions'])) {
                foreach ($product['descriptions'] as &$description) {
                    if (isset($description['language_id'])) {
                        // First, swap numeric site language_id -> language code
                        foreach ($site_languages as $site_language) {
                            if (isset($site_language['language_id']) && $site_language['language_id'] == $description['language_id']) {
                                if (isset($site_language['code'])) {
                                    $description['language_id'] = $site_language['code'];
                                }
                                break;
                            }
                        }
                        // Then, map language code -> FindIQ numeric ID if available
                        if (is_string($description['language_id']) && isset($this->FindIQLanguages[$description['language_id']])) {
                            $description['language_id'] = $this->FindIQLanguages[$description['language_id']];
                        }
                    }
                }
                unset($description);
            }

            // Attributes: same mapping steps, but only if attributes is an array
            if (isset($product['attributes']) && is_array($product['attributes'])) {
                foreach ($product['attributes'] as &$attribute) {
                    if (isset($attribute['language_id'])) {
                        foreach ($site_languages as $site_language) {
                            if (isset($site_language['language_id']) && $site_language['language_id'] == $attribute['language_id']) {
                                if (isset($site_language['code'])) {
                                    $attribute['language_id'] = $site_language['code'];
                                }
                                break;
                            }
                        }
                        if (is_string($attribute['language_id']) && isset($this->FindIQLanguages[$attribute['language_id']])) {
                            $attribute['language_id'] = $this->FindIQLanguages[$attribute['language_id']];
                        }
                    }
                }
                unset($attribute);
            }
        }
        unset($product);
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