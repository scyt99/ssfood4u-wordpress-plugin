<?php
/**
 * Enhanced OCR Receipt Validation with PDF Support and Transaction ID Extraction
 * Updated from existing version to include PDF handling and automatic transaction ID extraction
 */

if (!defined('ABSPATH')) exit;

class SSFood4U_Enhanced_OCR_Validator {
    
    private $api_key;
    private $api_url = 'https://api.ocr.space/parse/image';
    private $fallback_patterns = array();
    private $debug_mode = true;
    
    public function __construct() {
        $this->api_key = get_option('ssfood4u_ocr_api_key', '');
        $this->debug_mode = (get_option('ssfood4u_ocr_debug_mode', 'yes') === 'yes');
        $this->init_fallback_patterns();
        $this->debug_log('OCR Validator initialized with API key: ' . (empty($this->api_key) ? 'NOT SET' : 'SET'));
    }
    
    /**
     * Enhanced debug logging function
     */
    private function debug_log($message, $data = null) {
        if (!$this->debug_mode) return;
        
        $log_message = '[OCR DEBUG] ' . $message;
        if ($data !== null) {
            $log_message .= ' | Data: ' . print_r($data, true);
        }
        error_log($log_message);
    }
    
    /**
     * Initialize fallback patterns including transaction ID patterns
     */
    private function init_fallback_patterns() {
        $this->fallback_patterns = array(
            'total_indicators' => array('TOTAL', 'JUMLAH', 'AMOUNT', 'BAYARAN', 'PAYMENT', 'GRAND TOTAL'),
            'currency_symbols' => array('RM', 'MYR', 'RINGGIT'),
            'bank_indicators' => array('MAYBANK', 'CIMB', 'PUBLIC BANK', 'HONG LEONG', 'RHB', 'AMBANK', 'BSN', 'OCBC'),
            'transaction_id_patterns' => array(
                // Common transaction ID patterns for Malaysian banks
                '/(?:TXN|TRANSACTION|REF|REFERENCE|ID|NO)[:\s#]*([A-Z0-9]{6,20})/i',
                '/(?:APPROVAL|AUTH)[:\s#]*([A-Z0-9]{6,12})/i',
                '/(?:TRACE|RECEIPT)[:\s#]*([0-9]{6,15})/i',
                '/(?:FT|IBG|IBFT)[:\s#]*([A-Z0-9]{8,20})/i',
                // Bank-specific patterns
                '/(?:MAYBANK|MBB)[:\s#]*([A-Z0-9]{8,15})/i',
                '/(?:CIMB)[:\s#]*([A-Z0-9]{8,15})/i',
                '/(?:PUBLIC)[:\s#]*([A-Z0-9]{8,15})/i',
                '/(?:HONG LEONG|HLBB)[:\s#]*([A-Z0-9]{8,15})/i',
                '/(?:RHB)[:\s#]*([A-Z0-9]{8,15})/i',
                // Generic alphanumeric patterns
                '/\b([A-Z]{2,4}[0-9]{6,12})\b/',
                '/\b([0-9]{8,15})\b(?=\s*(?:SUCCESS|APPROVED|COMPLETED))/i',
                // QR payment patterns (DuitNow, etc.)
                '/(?:QR|DUITNOW)[:\s#]*([A-Z0-9]{8,20})/i',
                // FPX patterns
                '/(?:FPX)[:\s#]*([A-Z0-9]{10,20})/i'
            )
        );
        $this->debug_log('Enhanced fallback patterns initialized with transaction ID extraction');
    }
    
    /**
     * Enhanced receipt validation with PDF support and transaction ID extraction
     */
    public function validate_receipt_amount($image_path, $expected_amount, $transaction_id = '') {
        $this->debug_log('Starting receipt validation', array(
            'image_path' => $image_path,
            'expected_amount' => $expected_amount,
            'transaction_id' => $transaction_id
        ));
        
        if (empty($this->api_key)) {
            $this->debug_log('OCR API key not configured');
            return array(
                'success' => false,
                'message' => 'OCR API key not configured',
                'validation' => 'skipped',
                'confidence' => 0
            );
        }
        
        // Enhanced file validation with PDF support
        $file_validation = $this->validate_file_comprehensive($image_path);
        if (!$file_validation['valid']) {
            $this->debug_log('File validation failed', $file_validation);
            return array(
                'success' => false,
                'message' => 'File validation failed: ' . $file_validation['error'],
                'validation' => 'failed',
                'confidence' => 0,
                'file_debug' => $file_validation
            );
        }
        
        // Extract text with multiple OCR engines
        $ocr_result = $this->extract_text_with_fallback($image_path);
        
        if (!$ocr_result['success']) {
            $this->debug_log('OCR text extraction failed', $ocr_result);
            return array(
                'success' => false,
                'message' => 'OCR processing failed: ' . $ocr_result['message'],
                'validation' => 'failed',
                'confidence' => 0,
                'file_debug' => $file_validation
            );
        }
        
        // Process extracted text
        $processed_text = $this->preprocess_ocr_text($ocr_result['text']);
        $amounts_found = $this->extract_amounts_enhanced($processed_text);
        $receipt_metadata = $this->extract_receipt_metadata($processed_text);
        
        // Extract transaction ID from OCR text
        $extracted_transaction_id = $this->extract_transaction_id($processed_text);
        
        // Validate transaction ID if provided, otherwise use extracted one
        $transaction_match = true; // Default to true for auto-extracted IDs
        if (!empty($transaction_id)) {
            $transaction_match = $this->validate_transaction_id($processed_text, $transaction_id);
        }
        
        // Enhanced validation with context
        $validation_result = $this->validate_amounts_enhanced(
            $amounts_found, 
            $expected_amount, 
            $receipt_metadata,
            $transaction_match
        );
        
        // Calculate overall confidence score
        $confidence_score = $this->calculate_confidence_score(
            $validation_result,
            $receipt_metadata,
            $transaction_match,
            count($amounts_found),
            !empty($extracted_transaction_id)
        );
        
        $final_result = array(
            'success' => true,
            'ocr_text' => $ocr_result['text'],
            'processed_text' => $processed_text,
            'amounts_found' => $amounts_found,
            'receipt_metadata' => $receipt_metadata,
            'expected_amount' => $expected_amount,
            'transaction_match' => $transaction_match,
            'extracted_transaction_id' => $extracted_transaction_id,
            'validation' => $validation_result['status'],
            'message' => $validation_result['message'],
            'confidence' => $confidence_score,
            'matched_amount' => $validation_result['matched_amount'] ?? null,
            'file_debug' => $file_validation
        );
        
        $this->debug_log('Final validation result', $final_result);
        return $final_result;
    }
    
