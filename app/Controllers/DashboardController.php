<?php
namespace App\Controllers;

use PDO;

class DashboardController extends Controller {

    public function getStats() {
        $db = $this->db;
        $user = $this->user;
        
        if ($user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Permission Denied');
        }

        $stats = [];
        
        // 1. Total users
        $stmtUsers = $db->query("SELECT COUNT(*) FROM users");
        $stats['total_users'] = (int)$stmtUsers->fetchColumn();

        // 2 & 3. Total active stories & Status breakdown
        $stmtStatus = $db->query("SELECT status, COUNT(*) as count FROM stories WHERE is_deleted = 0 AND format != 'BREAK' GROUP BY status");
        $statusCounts = [];
        $totalStories = 0;
        while ($row = $stmtStatus->fetch(PDO::FETCH_ASSOC)) {
            $statusCounts[$row['status']] = (int)$row['count'];
            $totalStories += (int)$row['count'];
        }
        $stats['status_counts'] = $statusCounts;
        $stats['total_stories'] = $totalStories;

        // 4. Department breakdown
        $stmtDept = $db->query("SELECT d.name as department_name, COUNT(s.id) as count 
                                FROM stories s 
                                LEFT JOIN departments d ON s.department_id = d.id 
                                WHERE s.is_deleted = 0 AND s.format != 'BREAK' 
                                GROUP BY d.name");
        $deptCounts = [];
        while ($row = $stmtDept->fetch(PDO::FETCH_ASSOC)) {
            $name = empty($row['department_name']) ? 'Unknown' : $row['department_name'];
            $deptCounts[$name] = (int)$row['count'];
        }
        $stats['dept_counts'] = $deptCounts;

        // 5. Total rundowns
        $stmtRundownsTot = $db->query("SELECT COUNT(*) FROM rundowns");
        $stats['total_rundowns'] = (int)$stmtRundownsTot->fetchColumn();

        // 6. Upcoming broadcasts with TRT Calculation
        $stmtRun = $db->query("
            SELECT r.id, r.name, r.broadcast_time, r.target_trt,
                   COALESCE(trt.script_time, 0) + COALESCE(trt.total_break, 0) as current_trt
            FROM rundowns r 
            LEFT JOIN (
                SELECT rs.rundown_id, 
                       SUM(CASE WHEN rs.is_break = 0 THEN s.estimated_time ELSE 0 END) as script_time, 
                       SUM(rs.break_duration) as total_break 
                FROM rundown_stories rs 
                LEFT JOIN stories s ON rs.story_id = s.id 
                WHERE rs.is_dropped = 0 
                GROUP BY rs.rundown_id
            ) as trt ON r.id = trt.rundown_id
            ORDER BY r.broadcast_time DESC 
            LIMIT 5
        ");
        $stats['upcoming_rundowns'] = $stmtRun->fetchAll(PDO::FETCH_ASSOC);

        // 7 & 8. Total assignments & Assignment status breakdown
        $stmtAssStat = $db->query("SELECT status, COUNT(*) as count FROM assignments GROUP BY status");
        $astatusCounts = [];
        $totalAssignments = 0;
        while ($row = $stmtAssStat->fetch(PDO::FETCH_ASSOC)) {
            $astatusCounts[$row['status']] = (int)$row['count'];
            $totalAssignments += (int)$row['count'];
        }
        $stats['assignment_counts'] = $astatusCounts;
        $stats['total_assignments'] = $totalAssignments;

        // 9. Equipment stats
        $stmtEq1 = $db->query("SELECT SUM(total_units) FROM equipment_master WHERE is_active = 1");
        $stats['total_equipment'] = (int)$stmtEq1->fetchColumn();

        $stmtEq2 = $db->query("SELECT SUM(ae.quantity) FROM assignment_equipment ae JOIN assignments a ON ae.assignment_id = a.id WHERE a.status IN ('PENDING', 'APPROVED')");
        $stats['borrowed_equipment'] = (int)$stmtEq2->fetchColumn();

        // 10. Reporter Productivity
        $stmtTopRep = $db->query("
            SELECT author_id, 
                   COUNT(*) as count,
                   SUM(CASE WHEN status='APPROVED' THEN 1 ELSE 0 END) as approved_count,
                   AVG(estimated_time) as avg_time
            FROM stories 
            WHERE is_deleted = 0 AND author_id IS NOT NULL AND author_id != '' 
            GROUP BY author_id 
            ORDER BY count DESC 
            LIMIT 5
        ");
        $stats['top_reporters'] = $stmtTopRep->fetchAll(PDO::FETCH_ASSOC);

        // 11. Recent Activity Feed
        $stmtAct1 = $db->query("SELECT 'Story' as type, slug as title, updated_at as timestamp, status, updated_at FROM stories WHERE is_deleted = 0 ORDER BY updated_at DESC LIMIT 5");
        $act1 = $stmtAct1->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtAct2 = $db->query("SELECT 'Assignment' as type, title, updated_at as timestamp, status, updated_at FROM assignments ORDER BY updated_at DESC LIMIT 5");
        $act2 = $stmtAct2->fetchAll(PDO::FETCH_ASSOC);

        $all_activity = array_merge($act1, $act2);
        usort($all_activity, function($a, $b) {
            return strtotime($b['updated_at']) - strtotime($a['updated_at']);
        });
        $stats['recent_activity'] = array_slice($all_activity, 0, 8);

        $this->jsonResponse(true, $stats);
    }

    public function getLiveStatus() {
        $db = $this->db;
        $user = $this->user;
        
        if ($user['role_id'] != 3) {
            $this->jsonResponse(false, [], 'Permission Denied');
        }

        $live = [];

        $stmtCritEq = $db->query("
            SELECT em.name, em.total_units, 
                   COALESCE((SELECT SUM(quantity) FROM assignment_equipment ae JOIN assignments a ON ae.assignment_id = a.id WHERE a.status IN ('PENDING', 'APPROVED') AND ae.equipment_name = em.name), 0) as borrowed
            FROM equipment_master em
            WHERE em.is_active = 1
        ");
        $criticalEq = [];
        while ($row = $stmtCritEq->fetch(PDO::FETCH_ASSOC)) {
            if (($row['total_units'] - $row['borrowed']) <= 0) {
                $criticalEq[] = $row['name'];
            }
        }
        $live['critical_equipment'] = $criticalEq;

        $live['bottleneck_reviews'] = (int)$db->query("SELECT COUNT(*) FROM stories WHERE status = 'REVIEW'")->fetchColumn();
        $live['bottleneck_assignments'] = (int)$db->query("SELECT COUNT(*) FROM assignments WHERE status = 'PENDING'")->fetchColumn();

        $stmtOnl = $db->query("SELECT u.full_name, d.name as dept_name, u.last_seen 
                               FROM users u LEFT JOIN departments d ON u.department_id = d.id 
                               WHERE u.last_seen > datetime('now', '-5 minutes')
                               ORDER BY u.last_seen DESC LIMIT 10");
        $live['online_users'] = $stmtOnl->fetchAll(PDO::FETCH_ASSOC);

        $stmtActS = $db->query("SELECT slug as title, locked_by as editor, locked_at 
                                FROM stories 
                                WHERE is_deleted = 0 AND locked_by IS NOT NULL 
                                AND locked_by != '' 
                                AND locked_at > datetime('now', '-5 minutes')
                                ORDER BY locked_at DESC");
        $live['active_stories'] = $stmtActS->fetchAll(PDO::FETCH_ASSOC);

        $stmtActR = $db->query("SELECT name as title, locked_by as editor, locked_at 
                                FROM rundowns 
                                WHERE locked_by IS NOT NULL 
                                AND locked_by != '' 
                                AND locked_at > datetime('now', '-5 minutes')
                                ORDER BY locked_at DESC");
        $live['active_rundowns'] = $stmtActR->fetchAll(PDO::FETCH_ASSOC);

        $stmtTodayA = $db->query("SELECT a.title, a.reporter_name as assignee, at.location_name as location, at.start_time as time 
                                  FROM assignments a 
                                  JOIN assignment_trips at ON a.id = at.assignment_id 
                                  WHERE a.status = 'APPROVED' 
                                  AND at.trip_date = date('now')
                                  ORDER BY at.start_time ASC");
        $live['today_assignments'] = $stmtTodayA->fetchAll(PDO::FETCH_ASSOC);

        $this->jsonResponse(true, $live);
    }

    public function getCalendarData() {
        $db = $this->db;
        $user = $this->user;
        
        $month = $_GET['month'] ?? ''; // YYYY-MM
        $role_id = intval($user['role_id']);
        $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        $user_dept = $user['department_id'];
        $fDept = $_GET['department_id'] ?? '';

        $where = ["a.status != 'DELETED'", "t.trip_date LIKE ?"];
        $params = [$month . '-%'];

        if ($role_id == 2) {
            $fDept = $user_dept;
        }

        if ($fDept) {
            $where[] = "a.department_id = ?";
            $params[] = $fDept;
        }

        if ($role_id == 1 || $role_id == 4) {
            $where[] = "a.reporter_id = ?";
            $params[] = $user_emp_id;
        }

        $whereStr = implode(" AND ", $where);
        $sql = "SELECT t.trip_date as date, a.id as assignment_id, a.title, a.status, a.reporter_name, t.location_name, a.department_id
                FROM assignment_trips t
                JOIN assignments a ON t.assignment_id = a.id
                WHERE $whereStr";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $assIds = array_unique(array_column($trips, 'assignment_id'));
        if (!empty($assIds)) {
            $inPart = implode(',', array_fill(0, count($assIds), '?'));
            
            $stmtEQ = $db->prepare("SELECT assignment_id, equipment_name FROM assignment_equipment WHERE assignment_id IN ($inPart)");
            $stmtEQ->execute(array_values($assIds));
            $allEq = $stmtEQ->fetchAll(PDO::FETCH_ASSOC);
            $eqByAss = [];
            foreach ($allEq as $e) {
                $eqByAss[$e['assignment_id']][] = $e['equipment_name'];
            }

            foreach ($trips as &$t) {
                $t['equipment'] = $eqByAss[$t['assignment_id']] ?? [];
            }
        } else {
            foreach ($trips as &$t) {
                $t['equipment'] = [];
            }
        }

        $this->jsonResponse(true, $trips);
    }
}
