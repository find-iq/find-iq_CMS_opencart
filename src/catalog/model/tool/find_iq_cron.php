<?php

class ModelToolFindIQCron extends Model
{

    private $seopro_status;
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
                fp.find_iq_id AS id,
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
                            'name'        => $description['name'],
                            'description' => $this->sanitaze($description['description']),
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
        $where = "WHERE {$time_field} < {$time_limit}";

        if($mode === 'full'){
            $where .= " AND (find_iq_id = 0 OR find_iq_id IS NULL)";
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

    public function updateProductsFindIqIds($ids)
    {
        if (empty($ids['id_map'])) {
            return;
        }

        foreach ($ids['id_map'] as $product_id => $find_iq_id) {
            $product_id = (int)$product_id;
            $find_iq_id = (int)$find_iq_id;

            $this->db->query("
            UPDATE " . DB_PREFIX . "find_iq_sync_products 
            SET find_iq_id = {$find_iq_id}
            WHERE product_id = {$product_id}
        ");
        }
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
        SELECT category_id
        FROM " . DB_PREFIX . "product_to_category
        WHERE product_id = {$product_id}
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

    /**
     * Очищає текст від HTML-тегів, не UTF-8 символів та зайвих пробілів.
     *
     * @param string $text Вхідний текст
     * @return string Очищений текст
     */
    private function sanitaze($text): string
    {
        // Видаляємо HTML-теги
        $text = strip_tags($text);

        // Замінюємо послідовні пробіли на один пробіл
        $text = preg_replace('/\s+/', ' ', $text);

        // Залишаємо лише літери, цифри і деякі розділові знаки
        $text = preg_replace('/[^\p{L}\p{N}\s.,!?":;-]/u', '', $text);

        // Видаляємо емодзі (діапазони Unicode для емодзі)
        $text = preg_replace('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', '', $text);

        return trim($text);
    }
}