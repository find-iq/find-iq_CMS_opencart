<?php

class ModelExtensionModuleFindIQIntegration extends Model
{
    public function install()
    {

        $this->db->query(
            'create table if not exists ' . DB_PREFIX . 'find_iq_sync_products
            (
                product_id   int                    not null,
                fast_updated int unsigned default 0 not null,
                updated      int unsigned default 0 not null,
                rejected     tinyint(1)   default 0 not null,
                last_sended_price    decimal(15, 4) null,
                last_sended_special  decimal(15, 4) null,
                last_sended_quantity int            null
            )');


        $this->db->query('
            create index ' . DB_PREFIX . 'find_iq_sync_products_fast_updated_index
                on ' . DB_PREFIX . 'find_iq_sync_products (fast_updated)'
        );


        $this->db->query('create index ' . DB_PREFIX . 'find_iq_sync_products_rejected_index
            on ' . DB_PREFIX . 'find_iq_sync_products (rejected)');


        $this->db->query('create index ' . DB_PREFIX . 'find_iq_sync_products_updated_index
            on ' . DB_PREFIX . 'find_iq_sync_products (updated)');

        $this->db->query('alter table ' . DB_PREFIX . 'find_iq_sync_products
            add primary key (product_id)');

    }

    public function uninstall(){
        $this->db->query('drop table if exists oc_find_iq_sync_products');
    }
}
