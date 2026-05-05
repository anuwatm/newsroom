<?php
namespace App\Controllers;

use PDO;
use Exception;

class AssignmentController extends Controller {

    public function getAssignments() {
        $db = $this->db;
        $user = $this->user;
        
        $status = $_GET['status'] ?? '';
        $month = $_GET['month'] ?? ''; // YYYY-MM
        $dept = $_GET['department_id'] ?? '';
        
        $role_id = intval($user['role_id']);
        $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        $user_dept = $user['department_id'];

        $where = ["1=1"];
        $params = [];

        if ($role_id == 1 || $role_id == 4) {
            $where[] = "a.reporter_id = ?";
            $params[] = $user_emp_id;
        } elseif ($role_id == 2) {
            $dept = $user_dept; // Force scope to user's assigned department
        }
        
        if ($status) {
            $where[] = "a.status = ?";
            $params[] = $status;
        }
        if ($dept) {
            $where[] = "a.department_id = ?";
            $params[] = $dept;
        }
        if ($month) {
            $where[] = "EXISTS (SELECT 1 FROM assignment_trips t WHERE t.assignment_id = a.id AND t.trip_date LIKE ?)";
            $params[] = $month . '-%';
        }
        $where[] = "a.status != 'DELETED'";

        $whereStr = implode(" AND ", $where);
        $stmt = $db->prepare("SELECT a.*, d.name as department_name FROM assignments a LEFT JOIN departments d ON a.department_id = d.id WHERE $whereStr ORDER BY a.created_at DESC");
        $stmt->execute($params);
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $assIds = array_column($assignments, 'id');
        if (!empty($assIds)) {
            $inPart = implode(',', array_fill(0, count($assIds), '?'));
            
            $stmtT = $db->prepare("SELECT * FROM assignment_trips WHERE assignment_id IN ($inPart) ORDER BY trip_date ASC, start_time ASC");
            $stmtT->execute($assIds);
            $allTrips = $stmtT->fetchAll(PDO::FETCH_ASSOC);
            $tripsByAss = [];
            foreach ($allTrips as $t) {
                $tripsByAss[$t['assignment_id']][] = $t;
            }

            $stmtE = $db->prepare("SELECT e.*, m.name FROM assignment_equipment e LEFT JOIN equipment_master m ON e.equipment_name = m.name WHERE e.assignment_id IN ($inPart)");
            $stmtE->execute($assIds);
            $allEq = $stmtE->fetchAll(PDO::FETCH_ASSOC);
            $eqByAss = [];
            foreach ($allEq as $e) {
                $eqByAss[$e['assignment_id']][] = $e;
            }

            foreach ($assignments as &$ass) {
                $ass['trips'] = $tripsByAss[$ass['id']] ?? [];
                $ass['equipment'] = $eqByAss[$ass['id']] ?? [];
            }
        } else {
            foreach ($assignments as &$ass) {
                $ass['trips'] = [];
                $ass['equipment'] = [];
            }
        }
        echo json_encode(['success' => true, 'data' => $assignments]);
    }

