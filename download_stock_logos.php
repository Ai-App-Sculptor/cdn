<?php
/**
 * Stock Logo Downloader
 * Downloads SVG logos from Finnhub API for all tickers in the stock-status API
 *
 * Usage: php download_stock_logos.php [start_id]
 */

class StockLogoDownloader {
    private $baseUrl;
    private $apiUrl;
    private $downloadDir;
    private $logFile;
    private $startId;
    
    public function __construct($startId = null) {
        // Load configuration from local file
        $configFile = __DIR__ . '/config.local.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            $this->baseUrl = $config['api']['finnhub_logo_base_url'];
            $this->apiUrl = $config['api']['stock_status_api_url'];
        } else {
            // Fallback to default values if config file doesn't exist
            $this->baseUrl = 'https://static2.finnhub.io/file/publicdatany/finnhubimage/stock_logo/';
            $this->apiUrl = 'https://codestomp.com/api/stock-status';
            $this->log("⚠️ Local config file not found, using default URLs");
        }
        
        $this->downloadDir = __DIR__ . '/images/stocks';
        $this->logFile = __DIR__ . '/logs/logo_download.log';
        $this->startId = $startId;
        $this->setupDirectories();
    }
    
    private function setupDirectories() {
        // Create download directory if it doesn't exist
        if (!file_exists($this->downloadDir)) {
            if (!mkdir($this->downloadDir, 0755, true)) {
                die("❌ Failed to create download directory: {$this->downloadDir}\n");
            }
            $this->log("📁 Created download directory: {$this->downloadDir}");
        }
        
        // Create logs directory if it doesn't exist
        $logDir = dirname($this->logFile);
        if (!file_exists($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                die("❌ Failed to create logs directory: {$logDir}\n");
            }
        }
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    public function getTickers() {
        try {
            $this->log("🔍 Fetching tickers from API...");
            
            // Initialize cURL
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StockLogoDownloader/1.0)',
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                $this->log("❌ cURL error while fetching tickers: {$error}");
                return [];
            }
            
            if ($httpCode !== 200) {
                $this->log("❌ HTTP {$httpCode} while fetching tickers");
                return [];
            }
            
            $data = json_decode($response, true);
            
            if (!isset($data['success']) || $data['success'] !== true || !isset($data['data'])) {
                $this->log("❌ Invalid API response format");
                return [];
            }
            
            // Filter by start ID if provided
            if ($this->startId !== null) {
                $filteredData = [];
                $foundStartId = false;
                
                foreach ($data['data'] as $item) {
                    if ($foundStartId || $item['id'] == $this->startId) {
                        $foundStartId = true;
                        $filteredData[] = $item;
                    }
                }
                
                if (!$foundStartId) {
                    $this->log("⚠️ Start ID {$this->startId} not found, using all tickers");
                    $tickers = array_column($data['data'], 'ticker');
                } else {
                    $this->log("🔍 Starting from ID {$this->startId}");
                    $tickers = array_column($filteredData, 'ticker');
                }
            } else {
                $tickers = array_column($data['data'], 'ticker');
            }
            
            $this->log("📊 Found " . count($tickers) . " tickers from API");
            return $tickers;
        } catch (Exception $e) {
            $this->log("❌ Error while fetching tickers: " . $e->getMessage());
            return [];
        }
    }
    
    public function downloadLogo($ticker) {
        $url = $this->baseUrl . $ticker . '.svg';
        $filePath = $this->downloadDir . '/' . $ticker . '.svg';
        
        // Skip if file already exists
        if (file_exists($filePath)) {
            $this->log("⏭️  Skipping {$ticker} - file already exists");
            return ['status' => 'skipped', 'message' => 'File already exists'];
        }
        
        $this->log("⬇️  Downloading logo for {$ticker}...");
        
        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StockLogoDownloader/1.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: image/svg+xml,image/*,*/*',
                'Accept-Language: en-US,en;q=0.9'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->log("❌ cURL error for {$ticker}: {$error}");
            return ['status' => 'error', 'message' => $error];
        }
        
        if ($httpCode !== 200) {
            $this->log("⚠️  HTTP {$httpCode} for {$ticker} - logo may not exist");
            return ['status' => 'not_found', 'message' => "HTTP {$httpCode}"];
        }
        
        // Validate that the response is actually an SVG
        if (strpos($response, '<svg') === false) {
            $this->log("⚠️  Invalid SVG response for {$ticker}");
            return ['status' => 'invalid', 'message' => 'Not a valid SVG file'];
        }
        
        // Save the file
        if (file_put_contents($filePath, $response) === false) {
            $this->log("❌ Failed to save file for {$ticker}");
            return ['status' => 'error', 'message' => 'Failed to save file'];
        }
        
        $fileSize = number_format(strlen($response) / 1024, 2);
        $this->log("✅ Successfully downloaded {$ticker}.svg ({$fileSize} KB)");
        return ['status' => 'success', 'message' => "Downloaded {$fileSize} KB"];
    }
    
    public function downloadAll() {
        $this->log("🚀 Starting stock logo download process...");
        if ($this->startId !== null) {
            $this->log("ℹ️ Using start ID: {$this->startId}");
        }
        
        $tickers = $this->getTickers();
        
        if (empty($tickers)) {
            $this->log("❌ No tickers found from API");
            return;
        }
        
        $stats = [
            'total' => count($tickers),
            'success' => 0,
            'skipped' => 0,
            'not_found' => 0,
            'errors' => 0
        ];
        
        $startTime = time();
        
        foreach ($tickers as $index => $ticker) {
            $this->log("📍 Processing {$ticker} (" . ($index + 1) . "/" . $stats['total'] . ")");
            
            $result = $this->downloadLogo($ticker);
            $stats[$result['status'] === 'success' ? 'success' : 
                   ($result['status'] === 'skipped' ? 'skipped' : 
                   ($result['status'] === 'not_found' ? 'not_found' : 'errors'))]++;
            
            // Add a small delay to be respectful to the API
            usleep(500000); // 0.5 seconds
        }
        
        $duration = time() - $startTime;
        $this->log("🎉 Download process completed in {$duration} seconds");
        $this->log("📊 Final Statistics:");
        $this->log("   - Total tickers: {$stats['total']}");
        $this->log("   - Successfully downloaded: {$stats['success']}");
        $this->log("   - Skipped (already exist): {$stats['skipped']}");
        $this->log("   - Not found: {$stats['not_found']}");
        $this->log("   - Errors: {$stats['errors']}");
    }
}

// Get the start ID from command line arguments if provided
$startId = isset($argv[1]) ? $argv[1] : null;

// Run the downloader
$downloader = new StockLogoDownloader($startId);
$downloader->downloadAll();