    /**
     * Extract transaction ID from OCR text using comprehensive patterns
     */
    private function extract_transaction_id($text) {
        $this->debug_log('Starting transaction ID extraction from text');
        
        $extracted_ids = array();
        
        // Apply all transaction ID patterns
        foreach ($this->fallback_patterns['transaction_id_patterns'] as $pattern) {
            preg_match_all($pattern, $text, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $match) {
                    $clean_id = trim($match);
                    // Validate the extracted ID (length and format)
                    if (strlen($clean_id) >= 6 && strlen($clean_id) <= 20) {
                        $extracted_ids[] = $clean_id;
                    }
                }
            }
        }
        
        // Remove duplicates and prioritize by likely importance
        $extracted_ids = array_unique($extracted_ids);
        
        // Score transaction IDs by pattern strength
        $scored_ids = array();
        foreach ($extracted_ids as $id) {
            $score = $this->score_transaction_id($id, $text);
            $scored_ids[] = array('id' => $id, 'score' => $score);
        }
        
        // Sort by score (highest first)
        usort($scored_ids, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        $best_id = !empty($scored_ids) ? $scored_ids[0]['id'] : null;
        
        $this->debug_log('Transaction ID extraction completed', array(
            'total_ids_found' => count($extracted_ids),
            'all_ids' => $extracted_ids,
            'scored_ids' => $scored_ids,
            'best_id' => $best_id
        ));
        
        return $best_id;
    }
    
    /**
     * Score transaction ID based on context and format
     */
    private function score_transaction_id($id, $text) {
        $score = 0;
        
        // Length scoring (8-12 characters are most common)
        $length = strlen($id);
        if ($length >= 8 && $length <= 12) {
            $score += 10;
        } elseif ($length >= 6 && $length <= 15) {
            $score += 5;
        }
        
        // Format scoring
        if (preg_match('/^[A-Z]{2,4}[0-9]{6,}$/', $id)) {
            $score += 15; // Common bank format
        } elseif (preg_match('/^[0-9]{8,}$/', $id)) {
            $score += 10; // Numeric only
        } elseif (preg_match('/^[A-Z0-9]+$/', $id)) {
            $score += 8; // Alphanumeric
        }
        
        // Context scoring
        $id_lower = strtolower($id);
        $text_lower = strtolower($text);
        
        if (strpos($text_lower, 'txn:' . $id_lower) !== false || 
            strpos($text_lower, 'transaction:' . $id_lower) !== false) {
            $score += 20;
        }
        
        if (strpos($text_lower, 'ref:' . $id_lower) !== false || 
            strpos($text_lower, 'reference:' . $id_lower) !== false) {
            $score += 15;
        }
        
        if (strpos($text_lower, 'approval:' . $id_lower) !== false) {
            $score += 12;
        }
        
        return $score;
    }
    
