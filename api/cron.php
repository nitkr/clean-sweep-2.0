<?php
/**
 * Clean Sweep — Cron / scheduled task audit API
 *
 * Actions:
 *   audit                 Full WP-Cron + Action Scheduler + optional crontab
 *   delete_event          hook, timestamp, sig?
 *   clear_hook            hook
 *   cancel_as_action      action_id
 */

require_once __DIR__ . '/bootstrap.php';
require_once CLEAN_SWEEP_ROOT . 'includes/ApiResponse.php';
require_once CLEAN_SWEEP_ROOT . 'features/security/cron-audit.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'audit_cron':
        clean_sweep_handle_cron_audit();
        break;
    case 'delete_event':
        clean_sweep_handle_delete_event();
        break;
    case 'clear_hook':
        clean_sweep_handle_clear_hook();
        break;
    case 'cancel_as_action':
        clean_sweep_handle_cancel_as();
        break;
    default:
        CleanSweep_ApiResponse::sendError('Unknown action: ' . $action, 'UNKNOWN_ACTION');
}

function clean_sweep_handle_cron_audit() {
    @set_time_limit(120);
    try {
        $audit = new CleanSweep_CronAudit();
        $data = $audit->audit();
        CleanSweep_ApiResponse::sendSuccess($data);
    } catch (Throwable $e) {
        CleanSweep_ApiResponse::sendError('Cron audit failed: ' . $e->getMessage(), 'CRON_AUDIT_ERROR');
    }
}

function clean_sweep_handle_delete_event() {
    $hook = isset($_POST['hook']) ? (string) $_POST['hook'] : '';
    $timestamp = (int) ($_POST['timestamp'] ?? 0);
    $sig = isset($_POST['sig']) ? (string) $_POST['sig'] : null;
    $audit = new CleanSweep_CronAudit();
    $result = $audit->delete_wp_cron_event($hook, $timestamp, $sig);
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Failed', 'DELETE_EVENT_FAILED');
    }
    CleanSweep_ApiResponse::sendSuccess($result);
}

function clean_sweep_handle_clear_hook() {
    $hook = isset($_POST['hook']) ? (string) $_POST['hook'] : '';
    $audit = new CleanSweep_CronAudit();
    $result = $audit->clear_hook($hook);
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Failed', 'CLEAR_HOOK_FAILED');
    }
    CleanSweep_ApiResponse::sendSuccess($result);
}

function clean_sweep_handle_cancel_as() {
    $action_id = (int) ($_POST['action_id'] ?? 0);
    $audit = new CleanSweep_CronAudit();
    $result = $audit->cancel_as_action($action_id);
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Failed', 'CANCEL_AS_FAILED');
    }
    CleanSweep_ApiResponse::sendSuccess($result);
}
