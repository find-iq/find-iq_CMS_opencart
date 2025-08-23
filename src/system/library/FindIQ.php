<?php

/**
 * @package   FindIQ Integration for Opencart 3
 * @author    Mykola Hlushpenko
 * @link      https://hlushpenko.top
 */

class FindIQ
{
    /** @var Registry */
    private $registry;
    /** @var Config */
    private $config;
    /** @var Log */
    private $opencart_log;

    /** @var resource cURL handle */
    private $curl;

    /** @var string */
    private $base_url = 'https://panel.find-iq.com';
    /** @var string */
    private $token = '';

    /** @var int Total request timeout in seconds */
    private $timeout = 120;
    /** @var int Connect timeout in seconds */
    private $connect_timeout = 10;

    /** @var array */
    private $last_response = [
        'status' => null,
        'headers' => [],
        'body_raw' => null,
        'error' => null,
    ];

    public function __construct($registry)
    {
        $this->registry = $registry;
        $this->config = $registry->get('config');
        $this->opencart_log = new Log('find_iq_integration_cron.log');

        $settings = $this->config->get('module_find_iq_integration_config') ?: [];
        $this->token = isset($settings['token']) ? (string)$settings['token'] : '';
        $this->base_url = rtrim(isset($settings['base_url']) && $settings['base_url'] ? $settings['base_url'] : $this->base_url, '/');

        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);

        // Allow timeouts to be configured from module settings
        $http_timeout = isset($settings['http_timeout']) ? (int)$settings['http_timeout'] : 120;
        $connect_timeout = isset($settings['http_connect_timeout']) ? (int)$settings['http_connect_timeout'] : 10;
        $this->timeout = max(1, $http_timeout);
        $this->connect_timeout = max(1, $connect_timeout);

        curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, $this->connect_timeout);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($this->curl, CURLOPT_USERAGENT, 'FindIQ-OpenCart-Client/1.0');
        // Improve reliability for long-running requests
        if (defined('CURLOPT_TCP_KEEPALIVE')) {
            @curl_setopt($this->curl, CURLOPT_TCP_KEEPALIVE, 1);
        }
        if (defined('CURLOPT_NOSIGNAL')) {
            @curl_setopt($this->curl, CURLOPT_NOSIGNAL, true);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->curl)) {
            curl_close($this->curl);
        }
    }

    // ===================== Public API methods =====================

    /**
     * GET /public/products
     * @return array Decoded JSON (products list) or throws RuntimeException
     */
    public function getProducts()
    {
        return $this->requestJson('GET', '/public/products');
    }

    /**
     * GET /public/products/{id}
     * @param string|int $id
     * @return array Decoded JSON (product) or throws RuntimeException
     */
    public function getProduct($id)
    {
        $id = rawurlencode((string)$id);
        return $this->requestJson('GET', "/public/products/{$id}");
    }



    /**
     * GET /public/products/ids
     * @param array $ids
     * @return array Decoded JSON (product ids) or throws RuntimeException
     */
    public function getProductFindIqIds(array $ids){
        if(empty($ids)){
            return [];
        }

        return $this->requestJson('POST', '/public/products/ids',  ['product_id_exts' =>  array_values($ids)]);
    }


    /**
     * POST /public/categories/batch
     * @param array $categories Batch of categories payloads
     * @return array Decoded JSON response or empty array if no categories provided
     */
    public function postCategoriesBatch(array $categories)
    {
        if (empty($categories)) {
            return [];
        }
        return $this->requestJson('POST', '/public/categories/batch', array_values($categories));
    }


    /**
     * POST /public/products/batch
     * @param array $products Batch of product payloads
     * @return array Decoded JSON response or empty array if no products provided
     */
    public function postProductsBatch(array $products)
    {
        if (empty($products)) {
            return [];
        }
        return $this->requestJson('POST', '/public/products/batch', array_values($products));
    }




    /**
     * PUT /public/products/{id}
     * Body: {"status": 1}
     * @param string|int $id
     * @param int $status 0|1
     * @return array
     */
    public function putProductStatus($id, $status)
    {
        $id = rawurlencode((string)$id);
        $payload = ['status' => (int)$status];
        return $this->requestJson('PUT', "/public/products/{$id}", $payload);
    }

    /**
     * GET /public/builder/widget
     * Returns the script content (not JSON)
     * @return string
     */
    public function getFrontendScript()
    {
        return $this->requestRaw('GET', '/public/builder/widget');
    }

    /**
     * Get info about the last HTTP response
     * @return array{status:int|null,headers:array,body_raw:?string,error:?string}
     */
    public function getLastResponse()
    {
        return $this->last_response;
    }

    // ===================== Internal HTTP helpers =====================

    /**
     * Perform request and decode JSON
     * @param string $method
     * @param string $path
     * @param mixed $body
     * @return array
     */
    private function requestJson($method, $path, $body = null)
    {
        $raw = $this->request($method, $path, $body, true);
        $decoded = json_decode($raw, true);



        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('FindIQ: Failed to decode JSON: ' . json_last_error_msg());
            throw new RuntimeException('FindIQ API: Invalid JSON response');
        }
        return $decoded;
    }

    /**
     * Perform request and return raw body
     * @param string $method
     * @param string $path
     * @param mixed $body
     * @return string
     */
    private function requestRaw($method, $path, $body = null)
    {
        return $this->request($method, $path, $body, false);
    }

    /**
     * Low-level request via cURL
     * @param string $method
     * @param string $path
     * @param mixed $body
     * @param bool $expect_json
     * @return string
     */
    private function request($method, $path, $body = null, $expect_json = true)
    {
        $this->resetLastResponse();

        $url = $this->buildUrl($path);

        $headers = [
            'X-Auth-Token: ' . $this->token,
        ];

        $method = strtoupper($method);
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            // Encode body as JSON if it's an array/object; if string, pass as is
            if (is_array($body) || is_object($body)) {
                $body_str = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, JSON_PRETTY_PRINT);
                $headers[] = 'Content-Type: application/json';
            } else {
                $body_str = (string)$body;
            }

            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $body_str);
        } else {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, null);
        }

        // Capture response headers
        $response_headers = [];
        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, function ($ch, $header_line) use (&$response_headers) {
            $len = strlen($header_line);
            $parts = explode(':', $header_line, 2);
            if (count($parts) == 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                $response_headers[strtolower($name)] = $value;
            }
            return $len;
        });

        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);

        // Apply current timeouts (in case they were changed)
        curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, $this->connect_timeout);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, $this->timeout);

        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);

        $attempt = 0;
        $max_attempts = 3;
        $backoff = 1; // seconds
        $response = null;
        $errno = 0;
        $error = null;
        $status = 0;
        do {
            $attempt++;
            $response = curl_exec($this->curl);

            $errno = curl_errno($this->curl);
            $error = $errno ? curl_error($this->curl) : null;
            $status = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

            $this->last_response['status'] = $status;
            $this->last_response['headers'] = $response_headers;
            $this->last_response['body_raw'] = $response;
            $this->last_response['error'] = $error;

            $should_retry = false;
            if ($errno === CURLE_OPERATION_TIMEDOUT || $errno === CURLE_COULDNT_CONNECT) {
                $should_retry = true;
            } elseif ($status === 0) {
                // No response received
                $should_retry = true;
            } elseif ($status === 429 || $status >= 500) {
                $should_retry = true;
            }

            if ($should_retry && $attempt < $max_attempts) {
                $this->log('FindIQ: transient error (attempt ' . $attempt . '): errno=' . $errno . ', status=' . $status . '. Retrying in ' . $backoff . 's');
                sleep($backoff);
                $backoff *= 2;
                continue;
            }
            break;
        } while (true);

        if ($errno) {
            $this->log('FindIQ: cURL error #' . $errno . ': ' . $error);
            throw new RuntimeException('FindIQ API request failed: ' . $error, $errno);
        }

        if ($status >= 400) {
            // Try to extract error message from JSON
            $msg = 'HTTP ' . $status;
            $decoded = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (isset($decoded['error'])) {
                    $msg .= ' - ' . (is_string($decoded['error']) ? $decoded['error'] : json_encode($decoded['error']));
                } elseif (isset($decoded['message'])) {
                    $msg .= ' - ' . (is_string($decoded['message']) ? $decoded['message'] : json_encode($decoded['message']));
                }
            }
            $this->log('FindIQ: API error: ' . $msg);
            throw new RuntimeException('FindIQ API error: ' . $msg, $status);
        }

        return (string)$response;
    }

    private function buildUrl($path)
    {
        $path = '/' . ltrim((string)$path, '/');
        return $this->base_url . $path;
    }

    private function resetLastResponse()
    {
        $this->last_response = [
            'status' => null,
            'headers' => [],
            'body_raw' => null,
            'error' => null,
        ];
    }

    private function log($message)
    {
        if (is_array($message) || is_object($message)) {
            $msg = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $msg = (string)$message;
        }

        if ($this->opencart_log) {
            $this->opencart_log->write($msg);
        }
    }
}