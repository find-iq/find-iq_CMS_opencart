<?php

class ControllerToolFindIQCron extends Controller
{
    private $now;

    private $categories;

    private $FindIQLanguages = [];

    private $actions;


    public function __construct($registry)
    {
        parent::__construct($registry);

        $this->now = (new DateTime())->getTimestamp();

    }

    public function index()
    {

        if ($this->config->get('module_find_iq_integration_status') == '1') {

            $this->load->library('FindIQ');
            $this->load->library('FindIQImage');

            // Dedicated cron log for per-portion timeline
            $cron_log = new Log('find_iq_integration_cron.log');

            $this->actions = explode(',', $this->request->get['actions'] ?? '');

            $mode = $this->request->get['mode'] ?? 'fast';

            // Time limit in seconds for the whole run (optional)
            $timeLimitSeconds = (int)($this->request->get['time'] ?? 0);
            $timeStart = time();

            $config = $this->config->get('module_find_iq_integration_config');

            $this->load->model('tool/image');
            $this->load->model('tool/find_iq_cron');

            $this->model_tool_find_iq_cron->prepareTempTable();

            if ($mode == 'fast') {
                $offset_hours = $config['fast_reindex_timeout'] ?? 4;
            } else {
                $offset_hours = $config['full_reindex_timeout'] ?? 24;
            }


            // items per batch (API batch size)
            $batch_size = $this->request->get['batch_size'] ?? 50;

            if (in_array('categories', $this->actions) || in_array('products', $this->actions)) {
                $this->categories = array_chunk($this->model_tool_find_iq_cron->getAllCategories(), 100, true);
            }

            $this->FindIQLanguages = $this->cache->get('find_iq_languages');
            if(!$this->FindIQLanguages){
                $this->FindIQLanguages = $this->FindIQ->getLanguages();
                $this->cache->set('find_iq_languages', $this->FindIQLanguages, 3600);
            }


            if (in_array('categories', $this->actions)) {
                foreach($this->categories as $categories_pack){
                    $this->FindIQ->postCategoriesBatch(
                        $this->prepareCategoriesForSync(
                            $categories_pack
                        )
                    );
                }
            }



            if (in_array('products', $this->actions)) {

                $time_limit_reached = false;

                // === PHASE 1: NEW PRODUCTS (never synced to FindIQ) ===
                $new_total = $this->getProductsListToSync('full', 1, $offset_hours, 'new')['total'] ?? 0;
                echo 'New products (never synced): ' . $new_total . PHP_EOL;

                if ($new_total > 0) {
                    // Auto-sync categories before sending new products
                    foreach ($this->categories as $categories_pack) {
                        $this->FindIQ->postCategoriesBatch(
                            $this->prepareCategoriesForSync($categories_pack)
                        );
                    }
                    $time_limit_reached = $this->runSyncPhase(
                        'new', 'full', $batch_size, $new_total, $config, $timeLimitSeconds, $timeStart, $offset_hours
                    );
                }

                // === PHASE 2: CHANGED PRODUCTS (price / qty / special changed) ===
                if (!$time_limit_reached) {
                    $changed_total = $this->getProductsListToSync('fast', 1, $offset_hours, 'changed')['total'] ?? 0;
                    echo 'Changed products (price/qty): ' . $changed_total . PHP_EOL;

                    if ($changed_total > 0) {
                        $time_limit_reached = $this->runSyncPhase(
                            'changed', 'fast', $batch_size, $changed_total, $config, $timeLimitSeconds, $timeStart, $offset_hours
                        );
                    }
                }

                // === PHASE 3: REINDEX (full mode only) ===
                if (!$time_limit_reached && $mode === 'full') {
                    $reindex_total = $this->getProductsListToSync('full', 1, $offset_hours, 'reindex')['total'] ?? 0;
                    echo 'Products to reindex: ' . $reindex_total . PHP_EOL;

                    if ($reindex_total > 0) {
                        $this->runSyncPhase(
                            'reindex', 'full', $batch_size, $reindex_total, $config, $timeLimitSeconds, $timeStart, $offset_hours
                        );
                    }
                }
            }

            if (in_array('frontend', $this->actions)) {

                $frontend = json_decode($this->FindIQ->getFrontendScript(), true);

                echo "<pre>" . strip_tags(json_encode($frontend, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), '<br>') . "</pre>";

                if(isset($frontend['css_url']) && isset($frontend['js_url'])){

                    try {
                        $frontend['updated_at'] = (new DateTime($frontend['updated_at']))->getTimestamp();
                    } catch (Exception $e) {
                        $frontend['updated_at'] = $this->now;
                    }

                    $this->model_tool_find_iq_cron->updateFrontend($frontend);
                }
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
                $description['url'] = html_entity_decode($this->url->link('product/category', 'path=' . $category['category_id_ext'], true));
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
                        $description['language_id'] = (int)$mapLang($description['language_id']);
                        unset($description['language_code']);
                    }
                }
                unset($description);
            }

