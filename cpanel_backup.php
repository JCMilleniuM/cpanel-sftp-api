<?php

/**
 * cPanel Full Backup via SFTP using API Token
 */

// ─── Configuration ────────────────────────────────────────────────────────────
// cPanel Server Details
define('CPANEL_HOST', 'cpanel.example.com'); // Your cPanel server hostname
define('CPANEL_PORT', 2083); // 2083 = HTTPS, 2082 = HTTP
define('CPANEL_USER', 'your_username'); // Your cPanel username
define('CPANEL_API_TOKEN', 'YOUR_API_TOKEN'); // cPanel API Token (Manage API Tokens in cPanel)

// Remote Destination Details (SFTP/SCP)
define('SCP_HOST', 'storage.example.com'); // Remote backup server hostname
define('SCP_PORT', 22); // Remote SSH/SFTP port
define('SCP_USER', 'remote_user'); // Remote username
define('SCP_PASS', 'remote_password'); // Remote password
define('SCP_REMOTE_DIR', '/backups/cpanel'); // Remote directory path (will be created if missing)

// Notification Settings
define('NOTIFY_EMAIL', 'admin@example.com'); // Email address to receive backup reports
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Send a request to the cPanel UAPI or API2 via cURL.
 */
function cpanel_api_request(string $module, string $function, array $params = [], int $timeout = 60): array
{
    $query = http_build_query($params);
    $url = sprintf(
        'https://%s:%d/execute/%s/%s?%s',
        CPANEL_HOST,
        CPANEL_PORT,
        $module,
        $function,
        $query
    );

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, // Set to false only for self-signed certs
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Authorization: cpanel ' . CPANEL_USER . ':' . CPANEL_API_TOKEN,
        ],
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => "HTTP $httpCode received from cPanel API"];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid JSON response from cPanel API'];
    }

    return $data;
}

/**
 * Trigger a full cPanel backup to a remote SCP destination.
 */
function trigger_backup(): array
{
    // 1. Trigger Local Backup
    echo "Requesting local backup generation via cPanel UAPI...\n";

    $params = [
        'email' => NOTIFY_EMAIL,
    ];
    // Use UAPI Backup::fullbackup_to_homedir
    $response = cpanel_api_request('Backup', 'fullbackup_to_homedir', $params);

    if (isset($response['success']) && !$response['success']) {
        return $response;
    }

    // UAPI might return 'result' object OR flat structure depending on call/version
    // Check for 'result' wrapper first, otherwise use response itself
    $result = $response['result'] ?? $response;

    // Check status
    if (!isset($result['status']) || $result['status'] == 0) {
        $errors = $result['errors'] ?? ['Unknown error from cPanel API'];
        $errorMsg = is_array($errors) ? implode('; ', $errors) : $errors;
        $raw = json_encode($response, JSON_PRETTY_PRINT);

        // Log error but proceed, as user reports backup still runs
        echo "[WARN] API reported error: $errorMsg\n";
        echo "However, attempting to monitor for backup file anyway...\n";
    }
    else {
        echo "Backup process initiated via API. PID: " . ($result['data']['pid'] ?? 'Unknown') . "\n";
    }

    // 2. Wait for Backup File
    $backupFile = wait_for_backup_completion();
    if (!$backupFile) {
        return ['success' => false, 'error' => 'Timeout waiting for backup file creation.'];
    }

    echo "Local backup created: $backupFile\n";

    // 3. Upload via SCP (shell_exec)
    if (upload_via_scp($backupFile)) {
        // 4. Delete Local File
        if (unlink($backupFile)) {
            echo "Local backup file deleted.\n";
        }
        else {
            echo "[WARN] Failed to delete local backup file: $backupFile\n";
        }

        return ['success' => true, 'message' => "Backup successfully uploaded to " . SCP_HOST];
    }
    else {
        return ['success' => false, 'error' => 'Failed to upload backup via SCP. Check output for details.'];
    }
}

/**
 * Polls the home directory for a completed backup file.
 * Strategy: Find the newest backup-*.tar.gz file and wait for its size to stop changing.
 */
