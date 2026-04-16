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

// Stop flag: if action=stop was called, do not run sync.
// The flag is NOT deleted here — it persists until action=start removes it.
// This ensures every respawned process also exits, not just the first one.
$stopFlag = DIR_STORAGE . 'find_iq_sync.stop';
if (is_file($stopFlag)) {
    exit('Stopped by stop flag' . PHP_EOL);
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
    if (strpos($arg, '=') !== false) {
        parse_str($arg, $parsed);
        $get_params = array_merge($get_params, $parsed);
    }
}

// Передаємо GET-параметри в запит
$request->get = array_merge($request->get, $get_params);

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

// Attach SEO URL rewrite (as in web startup) AFTER settings and language are loaded
if ($config->get('config_seo_url')) {
    if (class_exists('ControllerStartupSeoUrl')) {
        $seo_url = new ControllerStartupSeoUrl($registry);
        $url->addRewrite($seo_url);
    } else {
        $seo_controller_file = DIR_APPLICATION . 'controller/startup/seo_url.php';
        if (is_file($seo_controller_file)) {
            require_once($seo_controller_file);
            if (class_exists('ControllerStartupSeoUrl')) {
                $seo_url = new ControllerStartupSeoUrl($registry);
                $url->addRewrite($seo_url);
            }
        }
    }
}

// Виклик контролера
$controller = new Action('tool/find_iq_cron');
$controller->execute($registry);

// Вивід
$response->output();

// Self-respawn: if products remain — spawn new process and update lock;
// otherwise remove lock to signal completion.
$lockFile = DIR_STORAGE . 'find_iq_sync.lock';

$remaining = $db->query(
    "SELECT COUNT(*) AS cnt FROM `" . DB_PREFIX . "find_iq_sync_products`"
    . " WHERE first_synced IS NULL AND rejected = 0"
);

if (is_file($stopFlag)) {
    // Stop was requested — do not respawn, just clean up the lock.
    if (is_file($lockFile)) {
        @unlink($lockFile);
    }
} elseif ((int)$remaining->row['cnt'] > 0) {
    $phpBin   = PHP_BINARY ?: 'php';
    $selfFile = __FILE__;
    $passArgs = implode(' ', array_slice($argv, 1));
    $cmd      = sprintf(
        'nohup %s %s %s > /dev/null 2>&1 & echo $!',
        escapeshellarg($phpBin),
        escapeshellarg($selfFile),
        $passArgs
    );
    $newPid = (int)trim(shell_exec($cmd));
    file_put_contents($lockFile, $newPid);
} else {
    if (is_file($lockFile)) {
        @unlink($lockFile);
    }
}
