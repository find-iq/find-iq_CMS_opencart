<?php

/**
 * @package        FindIQ Integration for Opencart 3
 * @author        Mykola Hlushpenko
 * @link        https://hlushpenko.top
 */


class FindIQ
{
    public function __construct($registry)
    {
        $this->registry = $registry;
        $this->load = $registry->get('load');
        $this->config = $registry->get('config');
        $this->db = $registry->get('db');
        $this->opencart_log = $registry->get('log');
        $this->status = $this->config->get('module_find_iq_integration_config_status');
        $this->setting = $this->config->get('module_find_iq_integration_config');

        $this->curl = curl_init();
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

    private function log($message) {
        $this->opencart_log->write($message);
        if( is_array($message)){
            $message = json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        echo $message . PHP_EOL;
    }
}