<?php
namespace App\Controllers;

use PDO;
use Exception;

class EquipmentController extends Controller {

    public function getAllEquipment() {
        $db = $this->db;
        $user = $this->user;
        
        if ($user['role_id'] != 3) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
        $stmt = $db->query("SELECT * FROM equipment_master ORDER BY category, name");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function saveEquipment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        if ($user['role_id'] != 3) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
        $id = intval($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $cat = trim($data['category'] ?? '');
        $qty = intval($data['total_units'] ?? 1);
        $active = intval($data['is_active'] ?? 1);

        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Equipment name is required']); exit;
        }

        try {
            if ($id) {
                $stmt = $db->prepare("UPDATE equipment_master SET name=?, category=?, total_units=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $cat, $qty, $active, $id]);
            } else {
                $stmt = $db->prepare("INSERT INTO equipment_master (name, category, total_units, is_active) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $cat, $qty, $active]);
            }
            write_log('SAVE_EQUIPMENT', "Saved equipment entry: {$name}");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Duplicate item name or server error']);
        }
    }

    public function checkAvailability() {
        $db = $this->db;
        
        $date = $_GET['date'] ?? '';
        $equipment = $_GET['equipment'] ?? '';
        $qty = intval($_GET['qty'] ?? 1);
        
        if (empty($date) || empty($equipment)) {
            echo json_encode(['success' => false, 'error' => 'Missing date or equipment name']); exit;
        }
        
        $stmtMaster = $db->prepare("SELECT total_units FROM equipment_master WHERE name = ? AND is_active = 1");
        $stmtMaster->execute([$equipment]);
        $total_units = $stmtMaster->fetchColumn();
        if ($total_units === false) {
            echo json_encode(['success' => false, 'error' => 'Equipment not found or inactive']); exit;
        }
        
        // Find how many are already requested for that day
        $stmtUsed = $db->prepare("
            SELECT SUM(ae.quantity) 
            FROM assignment_equipment ae 
            JOIN assignments a ON ae.assignment_id = a.id 
            WHERE ae.equipment_name = ? 
            AND a.status IN ('PENDING', 'APPROVED')
            AND EXISTS (SELECT 1 FROM assignment_trips t WHERE t.assignment_id = a.id AND t.trip_date = ?)
        ");
        $stmtUsed->execute([$equipment, $date]);
        $used_units = intval($stmtUsed->fetchColumn() ?: 0);
        
        $remaining = $total_units - $used_units;
        $available = ($remaining >= $qty);
        
        echo json_encode(['success' => true, 'available' => $available, 'remaining' => $remaining, 'total' => $total_units]);
    }

    public function deleteEquipment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        if ($user['role_id'] != 3) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
        $id = intval($data['id'] ?? 0);
        try {
            $stmtName = $db->prepare("SELECT name FROM equipment_master WHERE id = ?");
            $stmtName->execute([$id]);
            $name = $stmtName->fetchColumn();
            if ($name) {
                $stmtCheck = $db->prepare("SELECT COUNT(*) FROM assignment_equipment WHERE equipment_name = ?");
                $stmtCheck->execute([$name]);
                if ($stmtCheck->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'error' => 'Cannot delete item currently used in assignments. Select "Inactive" status instead.']); exit;
                }
                $stmtDel = $db->prepare("DELETE FROM equipment_master WHERE id = ?");
                $stmtDel->execute([$id]);
                write_log('DELETE_EQUIPMENT', "Deleted equipment ID {$id} ({$name})");
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => true]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function getActiveEquipment() {
        $db = $this->db;
        $stmt = $db->query("SELECT * FROM equipment_master WHERE is_active = 1");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function getConflicts() {
        $db = $this->db;
        
        $date = trim($_GET['date'] ?? '');
        if (!$date) {
             echo json_encode(['success' => true, 'data' => []]); exit;
        }
        $query = "SELECT e.equipment_name, SUM(e.quantity) as used_qty
                  FROM assignment_equipment e
                  JOIN assignments a ON e.assignment_id = a.id
                  JOIN assignment_trips t ON a.id = t.assignment_id
                  WHERE t.trip_date = ? AND a.status IN ('APPROVED', 'PENDING')
                  GROUP BY e.equipment_name";
        $stmt = $db->prepare($query);
        $stmt->execute([$date]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
}