function wait_for_backup_completion(int $timeout = 600): ?string
{
    $start = time();
    $homeDir = getenv('HOME') ?: '/home/' . CPANEL_USER;
    echo "Monitoring $homeDir for new backup file (Timeout: {$timeout}s)....\n";

    // Wait up to 120s for the file to APPEAR first
    echo "Waiting for backup file to appear...\n";
    $fileFound = false;
    $targetFile = null;

    while (time() - $start < $timeout) {
        $files = glob("$homeDir/backup-*.tar.gz");
        if (!empty($files)) {
            // Sort new to old
            usort($files, function ($a, $b) {
                return filemtime($a) <=> filemtime($b);
            });
            $newestFile = end($files);

            // Must be created within the last 2 minutes (or slightly in future due to clock drift)
            if (time() - filemtime($newestFile) < 120) {
                $targetFile = $newestFile;
                $fileFound = true;
                break;
            }
        }
        sleep(5);
    }

    if (!$fileFound) {
        echo "[ERROR] New backup file did not appear within timeout.\n";
        return null;
    }

    echo "Found new backup file: " . basename($targetFile) . "\n";
    echo "Waiting for write completion...\n";

    $lastSize = -1;
    $stableCount = 0;

    // Monitor file growth
    while (time() - $start < $timeout + 300) { // Add extra time for writing
        clearstatcache(true, $targetFile);
        $currentSize = filesize($targetFile);

        echo "Current size: " . number_format($currentSize / 1024 / 1024, 2) . " MB... \r";

        if ($currentSize === $lastSize) {
            $stableCount++;
        }
        else {
            $lastSize = $currentSize;
            $stableCount = 0;
        }

        // If stable for 6 checks (30 seconds) and > 1MB (avoid empty initial files)
        if ($stableCount >= 6 && $currentSize > 1024 * 1024) {
            echo "\nFile size stable for 30s. Assuming complete.\n";
            return $targetFile;
        }

        sleep(5);
    }

    echo "\n[ERROR] Timeout waiting for backup completion.\n";
    return null;
}

/**
 * Uploads the backup file to the remote server using cURL (SFTP).
 * Supports password authentication and automatic directory creation.
 */
function upload_via_scp(string $localFile): bool
{
    // Use SFTP to support recursive directory creation and better compatibility
    $remoteUrl = sprintf(
        'sftp://%s:%d/%s/%s',
        SCP_HOST,
        SCP_PORT,
        trim(SCP_REMOTE_DIR, '/'),
        basename($localFile)
    );

    echo "Uploading to $remoteUrl via cURL...\n";

    // -u user:pass
    // -T localfile
    // -k (insecure/skip host key check)
    // --ftp-create-dirs (create remote dirs recursively)

    $cmd = sprintf(
        'curl -k --ftp-create-dirs -u %s:%s -T %s %s 2>&1',
        escapeshellarg(SCP_USER),
        escapeshellarg(SCP_PASS),
        escapeshellarg($localFile),
        escapeshellarg($remoteUrl)
    );

    // Mask password in output if we print the command (we won't print it directly)
    // echo "Command: $cmd\n"; 

    $output = [];
    $returnVar = 0;
    exec($cmd, $output, $returnVar);

    if ($returnVar === 0) {
        echo "[SUCCESS] Upload complete.\n";
        return true;
    }
    else {
        echo "[ERROR] cURL Upload Failed (Exit Code: $returnVar):\n";
        echo implode("\n", $output) . "\n";
        return false;
    }
}

/**
 * Send an email notification about the backup status.
 */
function send_notification(bool $success, string $detail): void
{
    $status = $success ? 'SUCCESS ✅' : 'FAILED ❌';
    $subject = "[cPanel Backup] $status - " . date('Y-m-d H:i:s');

    $body = "cPanel Full Backup Notification\n";
    $body .= "================================\n\n";
    $body .= "Status  : $status\n";
    $body .= "Server  : " . CPANEL_HOST . "\n";
    $body .= "SCP Dest: " . SCP_USER . "@" . SCP_HOST . ":" . SCP_REMOTE_DIR . "\n";
    $body .= "Time    : " . date('Y-m-d H:i:s T') . "\n\n";
    $body .= "Detail  : $detail\n";

    $headers = implode("\r\n", [
        'From: cPanel Backup <no-reply@' . CPANEL_HOST . '>',
        'X-Mailer: PHP/' . PHP_VERSION,
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    if (!mail(NOTIFY_EMAIL, $subject, $body, $headers)) {
        error_log("[cpanel-backup] Failed to send notification email to " . NOTIFY_EMAIL);
    }
}

// ─── Main Execution ───────────────────────────────────────────────────────────
echo "Starting cPanel Full Backup Process...\n";


$result = trigger_backup();

if ($result['success']) {
    $detail = $result['message'] ?? 'Backup job completed successfully.';
    echo "[OK] $detail\n";
    send_notification(true, $detail);
}
else {
    $detail = $result['error'] ?? 'Unknown error.';
    $raw = isset($result['raw']) ? json_encode($result['raw'], JSON_PRETTY_PRINT) : 'N/A';
    echo "[ERROR] $detail\n";
    error_log("[cpanel-backup] $detail");
    send_notification(false, "$detail\n\nDebug Info:\n$raw");
}