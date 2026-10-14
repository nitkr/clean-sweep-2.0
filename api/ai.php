<?php
/**
 * AI scan API. The signature engine stays on api/malware.php.
 */
require_once __DIR__ . '/bootstrap.php';
require_once CLEAN_SWEEP_ROOT . 'includes/ApiResponse.php';
require_once CLEAN_SWEEP_ROOT . 'features/ai/load.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pipeline = new AiPipeline();
$config = new AiConfig($pipeline->store());

switch ($action) {
    case 'get_config':
        $public = $config->getPublic();
        $public['prefixes'] = (new AiExcludeList($pipeline->store(), ai_site_root()))->all();
        CleanSweep_ApiResponse::sendSuccess($public);
        break;

    case 'save_config':
        $result = $config->save([
            'provider' => $_POST['provider'] ?? null,
            'key' => $_POST['key'] ?? null,
            'model' => $_POST['model'] ?? null,
        ]);
        if (empty($result['ok'])) {
            CleanSweep_ApiResponse::sendError($result['error'] ?? 'Could not save.', $result['code'] ?? 'SAVE_FAILED');
        }
        CleanSweep_ApiResponse::sendSuccess($config->getPublic());
        break;

    case 'save_policy':
        if (isset($_POST['database_ai']) && (string) $_POST['database_ai'] === 'send') {
            CleanSweep_ApiResponse::sendError(
                'Database AI review is not in this version.',
                'DATABASE_AI_UNAVAILABLE'
            );
        }
        $input = [];
        if (isset($_POST['request_logs'])) {
            $input['request_logs'] = $_POST['request_logs'];
        }
        if (isset($_POST['full_pass'])) {
            $input['full_pass'] = $_POST['full_pass'];
        }
        if ($input !== []) {
            $saved = $config->save($input);
            if (empty($saved['ok'])) {
                CleanSweep_ApiResponse::sendError($saved['error'] ?? 'Could not save.', $saved['code'] ?? 'SAVE_FAILED');
            }
        }
        $list = new AiExcludeList($pipeline->store(), ai_site_root());
        if (isset($_POST['add_prefix'])) {
            $added = $list->add((string) $_POST['add_prefix']);
            if (empty($added['ok'])) {
                CleanSweep_ApiResponse::sendError($added['error'] ?? 'Could not add that path.', $added['code'] ?? 'BAD_PREFIX');
            }
        }
        if (isset($_POST['remove_prefix'])) {
            $list->remove((string) $_POST['remove_prefix']);
        }
        $public = $config->getPublic();
        $public['prefixes'] = $list->all();
        CleanSweep_ApiResponse::sendSuccess($public);
        break;

    case 'list_models':
        $raw = $config->raw();
        if ($raw['key'] === '' || $config->baseUrl() === '') {
            CleanSweep_ApiResponse::sendError('Save a provider and key first.', 'NO_KEY');
        }
        $client = new AiChatClient($config->baseUrl(), $raw['key'], AiChatClient::timeoutForHost());
        $listed = $client->listModels();
        if (empty($listed['ok'])) {
            CleanSweep_ApiResponse::sendError($listed['error'] ?? 'Could not load models.', 'MODELS_FAILED');
        }
        if (!empty($listed['windows']) && is_array($listed['windows'])) {
            $config->rememberWindows($listed['windows']);
        }
        if (!empty($listed['prices']) && is_array($listed['prices'])) {
            $config->rememberPrices($listed['prices']);
        }
        CleanSweep_ApiResponse::sendSuccess(['models' => $listed['models']]);
        break;

    case 'test':
        $raw = $config->raw();
        if ($raw['key'] === '' || $raw['model'] === '' || $config->baseUrl() === '') {
            CleanSweep_ApiResponse::sendError('Choose a provider, key, and model first.', 'NOT_READY');
        }
        $client = new AiChatClient($config->baseUrl(), $raw['key'], AiChatClient::timeoutForHost());
        $reply = $client->complete($raw['model'], 'Reply with the single word ok.', 'ok', 16);
        $config->markTested(!empty($reply['ok']));
        if (empty($reply['ok'])) {
            $message = (string) ($reply['error'] ?? 'Test failed.');
            if ($raw['key'] !== '') {
                $message = str_replace($raw['key'], '[key]', $message);
            }
            CleanSweep_ApiResponse::sendError($message, 'TEST_FAILED');
        }
        CleanSweep_ApiResponse::sendSuccess($config->getPublic());
        break;

    case 'begin':
        $begun = $pipeline->begin(trim((string) ($_POST['scan_id'] ?? '')));
        if (empty($begun['ok'])) {
            CleanSweep_ApiResponse::sendError($begun['error'] ?? 'Could not start.', $begun['code'] ?? 'BEGIN_FAILED');
        }
        CleanSweep_ApiResponse::sendSuccess($pipeline->status());
        break;

    case 'start':
        $scanId = trim((string) ($_POST['scan_id'] ?? ''));
        $status = $pipeline->status();
        if ($scanId !== '' && $status['scan_id'] !== $scanId) {
            $begun = $pipeline->begin($scanId);
            if (empty($begun['ok'])) {
                CleanSweep_ApiResponse::sendError($begun['error'] ?? 'Could not start.', $begun['code'] ?? 'BEGIN_FAILED');
            }
        }
        CleanSweep_ApiResponse::sendSuccess($pipeline->step());
        break;

    case 'status':
        CleanSweep_ApiResponse::sendSuccess($pipeline->status());
        break;

    case 'cancel':
        CleanSweep_ApiResponse::sendSuccess($pipeline->cancel());
        break;

    case 'get_log':
        $status = $pipeline->status();
        $offset = (int) ($_POST['offset'] ?? $_GET['offset'] ?? 0);
        $limit = (int) ($_POST['limit'] ?? $_GET['limit'] ?? 200);
        $rows = (new AiReadLog($pipeline->store()))->forScan((string) $status['scan_id'], $offset, $limit);
        CleanSweep_ApiResponse::sendSuccess([
            'rows' => $rows,
            'count' => count($rows),
            'total' => (new AiReadLog($pipeline->store()))->count((string) $status['scan_id']),
        ]);
        break;

    case 'get_verdicts':
        $status = $pipeline->status();
        $rows = (new AiVerdictStore($pipeline->store()))->forScan((string) $status['scan_id']);
        $offset = (int) ($_POST['offset'] ?? $_GET['offset'] ?? 0);
        $limit = (int) ($_POST['limit'] ?? $_GET['limit'] ?? 200);
        $slice = array_slice($rows, max(0, $offset), max(1, $limit));
        CleanSweep_ApiResponse::sendSuccess([
            'verdicts' => $slice,
            'total' => count($rows),
        ]);
        break;

    case 'get_report':
        $report = $pipeline->report();
        if ($report === null) {
            CleanSweep_ApiResponse::sendError('The report is not ready.', 'NOT_READY');
        }
        CleanSweep_ApiResponse::sendSuccess($report);
        break;

    case 'send_file':
        $sent = $pipeline->sendFile(trim((string) ($_POST['path'] ?? '')));
        if (empty($sent['ok'])) {
            CleanSweep_ApiResponse::sendError($sent['error'] ?? 'Could not send that file.', $sent['code'] ?? 'SEND_FAILED');
        }
        CleanSweep_ApiResponse::sendSuccess($pipeline->report() ?? []);
        break;

    case 'keep':
        $pipeline->keep(trim((string) ($_POST['path'] ?? '')));
        $report = $pipeline->report();
        CleanSweep_ApiResponse::sendSuccess($report ?? ['ok' => true]);
        break;

    default:
        CleanSweep_ApiResponse::sendError('Unknown action: ' . $action, 'UNKNOWN_ACTION');
}

function ai_site_root(): string {
    return AiPaths::siteRoot();
}
