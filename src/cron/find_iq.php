<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(-1);

// Configuration File
$config_file = realpath(__DIR__ . '/..') . '/config.php';

if (is_file($config_file)) {
    require_once($config_file);
} else {
    exit('Config is not found' . PHP_EOL);
}

if(is_file(DIR_SYSTEM . '/library/find_iq.php')){
    require_once(DIR_SYSTEM . '/library/find_iq.php');
} else {
    exit('FindIQ is not found' . PHP_EOL);
}

// Define application config
$application_config = 'catalog'; // або 'admin', якщо потрібно завантажити конфігурацію адміністративної частини

// Load required OpenCart files, but without initiating full OpenCart application
require_once(DIR_SYSTEM . 'startup.php');

$registry = new Registry();

// Config
$config = new Config();
$registry->set('config', $config);

// Log
$log = new Log('find_iq_cron.log');
$registry->set('log', $log);

// Request
$request = new Request();
$registry->set('request', $request);

// Load the settings from the database
$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
$registry->set('db', $db);

// Load the settings from database
$query = $db->query("SELECT * FROM " . DB_PREFIX . "setting WHERE store_id = '0'");
foreach ($query->rows as $setting) {
    if (!$setting['serialized']) {
        $config->set($setting['key'], $setting['value']);
    } else {
        $config->set($setting['key'], json_decode($setting['value'], true));
    }
}

// Loader
$loader = new Loader($registry);
$registry->set('load', $loader);

if(!$config->get('module_find_iq_status')){
    return;
}

$FindIQ = new FindIQ($registry);

$FindIQ->makeIndex();

exit;
