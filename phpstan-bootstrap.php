<?php
/**
 * PHPStan bootstrap file for WordPress constants and stubs
 */

// Define WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

if (!defined('NVM_WEBHOOK_API_KEY')) {
    define('NVM_WEBHOOK_API_KEY', 'test-key');
}

if (!defined('NVM_WEBHOOK_LOGS')) {
    define('NVM_WEBHOOK_LOGS', false);
}
