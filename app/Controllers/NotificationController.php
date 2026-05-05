<?php
namespace App\Controllers;

use PDO;

class NotificationController extends Controller {

    public function getNotifications() {
        $db = $this->db;
        $user = $this->user;
        
        $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->execute([$empId]);
        
        $this->jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markRead() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        $id = intval($data['id'] ?? 0);
        $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        
        if ($id === 0) {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$empId]);
        } else {
            $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $empId]);
        }
        
        $this->jsonResponse(true);
    }
}
