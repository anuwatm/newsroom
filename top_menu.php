<?php
// Validate session user
$user = $_SESSION['user'] ?? null;
if (!$user) return; // fail-safe
require_once 'session_guard.php';
$active_menu = $active_menu ?? '';

// Update presence heartbeat
try {
    $db_heartbeat = new PDO("sqlite:" . __DIR__ . '/database/newsroom.sqlite');
    $db_heartbeat->exec("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE employee_id = '{$user['employee_id']}'");
} catch(Exception $e) {}
?>
    <!-- App Header -->
    <div class="app-header">
        <div class="header-left" style="display: flex; align-items: center;">
            <div class="app-title" style="font-weight: 700; font-size: 18px; margin-right: 40px;">News Room</div>
            <nav class="top-nav" style="display: flex;">
                <div class="nav-item dropdown">
                    <span class="nav-link" <?php echo $active_menu === 'story' ? 'style="color: #4caf50;"' : ''; ?>>Story ▾</span>
                    <div class="dropdown-menu">
                        <a href="<?php echo $active_menu === 'story' ? '#' : 'index.php'; ?>" id="nav-new-story">New Story</a>
                        <a href="<?php echo $active_menu === 'story' ? '#' : 'index.php'; ?>" id="nav-find-story">Find Story</a>
                        <a href="<?php echo $active_menu === 'story' ? '#' : 'index.php'; ?>" id="nav-my-story">My Story</a>
                    </div>
                </div>
                <div class="nav-item">
                    <a href="rundown.php" class="nav-link" <?php echo $active_menu === 'rundown' ? 'style="color: #4caf50;"' : ''; ?>>Rundown</a>
                </div>
                <div class="nav-item">
                    <a href="assignment.php" class="nav-link" <?php echo $active_menu === 'assignment' ? 'style="color: #4caf50;"' : ''; ?>>
                        Assignment
                        <span id="nav-badge" class="badge">0</span>
                    </a>
                </div>
                <?php if ($user['role_id'] == 3 || $user['role_id'] == 2): ?>
                <div class="nav-item dropdown">
                    <span class="nav-link" <?php echo $active_menu === 'admin' ? 'style="color: #4caf50;"' : ''; ?>>Admin ▾</span>
                    <div class="dropdown-menu">
                        <a href="dashboard.php">Dashboard</a>
                        <?php if ($user['role_id'] == 3): ?>
                        <a href="admin.php">Program Data</a>
                        <a href="users.php">User Management</a>
                        <a href="departments.php">Department Management</a>
                        <a href="equipment.php">Equipment Management</a>
                        <a href="syslog.php" style="color:#ffb74d;">System Logs (Audit)</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </nav>
        </div>
        <div class="user-info-bar" style="display: flex; align-items: center; gap: 12px;">
            <div class="user-avatar" style="width: 32px; height: 32px; background: #333; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #fff;">
                <?php echo htmlspecialchars(mb_substr($user['full_name'], 0, 1, 'UTF-8')); ?>
            </div>
            <div class="user-details" style="display: flex; flex-direction: column;">
                <div class="user-name" style="font-size: 14px; font-weight: 600; color: #fff;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role" style="font-size: 12px; color: #aaa;"><?php echo htmlspecialchars($user['department_name'] . ' • ' . $user['role_name']); ?></div>
            </div>
            <div class="nav-item dropdown" style="margin-left: 16px; position: relative;">
                <span id="nav-notifications" style="cursor: pointer; color: #fff; position: relative;">
                    <i class="fa-solid fa-bell" style="font-size: 18px;"></i>
                    <span id="notif-badge" class="badge" style="display:none; position:absolute; top:-8px; right:-10px; background:#f44336; color:#fff; border-radius:10px; padding:2px 6px; font-size:10px; font-weight:bold;">0</span>
                </span>
                <div class="dropdown-menu" id="notif-dropdown" style="right: 0; left: auto; width: 300px; max-height: 400px; overflow-y: auto; padding: 0;">
                    <div style="padding: 10px; border-bottom: 1px solid #333; display: flex; justify-content: space-between; align-items: center; background: #2a2a2a;">
                        <span style="font-weight: bold; color: #fff;">Notifications</span>
                        <span style="font-size: 12px; color: #2196f3; cursor: pointer;" onclick="markAllNotifRead()">Mark all read</span>
                    </div>
                    <div id="notif-list" style="padding: 10px; font-size: 13px; color: #aaa; text-align: center;">Loading...</div>
                </div>
            </div>

            <a href="change_password.php" class="btn-logout" title="Change Password" style="margin-left: 16px; color: var(--accent); text-decoration: none; font-size: 13px; font-weight: bold;">
                Change Password
            </a>
            <a href="logout.php" class="btn-logout" title="Logout" style="margin-left: 16px; display: flex; align-items: center; gap: 6px; color: #f44336; text-decoration: none; font-size: 13px; font-weight: bold;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Sign Out
            </a>
        </div>
    </div>
    </div>

<script>
    async function loadNotifications() {
        try {
            const res = await fetch('api.php?action=get_notifications');
            const json = await res.json();
            if (json.success && json.data) {
                const unread = json.data.filter(n => n.is_read == 0);
                const badge = document.getElementById('notif-badge');
                if (badge) {
                    badge.innerText = unread.length;
                    badge.style.display = unread.length > 0 ? 'inline-block' : 'none';
                }
                const list = document.getElementById('notif-list');
                if (list) {
                    if (json.data.length === 0) {
                        list.innerHTML = 'No new notifications';
                    } else {
                        list.innerHTML = json.data.map(n => `
                            <div style="padding: 10px; border-bottom: 1px solid #333; background: ${n.is_read == 0 ? '#1a2b3c' : 'transparent'};">
                                <a href="${n.link || '#'}" onclick="markNotifRead(${n.id})" style="color: #fff; text-decoration: none; display: block;">
                                    ${n.message}
                                    <div style="font-size: 11px; color: #888; margin-top: 4px;">${n.created_at}</div>
                                </a>
                            </div>
                        `).join('');
                    }
                }
            }
        } catch(e) {}
    }
    
    async function markNotifRead(id) {
        try {
            await fetch('api.php?action=mark_notification_read', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            });
            loadNotifications();
        } catch(e) {}
    }

    async function markAllNotifRead() {
        await markNotifRead(0);
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadNotifications();
        setInterval(loadNotifications, 30000);
    });
</script>
