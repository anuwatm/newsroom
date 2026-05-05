<?php
namespace App\Controllers;

class SystemController extends Controller {
    
    public function getSystemSettings() {
        if (!isset($this->user) || $this->user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Unauthorized');
        }

        try {
            $stmt = $this->db->query("SELECT * FROM system_settings");
            $settings = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            $this->jsonResponse(true, ['settings' => $settings]);
        } catch (\Exception $e) {
            $this->jsonResponse(false, [], $e->getMessage());
        }
    }

    public function saveSystemSettings() {
        if (!isset($this->user) || $this->user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Unauthorized');
        }

        $data = $this->getJsonPayload();
        $settings = $data['settings'] ?? [];

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            foreach ($settings as $key => $value) {
                $stmt->execute([$value, $key]);
            }
            $this->db->commit();
            $this->jsonResponse(true);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->jsonResponse(false, [], $e->getMessage());
        }
    }

    public function getLogFiles() {
        if (!isset($this->user) || $this->user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Unauthorized');
        }
        
        $log_dir = __DIR__ . '/../../data/log';
        $files = [];
        if (is_dir($log_dir)) {
            $found = glob($log_dir . '/*.log');
            foreach ($found as $filepath) {
                $files[] = basename($filepath);
            }
        }
        rsort($files); // newest first
        $this->jsonResponse(true, $files);
    }

    public function getLogContent() {
        if (!isset($this->user) || $this->user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Unauthorized');
        }
        
        $filename = $_GET['file'] ?? '';
        // Security check to prevent directory traversal
        if (empty($filename) || preg_match('/[^a-zA-Z0-9_\-\.]/', $filename) || strpos($filename, '..') !== false) {
            $this->jsonResponse(false, [], 'Invalid filename');
        }
        
        $filepath = __DIR__ . '/../../data/log/' . $filename;
        if (!file_exists($filepath)) {
            $this->jsonResponse(false, [], 'File not found');
        }

        $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $parsed_logs = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[(.*?)\] \[(.*?)\] \[(.*?)\] \[(.*?)\] \[(.*?)\] (.*)$/', $line, $matches)) {
                $parsed_logs[] = [
                    'time' => $matches[1],
                    'level' => $matches[2],
                    'ip' => $matches[3],
                    'user' => $matches[4],
                    'action' => $matches[5],
                    'details' => $matches[6]
                ];
            } else {
                $parsed_logs[] = [
                    'time' => '',
                    'level' => 'INFO',
                    'ip' => '',
                    'user' => '',
                    'action' => 'RAW',
                    'details' => $line
                ];
            }
        }
        // Return chronological order (first event at the top)
        $this->jsonResponse(true, $parsed_logs);
    }
}
