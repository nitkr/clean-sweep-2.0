<?php
/**
 * Clean Sweep — Users / Access Audit API
 *
 * Actions:
 *   audit                    Full user access audit
 *   revoke_app_passwords     user_id
 *   destroy_sessions         user_id
 *   demote_user              user_id, role?
 *   delete_user              user_id, reassign?
 */

require_once __DIR__ . '/bootstrap.php';
require_once CLEAN_SWEEP_ROOT . 'includes/ApiResponse.php';
require_once CLEAN_SWEEP_ROOT . 'features/security/user-audit.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'audit_users':
        clean_sweep_handle_users_audit();
        break;
    case 'revoke_app_passwords':
        clean_sweep_handle_revoke_app_passwords();
        break;
    case 'destroy_sessions':
        clean_sweep_handle_destroy_sessions();
        break;
    case 'demote_user':
        clean_sweep_handle_demote_user();
        break;
    case 'delete_user':
        clean_sweep_handle_delete_user();
        break;
    default:
        CleanSweep_ApiResponse::sendError('Unknown action: ' . $action, 'UNKNOWN_ACTION');
}

function clean_sweep_handle_users_audit() {
    @set_time_limit(120);
    try {
        $audit = new CleanSweep_UserAudit();
        $data = $audit->audit();
        CleanSweep_ApiResponse::sendSuccess($data);
    } catch (Throwable $e) {
        CleanSweep_ApiResponse::sendError('User audit failed: ' . $e->getMessage(), 'USER_AUDIT_ERROR');
    }
}

function clean_sweep_handle_revoke_app_passwords() {
    $user_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $audit = new CleanSweep_UserAudit();
    $result = $audit->revoke_application_passwords($user_id);
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Failed', 'REVOKE_APP_PW_FAILED');
    }
    CleanSweep_ApiResponse::sendSuccess($result);
}

function clean_sweep_handle_destroy_sessions() {
    $user_id = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    $audit = new CleanSweep_UserAudit();
    $result = $audit->destroy_sessions($user_id);
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Failed', 'DESTROY_SESSIONS_FAILED');
    }
    CleanSweep_ApiResponse::sendSuccess($result);
}

function clean_sweep_handle_demote_user() {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $role = isset($_POST['role']) ? sanitize_key((string) $_POST['role']) : 'subscriber';
    $audit = new CleanSweep_UserAudit();
    $result = $audit->demote_user($user_id, $role);
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Failed', 'DEMOTE_USER_FAILED');
    }
    CleanSweep_ApiResponse::sendSuccess($result);
}

function clean_sweep_handle_delete_user() {
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $reassign = isset($_POST['reassign']) ? (int) $_POST['reassign'] : null;
    $audit = new CleanSweep_UserAudit();
    $result = $audit->delete_user($user_id, $reassign);
    if (empty($result['success'])) {
        CleanSweep_ApiResponse::sendError($result['error'] ?? 'Failed', 'DELETE_USER_FAILED');
    }
    CleanSweep_ApiResponse::sendSuccess($result);
}
