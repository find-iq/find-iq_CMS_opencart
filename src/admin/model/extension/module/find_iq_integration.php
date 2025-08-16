<?php

class ModelExtensionModuleFindIQIntegration extends Model
{
    public function install()
    {
        $this->db->query(
            'create table ' . DB_PREFIX . 'find_iq_sync_products
                (
                    product_id   int                    not null
                        primary key,
                    fast_updated int unsigned default 0 null,
                    updated      int unsigned default 0 not null,
                    find_iq_id   bigint unsigned        null,
                    constraint ' . DB_PREFIX . 'find_iq_sync_products_find_iq_id_uindex
                        unique (find_iq_id)
                );
                
                create index ' . DB_PREFIX . 'find_iq_sync_products_fast_updated_index
                    on ' . DB_PREFIX . 'find_iq_sync_products (fast_updated);
                
                create index ' . DB_PREFIX . 'find_iq_sync_products_updated_index
                    on ' . DB_PREFIX . 'find_iq_sync_products (updated);
                
                '
        );
    }

}