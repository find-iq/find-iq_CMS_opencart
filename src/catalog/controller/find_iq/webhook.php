<?php

/**
 * FindIQ Webhook endpoint (OC 3)
 * URL: index.php?route=find_iq/webhook
 *
 * Дозволяє FindIQ-серверу централізовано запускати синхронізацію товарів.
 *
 * Параметри GET:
 *   secret     — токен магазину (має збігатись з module_find_iq_integration_config['token'])
 *   mode       — fast (ціни/наявність) | full (повна заливка)
 *   actions    — через кому: products, categories, frontend (default: products)
 *   batch_size — кількість товарів за один запит (default: 10)
 *   time       — ліміт виконання в секундах (0 = без ліміту)
 */
class ControllerFindIqWebhook extends Controller
{
    public function index()
    {
        $this->response->addHeader('Content-Type: application/json');

        // 1. Перевірка що модуль увімкнено
        if ($this->config->get('module_find_iq_integration_status') != '1') {
            $this->response->setOutput(json_encode([
                'status'  => 'error',
                'message' => 'Module is disabled',
            ]));
            return;
        }

        // 2. Перевірка секретного токену
        $config = $this->config->get('module_find_iq_integration_config');
        $token  = isset($config['token']) ? (string)$config['token'] : '';
        $secret = isset($this->request->get['secret']) ? (string)$this->request->get['secret'] : '';

        if (empty($token) || $secret !== $token) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->setOutput(json_encode([
                'status'  => 'error',
                'message' => 'Invalid secret',
            ]));
            return;
        }

        // 3. Параметри запуску
        $mode    = $this->request->get['mode'] ?? 'fast';
        $actions = $this->request->get['actions'] ?? 'products';
        $batch   = (int)($this->request->get['batch_size'] ?? 10);
        $time    = (int)($this->request->get['time'] ?? 0);

        if (!in_array($mode, ['fast', 'full'])) {
            $mode = 'fast';
        }

        // 4. Передаємо параметри у GET щоб cron-контролер їх побачив
        $this->request->get['mode']       = $mode;
        $this->request->get['actions']    = $actions;
        $this->request->get['batch_size'] = $batch;
        $this->request->get['time']       = $time;

        // 5. Запускаємо cron-контролер і захоплюємо вивід
        ob_start();
        $this->load->controller('tool/find_iq_cron');
        $output = ob_get_clean();

        $this->response->setOutput(json_encode([
            'status'  => 'ok',
            'mode'    => $mode,
            'actions' => $actions,
            'log'     => $output,
        ]));
    }
}
