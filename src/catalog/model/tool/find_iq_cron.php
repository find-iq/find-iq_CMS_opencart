<?php
class ModelToolFindIQCron extends Model
{
    /**
     * Отримання партії товарів із оптимізованими запитами.
     * — Забрано віконні функції (ROW_NUMBER), сумісно з MySQL 5.7.
     * — Агрегація рейтингу через LEFT JOIN підзапиту.
     * — Обчислення актуальної спец-ціни через скалярний підзапит з ORDER BY ... LIMIT 1.
     */
    public function getProductsBatchOptimized($products_list = array(), $language_id)
    {
        if (empty($products_list)) {
            return [];
        }

        // Округлення часу до 5 хвилин

        $current_datetime = date('Y-m-d H:i:s', floor(time() / (5 * 60)) * (5 * 60));
        $current_datetime_esc = $this->db->escape($current_datetime);

        // Гарантуємо лише int-значення
        $product_ids = implode(',', array_map('intval', $products_list));
        $language_id = (int)$language_id;

        $sql = "
            SELECT
                p.product_id AS product_id_ext,
                p.image,
                p.manufacturer_id,
                p.sku,
                p.stock_status_id,
                p.quantity,
                p.price,
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
                ) AS sale_price,
                p.status,
                p.model,
                p.ean,
                p.upc,
                p.jan,
                p.isbn,
                pd.name,
                r.rating
            FROM " . DB_PREFIX . "product p
            JOIN " . DB_PREFIX . "product_description pd
                ON pd.product_id = p.product_id AND pd.language_id = {$language_id}
            LEFT JOIN (
                SELECT r1.product_id, AVG(r1.rating) AS rating
                FROM " . DB_PREFIX . "review r1
                WHERE r1.status = '1'
                GROUP BY r1.product_id
            ) r ON r.product_id = p.product_id
            WHERE p.product_id IN (" . $product_ids . ")
        ";

        $query = $this->db->query($sql);

        return $query->rows;
    }


    public function getProductsListToSync(int $time_limit, int $limit, string $mode)
    {
        $time_field = ($mode === 'fast') ? 'fast_updated' : 'updated';
        $time_limit = (int)$time_limit;
        $limit = (int)$limit;

        $table = DB_PREFIX . "find_iq_sync_products";
        $where = "WHERE {$time_field} < {$time_limit}";
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
            'total'    => isset($totalRow['total']) ? (int)$totalRow['total'] : 0,
        ];
    }

    /**
     * Підготовка тимчасової таблиці задач синхронізації.
     */
    public function prepareTempTable()
    {
        // ВАЖЛИВО: у таблиці "find_iq_sync_products" має бути унікальний індекс на product_id:
        // ALTER TABLE " . DB_PREFIX . "find_iq_sync_products ADD UNIQUE KEY uq_product_id (product_id);

        $this->db->query('
            INSERT IGNORE INTO ' . DB_PREFIX . 'find_iq_sync_products (product_id)
            SELECT p.product_id
            FROM ' . DB_PREFIX . 'product p
            WHERE p.status = 1
        ');
    }
}