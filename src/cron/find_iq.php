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

if (!defined('VERSION')) {
    define('VERSION', '3.0.2.0');
}

// Define application config
$application_config = 'catalog';

// Load required OpenCart files
require_once(DIR_SYSTEM . 'startup.php');

$registry = new Registry();

// Config
$config = new Config();
$registry->set('config', $config);

// Request
$request = new Request();
$registry->set('request', $request);

// --- Парсинг CLI-параметрів ---
$get_params = [];
$log_filename = 'find_iq_integration_cron.log';

foreach ($argv as $index => $arg) {
    if ($index === 0) continue;

    // Параметр лог-файлу
//    if (strpos($arg, '--log=') === 0) {
//        $log_filename = substr($arg, 6);
//        continue;
//    }

    // GET-параметри типу route=controller/action
    if (strpos($arg, '=') !== false) {
        parse_str($arg, $parsed);
        $get_params = array_merge($get_params, $parsed);
    }
}

// Передаємо GET-параметри в запит
$request->get = array_merge($request->get, $get_params);

// Перевірка на наявність route
if (empty($request->get['route'])) {
    exit('Error: route parameter is required. Example: php cron/find_iq.php route=tool/find_iq_cron action=products mode=full
 ' . PHP_EOL);
}

// Log
$log = new Log($log_filename);
$registry->set('log', $log);

// Event
$event = new Event($registry);
$registry->set('event', $event);

// Loader
$loader = new Loader($registry);
$registry->set('load', $loader);

// Session
$session = new Session('file', $registry);
$registry->set('session', $session);

// URL
$url = new Url(HTTP_SERVER, HTTPS_SERVER);
$registry->set('url', $url);

// Cache
$cache = new Cache('file');
$registry->set('cache', $cache);

// Response
$response = new Response();
$response->addHeader('Content-Type: text/html; charset=utf-8');
$registry->set('response', $response);

// Language (тимчасово, мова підтягнеться після config)
$language = new Language('en-gb');
$registry->set('language', $language);

// Document
$document = new Document();
$registry->set('document', $document);

// Database
$db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
$registry->set('db', $db);

// Load settings from DB
$query = $db->query("SELECT * FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0'");
foreach ($query->rows as $setting) {
    if (!$setting['serialized']) {
        $config->set($setting['key'], $setting['value']);
    } else {
        $config->set($setting['key'], json_decode($setting['value'], true));
    }
}

// Reload language with actual config language
$language = new Language($config->get('config_language'));
$language->load($config->get('config_language'));
$registry->set('language', $language);

// Виклик контролера
$controller = new Action($request->get['route']);
$controller->execute($registry);

// Вивід
$response->output();
