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

    private $actions;

    public function index()
    {


        if ($this->config->get('module_find_iq_integration_status') == '1') {

            $this->load->library('FindIQ');

            // Dedicated cron log for per-portion timeline
            $cron_log = new Log('find_iq_integration_cron.log');

            $this->actions = explode(',', $this->request->get['actions'] ?? '');

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


            $batch_size = 100; // items per batch (API batch size)

            $this->categories =  $this->model_tool_find_iq_cron->getAllCategories();

            if ($mode == 'full' and in_array('categories', $this->actions)) {
                $this->FindIQ->postCategoriesBatch(
                    $this->prepareCategoriesForSync(
                        $this->categories
                    )
                );
            }

            $total = $this->getProductsListToSync($mode, 1, $offset_hours)['total'] ?? 0;

            echo 'Total products to sync: ' . $total . PHP_EOL;

            $have_products_to_update = $total > 0;



            $processed = 0;
            while ($have_products_to_update === true) {

                $request_limit = ($mode === 'fast') ? max(1, (int)ceil($batch_size * 10)) : $batch_size;


                $to_update = $this->getProductsListToSync($mode, $request_limit, $offset_hours);
                $have_products_to_update = isset($to_update['products']) && count($to_update['products']) > 0;

                if (!$have_products_to_update) {
                    break;
                }

                echo 'start [ ' . $processed . '-' . ($processed + count($to_update['products'])) . '/' . $total .  ' ] products ';


                $products = $this->model_tool_find_iq_cron->getProductsBatchOptimized(
                    $to_update['products'],
                    $mode
                );



                echo '=';
                if ($mode == 'full') {
                    $this->swapLanguageId($products, 'product');

                    echo '=';

                    foreach ($products as $product_key => $product) {

                        if(is_file(DIR_IMAGE . $product['image'])){
                            $products[$product_key]['image'] = $this->model_tool_image->resize($product['image'], $config['resize-width'] ?? '200', $config['resize-height'] ?? '200');
                        } else {
                            $products[$product_key]['image'] = $this->model_tool_image->resize('no_image.png', $config['resize-width'] ?? '200', $config['resize-height'] ?? '200');
                        }

                        foreach ($product['descriptions'] as $product_description_key => $description) {
                            $this->config->set('config_language_id', $description['language_id']);
                            $products[$product_key]['descriptions'][$product_description_key]['url'] = html_entity_decode($this->url->link('product/product', 'product_id=' . $product['product_id_ext'], true));
                        }

                        $products[$product_key]['categories'][] = $product['category_id'];

                        unset($products[$product_key]['category_id']);

                    }
                    echo '=';
                }




                echo '=';



                $this->FindIQ->postProductsBatch($products);



                echo '=';


                $this->model_tool_find_iq_cron->updateProductsFindIqIds(
                    $this->FindIQ->getProductFindIqIds(array_column($products, 'product_id_ext')
                    )
                );
                echo '=';
                $this->model_tool_find_iq_cron->markProductsAsSynced(array_column($products, 'product_id_ext'), $mode, $this->now);
                echo '=';

                $processed += count($products);

                // Optionally output progress per batch to logs
                 echo ' processed ' . count($products) . PHP_EOL;
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