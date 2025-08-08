<?php
/**
 * VAX B2B Web Scraper
 * Logs into VAX system and extracts product prices from specified categories
 */

require_once 'config.php';

class VaxScraper {
    private $curl;
    private $cookieFile;
    private $logFile;
    private $isLoggedIn = false;
    
    public function __construct() {
        $this->cookieFile = COOKIE_FILE;
        $this->logFile = LOG_FILE;
        $this->initializeCurl();
        $this->log("VAX Scraper initialized");
    }
      
    /**
     * Initialize cURL with common settings
     */
    private function initializeCurl() {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_USERAGENT => USER_AGENT,
            CURLOPT_TIMEOUT => TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => false,
            CURLOPT_VERBOSE => false,
            CURLOPT_ENCODING => ''
        ]);
    }
    
    /** 
     * Log messages to file and console
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        echo $logMessage;
    }
    
    /**
     * Make HTTP request with retry logic
     */
    private function makeRequest($url, $postData = null, $retries = MAX_RETRIES, $allowAuthErrors = false) {
        for ($i = 0; $i < $retries; $i++) {
            curl_setopt($this->curl, CURLOPT_URL, $url);
            
            if ($postData !== null) {
                curl_setopt($this->curl, CURLOPT_POST, true);
                curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postData);
            } else {
                curl_setopt($this->curl, CURLOPT_POST, false);
            }
            
            $response = curl_exec($this->curl);
            $httpCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
            $error = curl_error($this->curl);
            
            if ($response === false || !empty($error)) {
                $this->log("Request failed (attempt " . ($i + 1) . "): $error");
                if ($i < $retries - 1) {
                    sleep(2); // Wait before retry
                    continue;
                }
                throw new Exception("Failed to make request after $retries attempts: $error");
            }
            
            // Allow 401 errors for login page access (expected behavior)
            if ($httpCode >= 400 && !($allowAuthErrors && $httpCode == 401)) {
                $this->log("HTTP error $httpCode for URL: $url");
                if ($i < $retries - 1) {
                    sleep(2);
                    continue;
                }
                throw new Exception("HTTP error $httpCode");
            }
            
            return $response;
        }
        
        return false;
    }
    
    /**
     * Login to VAX system
     */
    public function login() {
        try {
            $this->log("Attempting to login to VAX system...");
            
            // Set additional headers for login
            curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pl-PL,pl;q=0.9,en;q=0.8',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Cache-Control: max-age=0'
            ]);
            
            // First, get the login page to check for CSRF tokens and collect cookies
            $loginPageHtml = $this->makeRequest(VAX_LOGIN_URL, null, MAX_RETRIES, true);
            
            if (!$loginPageHtml) {
                throw new Exception("Failed to load login page");
            }
            
            $this->log("Login page loaded, looking for form structure...");
            
            // Parse login form to find any hidden fields or CSRF tokens
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML($loginPageHtml);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Look for login form
            $forms = $xpath->query('//form');
            $hiddenFields = [];
            
            foreach ($forms as $form) {
                $inputs = $xpath->query('.//input[@type="hidden"]', $form);
                foreach ($inputs as $input) {
                    $name = $input->getAttribute('name');
                    $value = $input->getAttribute('value');
                    if (!empty($name)) {
                        $hiddenFields[$name] = $value;
                    }
                }
            }
            
            // Prepare login data with correct field names
            $loginData = array_merge($hiddenFields, [
                'security_username' => VAX_EMAIL,
                'security_password' => VAX_PASSWORD,
                'remember_me' => '1',
                'login' => '1'
            ]);
            
            // Convert to POST format
            $postData = http_build_query($loginData);
            
            $this->log("Submitting login form with email: " . VAX_EMAIL);
            
            // Set proper headers for form submission
            curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pl-PL,pl;q=0.9,en;q=0.8',
                'Content-Type: application/x-www-form-urlencoded',
                'Origin: https://b2b.waks.pl',
                'Referer: https://b2b.waks.pl/security/login',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: same-origin'
            ]);
            
            // Submit login form
            $loginResponse = $this->makeRequest(VAX_LOGIN_URL, $postData, MAX_RETRIES, true);
            
            // Check if login was successful
            if (strpos($loginResponse, 'security/login') !== false && 
                strpos($loginResponse, 'error') !== false) {
                throw new Exception("Login failed - invalid credentials or blocked");
            }
            
            // Check for successful login indicators
            if (strpos($loginResponse, 'product/list') !== false || 
                strpos($loginResponse, 'Log Out') !== false ||
                strpos($loginResponse, 'Logout') !== false) {
                $this->isLoggedIn = true;
                $this->log("Successfully logged in to VAX system");
                return true;
            }
            
            // Additional check - try to access a protected page
            $testResponse = $this->makeRequest(VAX_PRODUCT_LIST_URL);
            if (strpos($testResponse, 'Gross list price') !== false) {
                $this->isLoggedIn = true;
                $this->log("Login verified - can access product pages");
                return true;
            }
            
            throw new Exception("Login status unclear - please check credentials");
            
        } catch (Exception $e) {
            $this->log("Login failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Extract product data from a page
     */
    private function extractProductsFromPage($html) {
        $products = [];
        
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Debug: Check if category is empty
        if (strpos($html, 'Brak produktów') !== false || 
            strpos($html, 'no products') !== false ||
            strpos($html, 'Wybierz kategorię') !== false) {
            $this->log("Category appears to be empty or no category selected");
            return $products;
        }
        
        // Try multiple selectors for product rows
        $selectors = [
            '//table//tr[td and position() > 1]', // Skip header row
            '//tr[td[contains(text(), "zł") or contains(text(), "PLN")]]', // Rows with prices
            '//tbody//tr[td]', // Rows in tbody
            '//table[contains(@class, "footable")]//tr[td]' // Footable rows
        ];
        
        $rows = null;
        foreach ($selectors as $selector) {
            $rows = $xpath->query($selector);
            if ($rows->length > 0) {
                $this->log("Found " . $rows->length . " rows using selector: $selector");
                break;
            }
        }
        
        if (!$rows || $rows->length === 0) {
            $this->log("No product rows found with any selector");
            // Debug: Look for any prices in the HTML
            if (preg_match_all('/([0-9]+[,.]?[0-9]*)\s*zł/i', $html, $matches)) {
                $this->log("Found " . count($matches[0]) . " price mentions in HTML");
            }
            return $products;
        }
        
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);
            
            if ($cells->length >= 5) {
                // Extract data from each column
                $productCode = trim($cells->item(1)->textContent ?? '');
                $category = trim($cells->item(2)->textContent ?? '');
                $description = trim($cells->item(3)->textContent ?? '');
                $priceText = trim($cells->item(4)->textContent ?? '');
                $availability = trim($cells->item(5)->textContent ?? '');
                
                // Clean up price - handle both "zł" and corrupted "zÅ"
                $priceText = str_replace(['zÅ', 'zł'], '', $priceText);
                $price = preg_replace('/[^\d,.]/', '', $priceText);
                $price = str_replace(',', '.', $price);
                
                // Skip invalid entries
                if (empty($productCode) || empty($price) || !is_numeric($price)) {
                    continue;
                }
                
                $products[] = [
                    'product_code' => $productCode,
                    'category' => $category,
                    'description' => $this->cleanDescription($description),
                    'gross_price' => (float)$price,
                    'price_currency' => 'PLN',
                    'availability' => $availability,
                    'scraped_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        $this->log("Extracted " . count($products) . " products from current page");
        return $products;
    }
    
    /**
     * Clean product description
     */
    private function cleanDescription($description) {
        // Remove HTML tags and excessive whitespace
        $description = strip_tags($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);
        
        // Fix encoding issues - convert from UTF-8 to proper characters
        $description = mb_convert_encoding($description, 'UTF-8', 'UTF-8');
        
        // Fix common character issues
        $replacements = [
            'Ä' => 'ć',
            'Å' => 'ł', 
            'Ä' => 'ń',
            'Ä' => 'ą',
            'Ä' => 'ę',
            'Å' => 'ś',
            'Å' => 'ź',
            'Å' => 'ż'
        ];
        
        foreach ($replacements as $wrong => $correct) {
            $description = str_replace($wrong, $correct, $description);
        }
        
        // Limit length to prevent CSV issues
        if (strlen($description) > 200) {
            $description = substr($description, 0, 197) . '...';
        }
        
        return $description;
    }
    
    /**
     * Fix encoding issues for Polish characters
     */
    private function fixEncoding($text) {
        // First try to detect and convert from various encodings
        $detectedEncoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-2'], true);
        if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
            $text = mb_convert_encoding($text, 'UTF-8', $detectedEncoding);
        }
        
        // Fix common encoding corruption patterns for Polish characters
        $replacements = [
            // Common UTF-8 corruption patterns
            'Ä…' => 'ą',
            'Ä‡' => 'ć',  
            'Ä™' => 'ę',
            'Å‚' => 'ł',
            'Å' => 'ł',
            'Å„' => 'ń',
            'Ă³' => 'ó',
            'Åº' => 'ś',
            'Ĺ›' => 'ś',
            'ź' => 'ź',
            'Ĺş' => 'ź',
            'Ĺź' => 'ż',
            'ż' => 'ż',
            
            // Price-related fixes
            'zĹ‚' => 'zł',
            'zÅ‚' => 'zł',
            'zÅ' => 'zł',
            'zż' => 'zł',
            
            // Availability fixes  
            'dostÄ' => 'dostę',
            'dostÄp' => 'dostęp',
            'pnoÅ›Ä' => 'pność',
            'pnoÅÄ' => 'pność', 
            'Pytaj o dostÄpnoÅÄ' => 'Pytaj o dostępność',
            'dostÄpnoÅÄ' => 'dostępność',
            'dostępnoÅÄ' => 'dostępność',
            'dostępnołÄ' => 'dostępność',
            
            // Product name fixes
            'moduÅowy' => 'modułowy',
            'moduł' => 'modułowy',
            'prÄdowy' => 'prądowy',
            'zastosowañ' => 'zastosowań',
            'przemysłowych' => 'przemysłowych',
            'specjalistyczny komponent aktywny do zastosowaÅ\n przemysÅowych' => 'specjalistyczny komponent aktywny do zastosowań przemysłowych'
        ];
        
        foreach ($replacements as $wrong => $correct) {
            $text = str_replace($wrong, $correct, $text);
        }
        
        return trim($text);
    }
    
    /**
     * Get total number of pages
     */
    private function getTotalPages($html) {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Look for pagination info - "of [66]" format
        $paginationText = $xpath->query('//text()[contains(., "of")]');
        
        foreach ($paginationText as $text) {
            if (preg_match('/of\s+\[(\d+)\]/', $text->textContent, $matches)) {
                return (int)$matches[1];
            }
        }
        
        // Alternative: look for page links
        $pageLinks = $xpath->query('//a[contains(@href, "/page/")]');
        $maxPage = 1;
        
        foreach ($pageLinks as $link) {
            $href = $link->getAttribute('href');
            if (preg_match('/page\/(\d+)/', $href, $matches)) {
                $maxPage = max($maxPage, (int)$matches[1]);
            }
        }
        
        return $maxPage;
    }
    
    /**
     * Scrape all products from category using AJAX endpoint
     */
    public function scrapeProducts($categoryId = CATEGORY_ID) {
        if (!$this->isLoggedIn) {
            throw new Exception("Must be logged in before scraping");
        }
        
        $this->log("Starting to scrape products from category $categoryId using AJAX endpoint");
        
        // Use AJAX endpoint which loads all products at once
        $ajaxUrl = VAX_BASE_URL . "/product/list/ajax";
        
        // Set AJAX headers
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json, text/html, */*; q=0.01',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            'Referer: ' . VAX_BASE_URL . '/product/list/page/1?productcategoryid=' . $categoryId
        ]);
        
        // Make AJAX request
        $postData = http_build_query(['productcategoryid' => $categoryId]);
        $ajaxResponse = $this->makeRequest($ajaxUrl, $postData);
        
        if (!$ajaxResponse) {
            throw new Exception("Failed to load AJAX product data");
        }
        
        $this->log("AJAX response received, size: " . strlen($ajaxResponse) . " bytes");
        
        // Extract products from AJAX response
        $products = $this->extractProductsFromPage($ajaxResponse);
        
        $this->log("Completed scraping. Total products found: " . count($products));
        return $products;
    }
    
    /**
     * Save products to CSV file
     */
    public function saveToCSV($products, $filename = OUTPUT_FILE) {
        if (empty($products)) {
            $this->log("No products to save");
            return false;
        }
        
        $this->log("Saving " . count($products) . " products to CSV: $filename");
        
        $fp = fopen($filename, 'w');
        if (!$fp) {
            throw new Exception("Cannot create output file: $filename");
        }
        
        // No BOM for clean Google Sheets compatibility
        // (BOM removed to avoid display issues)
        
        // Write header
        $headers = [
            'Product Code',
            'Name', 
            'zakup',
            'Gross Price (PLN)',
            'Currency',
            'Availability',
            'Scraped At'
        ];
        
        fputcsv($fp, $headers, ','); // Use comma for Google Sheets compatibility
        
        // Write data
        foreach ($products as $product) {
            // Clean and fix encoding for all text fields
            $cleanDescription = $this->fixEncoding($product['description']);
            $cleanAvailability = $this->fixEncoding($product['availability']);
            
            // Remove problematic characters that can break CSV structure
            // Replace commas in descriptions with periods for CSV compatibility
            $cleanDescription = str_replace([',', '"', '\n', '\r'], ['.', "'", ' ', ' '], $cleanDescription);
            $cleanAvailability = str_replace([',', '"', '\n', '\r'], [' ', "'", ' ', ' '], $cleanAvailability);
            
            // Remove currency symbols from descriptions
            $cleanDescription = str_replace(['zł', 'zÅ', 'PLN'], '', $cleanDescription);
            $cleanDescription = preg_replace('/\s+/', ' ', trim($cleanDescription)); // Clean extra spaces
            
            // Clean product code and category too
            $cleanProductCode = str_replace([',', '"', '\n', '\r'], ['-', "'", ' ', ' '], $product['product_code']);
            $cleanCategory = str_replace([',', '"', '\n', '\r'], ['-', "'", ' ', ' '], $product['category']);
            
            $row = [
                $cleanProductCode,
                $cleanCategory,
                $cleanDescription,
                number_format($product['gross_price'], 2, '.', ''), // Use dot for decimal separator in CSV
                $product['price_currency'],
                $cleanAvailability,
                $product['scraped_at']
            ];
            
            fputcsv($fp, $row, ',');
        }
        
        fclose($fp);
        $this->log("CSV file saved successfully: $filename");
        return true;
    }
    
    /**
     * Display products in console
     */
    public function displayProducts($products) {
        if (empty($products)) {
            echo "No products found.\n";
            return;
        }
        
        echo "\n" . str_repeat("=", 100) . "\n";
        echo "EXTRACTED PRODUCTS FROM VAX B2B SYSTEM\n";
        echo str_repeat("=", 100) . "\n";
        echo sprintf("%-15s %-12s %-50s %-12s %-15s\n", 
            'Product Code', 'Category', 'Description', 'Price (PLN)', 'Availability');
        echo str_repeat("-", 100) . "\n";
        
        foreach ($products as $product) {
            $description = strlen($product['description']) > 50 ? 
                substr($product['description'], 0, 47) . '...' : 
                $product['description'];
                
            echo sprintf("%-15s %-12s %-50s %8.2f PLN %-15s\n",
                $product['product_code'],
                $product['category'],
                $description,
                $product['gross_price'],
                $product['availability']
            );
        }
        
        echo str_repeat("=", 100) . "\n";
        echo "Total products: " . count($products) . "\n";
        echo "Average price: " . number_format(array_sum(array_column($products, 'gross_price')) / count($products), 2) . " PLN\n";
        echo str_repeat("=", 100) . "\n\n";
    }
    
    /**
     * Cleanup resources
     */
    public function __destruct() {
        if ($this->curl) {
            curl_close($this->curl);
        }
        
        // Clean up cookie file
        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }
}

// Main execution
try {
    echo "VAX B2B Product Price Scraper\n";
    echo "=============================\n\n";
    
    // Initialize scraper
    $scraper = new VaxScraper();
    
    // Login
    if (!$scraper->login()) {
        throw new Exception("Failed to login to VAX system");
    }
    
    // Try different categories if the main one is empty
    $categoriesToTry = [
        CATEGORY_ID, // Original category 1395
        1, 2, 3, 5, 10, 20, 50, 100 // Common category IDs
    ];
    
    $products = [];
    foreach ($categoriesToTry as $categoryId) {
        echo "Trying category ID: $categoryId\n";
        try {
            $products = $scraper->scrapeProducts($categoryId);
            if (!empty($products)) {
                echo "Found products in category $categoryId!\n";
                break;
            }
        } catch (Exception $e) {
            echo "Category $categoryId failed: " . $e->getMessage() . "\n";
            continue;
        }
    }
    
    if (empty($products)) {
        echo "No products found in any tested category.\n";
        echo "This might indicate:\n";
        echo "1. The account has no access to product data\n";
        echo "2. All tested categories are empty\n";
        echo "3. The website structure has changed\n";
        echo "4. Products are loaded dynamically via JavaScript\n";
        exit(1);
    }
    
    // Display results
    $scraper->displayProducts($products);
    
    // Save to CSV
    $scraper->saveToCSV($products);
    
    // Also save as awaxprices.csv for easy access
    $awaxFile = "awaxprices.csv";
    $scraper->saveToCSV($products, $awaxFile);
    
    echo "Scraping completed successfully!\n";
    echo "Results saved to: " . OUTPUT_FILE . "\n";
    echo "Also saved as: " . $awaxFile . "\n";
    echo "Log file: " . LOG_FILE . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    file_put_contents(LOG_FILE, "[" . date('Y-m-d H:i:s') . "] FATAL ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    exit(1);
}
?>
