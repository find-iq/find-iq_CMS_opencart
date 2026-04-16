<?php

/**
 * FindIQ Webhook endpoint (OC 3)
 * URL: index.php?route=find_iq/webhook
 *
 * Fire-and-forget: responds immediately, runs sync as background PHP CLI process.
 * Sync progress is tracked via DB and a lock file.
 *
 * GET params:
 *   secret     — shop token (must match module_find_iq_integration_config['token'])
 *   action     — start (default) | status | stop | frontend
 *   mode       — fast (price/qty) | full (default: fast)
 *   actions    — comma-separated: products, categories, frontend (default: products)
 *   batch_size — products per API batch (default: 10)
 *   reset      — 1 = reset sync state (first_synced=NULL, updated=0) before launch
 */
class ControllerFindIqWebhook extends Controller
{
    private function getLockFile(): string
    {
        return DIR_STORAGE . 'find_iq_sync.lock';
    }

    public function index()
    {
        $this->response->addHeader('Content-Type: application/json');

        // 1. Module enabled check
        if ($this->config->get('module_find_iq_integration_status') != '1') {
            $this->response->setOutput(json_encode([
                'status'  => 'error',
                'message' => 'Module is disabled',
            ]));
            return;
        }

        // 2. Token check
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

        $action = $this->request->get['action'] ?? 'start';

        if ($action === 'status') {
            $this->handleStatus();
            return;
        }

        if ($action === 'stop') {
            $this->handleStop();
            return;
        }

        if ($action === 'frontend') {
            $this->handleFrontend();
            return;
        }

        // 3. Parse start params
        $mode    = $this->request->get['mode'] ?? 'fast';
        $actions = $this->request->get['actions'] ?? 'products';
        $batch   = (int)($this->request->get['batch_size'] ?? 50);
        $reset   = isset($this->request->get['reset']) && $this->request->get['reset'] === '1';

        if (!in_array($mode, ['fast', 'full'])) {
            $mode = 'fast';
        }

        // 4. Lock check — prevent duplicate runs
        $lockFile = $this->getLockFile();
        if (is_file($lockFile)) {
            $pid = (int)trim(file_get_contents($lockFile));
            if ($pid > 0 && file_exists('/proc/' . $pid)) {
                $this->response->setOutput(json_encode([
                    'status'  => 'already_running',
                    'pid'     => $pid,
                    'message' => 'Sync job is already running',
                ]));
                return;
            }
            // Stale lock — remove
            @unlink($lockFile);
        }

        // 5. Reset sync state if requested
        if ($reset) {
            $this->load->model('tool/find_iq_cron');
            $this->model_tool_find_iq_cron->resetSyncState();
        }

        // 6. Clear stop flag if present (user called stop then start — start wins)
        $stopFlag = DIR_STORAGE . 'find_iq_sync.stop';
        if (is_file($stopFlag)) {
            @unlink($stopFlag);
        }

        // 7. Build CLI command and launch background process.
        // time=50 — voluntary stop before the server kills the process;
        // cron/find_iq.php will respawn itself if products remain.
        //
        // PHP_BINARY in an FPM request points to /usr/sbin/php-fpmX.Y which
        // cannot execute a script, so fall back to "php" from PATH in that
        // case. That resolves to the CLI binary on any sanely configured host.
        $phpBin = (defined('PHP_BINARY') && PHP_BINARY && strpos(PHP_BINARY, 'fpm') === false)
            ? PHP_BINARY
            : 'php';

        // DIR_BASE is not a standard OpenCart 3 constant; some configs define it,
        // others don't. Derive the OC root from DIR_APPLICATION (which always
        // points to catalog/) by stripping the trailing catalog/ segment.
        $cronFile = dirname(rtrim(DIR_APPLICATION, '/')) . '/cron/find_iq.php';

        // Write webhook marker so cron knows it was launched by webhook (not manual cron)
        file_put_contents(DIR_STORAGE . 'find_iq_sync.webhook', '1');

        $args = 'mode=' . escapeshellarg($mode)
            . ' actions=' . escapeshellarg($actions)
            . ' batch_size=' . (int)$batch
            . ' time=50';

        $cmd = sprintf(
            'nohup %s %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($phpBin),
            escapeshellarg($cronFile),
            $args
        );

        $pid = (int)trim(shell_exec($cmd));

        // Write lock file with PID
        file_put_contents($lockFile, $pid);

        $this->response->setOutput(json_encode([
            'status'  => 'started',
            'pid'     => $pid,
            'reset'   => $reset,
            'mode'    => $mode,
            'actions' => $actions,
            'message' => 'Sync job launched in background',
        ]));
    }

    private function handleStatus(): void
    {
        $lockFile = $this->getLockFile();
        $running  = false;
        $pid      = null;

        if (is_file($lockFile)) {
            $pid     = (int)trim(file_get_contents($lockFile));
            $running = $pid > 0 && file_exists('/proc/' . $pid);
        }

        $query = $this->db->query("
            SELECT
                COUNT(*)                       AS total,
                SUM(first_synced IS NOT NULL)  AS synced,
                SUM(rejected = 1)              AS rejected
            FROM `" . DB_PREFIX . "find_iq_sync_products`
        ");

        $row = $query->row;

        $this->response->setOutput(json_encode([
            'status'   => $running ? 'running' : 'idle',
            'pid'      => $pid,
            'progress' => [
                'total'    => (int)($row['total']    ?? 0),
                'synced'   => (int)($row['synced']   ?? 0),
                'rejected' => (int)($row['rejected'] ?? 0),
            ],
        ]));
    }

    private function handleFrontend(): void
    {
        $this->load->library('FindIQ');
        $this->load->model('tool/find_iq_cron');

        $raw      = $this->FindIQ->getFrontendScript();
        $frontend = json_decode($raw, true);

        if (!isset($frontend['css_url']) || !isset($frontend['js_url'])) {
            $this->response->setOutput(json_encode([
                'status'  => 'error',
                'message' => 'Invalid response from FindIQ API',
                'raw'     => $raw,
            ]));
            return;
        }

        try {
            $frontend['updated_at'] = (new DateTime($frontend['updated_at']))->getTimestamp();
        } catch (Exception $e) {
            $frontend['updated_at'] = (new DateTime())->getTimestamp();
        }

        $this->model_tool_find_iq_cron->updateFrontend($frontend);

        $this->response->setOutput(json_encode([
            'status'     => 'updated',
            'css_url'    => $frontend['css_url'],
            'js_url'     => $frontend['js_url'],
            'updated_at' => $frontend['updated_at'],
        ]));
    }

    private function handleStop(): void
    {
        // Write stop flag — the running cron checks this flag on every batch iteration
        // and halts immediately when found. The flag persists until action=start removes it.
        file_put_contents(DIR_STORAGE . 'find_iq_sync.stop', '1');

        $lockFile = $this->getLockFile();
        $pid      = null;

        if (is_file($lockFile)) {
            $pid = (int)trim(file_get_contents($lockFile));
            @unlink($lockFile);
        }

        $this->response->setOutput(json_encode([
            'status'  => 'stopped',
            'pid'     => $pid,
            'message' => 'Stop flag written — sync will halt after current batch',
        ]));
    }
}
