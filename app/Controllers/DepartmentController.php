<?php
namespace App\Controllers;

use PDO;
use Exception;

class DepartmentController extends Controller {

    public function getDepartments() {
        $stmt = $this->db->query("SELECT id, name FROM departments ORDER BY id ASC");
        $this->jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveDepartment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        if ($user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Permission Denied');
        }
        
        $id = $data['id'] ?? null;
        $name = trim($data['name'] ?? '');
        
        if (empty($name)) {
            $this->jsonResponse(false, [], 'Department name is required');
        }
        if (mb_strlen($name, 'UTF-8') > 255) {
            $this->jsonResponse(false, [], 'Department name exceeds maximum length of 255 characters');
        }
        
        // Ensure uniqueness
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM departments WHERE name=? AND id!=?");
        $stmtCheck->execute([$name, $id ?? 0]);
        if ($stmtCheck->fetchColumn() > 0) {
            $this->jsonResponse(false, [], 'Department name already exists');
        }
        
        if ($id) {
            $stmt = $db->prepare("UPDATE departments SET name=? WHERE id=?");
            $stmt->execute([$name, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO departments (name) VALUES (?)");
            $stmt->execute([$name]);
        }
        write_log('SAVE_DEPARTMENT', "Saved configuration for department: {$name}");
        $this->jsonResponse(true);
    }

    public function deleteDepartment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        if ($user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Permission Denied');
        }
        
        $id = $data['id'] ?? null;
        if ($id) {
            // Prevent deletion if connected to stories or users 
            $stmtCheck1 = $db->prepare("SELECT COUNT(*) FROM users WHERE department_id=?");
            $stmtCheck1->execute([$id]);
            if ($stmtCheck1->fetchColumn() > 0) {
                $this->jsonResponse(false, [], 'Cannot delete: Department is actively assigned to Users.');
            }

            $stmtCheck2 = $db->prepare("SELECT COUNT(*) FROM stories WHERE department_id=?");
            $stmtCheck2->execute([$id]);
            if ($stmtCheck2->fetchColumn() > 0) {
                $this->jsonResponse(false, [], 'Cannot delete: Department is assigned to existing Stories.');
            }

            $stmtCheck3 = $db->prepare("SELECT COUNT(*) FROM assignments WHERE department_id=?");
            $stmtCheck3->execute([$id]);
            if ($stmtCheck3->fetchColumn() > 0) {
                $this->jsonResponse(false, [], 'Cannot delete: Department is assigned to existing Assignments.');
            }

            $stmt = $db->prepare("DELETE FROM departments WHERE id=?");
            $stmt->execute([$id]);
        }
        write_log('DELETE_DEPARTMENT', "Deleted department ID: {$id}");
        $this->jsonResponse(true);
    }
}
