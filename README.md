# cPanel Full Backup to Remote SFTP

A PHP script to automate full cPanel account backups and transfer them to a remote server via SFTP.

## Features

*   **Automated Full Backup**: Triggers a full cPanel account backup using the UAPI.
*   **Secure Transfer**: Tranfers the backup file to a remote destination using SFTP (via cURL).
*   **Automatic Directory Creation**: Automatically creates the remote directory structure if it doesn't exist.
*   **Cleanup**: Automatically deletes the local backup file from the cPanel server after successful upload.
*   **Email Notifications**: Sends an email with the status (Success/Failure) and details of the operation.
*   **Resilience**: Handles API timeouts by monitoring the filesystem for backup completion.

## Requirements

*   **PHP 7.4+** (Recommended)
*   **cURL** extension for PHP
*   **cURL CLI** tool installed on the server (accessible via `shell_exec`)
*   **cPanel UAPI Access** (API Token)

## Configuration

1.  Open `cpanel_backup.php` in a text editor.
2.  Update the **Configuration** section at the top of the file:

    ```php
    // cPanel Server Details
    define('CPANEL_HOST', 'cpanel.example.com');
    define('CPANEL_USER', 'your_username');
    define('CPANEL_API_TOKEN', 'YOUR_API_TOKEN'); // Create via cPanel > Manage API Tokens

    // Remote Destination (SFTP)
    define('SCP_HOST', 'storage.example.com');
    define('SCP_USER', 'remote_user');
    define('SCP_PASS', 'remote_password');
    define('SCP_REMOTE_DIR', '/backups/cpanel');

    // Notification
    define('NOTIFY_EMAIL', 'your_email@example.com');
    ```

## Usage

### Manual Run
Run the script from the command line to test:
```bash
php cpanel_backup.php
```

### Cron Job (Automated)
Set up a cron job in cPanel to run the backup automatically (e.g., daily or weekly).

**Example (Run every Sunday at 2 AM):**
```bash
0 2 * * 0 /usr/local/bin/php /home/your_username/scripts/cpanel_backup.php > /dev/null 2>&1
```

*(Note: Verify the path to your PHP binary, e.g., `/usr/bin/php` or `/usr/local/bin/php`)*

## Troubleshooting

*   **Permission Denied**: Ensure the script has execute permissions (`chmod +x cpanel_backup.php`).
*   **API Errors**: Verify your cPanel API Token has `Backup` privileges.
*   **Upload Failures**: Check that the remote SFTP credentials are correct and that the cPanel server is not blocked by a firewall.
