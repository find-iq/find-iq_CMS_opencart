<?php

class ModelToolFindIQCron extends Model
{

    private $seopro_status;


    public function clearRelated($products_list){

        if (empty($products_list)) {
            return [];
        }

        $this->db->query("
            DELETE FROM " . DB_PREFIX. "product_related WHERE product_id IN (" .  implode(',', array_map('intval', $products_list)) . ")");
    }


    /**
     * Отримання партії товарів із оптимізованими запитами.
     * — Забрано віконні функції (ROW_NUMBER), сумісно з MySQL 5.7.
     * — Агрегація рейтингу через LEFT JOIN підзапиту.
     * — Обчислення актуальної спец-ціни через скалярний підзапит з ORDER BY ... LIMIT 1.
     *
     * @return array
     */
    public function getProductsBatchOptimized($products_list, $mode): array
    {
        if (empty($products_list)) {
            return [];
        }

        // Округлення часу до 5 хвилин

        $current_datetime = date('Y-m-d H:i:s', floor(time() / (5 * 60)) * (5 * 60));
        $current_datetime_esc = $this->db->escape($current_datetime);

        // Гарантуємо лише int-значення
        $product_ids = implode(',', array_map('intval', $products_list));

        $sql = "
            SELECT
                p.product_id AS product_id_ext,
                p.sku,
                p.quantity,
                p.price,
                p.status,
                (
                    SELECT ps1.price
                    FROM " . DB_PREFIX . "product_special ps1
                    WHERE ps1.product_id = p.product_id
                      AND (
                        (ps1.date_start = '0000-00-00 00:00:00' OR ps1.date_start < '{$current_datetime_esc}')
                        AND (ps1.date_end = '0000-00-00 00:00:00' OR ps1.date_end > '{$current_datetime_esc}')
                      )
                    ORDER BY ps1.priority ASC, ps1.price ASC
                    LIMIT 1
                ) AS sale_price";
        if ($mode === 'full'){
            $sql .= "
                ,
                p.image,
                p.stock_status_id,
                p.model,
                p.ean,
                p.upc,
                p.jan,
                p.isbn,
                r.rating
            ";
        }

        $sql .= "   
            FROM " . DB_PREFIX . "product p";

        if ($mode === 'full') {
            $sql .= "
                LEFT JOIN (
                SELECT r1.product_id, AVG(r1.rating) AS rating
                FROM " . DB_PREFIX . "review r1
                WHERE r1.status = '1'
                GROUP BY r1.product_id
            ) r ON r.product_id = p.product_id
            ";

        }

        $sql .= "
            JOIN " . DB_PREFIX . "find_iq_sync_products fp ON fp.product_id = p.product_id
            WHERE p.product_id IN (" . $product_ids . ")
        ";

        $products = $this->db->query($sql)->rows;

        if ($mode === 'full') {
            $languages = implode(',', array_map('intval', array_column($this->getAllLanguages(), 'language_id')));

            $products_descriptions = $this->getProductDescriptions($product_ids, $languages);

            $products_manufacturers = $this->getProductManufacturers($product_ids);

            $product_attributes = $this->getProductAttributes($product_ids);


            foreach ($products as &$product) {
                foreach ($products_descriptions as $description) {
                    if ($product['product_id_ext'] == $description['product_id']) {
                        $product['descriptions'][] = array(
                            'language_id' => $description['language_id'],
                            'name'        => $this->sanitaze(htmlspecialchars_decode($description['name'])),
                            'description' => $this->sanitaze( html_entity_decode(htmlspecialchars_decode($description['description']))),
                            'language_code' => $description['language_code'],
                        );
                    }
                }

                foreach ($products_manufacturers as $manufacturer) {
                    if ($product['product_id_ext'] == $manufacturer['product_id']) {
                        $product['manufacturer'] = array(
                            'name' => $manufacturer['name'],
                            'descriptions' => array()
                        );
                    }
                }

                foreach ($product_attributes as $attribute) {
                    if ($product['product_id_ext'] == $attribute['product_id']) {
                        $product['attributes'][] = array(
                            'attribute_group' => $attribute['attribute_group'],
                            'attribute_name' => $attribute['attribute_name'],
                            'value' => $attribute['value'],
                            'language_id' => $attribute['language_id'],
                        );
                    }
                }

                $product['category_id'] = $this->getProductCategory($product['product_id_ext']);
            }
        }




        return $products;
    }


    public function getProductAttributes(string $product_ids)
    {
        return $this->db->query("
            SELECT DISTINCT pa.product_id, pa.text as value, ad.name as attribute_name, agd.name as attribute_group, pa.language_id
            FROM " . DB_PREFIX . "product_attribute pa
            JOIN " . DB_PREFIX . "attribute_description ad ON ad.attribute_id = pa.attribute_id AND ad.language_id = pa.language_id
            JOIN " . DB_PREFIX . "attribute a ON a.attribute_id = pa.attribute_id
            JOIN " . DB_PREFIX . "attribute_group_description agd ON agd.attribute_group_id = a.attribute_group_id AND agd.language_id = pa.language_id
            WHERE pa.product_id IN (" . $this->db->escape($product_ids) . ")
        ")->rows;

    }

    public function getProductManufacturers(string $product_ids) : array
    {
        return $this->db->query("
            SELECT  p.product_id, m.manufacturer_id, m.name
            FROM " . DB_PREFIX . "product p
            JOIN " . DB_PREFIX . "manufacturer m ON (p.manufacturer_id = m.manufacturer_id)
            WHERE p.product_id IN (" . $this->db->escape($product_ids) . ")"
        )->rows;

    }

    /**
     * @param string $product_ids
     * @param string $languages
     * @return array
     */
    private function getProductDescriptions(string $product_ids, string $languages) : array
    {
        return $this->db->query("
            SELECT  pd.product_id, pd.name, pd.description, pd.language_id, l.code as language_code
            FROM " . DB_PREFIX . "product_description pd
            JOIN " . DB_PREFIX . "language l ON pd.language_id = l.language_id
            WHERE pd.product_id IN (" . $this->db->escape($product_ids) . ") 
            AND pd.language_id IN (" . $this->db->escape($languages) . ")
        ")->rows;
    }


    /**
     * @param int $time_limit
     * @param int $limit
     * @param string $mode
     *
     * @return array
     */
    public function getProductsListToSync(int $time_limit, int $limit, string $mode): array
    {
        $time_field = ($mode === 'fast') ? 'fast_updated' : 'updated';
        $time_limit = (int)$time_limit;
        $limit = (int)$limit;

        $table = DB_PREFIX . "find_iq_sync_products";
        $where = "WHERE {$time_field} < {$time_limit} AND rejected = 0";

        if($mode === 'fast'){
            $where .= " AND updated > 0";
        }

        $order = "ORDER BY {$time_field}, product_id";

        $products = $this->db->query("
            SELECT product_id
            FROM {$table}
            {$where}
            {$order}
            LIMIT {$limit}
        ")->rows;

        $totalRow = $this->db->query("
            SELECT COUNT(*) AS total
            FROM {$table}
            {$where}
        ")->row;

        return [
            'products' => array_map('intval', array_column($products, 'product_id')),
            'total' => isset($totalRow['total']) ? (int)$totalRow['total'] : 0,
        ];
    }

    public function markProductsAsRejected($product_ids){
        if (empty($product_ids)) {
            return;
        }

        // Гарантуємо лише int-значення для безпеки
        $product_ids_clean = array_map('intval', $product_ids);
        $product_ids_sql = implode(',', $product_ids_clean);


        $this->db->query("UPDATE " . DB_PREFIX . "find_iq_sync_products SET rejected = 1 WHERE product_id IN ({$product_ids_sql})");
        return $this->db->countAffected();

    }

    public function markProductsAsSynced($product_ids, $mode, $time)
    {
        if (empty($product_ids)) {
            return;
        }

        $updated_field = ($mode === 'fast') ? 'fast_updated' : 'updated';

        // Гарантуємо лише int-значення для безпеки
        $product_ids_clean = array_map('intval', $product_ids);
        $product_ids_sql = implode(',', $product_ids_clean);

        // Екрануємо час для безпеки
        $time_escaped = $this->db->escape($time);

        $this->db->query("
        UPDATE " . DB_PREFIX . "find_iq_sync_products 
        SET {$updated_field} = '{$time_escaped}'
        WHERE product_id IN ({$product_ids_sql})
    ");
    }


    public function getAllCategories()
    {

        $this->seopro_status = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product_to_category` LIKE 'main_category'")->num_rows > 0;

        $categories = $this->db->query("
            SELECT c.category_id,
                   c.parent_id,
                   cd.name,
                   cd.language_id,
                   l.code as language_code
            FROM oc_category c
                     JOIN " . DB_PREFIX . "category_description cd ON cd.category_id = c.category_id
                     JOIN " . DB_PREFIX . "language l ON cd.language_id = l.language_id
            WHERE c.status = '1'
        ")->rows;


        $response = [];
        foreach ($categories as &$category) {
            $response[$category['category_id']] = [
                'category_id_ext' => $category['category_id'],
                'parent_id_ext' => $category['parent_id'],
            ];
        }

        foreach ($categories as &$category) {
            $response[$category['category_id']]['descriptions'][] = [
                'language_id' => $category['language_id'],
                'name' => $category['name'],
                'language_code' => $category['language_code'],
            ];
        }

        return $response;
    }

    private function getProductCategory(int $product_id){

        $sql = "
        SELECT p2c.category_id
        FROM " . DB_PREFIX . "product_to_category p2c
        JOIN "  . DB_PREFIX . "category c ON p2c.category_id = c.category_id
        WHERE product_id = {$product_id} AND c.status = 1
        ORDER BY ";

        if($this->seopro_status){
            $sql .= " main_category DESC,";
        }

        $sql .= "
        category_id DESC
        LIMIT 1;
        ";

        $query = $this->db->query($sql);

        if($query->num_rows > 0){
            return $query->row['category_id'];
        } else {
            return 0;
        }
    }


    /**
     * @return array
     */
    public function getAllLanguages()
    {
        return $this->db->query("
            SELECT language_id, code
            FROM " . DB_PREFIX . "language
            WHERE status = '1'
        ")->rows;
    }

    /**
     * Підготовка тимчасової таблиці задач синхронізації.
     *
     * @return void
     */
    public function prepareTempTable(): void
    {
        $this->db->query('
            INSERT IGNORE INTO ' . DB_PREFIX . 'find_iq_sync_products (product_id)
            SELECT p.product_id
            FROM ' . DB_PREFIX . 'product p
            WHERE p.status = 1
        ');
    }

    public function updateFrontend($frontend)
    {
        $store_id = 0;
        $code = "module_find_iq_integration";
        $key = "module_find_iq_integration_frontend";
        $value = json_encode($frontend);
        $serialized = 1;

        // Підготуємо умову WHERE
        $where = 'WHERE store_id = ' . (int)$store_id . ' AND `code` = "' . $this->db->escape($code) . '" AND `key` = "' . $this->db->escape($key) . '"';

        // Перевірка наявності запису
        $query = $this->db->query('
            SELECT COUNT(*) AS count 
            FROM ' . DB_PREFIX . 'setting 
            ' . $where
        );

        if ((int)$query->row['count'] === 0) {
            // Якщо запису немає — вставляємо новий
            $this->db->query('
                INSERT INTO ' . DB_PREFIX . 'setting (store_id, `code`, `key`, `value`, serialized)
                VALUES (' . (int)$store_id . ', "' . $this->db->escape($code) . '", "' . $this->db->escape($key) . '", "' . $this->db->escape($value) . '", ' . (int)$serialized . ')
            ');
        } else {
            // Якщо запис є — оновлюємо його
            $this->db->query('
                UPDATE ' . DB_PREFIX . 'setting 
                SET `value` = "' . $this->db->escape($value) . '" 
                ' . $where
            );
        }
    }
    /**
     * Очищає текст від HTML-тегів, не UTF-8 символів та зайвих пробілів.
     *
     * @param string $text Вхідний текст
     * @return string Очищений текст
     */
    private function sanitaze($text): string
    {
        // Гарантуємо коректну UTF-8 та прибираємо невалідні байти
        $enc = function_exists('mb_detect_encoding') ? mb_detect_encoding($text, ['UTF-8', 'Windows-1251', 'ISO-8859-1', 'ASCII'], true) : 'UTF-8';
        if ($enc && $enc !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $text = mb_convert_encoding($text, 'UTF-8', $enc);
        }
        if (function_exists('iconv')) {
            $tmp = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($tmp !== false) {
                $text = $tmp;
            }
        }

        // Видаляємо HTML-теги
        $text = strip_tags($text);

        // Прибираємо керівні символи, BOM, zero-width, bidi та інші проблемні символи,
        // а також нормалізуємо всі види пропусків (у тому числі перенос рядків) до одного пробілу
        $text = preg_replace('/[\x{00}-\x{1F}\x{7F}\x{AD}\x{200B}-\x{200F}\x{2028}-\x{202F}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $text);
        $text = preg_replace('/[\p{Z}]+/u', ' ', $text); // усі юнікод-пробіли -> пробіл

        // Колапсуємо множинні пробіли в один
        $text = preg_replace('/[ ]{2,}/', ' ', $text);

        // Залишаємо лише літери, цифри і деякі розділові знаки
        $text = preg_replace('/[^\p{L}\p{N}\s.,!?":;-]/u', '', $text);

        // Видаляємо емодзі (розширені діапазони Unicode для емодзі/піктограм)
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{1FC00}-\x{1FFFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);

        return trim($text);
    }

    public function getProductQtyData($product_id){
        return $this->db->query("
            SELECT f.product_id AS \"product-id\", p.quantity
            FROM " . DB_PREFIX . "find_iq_sync_products f
            JOIN " . DB_PREFIX . "product p ON f.product_id = p.product_id
            WHERE f.product_id = " . (int)$product_id)->row;
    }
}