<?php
class ModelToolFindIQCron extends Model
{
    /**
     * Fetches a batch of product details optimized for performance by using specific SQL queries.
     *
     * @param array $products_list An array of product IDs to fetch data for. If empty, returns an empty array.
     * @param int $language_id The language ID to fetch the product description in.
     *
     * @return array Returns an array of product details including ID, image, manufacturer, SKU, prices, and more.
     */

    public function getProductsBatchOptimized($products_list = array(), $language_id) {

        if (empty($products_list)) {
            return [];
        }

//        $current_datetime = date('Y-m-d H:i:s', floor(time() / 60) * 60);


        $current_datetime = date('Y-m-d H:i:s', floor(time() / (5 * 60)) * (5 * 60));

        $product_ids = implode(',', array_map('intval', $products_list));

        $sql = "
            SELECT 
                p.product_id AS product_id_ext,
                p.image,
                p.manufacturer_id,
                p.sku,
                p.stock_status_id,
                p.quantity,
                p.price,
                ps.price AS sale_price,
                p.status,
                p.model,
                p.ean,
                p.upc,
                p.jan,
                p.isbn,
                
                pd.name,
                (
                    SELECT AVG(rating) 
                    FROM " . DB_PREFIX . "review r1 
                    WHERE r1.product_id = p.product_id AND r1.status = '1' 
                    GROUP BY r1.product_id
                ) AS rating
            FROM " . DB_PREFIX . "product p
            JOIN " . DB_PREFIX . "product_description pd 
                ON (pd.product_id = p.product_id AND pd.language_id = " . (int)$language_id . ")
            LEFT JOIN (
                SELECT 
                    product_id,
                    price,
                    ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY priority ASC, price ASC) AS rn
                FROM " . DB_PREFIX . "product_special
                WHERE 
                    ((date_start = '0000-00-00 00:00:00' OR date_start < '" . $this->db->escape($current_datetime) . "') AND (date_end = '0000-00-00 00:00:00' OR date_end > '" . $this->db->escape($current_datetime) . "'))
            ) ps ON p.product_id = ps.product_id AND ps.rn = 1
            WHERE p.product_id IN (" . $product_ids . ")";

        $query = $this->db->query($sql);

        return $query->rows;
    }


    /**
     * Prepares a temporary table by inserting product IDs of active products
     * that do not already exist in the find_iq_sync_products table.
     *
     * @return void
     */
    public function prepareTempTable() {
        $this->db->query('
            INSERT INTO ' . DB_PREFIX . 'find_iq_sync_products (product_id)
                SELECT p.product_id
                FROM ' . DB_PREFIX . 'product p
                WHERE p.status = 1
                  AND NOT EXISTS (
                    SELECT 1
                    FROM ' . DB_PREFIX . 'find_iq_sync_products fip
                    WHERE fip.product_id = p.product_id
                )
        ');

    }

}