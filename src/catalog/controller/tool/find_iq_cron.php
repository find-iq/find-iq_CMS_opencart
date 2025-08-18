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

            $this->categories =  $this->model_tool_find_iq_cron->getAllCategories();

            if ($mode == 'full') {
                $this->FindIQ->postCategoriesBatch(
                    $this->prepareCategoriesForSync(
                        $this->categories
                    )
                );
            }

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

                if ($mode == 'full') {
                    $this->swapLanguageId($products, 'product');

                    foreach ($products as $product_key => $product) {

                        $product['image'] = $this->model_tool_image->resize($product['image'], $config['resize-width'] ?? '200', $config['resize-height'] ?? '200');
                        foreach ($product['descriptions'] as $product_description_key => $description) {
                            $this->config->set('config_language_id', $description['language_code']);
                            $products[$product_key]['descriptions'][$product_description_key]['url'] = $this->url->link('product/product', 'product_id=' . $product['product_id_ext']);
                        }


                        foreach ($this->getCategoryPath($product['category_id']) as $categoryId) {
                            $products[$product_key]['categories'][] = (string)$categoryId;
                        }
                        unset($products[$product_key]['category_id']);

                    }


                }

                foreach ($products as $key => &$product) {
                    $product = $this->removeNullValues($product);
                    if (empty($product['categories'])) {
                        unset($products[$key]);
                    }
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

//                die/

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

    private function prepareCategoriesForSync($categories)
    {
        foreach ($categories as &$category) {
            foreach ($category['descriptions'] as &$description) {
                $this->config->set('config_language_id', $description['language_id']);
                $description['url'] = $this->url->link('product/category', 'path=' . $category['category_id_ext']);
                unset($description['language_code']);
            }
        }

        $this->swapLanguageId($categories, 'category');
        return array_values($categories);
    }


    private function getCategoryPath($categoryId)
    {
        $path = [];
        $currentId = $categoryId;

        // Рекурсивно піднімаємося по ієрархії до кореневої категорії
        while ($currentId && isset($this->categories[$currentId])) {
            $category = $this->categories[$currentId];

            // Додаємо поточний ID в початок масиву
            array_unshift($path, $currentId);

            // Якщо це коренева категорія (parent_id_ext = 0), зупиняємося
            if ($category['parent_id_ext'] == '0') {
                break;
            }

            // Переходимо до батьківської категорії
            $currentId = $category['parent_id_ext'];
        }

        return $path;
    }


    private function swapLanguageId(&$data, $type = 'product')
    {
        // Ensure we have an array of items
        if (!is_array($data) || empty($data)) {
            return;
        }

        $this->load->model('tool/find_iq_cron');

        $site_languages = $this->model_tool_find_iq_cron->getAllLanguages();
        if (!is_array($site_languages)) {
            $site_languages = [];
        }

        // Build a fast lookup: language_id (int) -> normalized code ("en", "uk", etc.)
        $siteLangIdToCode = [];
        foreach ($site_languages as $lang) {
            if (!isset($lang['language_id'])) {
                continue;
            }
            $code = '';
            if (isset($lang['code']) && is_string($lang['code'])) {
                $c = strtolower(trim($lang['code']));
                $parts = explode('-', $c);
                $code = $parts[0] ?? $c;
            }
            $siteLangIdToCode[(string)$lang['language_id']] = $code;
        }

        // Build mapping code -> FindIQ numeric id (if exists)
        $codeToFindIQId = [];
        if (is_array($this->FindIQLanguages)) {
            foreach ($this->FindIQLanguages as $code => $fid) {
                if (!is_string($code)) continue;
                $c = strtolower(trim($code));
                $parts = explode('-', $c);
                $c = $parts[0] ?? $c;
                $codeToFindIQId[$c] = $fid;
            }
        }

        // Mapper closure: any input (int|string) -> FindIQ id if known, else normalized code
        $mapLang = function ($value) use ($siteLangIdToCode, $codeToFindIQId) {
            // If it's a numeric site language id, convert to code first
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $key = (string)$value;
                if (isset($siteLangIdToCode[$key]) && $siteLangIdToCode[$key] !== '') {
                    $value = $siteLangIdToCode[$key];
                } else {
                    // Unknown id: keep as-is
                    return $value;
                }
            }

            // If it's a string (code), normalize and then map to FindIQ id
            if (is_string($value)) {
                $code = strtolower(trim($value));
                $parts = explode('-', $code);
                $code = $parts[0] ?? $code;
                if (isset($codeToFindIQId[$code])) {
                    return $codeToFindIQId[$code];
                }
                return $code; // keep normalized code if no mapping
            }

            // Fallback: return original
            return $value;
        };

        foreach ($data as &$row) {
            // Descriptions: map language_id
            if (isset($row['descriptions']) && is_array($row['descriptions'])) {
                foreach ($row['descriptions'] as &$description) {
                    if (array_key_exists('language_id', $description)) {
                        $description['language_id'] = $mapLang($description['language_id']);
                    }
                }
                unset($description);
            }

            if ($type === 'product') {
                // Attributes: map language_id
                if (isset($row['attributes']) && is_array($row['attributes'])) {
                    foreach ($row['attributes'] as &$attribute) {
                        if (array_key_exists('language_id', $attribute)) {
                            $attribute['language_id'] = $mapLang($attribute['language_id']);
                        }
                    }
                    unset($attribute);
                }
            }
        }
        unset($row);
    }

    private function sendSseEvent(string $event, $data): void
    {
        // $data can be any serializable type; we encode to JSON
        $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo "event: {$event}\n";
        echo "data: {$payload}\n\n";
        // Explicitly flush output buffers
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
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