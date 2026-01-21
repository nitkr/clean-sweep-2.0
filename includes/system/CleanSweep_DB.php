<?php
/**
 * Clean Sweep - Direct Database Connection
 *
 * Parses wp-config.php and creates direct database connections without loading WordPress.
 *
 * @version 1.0
 * @author Nithin K R
 */

class CleanSweep_DB {

    private $pdo = null;
    private $db_config = [];

    /**
     * Constructor - parse wp-config.php and establish connection
     */
    public function __construct() {
        $this->parse_wp_config();
        $this->connect();
    }

    /**
     * Parse wp-config.php to extract database configuration
     */
    private function parse_wp_config() {
        // Use robust wp-config detection (same as FreshEnvironment)
        $wp_config_paths = [
            dirname(dirname(dirname(__DIR__))) . '/wp-config.php',
            dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-config.php',
            dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/wp-config.php'
        ];

        $wp_config_found = false;
        $config_content = '';

        foreach ($wp_config_paths as $config_path) {
            if (file_exists($config_path)) {
                $config_content = file_get_contents($config_path);
                $wp_config_found = true;
                break;
            }
        }

        if (!$wp_config_found) {
            throw new Exception('Clean Sweep: Could not find wp-config.php. Please ensure Clean Sweep is placed in your WordPress root directory.');
        }

        // Parse database constants from wp-config.php
        $patterns = [
            'DB_HOST' => "/define\(\s*['\"](DB_HOST)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_NAME' => "/define\(\s*['\"](DB_NAME)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_USER' => "/define\(\s*['\"](DB_USER)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_PASSWORD' => "/define\(\s*['\"](DB_PASSWORD)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_CHARSET' => "/define\(\s*['\"](DB_CHARSET)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'DB_COLLATE' => "/define\(\s*['\"](DB_COLLATE)['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\);/",
            'table_prefix' => "/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]\s*;/"
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $config_content, $matches)) {
                $this->db_config[$key] = $matches[1];
            }
        }

        // Set defaults
        $this->db_config['DB_CHARSET'] = $this->db_config['DB_CHARSET'] ?? 'utf8';
        $this->db_config['table_prefix'] = $this->db_config['table_prefix'] ?? 'wp_';

        // Validate required config
        $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
        foreach ($required as $req) {
            if (empty($this->db_config[$req])) {
                throw new Exception("Clean Sweep: Could not parse $req from wp-config.php");
            }
        }
    }

    /**
     * Establish PDO database connection
     */
    private function connect() {
        try {
            // Remove charset from DSN to avoid "Unknown character set" errors
            // Some MySQL versions don't recognize 'utf8' and require 'utf8mb4'
            $dsn = "mysql:host={$this->db_config['DB_HOST']};dbname={$this->db_config['DB_NAME']}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->pdo = new PDO($dsn, $this->db_config['DB_USER'], $this->db_config['DB_PASSWORD'], $options);

            if (function_exists('clean_sweep_log_message')) {
                clean_sweep_log_message("✅ Database connection established successfully", 'info');
            }

        } catch (PDOException $e) {
            throw new Exception("Clean Sweep: Database connection failed - " . $e->getMessage());
        }
    }

    /**
     * Get PDO instance
     */
    public function get_pdo() {
        return $this->pdo;
    }

    /**
     * Get database configuration
     */
    public function get_config() {
        return $this->db_config;
    }

    /**
     * Get table prefix
     */
    public function get_table_prefix() {
        return $this->db_config['table_prefix'];
    }

    /**
     * Execute a query and return results
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new Exception("Database query failed: " . $e->getMessage());
        }
    }

    /**
     * Get single row
     */
    public function get_row($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Get multiple rows
     */
    public function get_results($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Get single value
     */
    public function get_var($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }
}
