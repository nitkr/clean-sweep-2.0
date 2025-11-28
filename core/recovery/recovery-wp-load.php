<?php
/**
 * Clean Sweep Recovery Bootstrap
 *
 * Emergency WordPress bootstrap that loads clean core files
 * when the site's wp-settings.php is corrupted or missing.
 *
 * This provides a working WordPress environment for Clean Sweep
 * to perform repairs on severely infected sites.
 */

// ============================================================================
// RECOVERY BOOTSTRAP - CLEAN WORDPRESS ENVIRONMENT
// ============================================================================

// Define recovery mode
define('CLEAN_SWEEP_RECOVERY_MODE', true);

// Load recovery wp-config.php which will:
// 1. Parse site's database credentials
// 2. Load clean recovery-wp-settings.php
require_once __DIR__ . '/recovery-wp-config.php';

// WordPress is now fully initialized with clean core files
// Clean Sweep can proceed with all operations