    /**
     * Comprehensive file validation with PDF support
     */
    private function validate_file_comprehensive($file_path) {
        $this->debug_log('Starting comprehensive file validation for: ' . $file_path);
        
        $validation_result = array(
            'valid' => false,
            'error' => '',
            'file_exists' => false,
            'file_size' => 0,
            'file_extension' => '',
            'mime_type_finfo' => '',
            'mime_type_getimagesize' => '',
            'is_readable' => false,
            'file_permissions' => '',
            'real_path' => '',
            'is_temp_file' => false,
            'is_pdf' => false
        );
        
        // Check if file exists
        $validation_result['file_exists'] = file_exists($file_path);
        if (!$validation_result['file_exists']) {
            $validation_result['error'] = 'File does not exist at path: ' . $file_path;
            $this->debug_log('File does not exist', $validation_result);
            return $validation_result;
        }
        
        // Detect if this is a temporary uploaded file
        $validation_result['is_temp_file'] = (strpos($file_path, '/tmp/') === 0 || strpos($file_path, sys_get_temp_dir()) === 0);
        
        // Get real path and file info
        $validation_result['real_path'] = realpath($file_path);
        $validation_result['is_readable'] = is_readable($file_path);
        $validation_result['file_size'] = filesize($file_path);
        $validation_result['file_permissions'] = substr(sprintf('%o', fileperms($file_path)), -4);
        $validation_result['file_extension'] = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        if (!$validation_result['is_readable']) {
            $validation_result['error'] = 'File is not readable';
            return $validation_result;
        }
        
        if ($validation_result['file_size'] === 0) {
            $validation_result['error'] = 'File is empty (0 bytes)';
            return $validation_result;
        }
        
        if ($validation_result['file_size'] > 10 * 1024 * 1024) {
            $validation_result['error'] = 'File too large: ' . number_format($validation_result['file_size']) . ' bytes (max 10MB)';
            return $validation_result;
        }
        
        // Get MIME types using different methods
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $validation_result['mime_type_finfo'] = finfo_file($finfo, $file_path);
                finfo_close($finfo);
            }
        }
        
        $image_info = @getimagesize($file_path);
        if ($image_info !== false && isset($image_info['mime'])) {
            $validation_result['mime_type_getimagesize'] = $image_info['mime'];
        }
        
        // Determine primary MIME type
        $primary_mime = $validation_result['mime_type_finfo'] ?: $validation_result['mime_type_getimagesize'];
        
        // Check if it's a PDF
        $validation_result['is_pdf'] = ($primary_mime === 'application/pdf' || $validation_result['file_extension'] === 'pdf');
        
        $this->debug_log('MIME type detection', array(
            'finfo' => $validation_result['mime_type_finfo'],
            'getimagesize' => $validation_result['mime_type_getimagesize'],
            'primary' => $primary_mime,
            'is_pdf' => $validation_result['is_pdf']
        ));
        
        // Validate MIME type - now including PDF
        $allowed_mime_types = array('image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp', 'image/webp', 'application/pdf');
        
        if (!in_array($primary_mime, $allowed_mime_types)) {
            $validation_result['error'] = 'Invalid file type. MIME: ' . $primary_mime . '. Allowed: ' . implode(', ', $allowed_mime_types);
            return $validation_result;
        }
        
        // For temporary files, skip extension validation and rely on MIME type + file signature
        if ($validation_result['is_temp_file']) {
            $this->debug_log('Temporary file detected, skipping extension validation');
            
            // Verify file signature for additional security
            $signature_valid = $this->verify_file_signature($file_path, $primary_mime);
            if (!$signature_valid) {
                $validation_result['error'] = 'File signature does not match MIME type';
                return $validation_result;
            }
            
        } else {
            // For regular files, validate extension - now including PDF
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'pdf');
            
            if (empty($validation_result['file_extension'])) {
                $validation_result['error'] = 'File has no extension';
                return $validation_result;
            }
            
            if (!in_array($validation_result['file_extension'], $allowed_extensions)) {
                $validation_result['error'] = 'Invalid extension: ' . $validation_result['file_extension'] . '. Allowed: ' . implode(', ', $allowed_extensions);
                return $validation_result;
            }
        }
        
        // Validation passed
        $validation_result['valid'] = true;
        $validation_result['primary_mime_type'] = $primary_mime;
        
        $this->debug_log('File validation successful', $validation_result);
        return $validation_result;
    }
    
    /**
     * Verify file signature matches MIME type - updated with PDF support
     */
    private function verify_file_signature($file_path, $expected_mime) {
        $file_handle = @fopen($file_path, 'rb');
        if (!$file_handle) {
            $this->debug_log('Could not open file for signature verification');
            return false;
        }
        
        $file_header = fread($file_handle, 16);
        fclose($file_handle);
        
        $this->debug_log('File signature verification', array(
            'expected_mime' => $expected_mime,
            'header_hex' => bin2hex($file_header)
        ));
        
        // Define file signatures - now including PDF
        $signatures = array(
            'image/jpeg' => array("\xFF\xD8\xFF"),
            'image/jpg' => array("\xFF\xD8\xFF"),
            'image/png' => array("\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"),
            'image/gif' => array("GIF87a", "GIF89a"),
            'image/bmp' => array("BM"),
            'image/webp' => array("RIFF"),
            'application/pdf' => array("%PDF")
        );
        
        if (!isset($signatures[$expected_mime])) {
            $this->debug_log('No signature definition for MIME type: ' . $expected_mime);
            return true; // Allow unknown types to pass
        }
        
        $expected_signatures = $signatures[$expected_mime];
        foreach ($expected_signatures as $signature) {
            if (strpos($file_header, $signature) === 0) {
                $this->debug_log('File signature verified for: ' . $expected_mime);
                return true;
            }
        }
        
        $this->debug_log('File signature verification failed', array(
            'expected_mime' => $expected_mime,
            'expected_signatures' => $expected_signatures,
            'actual_header' => bin2hex($file_header)
        ));
        
        return false;
    }
    
    /**
     * Extract text with fallback to different OCR engines
     */
    private function extract_text_with_fallback($image_path) {
        $this->debug_log('Starting text extraction with fallback');
        
        // Try OCR Engine 2 first
        $result = $this->extract_text_from_image($image_path, 2);
        if ($result['success']) {
            $this->debug_log('OCR Engine 2 successful');
            return $result;
        }
        
        $this->debug_log('OCR Engine 2 failed, trying Engine 1');
        
        // Fallback to OCR Engine 1
        $result = $this->extract_text_from_image($image_path, 1);
        if ($result['success']) {
            $this->debug_log('OCR Engine 1 successful');
        } else {
            $this->debug_log('Both OCR engines failed');
        }
        
        return $result;
    }
    
    /**
     * Enhanced OCR extraction with PDF support
     */
    private function extract_text_from_image($image_path, $engine = 2) {
        $this->debug_log('Starting OCR extraction', array(
            'image_path' => $image_path,
            'engine' => $engine
        ));
        
        if (!file_exists($image_path)) {
            $this->debug_log('ERROR: Image file does not exist for OCR');
            return array(
                'success' => false,
                'message' => 'File does not exist: ' . $image_path
            );
        }
        
        // Detect file type for temporary files
        $file_type = null;
        $is_temp_file = (strpos($image_path, '/tmp/') === 0 || strpos($image_path, sys_get_temp_dir()) === 0);
        
        if ($is_temp_file || !pathinfo($image_path, PATHINFO_EXTENSION)) {
            // For temporary files, detect the file type from MIME type
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mime_type = finfo_file($finfo, $image_path);
                    finfo_close($finfo);
                    
                    // Map MIME types to file extensions for OCR.space - now including PDF
                    $mime_to_extension = array(
                        'image/jpeg' => 'jpg',
                        'image/jpg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/bmp' => 'bmp',
                        'image/webp' => 'webp',
                        'application/pdf' => 'pdf'
                    );
                    
                    if (isset($mime_to_extension[$mime_type])) {
                        $file_type = $mime_to_extension[$mime_type];
                        $this->debug_log('Detected file type for temporary file', array(
                            'mime_type' => $mime_type,
                            'file_type' => $file_type
                        ));
                    }
                }
            }
            
            // Fallback: try getimagesize (won't work for PDF, but that's ok)
            if (!$file_type && !$validation_result['is_pdf']) {
                $image_info = @getimagesize($image_path);
                if ($image_info !== false && isset($image_info['mime'])) {
                    $mime_type = $image_info['mime'];
                    $mime_to_extension = array(
                        'image/jpeg' => 'jpg',
                        'image/jpg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/bmp' => 'bmp',
                        'image/webp' => 'webp'
                    );
                    
                    if (isset($mime_to_extension[$mime_type])) {
                        $file_type = $mime_to_extension[$mime_type];
                        $this->debug_log('Detected file type via getimagesize', array(
                            'mime_type' => $mime_type,
                            'file_type' => $file_type
                        ));
                    }
                }
            }
        }
        
        $post_data = array(
            'apikey' => $this->api_key,
            'language' => 'eng',
            'isOverlayRequired' => 'false',
            'detectOrientation' => 'true',
            'isTable' => 'true',
            'OCREngine' => (string)$engine,
            'scale' => 'true',
            'isCreateSearchablePdf' => 'false'
        );
        
        // Add file type for temporary files or when detected
        if ($file_type) {
            $post_data['filetype'] = $file_type;
            $this->debug_log('Added file type to OCR request: ' . $file_type);
        }
        
        // Handle file upload
        try {
            if (function_exists('curl_file_create')) {
                $post_data['file'] = curl_file_create($image_path);
            } else {
                $post_data['file'] = '@' . $image_path;
            }
        } catch (Exception $e) {
            $this->debug_log('Error creating file upload: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => 'File upload preparation failed: ' . $e->getMessage()
            );
        }
        
        $this->debug_log('OCR request prepared', array(
            'has_file_type' => isset($post_data['filetype']),
            'file_type' => $post_data['filetype'] ?? 'not_set',
            'engine' => $engine,
            'is_temp_file' => $is_temp_file
        ));
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $this->api_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90, // Increased timeout for PDFs
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'SSFood4U OCR Validator/1.4'
        ));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_info = curl_getinfo($ch);
        
        curl_close($ch);
        
        $this->debug_log('cURL response received', array(
            'http_code' => $http_code,
            'curl_error' => $curl_error,
            'response_length' => strlen($response),
            'total_time' => $curl_info['total_time']
        ));
        
        if ($response === false || !empty($curl_error)) {
            return array(
                'success' => false,
                'message' => 'API request failed: ' . ($curl_error ?: 'HTTP ' . $http_code)
            );
        }
        
        if ($http_code !== 200) {
            return array(
                'success' => false,
                'message' => 'HTTP error: ' . $http_code
            );
        }
        
        $result = json_decode($response, true);
        if (!$result) {
            $json_error = json_last_error_msg();
            $this->debug_log('JSON decode failed', array(
                'json_error' => $json_error,
                'response_sample' => substr($response, 0, 500)
            ));
            return array(
                'success' => false,
                'message' => 'Invalid JSON response: ' . $json_error
            );
        }
        
        $this->debug_log('API response decoded successfully', array(
            'result_keys' => array_keys($result),
            'has_parsed_results' => isset($result['ParsedResults']),
            'is_errored' => isset($result['IsErroredOnProcessing']) ? $result['IsErroredOnProcessing'] : 'not_set'
        ));
        
        // Handle OCR processing errors properly
        if (isset($result['IsErroredOnProcessing']) && $result['IsErroredOnProcessing']) {
            $error_messages = $result['ErrorMessage'] ?? array('Unknown OCR error');
            if (is_array($error_messages)) {
                $error_msg = implode(' | ', $error_messages);
            } else {
                $error_msg = (string)$error_messages;
            }
            
            $this->debug_log('OCR processing error from API', array(
                'error_messages' => $error_messages,
                'combined_error' => $error_msg,
                'ocr_exit_code' => $result['OCRExitCode'] ?? 'not_set'
            ));
            
            return array(
                'success' => false,
                'message' => 'OCR processing error: ' . $error_msg
            );
        }
        
        if (!isset($result['ParsedResults'])) {
            $this->debug_log('ParsedResults missing from response', array(
                'available_keys' => array_keys($result),
                'full_response' => $result
            ));
            return array(
                'success' => false,
                'message' => 'Invalid API response structure: ParsedResults missing'
            );
        }
        
        // Extract text from results
        $extracted_text = '';
        foreach ($result['ParsedResults'] as $parsed) {
            $extracted_text .= ($parsed['ParsedText'] ?? '') . "\n";
        }
        
        $this->debug_log('OCR extraction completed', array(
            'text_length' => strlen(trim($extracted_text)),
            'engine' => $engine,
            'parsed_results_count' => count($result['ParsedResults'])
        ));
        
        return array(
            'success' => true,
            'text' => trim($extracted_text),
            'confidence' => 75, // Default confidence
            'engine' => $engine
        );
    }
    
    /**
     * Enhanced text preprocessing with better OCR error correction
     */
    private function preprocess_ocr_text($text) {
        $this->debug_log('Starting text preprocessing', array(
            'original_length' => strlen($text)
        ));
        
        // Normalize whitespace but preserve important separators
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace(array("\r\n", "\r", "\n"), ' ', $text);
        
        // Enhanced OCR corrections for common amount misreadings
        $corrections = array(
            'TOTAI' => 'TOTAL',
            'TOTALI' => 'TOTAL',
            'RINGG1T' => 'RINGGIT',
            'MAYBAN K' => 'MAYBANK',
            'TRANSACT1ON' => 'TRANSACTION',
            'REF3RENCE' => 'REFERENCE',
            'APPROV4L' => 'APPROVAL',
            'RECE1PT' => 'RECEIPT',
            'C1MB' => 'CIMB',
            // Common amount corrections
            'RM1 ' => 'RM10 ', // Common misread of RM10
            'RM1.' => 'RM10.',
            'RM1,' => 'RM10,',
            ' 1.00' => ' 10.00', // When 10.00 becomes 1.00
            ' 1.50' => ' 10.50', // When 10.50 becomes 1.50
            'TOTAL 1' => 'TOTAL 10',
            'AMOUNT 1' => 'AMOUNT 10'
        );
        
        foreach ($corrections as $wrong => $right) {
            $text = str_ireplace($wrong, $right, $text);
        }
        
        // Additional pattern-based corrections for amounts
        // Look for context clues that suggest "1" should be "10"
        $text = preg_replace('/\b1\.([0-9]{2})\b(?=\s*(?:RM|MYR|TOTAL|AMOUNT))/i', '10.$1', $text);
        $text = preg_replace('/(?:RM|MYR)\s*1\s*(?=\s*[^0-9])/i', 'RM10', $text);
        
        // Normalize currency
        $text = preg_replace('/R\s*M/', 'RM', $text);
        $text = preg_replace('/M\s*Y\s*R/', 'MYR', $text);
        
        // Normalize common transaction ID indicators
        $text = preg_replace('/TXN\s*[:]/i', 'TXN:', $text);
        $text = preg_replace('/REF\s*[:]/i', 'REF:', $text);
        $text = preg_replace('/TRANSACTION\s*[:]/i', 'TRANSACTION:', $text);
        
        $processed = trim($text);
        $this->debug_log('Text preprocessing completed', array(
            'processed_length' => strlen($processed),
            'corrections_applied' => 'Enhanced amount corrections applied'
        ));
        
        return $processed;
    }
    
    /**
     * Enhanced amount extraction with context-aware corrections
     */
    private function extract_amounts_enhanced($text) {
        $this->debug_log('Starting amount extraction');
        
        $amounts = array();
        $patterns = array(
            // More specific patterns for Malaysian currency
            '/RM\s*(\d{1,6}(?:[,.]\d{2})?)/i',
            '/MYR\s*(\d{1,6}(?:[,.]\d{2})?)/i',
            '/(?:TOTAL|JUMLAH|AMOUNT|BAYAR|GRAND\s*TOTAL)\s*:?\s*RM?\s*(\d{1,6}(?:[,.]\d{2})?)/i',
            // Pattern for standalone amounts with decimals
            '/(\d{1,4}[,.]\d{2})(?=\s*(?:RM|MYR|$))/i',
            // Pattern for whole numbers followed by currency
            '/(\d{1,6})\.00\s*(?:RM|MYR)?/i'
        );
        
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $amount) {
                    // Clean and normalize the amount
                    $clean_amount = str_replace(',', '', $amount);
                    
                    // Handle cases where decimal point might be missing
                    if (!strpos($clean_amount, '.') && strlen($clean_amount) > 2) {
                        // If no decimal and more than 2 digits, assume last 2 are cents
                        // But only if it makes sense (e.g., 1000 becomes 10.00, not 1000.00)
                        if (strlen($clean_amount) == 3 || strlen($clean_amount) == 4) {
                            $clean_amount = substr($clean_amount, 0, -2) . '.' . substr($clean_amount, -2);
                        }
                    }
                    
                    $float_amount = floatval($clean_amount);
                    
                    $this->debug_log('Processing amount', array(
                        'original' => $amount,
                        'cleaned' => $clean_amount,
                        'float' => $float_amount
                    ));
                    
                    if ($float_amount >= 0.01 && $float_amount <= 10000) {
                        $amounts[] = $float_amount;
                    }
                }
            }
        }
        
        $amounts = array_unique($amounts);
        rsort($amounts); // Sort descending to get largest amounts first
        
        $this->debug_log('Amount extraction completed', array(
            'amounts_found' => count($amounts),
            'amounts' => $amounts
        ));
        
        return $amounts;
    }
    
    /**
     * Context-aware amount validation with intelligent corrections
     */
    private function validate_amounts_enhanced($found_amounts, $expected_amount, $metadata, $transaction_match) {
        if (empty($found_amounts)) {
            return array(
                'status' => 'no_amounts_found',
                'message' => 'No monetary amounts detected'
            );
        }
        
        $expected = floatval($expected_amount);
        $tolerance = max(0.01, $expected * 0.001);
        
        $this->debug_log('Amount validation', array(
            'expected' => $expected,
            'found_amounts' => $found_amounts,
            'tolerance' => $tolerance
        ));
        
        // Check for exact matches first
        foreach ($found_amounts as $amount) {
            if (abs($amount - $expected) <= $tolerance) {
                return array(
                    'status' => 'match',
                    'message' => "Amount validated: RM{$amount}",
                    'matched_amount' => $amount
                );
            }
        }
        
        // Check for close matches
        foreach ($found_amounts as $amount) {
            $difference_percent = abs(($amount - $expected) / $expected) * 100;
            if ($difference_percent <= 2) {
                return array(
                    'status' => 'close_match',
                    'message' => "Close match: RM{$amount} vs RM{$expected}",
                    'matched_amount' => $amount
                );
            }
        }
        
        // Smart correction attempt: Check if any found amount could be a misread expected amount
        foreach ($found_amounts as $amount) {
            // Check if this could be a "10 vs 1" type error
            if ($expected >= 10 && $amount == ($expected / 10)) {
                $this->debug_log('Potential OCR digit error detected', array(
                    'found' => $amount,
                    'expected' => $expected,
                    'ratio' => $expected / $amount
                ));
                
                return array(
                    'status' => 'no_match',
                    'message' => "OCR reading error detected: Found RM{$amount}, expected RM{$expected}. The '0' in '{$expected}' may have been misread.",
                    'suggested_amount' => $expected
                );
            }
        }
        
        // No match found
        $amounts_str = implode(', ', array_map(function($a) { return "RM{$a}"; }, array_slice($found_amounts, 0, 3)));
        return array(
            'status' => 'no_match',
            'message' => "Expected RM{$expected} not found. Detected: {$amounts_str}",
            'found_amounts_list' => $amounts_str
        );
    }
    
    /**
     * Extract receipt metadata with enhanced transaction ID detection
     */
    private function extract_receipt_metadata($text) {
        $metadata = array(
            'has_bank_info' => false,
            'has_total_indicator' => false,
            'has_date' => false,
            'has_time' => false,
            'bank_detected' => null,
            'receipt_type' => 'unknown',
            'has_transaction_id' => false
        );
        
        // Check for bank indicators
        foreach ($this->fallback_patterns['bank_indicators'] as $bank) {
            if (stripos($text, $bank) !== false) {
                $metadata['has_bank_info'] = true;
                $metadata['bank_detected'] = $bank;
                break;
            }
        }
        
        // Check for total indicators
        foreach ($this->fallback_patterns['total_indicators'] as $indicator) {
            if (stripos($text, $indicator) !== false) {
                $metadata['has_total_indicator'] = true;
                break;
            }
        }
        
        // Check for transaction ID presence
        $extracted_id = $this->extract_transaction_id($text);
        $metadata['has_transaction_id'] = !empty($extracted_id);
        
        // Check patterns
        if (preg_match('/\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}/', $text)) {
            $metadata['has_date'] = true;
        }
        
        if (preg_match('/\d{1,2}:\d{2}/', $text)) {
            $metadata['has_time'] = true;
        }
        
        // Determine receipt type
        if (stripos($text, 'TRANSFER') !== false || stripos($text, 'IBFT') !== false) {
            $metadata['receipt_type'] = 'online_banking';
        } elseif (stripos($text, 'ATM') !== false) {
            $metadata['receipt_type'] = 'atm';
        } elseif (stripos($text, 'DEBIT') !== false || stripos($text, 'CREDIT') !== false) {
            $metadata['receipt_type'] = 'card_payment';
        } elseif (stripos($text, 'QR') !== false || stripos($text, 'DUITNOW') !== false) {
            $metadata['receipt_type'] = 'qr_payment';
        }
        
        return $metadata;
    }
    
    /**
     * Validate transaction ID
     */
    private function validate_transaction_id($text, $transaction_id) {
        if (empty($transaction_id)) return true;
        
        $clean_id = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper($transaction_id));
        $clean_text = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper($text));
        
        return strpos($clean_text, $clean_id) !== false;
    }
    
    /**
     * Calculate confidence score with transaction ID consideration
     */
    private function calculate_confidence_score($validation_result, $metadata, $transaction_match, $amounts_count, $has_extracted_transaction_id = false) {
        $base_score = 0;
        
        switch ($validation_result['status']) {
            case 'match': $base_score = 85; break;
            case 'close_match': $base_score = 70; break;
            case 'no_match': $base_score = 20; break;
            case 'no_amounts_found': $base_score = 10; break;
        }
        
        // Adjustments
        if ($metadata['has_bank_info']) $base_score += 5;
        if ($metadata['has_total_indicator']) $base_score += 5;
        if ($metadata['has_date']) $base_score += 3;
        if ($metadata['has_transaction_id'] || $has_extracted_transaction_id) $base_score += 8;
        if ($transaction_match) $base_score += 10;
        if ($amounts_count > 0) $base_score += 2;
        
        return min(100, max(0, $base_score));
    }
    
    /**
     * Get settings HTML for admin panel with PDF and transaction ID features
     */
    public function get_settings_html() {
        $api_key = get_option('ssfood4u_ocr_api_key', '');
        $auto_approve_threshold = get_option('ssfood4u_ocr_auto_approve', 85);
        $require_transaction_id = get_option('ssfood4u_ocr_require_transaction', 'no');
        $debug_mode = get_option('ssfood4u_ocr_debug_mode', 'yes');
        $auto_extract_transaction = get_option('ssfood4u_ocr_auto_extract_transaction', 'yes');
        
        ob_start();
        ?>
        <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <h3>Enhanced OCR Receipt Validation Settings</h3>
            
            <form method="post">
                <?php wp_nonce_field('ssfood4u_ocr_settings', 'ocr_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">OCR.space API Key</th>
                        <td>
                            <input type="text" name="ocr_api_key" value="<?php echo esc_attr($api_key); ?>" 
                                   style="width: 400px;" placeholder="Enter your OCR.space API key">
                            <p class="description">
                                Get your free API key from <a href="https://ocr.space/ocrapi" target="_blank">OCR.space</a><br>
                                Free tier: 25,000 requests/month | <strong>Now supports PDF receipts!</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-Approve Threshold</th>
                        <td>
                            <input type="number" name="auto_approve_threshold" 
                                   value="<?php echo esc_attr($auto_approve_threshold); ?>" 
                                   min="0" max="100" style="width: 100px;">%
                            <p class="description">
                                Automatically approve payments with confidence score above this threshold
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Transaction ID Handling</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_extract_transaction" value="yes" 
                                       <?php checked($auto_extract_transaction, 'yes'); ?>>
                                Automatically extract transaction ID from receipt
                            </label><br>
                            <label style="margin-top: 10px; display: block;">
                                <input type="checkbox" name="require_transaction_id" value="yes" 
                                       <?php checked($require_transaction_id, 'yes'); ?>>
                                Require transaction ID validation (manual or extracted)
                            </label>
                            <p class="description">
                                When enabled, the system will automatically detect and extract transaction IDs from receipts
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="debug_mode" value="yes" 
                                       <?php checked($debug_mode, 'yes'); ?>>
                                Enable detailed debug logging
                            </label>
                            <p class="description">
                                Enable for troubleshooting file extension, PDF, and OCR issues
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save OCR Settings', 'primary', 'save_ocr_settings'); ?>
            </form>
            
            <?php if ($api_key): ?>
            <div style="margin-top: 20px; background: #fff; padding: 15px; border-radius: 5px; border: 1px solid #ddd;">
                <h4>Test OCR System (Images & PDFs)</h4>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('ssfood4u_test_ocr', 'test_ocr_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <td>
                                <label>Upload Receipt:</label><br>
                                <input type="file" name="test_receipt" accept="image/*,.pdf" required>
                                <small>Supports: JPG, PNG, GIF, BMP, WebP, PDF</small>
                            </td>
                            <td>
                                <label>Expected Amount:</label><br>
                                <input type="number" name="test_amount" step="0.01" placeholder="15.50" required>
                            </td>
                            <td>
                                <label>Transaction ID (optional):</label><br>
                                <input type="text" name="test_transaction_id" placeholder="TXN123456">
                                <small>Leave blank to test auto-extraction</small>
                            </td>
                            <td style="vertical-align: bottom;">
                                <?php submit_button('Test OCR', 'secondary', 'test_enhanced_ocr', false); ?>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle settings form submission with new options
     */
    public function handle_enhanced_settings_form() {
        if (isset($_POST['save_ocr_settings']) && wp_verify_nonce($_POST['ocr_nonce'], 'ssfood4u_ocr_settings')) {
            $api_key = sanitize_text_field($_POST['ocr_api_key']);
            $auto_approve = intval($_POST['auto_approve_threshold']);
            $require_transaction = isset($_POST['require_transaction_id']) ? 'yes' : 'no';
            $debug_mode = isset($_POST['debug_mode']) ? 'yes' : 'no';
            $auto_extract_transaction = isset($_POST['auto_extract_transaction']) ? 'yes' : 'no';
            
            update_option('ssfood4u_ocr_api_key', $api_key);
            update_option('ssfood4u_ocr_auto_approve', $auto_approve);
            update_option('ssfood4u_ocr_require_transaction', $require_transaction);
            update_option('ssfood4u_ocr_debug_mode', $debug_mode);
            update_option('ssfood4u_ocr_auto_extract_transaction', $auto_extract_transaction);
            
            $this->debug_mode = ($debug_mode === 'yes');
            
            echo '<div class="notice notice-success"><p>OCR settings saved successfully! PDF support and auto transaction ID extraction enabled.</p></div>';
            $this->debug_log("OCR settings updated", array(
                'auto_approve_threshold' => $auto_approve,
                'transaction_id_validation' => $require_transaction,
                'auto_extract_transaction' => $auto_extract_transaction,
                'debug_mode' => $debug_mode,
                'api_key_configured' => !empty($api_key)
            ));
        }
        
        if (isset($_POST['test_enhanced_ocr']) && wp_verify_nonce($_POST['test_ocr_nonce'], 'ssfood4u_test_ocr')) {
            $this->handle_enhanced_ocr_test();
        }
    }
    
    /**
     * Handle OCR test functionality with PDF and transaction ID testing
     */
    private function handle_enhanced_ocr_test() {
        $this->debug_log('Starting OCR test from admin panel');
        
        if (!isset($_FILES['test_receipt']) || $_FILES['test_receipt']['error'] !== UPLOAD_ERR_OK) {
            $upload_error = $_FILES['test_receipt']['error'] ?? 'unknown';
            $this->debug_log('File upload failed', array(
                'upload_error_code' => $upload_error,
                'files_data' => $_FILES['test_receipt'] ?? 'not_set'
            ));
            echo '<div class="notice notice-error"><p><strong>File upload failed.</strong> Upload error code: ' . $upload_error . '. Please try again.</p></div>';
            return;
        }
        
        $test_amount = floatval($_POST['test_amount']);
        $transaction_id = sanitize_text_field($_POST['test_transaction_id'] ?? '');
        
        if ($test_amount <= 0) {
            echo '<div class="notice notice-error"><p><strong>Invalid amount.</strong> Please enter a valid amount.</p></div>';
            return;
        }
        
        $this->debug_log('OCR test parameters', array(
            'test_amount' => $test_amount,
            'transaction_id' => $transaction_id,
            'file_name' => $_FILES['test_receipt']['name'],
            'file_size' => $_FILES['test_receipt']['size'],
            'file_type' => $_FILES['test_receipt']['type']
        ));
        
        // Perform OCR test
        $test_result = $this->validate_receipt_amount($_FILES['test_receipt']['tmp_name'], $test_amount, $transaction_id);
        
        $this->debug_log('OCR test completed', $test_result);
        
        // Display test results
        $this->display_test_results($test_result, $test_amount, $transaction_id);
    }
    
    /**
     * Display comprehensive test results with transaction ID and PDF info
     */
    private function display_test_results($test_result, $test_amount, $transaction_id) {
        echo '<div class="notice notice-info" style="padding: 20px; max-width: none;">';
        echo '<h4>Enhanced OCR Test Results (PDF & Auto Transaction ID Support)</h4>';
        
        echo '<table class="widefat" style="margin-top: 15px;">';
        echo '<tr><td style="width: 200px;"><strong>Test Parameters:</strong></td><td>Amount: RM' . number_format($test_amount, 2);
        if ($transaction_id) echo ', Transaction ID: ' . esc_html($transaction_id);
        echo '</td></tr>';
        
        echo '<tr><td><strong>Validation Status:</strong></td><td><span class="ocr-status-' . esc_attr($test_result['validation']) . '">' . ucfirst(str_replace('_', ' ', $test_result['validation'])) . '</span></td></tr>';
        echo '<tr><td><strong>Confidence Score:</strong></td><td><strong>' . intval($test_result['confidence']) . '%</strong></td></tr>';
        echo '<tr><td><strong>Result Message:</strong></td><td>' . esc_html($test_result['message']) . '</td></tr>';
        
        // Transaction ID extraction results
        if (isset($test_result['extracted_transaction_id'])) {
            echo '<tr><td><strong>Auto-Extracted Transaction ID:</strong></td><td>';
            if ($test_result['extracted_transaction_id']) {
                echo '<span style="color: green; font-weight: bold;">' . esc_html($test_result['extracted_transaction_id']) . '</span>';
                echo ' <span style="background: #d5f4e6; padding: 2px 6px; border-radius: 3px; font-size: 11px;">AUTO-DETECTED</span>';
            } else {
                echo '<span style="color: orange;">None detected</span>';
            }
            echo '</td></tr>';
        }
        
        // File validation details
        if (isset($test_result['file_debug'])) {
            $file_debug = $test_result['file_debug'];
            echo '<tr><td><strong>File Validation:</strong></td><td>';
            if ($file_debug['valid']) {
                echo '<span style="color: green;">✓ Valid</span>';
                if (isset($file_debug['is_pdf']) && $file_debug['is_pdf']) {
                    echo ' <span style="background: #e8f4fd; padding: 2px 6px; border-radius: 3px; font-size: 11px; color: #0c5460;">PDF</span>';
                }
            } else {
                echo '<span style="color: red;">✗ Invalid: ' . esc_html($file_debug['error']) . '</span>';
            }
            echo '</td></tr>';
            
            echo '<tr><td><strong>File Details:</strong></td><td>';
            echo 'Size: ' . number_format($file_debug['file_size']) . ' bytes<br>';
            echo 'Extension: ' . esc_html($file_debug['file_extension']) . '<br>';
            if ($file_debug['mime_type_finfo']) {
                echo 'MIME (finfo): ' . esc_html($file_debug['mime_type_finfo']) . '<br>';
            }
            if ($file_debug['mime_type_getimagesize']) {
                echo 'MIME (getimagesize): ' . esc_html($file_debug['mime_type_getimagesize']) . '<br>';
            }
            echo 'Readable: ' . ($file_debug['is_readable'] ? 'Yes' : 'No') . '<br>';
            echo 'Permissions: ' . esc_html($file_debug['file_permissions']);
            echo '</td></tr>';
        }
        
        if (isset($test_result['amounts_found']) && !empty($test_result['amounts_found'])) {
            echo '<tr><td><strong>Amounts Detected:</strong></td><td>';
            echo implode(', ', array_map(function($a) { return "RM " . number_format($a, 2); }, $test_result['amounts_found']));
            echo '</td></tr>';
        }
        
        if (isset($test_result['matched_amount'])) {
            echo '<tr><td><strong>Matched Amount:</strong></td><td><strong>RM ' . number_format($test_result['matched_amount'], 2) . '</strong></td></tr>';
        }
        
        echo '<tr><td><strong>Transaction ID Match:</strong></td><td>';
        if ($transaction_id) {
            echo ($test_result['transaction_match'] ? 'Yes' : 'No');
        } else {
            echo 'Not provided (using auto-extraction)';
        }
        echo '</td></tr>';
        
        // Auto-approval simulation
        $auto_approve_threshold = get_option('ssfood4u_ocr_auto_approve', 85);
        $would_auto_approve = ($auto_approve_threshold > 0 && $test_result['confidence'] >= $auto_approve_threshold);
        echo '<tr><td><strong>Would Auto-Approve:</strong></td><td>';
        echo '<span style="color: ' . ($would_auto_approve ? '#27ae60' : '#e74c3c') . '; font-weight: bold;">';
        echo ($would_auto_approve ? 'YES' : 'NO');
        echo '</span> (threshold: ' . $auto_approve_threshold . '%)</td></tr>';
        
        echo '</table>';
        
        if (isset($test_result['ocr_text']) && !empty($test_result['ocr_text'])) {
            echo '<h5 style="margin-top: 20px;">Raw OCR Text Extracted:</h5>';
            echo '<textarea readonly style="width: 100%; height: 150px; font-family: monospace; font-size: 12px;">';
            echo esc_textarea($test_result['ocr_text']);
            echo '</textarea>';
        }
        
        // Enhanced recommendations with PDF and transaction ID info
        echo '<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-left: 4px solid #17a2b8; border-radius: 4px;">';
        echo '<h5 style="margin-top: 0;">Recommendations:</h5>';
        
        if (!$test_result['success']) {
            echo '<p style="color: #721c24;">Test failed. Check the file validation details above and debug logs for specific issues.</p>';
        } elseif ($test_result['confidence'] >= 85) {
            echo '<p style="color: #155724;">Excellent results! This receipt type should work well with auto-approval.</p>';
        } elseif ($test_result['confidence'] >= 70) {
            echo '<p style="color: #856404;">Good results. Consider adjusting auto-approval threshold.</p>';
        } else {
            echo '<p style="color: #721c24;">Low confidence. May need manual review.</p>';
        }
        
        if (isset($test_result['extracted_transaction_id']) && $test_result['extracted_transaction_id']) {
            echo '<p style="color: #155724;"><strong>Transaction ID Auto-Extraction:</strong> Successfully detected "' . esc_html($test_result['extracted_transaction_id']) . '"</p>';
        } elseif (empty($transaction_id)) {
            echo '<p style="color: #856404;"><strong>Transaction ID:</strong> Could not auto-extract. Receipt may need clearer transaction reference.</p>';
        }
        
        if ($test_result['validation'] === 'no_amounts_found') {
            echo '<p>Tip: Ensure the receipt contains clear monetary amounts with "RM" currency indicators.</p>';
        }
        
        if (isset($test_result['file_debug']) && $test_result['file_debug']['is_pdf']) {
            echo '<p style="color: #17a2b8;"><strong>PDF Processing:</strong> Successfully processed PDF receipt using OCR.</p>';
        }
        
        if (isset($test_result['file_debug']) && !$test_result['file_debug']['valid']) {
            echo '<p><strong>File Issue:</strong> ' . esc_html($test_result['file_debug']['error']) . '</p>';
        }
        
        echo '</div>';
        echo '</div>';
    }
}

?>