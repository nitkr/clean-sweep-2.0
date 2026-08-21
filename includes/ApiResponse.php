<?php
/**
 * Clean Sweep - API Response Standardization
 *
 * Provides standardized JSON response format for all API endpoints.
 * Ensures consistent success/error/progress response structure.
 *
 * @author Clean Sweep
 * @version 1.0
 */

class CleanSweep_ApiResponse {
    
    /**
     * Send a successful response
     *
     * @param mixed $data Response data
     * @param string $message Optional success message
     * @return array
     */
    public static function success($data = null, $message = '') {
        return [
            'success' => true,
            'data' => $data,
            'message' => $message,
            'timestamp' => time()
        ];
    }
    
    /**
     * Send an error response
     *
     * @param string $message Error message
     * @param string $code Error code for programmatic handling
     * @param array $details Additional error details
     * @return array
     */
    public static function error($message, $code = 'ERROR', $details = []) {
        return [
            'success' => false,
            'error' => $message,
            'code' => $code,
            'details' => $details,
            'timestamp' => time()
        ];
    }
    
    /**
     * Send progress update
     *
     * @param int $percent Progress percentage (0-100)
     * @param string $message Progress message
     * @param string $status Status: 'running', 'complete', 'error', 'cancelled'
     * @return array
     */
    public static function progress($percent, $message, $status = 'running') {
        return [
            'progress' => (int) $percent,
            'message' => $message,
            'status' => $status,
            'timestamp' => time()
        ];
    }
    
    /**
     * Send response and exit (for API endpoints)
     *
     * @param array $response Response array
     * @param int $statusCode HTTP status code
     */
    public static function send($response, $statusCode = 200) {
        if (is_array($response) && !isset($response['toolkit_integrity']) && isset($GLOBALS['clean_sweep_toolkit_integrity'])) {
            $response['toolkit_integrity'] = $GLOBALS['clean_sweep_toolkit_integrity'];
        }
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Send success and exit
     */
    public static function sendSuccess($data = null, $message = '') {
        self::send(self::success($data, $message));
    }
    
    /**
     * Send error and exit.
     *
     * Third argument may be:
     * - int HTTP status (common callers pass 404/403) — used as the response code
     * - array details payload (optional fourth arg = HTTP status)
     *
     * Default HTTP status is 400.
     *
     * @param string $message
     * @param string $code
     * @param array|int $detailsOrStatus
     * @param int|null $httpStatus
     */
    public static function sendError($message, $code = 'ERROR', $detailsOrStatus = [], $httpStatus = null) {
        $details = [];
        $status = 400;
        if (is_int($detailsOrStatus)) {
            $status = $detailsOrStatus;
        } elseif (is_array($detailsOrStatus)) {
            $details = $detailsOrStatus;
            if (is_int($httpStatus)) {
                $status = $httpStatus;
            }
        }
        if ($status < 400 || $status > 599) {
            $status = 400;
        }
        self::send(self::error($message, $code, $details), $status);
    }
    
    /**
     * Send progress and exit
     */
    public static function sendProgress($percent, $message, $status = 'running') {
        self::send(self::progress($percent, $message, $status));
    }
    
    /**
     * Validate required POST parameters
     *
     * @param array $required Array of required parameter names
     * @return array|bool True if valid, or error response array
     */
    public static function validateParams($required) {
        $missing = [];
        
        foreach ($required as $param) {
            if (!isset($_POST[$param]) || (is_string($_POST[$param]) && trim($_POST[$param]) === '')) {
                $missing[] = $param;
            }
        }
        
        if (!empty($missing)) {
            return self::error(
                'Missing required parameters: ' . implode(', ', $missing),
                'MISSING_PARAMS',
                ['missing' => $missing]
            );
        }
        
        return true;
    }
}
