<?php
namespace App\Controllers;

use PDO;
use Exception;

class UserController extends Controller {

    public function getUsers() {
        $db = $this->db;
        $user = $this->user;
        
        $role_id = intval($user['role_id']);
        if ($role_id == 1 || $role_id == 4) {
            $stmt = $db->prepare("SELECT employee_id as id, full_name as name FROM users WHERE department_id = ? ORDER BY full_name ASC");
            $stmt->execute([$user['department_id']]);
        } else {
            $stmt = $db->query("SELECT employee_id as id, full_name as name FROM users ORDER BY full_name ASC");
        }
        $this->jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getAllUsers() {
        $db = $this->db;
        $user = $this->user;
        
        if ($user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Permission Denied');
        }
        $query = "SELECT u.employee_id, u.full_name, u.role_id, u.department_id, 
                         r.name as role_name, d.name as department_name 
                  FROM users u
                  LEFT JOIN roles r ON u.role_id = r.id
                  LEFT JOIN departments d ON u.department_id = d.id
                  ORDER BY u.created_at ASC";
        $stmt = $db->query($query);
        $this->jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveUser() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        if ($user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Permission Denied');
        }
        
        $is_edit = $data['is_edit'] ?? false;
        $employee_id = trim($data['employee_id'] ?? '');
        $full_name = trim($data['full_name'] ?? '');
        $password = $data['password'] ?? '';
        $role_id = intval($data['role_id'] ?? 0);
        $department_id = intval($data['department_id'] ?? 0);
        
        if (empty($employee_id) || empty($full_name) || empty($role_id) || empty($department_id)) {
            $this->jsonResponse(false, [], 'Missing required fields');
        }

        if (!empty($password)) {
            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                $this->jsonResponse(false, [], 'Password must be at least 8 characters and contain both letters and numbers');
            }
        }
        
        if ($is_edit) {
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET full_name=?, password=?, role_id=?, department_id=? WHERE employee_id=?");
                $stmt->execute([$full_name, $hashed, $role_id, $department_id, $employee_id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET full_name=?, role_id=?, department_id=? WHERE employee_id=?");
                $stmt->execute([$full_name, $role_id, $department_id, $employee_id]);
            }
        } else {
            if (empty($password)) {
                $this->jsonResponse(false, [], 'Password is required for new users');
            }
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE employee_id=?");
            $stmtCheck->execute([$employee_id]);
            if ($stmtCheck->fetchColumn() > 0) {
                $this->jsonResponse(false, [], 'Username (Employee ID) already exists');
            }
            
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (employee_id, full_name, password, role_id, department_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$employee_id, $full_name, $hashed, $role_id, $department_id]);
        }
        write_log('SAVE_USER', "Configured user account mapping for employee ID: {$employee_id}");
        $this->jsonResponse(true);
    }

    public function deleteUser() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        if ($user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Permission Denied');
        }
        
        $employee_id = $data['employee_id'] ?? null;
        if ($employee_id) {
            if ($employee_id === $user['employee_id']) {
                $this->jsonResponse(false, [], 'Cannot delete your own account');
            }
            $stmt = $db->prepare("DELETE FROM users WHERE employee_id=?");
            $stmt->execute([$employee_id]);
        }
        write_log('DELETE_USER', "Deleted user account mapping for employee ID: {$employee_id}");
        $this->jsonResponse(true);
    }

    public function getRoles() {
        $stmt = $this->db->query("SELECT id, name FROM roles ORDER BY id ASC");
        $this->jsonResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