            if ($type === 'product') {
                // Attributes: map language_id
                if (isset($row['attributes']) && is_array($row['attributes'])) {
                    foreach ($row['attributes'] as &$attribute) {
                        if (array_key_exists('language_id', $attribute)) {
                            $attribute['language_id'] = (int)$mapLang($attribute['language_id']);
                        }
                    }
                    unset($attribute);
                }
            }
        }
        unset($row);
    }


    /**
     * Виконує один цикл синхронізації для заданого пріоритету.
     * Повертає true якщо досягнуто ліміт часу, false якщо завершено нормально.
     */
    private function runSyncPhase(
        string $priority,
        string $api_mode,
        int    $batch_size,
        int    $total,
        array  $config,
        int    $timeLimitSeconds,
        int    $timeStart,
        int    $offset_hours
    ): bool {
        $processed = 0;
        $stopFlag  = DIR_STORAGE . 'find_iq_sync.stop';

        while (true) {
            // Check stop flag on every iteration — stopper just writes this file.
            if (is_file($stopFlag)) {
                echo 'Stop flag detected, halting.' . PHP_EOL;
                return true;
            }

            $to_update = $this->getProductsListToSync($api_mode, $batch_size, $offset_hours, $priority);

            if (empty($to_update['products'])) {
                break;
            }

            echo 'start [' . $priority . ' ' . $processed . '-' . ($processed + count($to_update['products'])) . '/' . $total . '] products ';

            $products = $this->model_tool_find_iq_cron->getProductsBatchOptimized(
                $to_update['products'],
                $api_mode
            );

            $rejected_products = [];
            foreach (array_column($products, 'product_id_ext') as $product_id) {
                $rejected_products[$product_id] = 0;
            }

            echo '=';

            if ($api_mode === 'full') {
                echo '=';

                foreach ($products as $product_key => $product) {
                    foreach (array_keys($product) as $field) {
                        if (!in_array($field, ['quantity'])) {
                            if ($product[$field] == '' || $product[$field] === 0) {
                                unset($products[$product_key][$field]);
                            }
                        }
                    }

                    if (empty($product['manufacturer']['descriptions'])) {
                        unset($products[$product_key]['manufacturer']['descriptions']);
                    }

                    // Admin stores a single square size under "resize_size". The
                    // legacy "resize-width" key is kept as a safety net for configs
                    // saved before the key was normalised.
                    $resizeSize = (int)($config['resize_size'] ?? $config['resize-width'] ?? 200);
                    if ($resizeSize < 1) {
                        $resizeSize = 200;
                    }

                    $imagePath      = is_file(DIR_IMAGE . $product['image']) ? $product['image'] : 'no_image.png';
                    $imageProcessor = $config['image_processor'] ?? 'gd';

                    if ($imageProcessor === 'opencart') {
                        $products[$product_key]['image'] = $this->model_tool_image->resize($imagePath, $resizeSize, $resizeSize);
                    } else {
                        $products[$product_key]['image'] = $this->FindIQImage->resize($imagePath, $resizeSize, $resizeSize);
                    }

                    foreach ($product['descriptions'] as $product_description_key => $description) {
                        $this->config->set('config_language_id', $description['language_id']);
                        $products[$product_key]['descriptions'][$product_description_key]['url'] = html_entity_decode($this->url->link('product/product', 'product_id=' . $product['product_id_ext'], true));
                    }

                    $products[$product_key]['categories'][] = $product['category_id'];
                    unset($products[$product_key]['category_id']);

                    if ($product['category_id'] == '0') {
                        unset($products[$product_key]);
                        $rejected_products[$product['product_id_ext']] = 1;
                    }
                }

                echo '=';
                $this->swapLanguageId($products, 'product');
            }

            echo '=';
            $products = $this->removeNullValues($products);

            if ($api_mode === 'full') {
                $this->FindIQ->postProductsBatch($products);
            } else {
                $this->FindIQ->putProductsBatch($products);
            }

            $rejected_products = array_filter($rejected_products);
            if (!empty($rejected_products)) {
                echo '=[rejected-';
                echo $this->model_tool_find_iq_cron->markProductsAsRejected(array_keys($rejected_products));
                echo ']';
            }

            echo '=';
            $this->model_tool_find_iq_cron->markProductsAsSynced(array_column($products, 'product_id_ext'), $api_mode, $this->now);
            $this->model_tool_find_iq_cron->clearRelated(array_column($products, 'product_id_ext'));
            echo '=';

            $processed += count($products);
            echo ' processed ' . count($products) . PHP_EOL;

            if (!empty($timeLimitSeconds) && (time() - $timeStart) >= $timeLimitSeconds) {
                echo 'Time limit (' . $timeLimitSeconds . "s) reached. Stopping after current batch." . PHP_EOL;
                return true;
            }
        }

        return false;
    }


    private function getProductsListToSync(string $mode = 'fast', int $limit = 100, int $offset_hours = 2, string $priority = 'reindex'): array
    {
        if ($offset_hours < 1) {
            $offset_hours = ($mode === 'fast') ? 2 : 24;
        }

        if ($limit < 1) {
            $limit = 100;
        }

        if (!in_array($mode, ['fast', 'full'])) {
            $mode = 'fast';
        }

        $this->now = (new DateTime())->getTimestamp();
        $time_limit = $this->now - ($offset_hours * 60 * 60);

        $this->load->model('tool/find_iq_cron');

        return $this->model_tool_find_iq_cron->getProductsListToSync($time_limit, $limit, $mode, $priority);
    }
}