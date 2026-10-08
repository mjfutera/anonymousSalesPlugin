<?php

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin owns these tables; table and column names come from its own schema, never from user input, and the schema helpers below cannot use placeholders for identifiers.

    function anonymous_sales_write_log_guard_files($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $htaccessPath = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccessPath)) {
            $rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
            file_put_contents($htaccessPath, $rules);
        }

        $indexPath = trailingslashit($dir) . 'index.php';
        if (!file_exists($indexPath)) {
            file_put_contents($indexPath, "<?php\n// Silence is golden.\n");
        }
    }

    // The log folder lives in the uploads directory (uploads/anonymous-sales), not in the plugin
    // folder, which WordPress replaces on every update. Apache/LiteSpeed honour the .htaccess; on
    // nginx add a "deny all" for wp-content/uploads/anonymous-sales/ in the server config.
    function anonymous_sales_protect_var_dir() {
        wp_mkdir_p(ANONYMOUS_SALES_VAR_DIR);
        anonymous_sales_write_log_guard_files(ANONYMOUS_SALES_VAR_DIR);
    }

    function anonymous_sales_get_error_log_file_path() {
        $logsRoot = ANONYMOUS_SALES_VAR_DIR . '/logs';
        $errorDir = $logsRoot . '/error';

        wp_mkdir_p($errorDir);

        anonymous_sales_protect_var_dir();
        anonymous_sales_write_log_guard_files($logsRoot);
        anonymous_sales_write_log_guard_files($errorDir);

        return $errorDir . '/error.log';
    }

    function anonymous_sales_generate_random_string($length) {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    // Debug logging is OFF until the owner switches it on (Anonymous Sales -> Settings). What is
    // logged: events of the plugin (order numbers, payment ids, IP of rate-limited requests, short
    // excerpts of provider answers); never API keys or secrets (redacted below). The file is
    // capped at 1 MB and starts again when it grows past that.
    function anonymous_sales_logging_enabled() {
        return get_option('anonymous_sales_debug_logging', '0') === '1';
    }

    function anonymous_sales_log($level, $message, $context = [], $withOrigin = true) {
        unset($withOrigin); // kept for older callers; no backtraces are collected any more

        if (!anonymous_sales_logging_enabled()) {
            return;
        }

        $allowedLevels = ['debug', 'info', 'warning', 'error'];
        $level = strtolower(trim((string) $level));
        if (!in_array($level, $allowedLevels, true)) {
            $level = 'info';
        }

        $logFile = anonymous_sales_get_error_log_file_path();
        if (file_exists($logFile) && filesize($logFile) > 1048576) {
            file_put_contents($logFile, '[' . gmdate('Y-m-d H:i:s') . " UTC] [INFO] The log was larger than 1 MB and has been reset." . PHP_EOL, LOCK_EX);
        }

        $safeMessage = anonymous_sales_redact_sensitive_log_data((string) $message);
        $safeContext = '';
        if (is_array($context) && !empty($context)) {
            $contextJson = wp_json_encode($context);
            if (is_string($contextJson) && $contextJson !== '') {
                $safeContext = ' | context=' . anonymous_sales_redact_sensitive_log_data($contextJson);
            }
        }

        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] [' . strtoupper($level) . '] ' . $safeMessage . $safeContext . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    function anonymous_sales_error_log($message, $withOrigin = true) {
        anonymous_sales_log('error', $message, [], $withOrigin);
    }

    function anonymous_sales_log_debug($message, $context = [], $withOrigin = true) {
        anonymous_sales_log('debug', $message, $context, $withOrigin);
    }

    function anonymous_sales_log_info($message, $context = [], $withOrigin = true) {
        anonymous_sales_log('info', $message, $context, $withOrigin);
    }

    function anonymous_sales_log_warning($message, $context = [], $withOrigin = true) {
        anonymous_sales_log('warning', $message, $context, $withOrigin);
    }

    function anonymous_sales_log_error($message, $context = [], $withOrigin = true) {
        anonymous_sales_log('error', $message, $context, $withOrigin);
    }

    function anonymous_sales_redact_sensitive_log_data($message) {
        $message = (string) $message;

        if (!function_exists('anonymous_sales_get_schema_names') || !function_exists('get_option')) {
            return $message;
        }

        $schema = anonymous_sales_get_schema_names();
        $candidates = [];

        if (!empty($schema['nowpayments_apiKey'])) {
            $candidates[] = anonymous_sales_decrypt_secret((string) get_option($schema['nowpayments_apiKey'], ''));
        }
        if (!empty($schema['nowpayments_webhookKey'])) {
            $candidates[] = anonymous_sales_decrypt_secret((string) get_option($schema['nowpayments_webhookKey'], ''));
        }
        if (!empty($schema['router_auth_key'])) {
            $candidates[] = (string) get_option($schema['router_auth_key'], '');
        }

        foreach ($candidates as $secret) {
            $secret = trim((string) $secret);
            if ($secret === '') {
                continue;
            }

            $message = str_replace($secret, '***', $message);
        }

        return $message;
    }

    function anonymous_sales_run_dbdelta($sql, $label) {
        global $wpdb;

        $result = dbDelta($sql);
        if (!empty($result) && is_array($result)) {
            foreach ($result as $entry) {
                anonymous_sales_error_log("dbDelta {$label} result: " . $entry, false);
            }
        }
        if (!empty($wpdb->last_error)) {
            anonymous_sales_error_log("dbDelta {$label} error: " . $wpdb->last_error);
        }
    }

    function anonymous_sales_add_fk_if_missing($constraint_name, $sql) {
        global $wpdb;

        $schema = $wpdb->dbname;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = %s AND CONSTRAINT_NAME = %s",
            $schema, $constraint_name
        ));

        if (!$exists) {
            $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
            $wpdb->query($sql);

            if (!empty($wpdb->last_error)) {
                anonymous_sales_error_log("FK {$constraint_name} error: " . $wpdb->last_error, false);
            } else {
                anonymous_sales_error_log("FK {$constraint_name} successfully added.", false);
            }

            $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
        } else {
            anonymous_sales_error_log("FK {$constraint_name} already exists – skipped.", false);
        }
    }

    function anonymous_sales_add_column_if_missing($table_name, $column_name, $sql) {
        global $wpdb;

        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = %s AND COLUMN_NAME = %s AND TABLE_SCHEMA = %s",
            $table_name,
            $column_name,
            $wpdb->dbname
        ));

        if (!$column_exists) {
            $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
            $wpdb->query($sql);

            if (!empty($wpdb->last_error)) {
                anonymous_sales_error_log("ALTER TABLE {$column_name} error: " . $wpdb->last_error, false);
            } else {
                anonymous_sales_error_log("Column {$column_name} successfully added.", false);
            }

            $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
        } else {
            anonymous_sales_error_log("Column {$column_name} already exists – skipped.", false);
        }
    }

    function anonymous_sales_remove_fk_if_exists($table_name, $constraint_name) {
        global $wpdb;

        $schema = $wpdb->dbname;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = %s AND CONSTRAINT_NAME = %s",
            $schema, $constraint_name
        ));

        if ($exists) {
            $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
            $wpdb->query("ALTER TABLE `{$table_name}` DROP FOREIGN KEY `{$constraint_name}`");

            if (!empty($wpdb->last_error)) {
                anonymous_sales_error_log("DROP FK {$constraint_name} error: " . $wpdb->last_error, false);
            } else {
                anonymous_sales_error_log("FK {$constraint_name} successfully removed.", false);
            }

            $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
        } else {
            anonymous_sales_error_log("FK {$constraint_name} does not exist – skipped.", false);
        }
    }

    function anonymous_sales_drop_not_null_if_exists($table_name, $column_name) {
        global $wpdb;

        $column_info = $wpdb->get_row($wpdb->prepare(
            "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_NAME = %s AND COLUMN_NAME = %s AND TABLE_SCHEMA = %s",
            $table_name,
            $column_name,
            $wpdb->dbname
        ));

        if (!$column_info || $column_info->IS_NULLABLE === 'YES') {
            anonymous_sales_error_log("Column {$column_name} is already nullable – skipped.", false);
            return;
        }

        $column_type = $column_info->COLUMN_TYPE;
        $wpdb->query("SET FOREIGN_KEY_CHECKS = 0");
        $wpdb->query("ALTER TABLE `{$table_name}` MODIFY COLUMN `{$column_name}` {$column_type} DEFAULT NULL");

        if (!empty($wpdb->last_error)) {
            anonymous_sales_error_log("MODIFY {$column_name} error: " . $wpdb->last_error, false);
        } else {
            anonymous_sales_error_log("Column {$column_name} modified to nullable.", false);
        }

        $wpdb->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    if (!class_exists('FPDF')) {
        if (!defined('FPDF_FONTPATH')) {
            define('FPDF_FONTPATH', ANONYMOUS_SALES_VENDOR_DIR . '/fpdf/font/'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constant name required by the bundled FPDF library
        }
        require_once ANONYMOUS_SALES_VENDOR_DIR . '/fpdf/fpdf.php';
    }

class Anonymous_Sales_PDF extends FPDF {
    // Properties to hold the data
    public $websiteTitle;
    public $websiteAddress;
    public $offerTitle;
    public $offerAddress;
    public $offerPrice;
    public $orderNumber;
    public $claimToken;
    public $orderDate;
    public $userLogo;
    public $assetTableCount = 0;
    public $showContentHeaderOnNewPages;

    // Constructor to accept data
    function __construct($data = []) {
        parent::__construct();
        $this->websiteTitle = $data['websiteTitle'] ?? 'My Awesome Website';
        $this->websiteAddress = $data['websiteAddress'] ?? 'https://example.com';
        $this->offerTitle = $data['offerTitle'] ?? 'Exclusive E-book';
        $this->offerAddress = $data['offerAddress'] ?? 'https://example.com/ebook';
        $this->offerPrice = $data['offerPrice'] ?? '$19.99 USD (paid with 6,59 XRP)';
        $this->orderNumber = $data['orderNumber'] ?? '50M354MP1353CR37K3Y';
        $this->claimToken = $data['claimToken'] ?? '';
        $this->orderDate = $data['orderDate'] ?? gmdate('Y-m-d H:i:s') . ' UTC';
        $this->userLogo = $data['userLogo'] ?? '';
        $this->showContentHeaderOnNewPages = $data['showContentHeaderOnNewPages'] ?? true;
    }

    function Header() {
        $pageWidth = $this->GetPageWidth();
        $margin = 10;

        // Accent band
        $this->SetFillColor(0, 136, 204);
        $this->Rect(0, 0, $pageWidth, 22, 'F');

        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 14);
        $this->SetXY($margin, 8);
        $this->Cell(0, 6, 'Order Access Sheet', 0, 1, 'L');

        // Logo block
        $logoX = $margin;
        $logoY = 28;
        $logoSize = 34;
        $this->SetFillColor(246, 249, 252);
        $this->SetDrawColor(220, 230, 242);
        $this->Rect($logoX, $logoY, $logoSize, $logoSize, 'DF');

        if (!empty($this->userLogo)) {
            $this->Image($this->userLogo, $logoX + 2, $logoY + 2, $logoSize - 4);
        } else {
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(150, 160, 175);
            $this->SetXY($logoX, $logoY + ($logoSize / 2) - 2);
            $this->Cell($logoSize, 4, 'Anonymous', 0, 0, 'C');
        }

        // Summary card
        $cardX = $logoX + $logoSize + 6;
        $cardY = $logoY;
        $cardW = $pageWidth - $cardX - $margin;
        $this->SetFillColor(248, 251, 255);
        $this->SetDrawColor(214, 224, 240);
        $this->Rect($cardX, $cardY, $cardW, 39, 'DF');

        $this->SetTextColor(24, 37, 58);
        $this->SetFont('Arial', 'B', 11);
        $this->SetXY($cardX + 3, $cardY + 3);
        $this->Cell($cardW - 6, 5, $this->offerTitle, 0, 1, 'L');

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(64, 83, 108);
        $this->SetX($cardX + 3);
        $this->Cell($cardW - 6, 4.5, 'Website: ' . $this->websiteTitle, 0, 1, 'L');
        $this->SetX($cardX + 3);
        $this->Cell($cardW - 6, 4.5, 'Price: ' . $this->offerPrice, 0, 1, 'L');
        $this->SetX($cardX + 3);
        $this->Cell($cardW - 6, 4.5, 'Order number: ' . $this->orderNumber, 0, 1, 'L');
        $this->SetX($cardX + 3);
        $this->Cell($cardW - 6, 4.5, 'Claim token: ' . $this->claimToken, 0, 1, 'L');
        $this->SetX($cardX + 3);
        $this->Cell($cardW - 6, 4.5, 'Order date: ' . $this->orderDate, 0, 1, 'L');

        // Offer and website links
        $this->SetX($cardX + 3);
        $this->SetTextColor(0, 136, 204);
        $this->SetFont('Arial', 'U', 9);
        $this->Write(4.5, 'Open offer page', $this->offerAddress);
        $this->SetTextColor(120, 135, 156);
        $this->SetFont('Arial', '', 9);
        $this->Write(4.5, '  |  ');
        $this->SetTextColor(0, 136, 204);
        $this->SetFont('Arial', 'U', 9);
        $this->Write(4.5, 'Open website', $this->websiteAddress);

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->Ln(8);
    }

    function Footer() {
        $pageWidth = $this->GetPageWidth();
        $margin = 10;

        $this->SetY(-14);

        // Disclaimer
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(120, 135, 156);
        $this->SetX($margin);
        $this->Cell($pageWidth - (2 * $margin), 4, 'The creators of Anonymous Sales are not responsible for the content sold via this platform.', 0, 1, 'L');
        $this->Ln(1);

        // Separator line
        $this->SetDrawColor(0, 136, 204);
        $this->Line($margin, $this->GetY(), $pageWidth - $margin, $this->GetY());
        $this->Ln(2);

        // Title (left) and page number (right)
        $rowY = $this->GetY();
        $textW = $pageWidth - (2 * $margin);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(92, 108, 130);
        $this->SetXY($margin, $rowY + 1);
        $this->Cell($textW, 5, 'Generated access sheet', 0, 0, 'L');
        $this->SetXY($margin, $rowY + 1);
        $this->Cell($textW, 5, 'Page ' . $this->PageNo(), 0, 0, 'R');
    }

    function anonymous_sales_drawAssetTable($assetNumber, $assetData) {
        // Page break logic: if it's the 3rd, 5th, 7th, etc. table, start a new page
        if ($this->assetTableCount > 0 && $this->assetTableCount % 2 == 0) {
            $this->AddPage();
        }

        // Draw section header if it's the first table on a page
        if ($this->assetTableCount % 2 == 0) {
            if ($this->assetTableCount == 0 || $this->showContentHeaderOnNewPages) {
                $this->Ln(8);
                $this->SetFillColor(0, 136, 204);
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Arial', 'B', 12);
                $this->Cell(0, 8, 'Included resources', 0, 1, 'L', true);
                $this->SetTextColor(0, 0, 0);
                $this->Ln(3);
            }
        } else {
            $this->Ln(6);
        }

        $this->SetFont('Arial', '', 10);

        $tableData = [
            'Asset name' => $assetData['name'] ?? '',
            'Asset address' => $assetData['address'] ?? '',
            'Asset password' => $assetData['password'] ?? '',
            'Asset hash' => $assetData['hash'] ?? '',
            'Asset description' => $assetData['description'] ?? '',
            'Asset version' => $assetData['version'] ?? '',
            'Asset type' => $assetData['type'] ?? '',
            'Asset size' => $assetData['size'] ?? '',
        ];
        
        $startY = $this->GetY();
        $x = $this->GetX();
        $labelW = 44;
        $rowH = 8;

        $this->SetFillColor(0, 136, 204);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->SetX($x);
        $this->Cell(0, 8, 'Resource ' . $assetNumber, 1, 1, 'L', true);
        $this->SetTextColor(0, 0, 0);

        $rowIndex = 0;
        foreach ($tableData as $label => $value) {
            $value = trim((string) $value);
            $displayValue = $value;

            if (function_exists('mb_strimwidth')) {
                $displayValue = mb_strimwidth($value, 0, 120, '...');
            } elseif (strlen($value) > 120) {
                $displayValue = substr($value, 0, 117) . '...';
            }

            $this->SetX($x);
            $this->SetFillColor($rowIndex % 2 === 0 ? 245 : 252, $rowIndex % 2 === 0 ? 248 : 253, 255);
            $this->SetFont('Arial', 'B', 9);
            $this->Cell($labelW, $rowH, $label, 1, 0, 'L', true);

            $this->SetFont('Arial', '', 9);
            if ($label === 'Asset address' && filter_var($value, FILTER_VALIDATE_URL)) {
                $this->SetTextColor(0, 136, 204);
                $this->SetFont('Arial', 'U', 9);
                $this->Cell(0, $rowH, ' ' . $displayValue, 1, 1, 'L', false, $value);
                $this->SetTextColor(0, 0, 0);
                $this->SetFont('Arial', '', 9);
            } else {
                $this->Cell(0, $rowH, ' ' . $displayValue, 1, 1, 'L');
            }

            $rowIndex++;
        }

        $this->SetY($this->GetY() + 2);
        
        $this->assetTableCount++;
    }

    function anonymous_sales_drawInfoFromSeller($messageData) {
        $this->AddPage();
        $this->Ln(4);

        $this->SetFillColor(0, 136, 204);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 9, 'Message from seller', 0, 1, 'L', true);
        $this->Ln(6);

        // Main title
        $this->SetTextColor(24, 37, 58);
        $this->SetFont('Arial', 'B', 20);
        $title = $messageData['infoFromSellerTitle'] ?? 'A Message From Us';
        $this->Cell(0, 11, $title, 0, 1, 'L');
        $this->Ln(2);

        $margin = 15;
        $pageContentWidth = $this->GetPageWidth() - (2 * $margin);
        $pic = $messageData['infoFromSellerPic'] ?? '';
        $header = $messageData['infoFromSellerHeader'] ?? '';
        $value = $messageData['infoFromSellerValue'] ?? '';

        $this->SetDrawColor(214, 224, 240);
        $this->SetFillColor(248, 251, 255);
        $this->Rect($margin, $this->GetY(), $pageContentWidth, 12, 'DF');
        $this->SetFont('Arial', 'I', 12);
        $this->SetTextColor(64, 83, 108);
        $this->SetXY($margin + 4, $this->GetY() + 2);
        $this->Cell($pageContentWidth - 8, 8, $header, 0, 1, 'L');
        $this->Ln(4);

        // Pic and text section
        $startY = $this->GetY();

        if (!empty($pic) && file_exists($pic)) {
            $imageWidth = $pageContentWidth / 4;
            $imageSize = getimagesize($pic);
            $imageHeight = $imageSize[1] * ($imageWidth / $imageSize[0]);
            $this->Image($pic, $margin, $startY, $imageWidth);
        } else {
            $imageWidth = 0;
            $imageHeight = 0;
        }

        $headerX = $margin + $imageWidth + ($imageWidth > 0 ? 10 : 0);
        $headerWidth = $pageContentWidth - $imageWidth - ($imageWidth > 0 ? 10 : 0);
        $this->SetY($startY);

        $this->anonymous_sales_drawMarkdown($value, $headerX, $headerWidth);
        $headerHeight = $this->GetY() - $startY;

        $newY = $startY + max($imageHeight, $headerHeight);
        $this->SetY($newY);
        $this->Ln(6);
    }

    function anonymous_sales_drawMarkdown($text, $contentX, $contentWidth, $baseFontSize = 11, $lineH = 5.5) {
        $pageWidth    = $this->GetPageWidth();
        $origLMargin  = $this->lMargin;
        $origRMargin  = $this->rMargin;
        $this->SetLeftMargin($contentX);
        $this->SetRightMargin($pageWidth - $contentX - $contentWidth);

        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $this->Ln($lineH * 0.5);
                continue;
            }

            // Headings: # ## ###
            if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $m)) {
                $level    = strlen($m[1]);
                $fSize    = [1 => 15, 2 => 13, 3 => 12][$level];
                $vSpace   = [1 => 3,  2 => 2,  3 => 1 ][$level];
                $this->Ln($vSpace);
                $this->SetX($contentX);
                $this->anonymous_sales_writeMarkdownInline($m[2], $lineH + $vSpace, $fSize, true);
                $this->Ln($vSpace + 1);
                continue;
            }

            // Bullet list: - item or * item
            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m)) {
                $this->SetX($contentX);
                $this->SetFont('Arial', '', $baseFontSize);
                $this->SetTextColor(24, 37, 58);
                $this->Write($lineH, '  \xe2\x80\xa2  ');
                $this->anonymous_sales_writeMarkdownInline($m[1], $lineH, $baseFontSize);
                $this->Ln();
                continue;
            }

            // Regular paragraph line
            $this->SetX($contentX);
            $this->anonymous_sales_writeMarkdownInline($trimmed, $lineH, $baseFontSize);
            $this->Ln();
        }

        $this->SetLeftMargin($origLMargin);
        $this->SetRightMargin($origRMargin);
    }

    function anonymous_sales_writeMarkdownInline($text, $lineH, $fontSize = 11, $bold = false) {
        $base    = $bold ? 'B' : '';
        $pattern = '/(\*\*(.+?)\*\*|\*(.+?)\*|\[(.+?)\]\((.+?)\))/u';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        $offset = 0;
        foreach ($matches[0] as $idx => $match) {
            $matchText   = $match[0];
            $matchOffset = $match[1];

            // Plain text before this match
            if ($matchOffset > $offset) {
                $plain = substr($text, $offset, $matchOffset - $offset);
                $this->SetFont('Arial', $base, $fontSize);
                $this->SetTextColor(24, 37, 58);
                $this->Write($lineH, $plain);
            }

            if (strncmp($matchText, '**', 2) === 0) {
                // Bold
                $inner = $matches[2][$idx][0];
                $this->SetFont('Arial', 'B', $fontSize);
                $this->SetTextColor(24, 37, 58);
                $this->Write($lineH, $inner);
            } elseif ($matchText[0] === '*') {
                // Italic
                $inner = $matches[3][$idx][0];
                $this->SetFont('Arial', $bold ? 'BI' : 'I', $fontSize);
                $this->SetTextColor(24, 37, 58);
                $this->Write($lineH, $inner);
            } else {
                // [text](url)
                $linkText = $matches[4][$idx][0];
                $linkUrl  = $matches[5][$idx][0];
                $this->SetFont('Arial', 'U', $fontSize);
                $this->SetTextColor(0, 136, 204);
                $this->Write($lineH, $linkText, $linkUrl);
                $this->SetTextColor(24, 37, 58);
            }

            $offset = $matchOffset + strlen($matchText);
        }

        // Remaining plain text
        if ($offset < strlen($text)) {
            $plain = substr($text, $offset);
            $this->SetFont('Arial', $base, $fontSize);
            $this->SetTextColor(24, 37, 58);
            $this->Write($lineH, $plain);
        }

        // Reset to base
        $this->SetFont('Arial', $base, $fontSize);
        $this->SetTextColor(24, 37, 58);
    }
}

function anonymous_sales_renderPasswordSheet($data) {
    $data = apply_filters('anonymous_sales_pdf_render_input', $data);

    $pdf = new Anonymous_Sales_PDF($data);
    $pdf->AddPage();

    // Handle assets
    $assetList = $data['assetList'] ?? [];
    if (!empty($assetList)) {
        foreach ($assetList as $index => $assetData) {
            $pdf->anonymous_sales_drawAssetTable($index + 1, $assetData);
        }
    }

    // Handle info from seller
    $infoFromSellerMessages = $data['infoFromSeller'] ?? [];
    if (!empty($infoFromSellerMessages)) {
        foreach ($infoFromSellerMessages as $message) {
            $pdf->anonymous_sales_drawInfoFromSeller($message);
        }
    }

    $filename = ($data['orderNumber'] ?? 'document') . '.pdf';
    $content = $pdf->Output('S');

    $rendered = [
        'filename' => $filename,
        'content' => $content,
    ];

    $rendered = apply_filters('anonymous_sales_pdf_render_output', $rendered, $data);
    do_action('anonymous_sales_pdf_rendered', $rendered, $data);

    return $rendered;
}
