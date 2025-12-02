<?php
/**
 * Clean Sweep - Batch Processing Exception
 *
 * Exception thrown during batch processing operations.
 *
 * @author Nithin K R
 */

class CleanSweep_BatchProcessingException extends Exception {

    /**
     * @var array
     */
    private $contextData;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param array $contextData Additional context data
     * @param int $code Error code
     * @param Exception $previous Previous exception
     */
    public function __construct($message = '', $contextData = [], $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->contextData = $contextData;

        // Log the exception
        clean_sweep_log_message("BatchProcessingException: {$message}", 'error');

        if (!empty($contextData)) {
            clean_sweep_log_message("BatchProcessingException context: " . json_encode($contextData), 'error');
        }
    }

    /**
     * Get context data
     *
     * @return array Context data
     */
    public function getContextData() {
        return $this->contextData;
    }

    /**
     * Set context data
     *
     * @param array $contextData
     */
    public function setContextData($contextData) {
        $this->contextData = $contextData;
    }

    /**
     * Add context data
     *
     * @param string $key
     * @param mixed $value
     */
    public function addContext($key, $value) {
        $this->contextData[$key] = $value;
    }

    /**
     * Get context value
     *
     * @param string $key
     * @return mixed|null
     */
    public function getContext($key) {
        return $this->contextData[$key] ?? null;
    }

    /**
     * Convert to array for logging/serialization
     *
     * @return array Exception data
     */
    public function toArray() {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString(),
            'context' => $this->contextData,
            'timestamp' => time()
        ];
    }

    /**
     * Create from array (for deserialization)
     *
     * @param array $data Exception data
     * @return CleanSweep_BatchProcessingException
     */
    public static function fromArray($data) {
        $exception = new self(
            $data['message'] ?? 'Unknown error',
            $data['context'] ?? [],
            $data['code'] ?? 0
        );

        return $exception;
    }
}