    public function getAssignmentDetail() {
        $db = $this->db;
        $user = $this->user;
        
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT a.*, d.name as department_name FROM assignments a LEFT JOIN departments d ON a.department_id = d.id WHERE a.id = ?");
        $stmt->execute([$id]);
        $ass = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ass) {
            $role_id = intval($user['role_id']);
            $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
            $has_permission = true;

            if ($role_id == 1 || $role_id == 4) {
                if ($ass['reporter_id'] !== $user_emp_id && $ass['created_by'] !== $user_emp_id) {
                    $has_permission = false;
                }
            } elseif ($role_id == 2) {
                if ($ass['department_id'] != $user['department_id']) {
                    $has_permission = false;
                }
            }

            if (!$has_permission) {
                echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
            }

            $stmtT = $db->prepare("SELECT * FROM assignment_trips WHERE assignment_id = ? ORDER BY trip_date ASC, start_time ASC");
            $stmtT->execute([$id]);
            $ass['trips'] = $stmtT->fetchAll(PDO::FETCH_ASSOC);

            $stmtE = $db->prepare("SELECT * FROM assignment_equipment WHERE assignment_id = ?");
            $stmtE->execute([$id]);
            $ass['equipment'] = $stmtE->fetchAll(PDO::FETCH_ASSOC);
            
            $stmtS = $db->prepare("SELECT id, slug, status FROM stories WHERE assignment_id = ? AND is_deleted = 0");
            $stmtS->execute([$id]);
            $ass['linked_stories'] = $stmtS->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $ass]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Assignment not found']);
        }
    }

    public function createAssignment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        $role_id = intval($user['role_id']);
        $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        $user_dept = $user['department_id'];

        $title = trim($data['title'] ?? '');
        if (empty($title)) {
            echo json_encode(['success' => false, 'error' => 'Title is required.']); exit;
        }
        $reporter_id = $data['reporter_id'] ?? '';
        
        if ($role_id == 1 || $role_id == 4) {
            if ($reporter_id !== $user_emp_id) {
                echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only create for yourself.']); exit;
            }
        } elseif ($role_id == 2) {
            if ($data['department_id'] != $user_dept) {
                echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only create for your department.']); exit;
            }
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO assignments (title, description, reporter_id, reporter_name, department_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $data['description'] ?? '', $reporter_id, $data['reporter_name'] ?? '', $data['department_id'] ?? 0, $user_emp_id]);
            $assignmentId = $db->lastInsertId();

            $trips = $data['trips'] ?? [];
            if (empty($trips)) throw new Exception('At least 1 trip required.');
            $stmtT = $db->prepare("INSERT INTO assignment_trips (assignment_id, trip_date, start_time, end_time, location_name, location_detail, order_index) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($trips as $i => $t) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t['trip_date']) || !preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $t['start_time'])) {
                    throw new Exception("Invalid date or time format.");
                }
                $stmtT->execute([$assignmentId, $t['trip_date'], $t['start_time'], $t['end_time'] ?? null, $t['location_name'] ?? '', $t['location_detail'] ?? '', $i]);
            }

            $equip = $data['equipment'] ?? [];
            if (!empty($equip)) {
                $stmtE = $db->prepare("INSERT INTO assignment_equipment (assignment_id, equipment_name, quantity, note) VALUES (?, ?, ?, ?)");
                foreach ($equip as $e) {
                    $stmtE->execute([$assignmentId, $e['equipment_name'], intval($e['quantity'] ?? 1), $e['note'] ?? '']);
                }
            }
            $db->commit();
            write_log('CREATE_ASSIGNMENT', "Created assignment ID {$assignmentId} ({$title})");
            echo json_encode(['success' => true, 'id' => $assignmentId]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function updateAssignment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        $assignmentId = intval($data['id'] ?? 0);
        $role_id = intval($user['role_id']);
        $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        
        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
        $stmt->execute([$assignmentId]);
        $ass = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ass || $ass['status'] !== 'PENDING') {
            echo json_encode(['success' => false, 'error' => 'Can only edit PENDING assignments.']); exit;
        }
        
        if ($role_id == 1 || $role_id == 4) {
            if ($ass['created_by'] !== $user_emp_id) {
                echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only edit your own created assignment.']); exit;
            }
        } elseif ($role_id == 2) {
            if ($ass['department_id'] != $user['department_id']) {
                echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
            }
        }

        $db->beginTransaction();
        try {
            $title = trim($data['title'] ?? '');
            if (empty($title)) throw new Exception('Title is required.');
            $stmtU = $db->prepare("UPDATE assignments SET title=?, description=?, reporter_id=?, reporter_name=?, department_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmtU->execute([$title, $data['description'] ?? '', $data['reporter_id'], $data['reporter_name'], $data['department_id'], $assignmentId]);
            
            $stmtDelTrips = $db->prepare("DELETE FROM assignment_trips WHERE assignment_id = ?");
            $stmtDelTrips->execute([$assignmentId]);
            $stmtDelEq = $db->prepare("DELETE FROM assignment_equipment WHERE assignment_id = ?");
            $stmtDelEq->execute([$assignmentId]);
            
            $trips = $data['trips'] ?? [];
            if (empty($trips)) throw new Exception('At least 1 trip required.');
            $stmtT = $db->prepare("INSERT INTO assignment_trips (assignment_id, trip_date, start_time, end_time, location_name, location_detail, order_index) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($trips as $i => $t) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t['trip_date']) || !preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $t['start_time'])) {
                    throw new Exception("Invalid date or time format.");
                }
                $stmtT->execute([$assignmentId, $t['trip_date'], $t['start_time'], $t['end_time'] ?? null, $t['location_name'] ?? '', $t['location_detail'] ?? '', $i]);
            }

            $equip = $data['equipment'] ?? [];
            if (!empty($equip)) {
                $stmtE = $db->prepare("INSERT INTO assignment_equipment (assignment_id, equipment_name, quantity, note) VALUES (?, ?, ?, ?)");
                foreach ($equip as $e) {
                    $stmtE->execute([$assignmentId, $e['equipment_name'], intval($e['quantity'] ?? 1), $e['note'] ?? '']);
                }
            }
            $db->commit();
            write_log('UPDATE_ASSIGNMENT', "Updated assignment ID {$assignmentId} ({$title})");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function deleteAssignment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        $id = intval($data['id'] ?? 0);
        $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        $role_id = intval($user['role_id']);

        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
        $ass = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ass) {
            echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
        }
        
        if ($role_id != 3) {
            if ($ass['created_by'] !== $user_emp_id || $ass['status'] !== 'PENDING') {
                echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only delete your own PENDING assignments.']); exit;
            }
        }
        
        $stmtDel = $db->prepare("UPDATE assignments SET status='DELETED' WHERE id=?");
        $stmtDel->execute([$id]);
        write_log('DELETE_ASSIGNMENT', "Marked assignment ID {$id} as DELETED");
        echo json_encode(['success' => true]);
    }

    public function approveAssignment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        $role_id = intval($user['role_id']);
        if ($role_id == 1 || $role_id == 4) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
        $id = intval($data['id'] ?? 0);
        
        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
        $ass = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ass) {
            echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
        }
        if ($role_id == 2 && $ass['department_id'] != $user['department_id']) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
        if ($ass['status'] !== 'PENDING') {
            echo json_encode(['success' => false, 'error' => 'Must be PENDING']); exit;
        }
        
        $stmtA = $db->prepare("UPDATE assignments SET status='APPROVED', approved_by=?, approved_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmtA->execute([$user['employee_id'] ?? $user['id'] ?? $user['full_name'], $id]);
        write_log('APPROVE_ASSIGNMENT', "Approved assignment ID {$id}");
        echo json_encode(['success' => true]);
    }

    public function rejectAssignment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        $role_id = intval($user['role_id']);
        if ($role_id == 1 || $role_id == 4) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
        $id = intval($data['id'] ?? 0);
        $note = trim($data['rejection_note'] ?? '');
        if (!$note) {
            echo json_encode(['success' => false, 'error' => 'Rejection note required']); exit;
        }
        
        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
        $ass = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ass) {
            echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
        }
        if ($role_id == 2 && $ass['department_id'] != $user['department_id']) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
        if ($ass['status'] !== 'PENDING') {
            echo json_encode(['success' => false, 'error' => 'Must be PENDING']); exit;
        }
        
        $stmtA = $db->prepare("UPDATE assignments SET status='REJECTED', rejection_note=? WHERE id=?");
        $stmtA->execute([$note, $id]);
        write_log('REJECT_ASSIGNMENT', "Rejected assignment ID {$id}");
        echo json_encode(['success' => true]);
    }

    public function completeAssignment() {
        $db = $this->db;
        $user = $this->user;
        
        $data = $this->getJsonPayload();
        $id = intval($data['id'] ?? 0);
        $role_id = intval($user['role_id']);
        $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        
        $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
        $stmt->execute([$id]);
        $ass = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ass) {
            echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
        }

        if ($role_id == 1 || $role_id == 4) {
            if ($ass['reporter_id'] !== $user_emp_id) {
                echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
            }
        } elseif ($role_id == 2) {
            if ($ass['department_id'] != $user['department_id']) {
                echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
            }
        }
        
        if ($ass['status'] !== 'APPROVED') {
            echo json_encode(['success' => false, 'error' => 'Can only complete APPROVED assignment']); exit;
        }
        
        $stmtC = $db->prepare("UPDATE assignments SET status='COMPLETED' WHERE id=?");
        $stmtC->execute([$id]);
        write_log('COMPLETE_ASSIGNMENT', "Completed assignment ID {$id}");
        echo json_encode(['success' => true]);
    }

    public function getBadgeCount() {
        $db = $this->db;
        $user = $this->user;
        
        $role_id = intval($user['role_id']);
        $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        $user_dept = $user['department_id'];

        if ($role_id == 1 || $role_id == 4) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM assignments WHERE reporter_id = ? AND status = 'REJECTED'");
            $stmt->execute([$user_emp_id]);
        } elseif ($role_id == 2) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM assignments WHERE department_id = ? AND status = 'PENDING'");
            $stmt->execute([$user_dept]);
        } elseif ($role_id == 3) {
            $stmt = $db->query("SELECT COUNT(*) FROM assignments WHERE status = 'PENDING'");
        } else {
            echo json_encode(['success' => true, 'count' => 0]); exit;
        }
        echo json_encode(['success' => true, 'count' => $stmt->fetchColumn()]);
    }
}
