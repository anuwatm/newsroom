<?php
// Validate session user
$user = $_SESSION['user'] ?? null;
if (!$user) return; // fail-safe
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
            <a href="logout.php" class="btn-logout" title="Logout" style="margin-left: 16px; display: flex; align-items: center; gap: 6px; color: #f44336; text-decoration: none; font-size: 13px; font-weight: bold;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Sign Out
            </a>
        </div>
    </div>
