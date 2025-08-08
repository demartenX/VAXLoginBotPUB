<?php
/**
 * Configuration file for VAX B2B scraper 
 */

// VAX B2B system configuration
define('VAX_BASE_URL', 'https://b2b.waks.pl');
define('VAX_LOGIN_URL', VAX_BASE_URL . '/security/login');
define('VAX_PRODUCT_LIST_URL', VAX_BASE_URL . '/product/list/page/1?productcategoryid=1395');

// Authentication credentials - get from environment variables
define('VAX_EMAIL', getenv('VAX_EMAIL') ?: 'email');
define('VAX_PASSWORD', getenv('VAX_PASSWORD') ?: 'password');

// Scraper settings
define('COOKIE_FILE', __DIR__ . '/vax_cookies.txt');
define('LOG_FILE', __DIR__ . '/scraper_log.txt');
define('OUTPUT_FILE', __DIR__ . '/vax_prices_' . date('Y-m-d_H-i-s') . '.csv');

// HTTP settings
define('USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
define('TIMEOUT', 30);
define('MAX_RETRIES', 3);

// Category ID to scrape  
define('CATEGORY_ID', 1395);
?>
