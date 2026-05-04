<?php
/**
 * db.php
 * Initializes the SQLite database and creates setup schema.
 */
if (!in_array('ob_gzhandler', ob_list_handlers())) {
    ob_start('ob_gzhandler');
}
date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/logger.php';

$dbDir = __DIR__ . '/database';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

$dbFile = $dbDir . '/newsroom.sqlite';

try {
    $db = new PDO("sqlite:" . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Performance Tuning (PRAGMA)
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");
    $db->exec("PRAGMA temp_store = MEMORY;");
    $db->exec("PRAGMA cache_size = -64000;"); // Use 64MB of RAM for cache
    $db->exec("PRAGMA busy_timeout = 5000;");
    $db->exec("PRAGMA mmap_size = 30000000000;");

    // 1. Roles Table
    $db->exec("CREATE TABLE IF NOT EXISTS roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL
    )");

    // 2. Departments Table
    $db->exec("CREATE TABLE IF NOT EXISTS departments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL
    )");

    // 3. Users Table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        employee_id TEXT PRIMARY KEY,
        full_name TEXT NOT NULL,
        password TEXT NOT NULL,
        role_id INTEGER NOT NULL,
        department_id INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(role_id) REFERENCES roles(id),
        FOREIGN KEY(department_id) REFERENCES departments(id)
    )");

    try { $db->exec("ALTER TABLE users ADD COLUMN login_attempts INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME"); } catch (Exception $e) {}

    // 4. Stories Table
    $db->exec("CREATE TABLE IF NOT EXISTS stories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT,
        format TEXT,
        reporter TEXT,
        anchor TEXT,
        department_id INTEGER,
        status TEXT DEFAULT 'DRAFT',
        estimated_time INTEGER DEFAULT 0,
        current_version INTEGER DEFAULT 0,
        keywords TEXT,
        keyword_soundex TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(department_id) REFERENCES departments(id)
    )");

    try { $db->exec("ALTER TABLE stories ADD COLUMN assignment_id INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE stories ADD COLUMN author_id TEXT"); } catch (Exception $e) {}

    // NOTE: story_rows table is deprecated in favor of hybrid JSON file storage
    // But we don't drop it automatically to prevent accidental data loss of legacy stories.

    // 5. Phase 3 Tables
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        message TEXT NOT NULL,
        link TEXT,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(employee_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rundown_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rundown_id INTEGER NOT NULL,
        snapshot_json TEXT NOT NULL,
        locked_by TEXT NOT NULL,
        locked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(rundown_id) REFERENCES rundowns(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rundowns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        broadcast_time DATETIME NOT NULL,
        target_trt INTEGER NOT NULL DEFAULT 0,
        is_locked INTEGER DEFAULT 0,
        created_by TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS rundown_stories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rundown_id INTEGER NOT NULL,
        story_id INTEGER NOT NULL,
        order_index INTEGER NOT NULL DEFAULT 0,
        is_dropped INTEGER DEFAULT 0,
        FOREIGN KEY(rundown_id) REFERENCES rundowns(id) ON DELETE CASCADE,
        FOREIGN KEY(story_id) REFERENCES stories(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS programs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        duration INTEGER NOT NULL DEFAULT 0,
        break_count INTEGER NOT NULL DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS story_comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        story_id INTEGER NOT NULL,
        user_id TEXT NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(story_id) REFERENCES stories(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT,
        reporter_id TEXT NOT NULL,
        reporter_name TEXT NOT NULL,
        department_id INTEGER NOT NULL,
        status TEXT DEFAULT 'PENDING',
        approved_by TEXT,
        approved_at DATETIME,
        rejection_note TEXT,
        created_by TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS assignment_trips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
        trip_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME,
        location_name TEXT NOT NULL,
        location_detail TEXT,
        order_index INTEGER DEFAULT 0
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS assignment_equipment (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
        equipment_name TEXT NOT NULL,
        quantity INTEGER DEFAULT 1,
        note TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS equipment_master (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        category TEXT,
        total_units INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS active_viewers (
        story_id INTEGER,
        user_id TEXT,
        user_name TEXT,
        last_seen INTEGER,
        PRIMARY KEY(story_id, user_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
        ip TEXT PRIMARY KEY,
        hits INTEGER,
        last_reset INTEGER
    )");

    // Missing Columns Migration
    try { $db->exec("ALTER TABLE rundown_stories ADD COLUMN is_break INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE rundown_stories ADD COLUMN break_duration INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE rundowns ADD COLUMN program_id INTEGER"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE rundowns ADD COLUMN locked_by TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE rundowns ADD COLUMN locked_at DATETIME"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN last_seen DATETIME"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE stories ADD COLUMN current_version INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE stories ADD COLUMN keywords TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE stories ADD COLUMN keyword_soundex TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE stories ADD COLUMN is_deleted INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE stories ADD COLUMN locked_by TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE stories ADD COLUMN locked_at DATETIME"); } catch (Exception $e) {}

    // 6. Indexes for Performance (Search & Archive)
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stories_status ON stories(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stories_department ON stories(department_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stories_updated_at ON stories(updated_at DESC)");
    
    // Missing Indexes for System-wide Optimization
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_users_department ON users(department_id)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_assignments_status ON assignments(status)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_assignments_department ON assignments(department_id)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_assignment_trips_date ON assignment_trips(trip_date)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_story_comments_story_id ON story_comments(story_id)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_rundown_stories_rundown ON rundown_stories(rundown_id)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_equipment_master_active ON equipment_master(is_active)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_assignment_equipment_assignment_id ON assignment_equipment(assignment_id)"); } catch (Exception $e) {}

    // ----- SEED DATA -----

    // Seed Roles
    $stmt = $db->query("SELECT COUNT(*) FROM roles");
    if ($stmt->fetchColumn() == 0) {
        $roles = ['นักข่าว', 'บก', 'บกกลาง', 'rewriter'];
        $insert = $db->prepare("INSERT INTO roles (name) VALUES (?)");
        foreach ($roles as $r) {
            $insert->execute([$r]);
        }
    }

    // Seed Departments
    $stmt = $db->query("SELECT COUNT(*) FROM departments");
    if ($stmt->fetchColumn() == 0) {
        $depts = ['การเมือง', 'สังคม', 'เศรษฐกิจ', 'กีฬา'];
        $insert = $db->prepare("INSERT INTO departments (name) VALUES (?)");
        foreach ($depts as $d) {
            $insert->execute([$d]);
        }
    }

    // Seed Users
    $stmt = $db->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $hashed = password_hash('1234', PASSWORD_BCRYPT);

        $stmt_ins = $db->prepare("INSERT INTO users (employee_id, full_name, password, role_id, department_id) VALUES (?, ?, ?, ?, ?)");
        
        // 1. admin (บกกลาง / การเมือง)
        $stmt_ins->execute(['admin', 'Admin (บกกลาง)', $hashed, 3, 1]);

        // 2. editor1 (บก / สังคม)
        $stmt_ins->execute(['editor1', 'Editor 1 (บก)', $hashed, 2, 2]);

        // 3. reporter1 (นักข่าว / สังคม)
        $stmt_ins->execute(['reporter1', 'Reporter 1 (นักข่าว)', $hashed, 1, 2]);

        // 4. rewrite1 (rewriter / กีฬา)
        $stmt_ins->execute(['rewrite1', 'Rewriter 1', $hashed, 4, 4]);
    }

    // Seed System Settings
    $stmt = $db->query("SELECT COUNT(*) FROM system_settings");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('read_time_chars_per_sec', '40')");
    }

    // CMS Digital Publishing Extensions
    try { $db->exec("ALTER TABLE stories ADD COLUMN is_published INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE stories ADD COLUMN digital_url TEXT"); } catch (Exception $e) {}

} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}

