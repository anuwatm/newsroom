<?php
/**
 * db.php
 * Initializes the SQLite database and creates setup schema.
 */

$dbDir = __DIR__ . '/database';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

$dbFile = $dbDir . '/newsroom.sqlite';

try {
    $db = new PDO("sqlite:" . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

    // NOTE: story_rows table is deprecated in favor of hybrid JSON file storage
    // But we don't drop it automatically to prevent accidental data loss of legacy stories.

    // 6. Indexes for Performance (Search & Archive)
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stories_status ON stories(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stories_department ON stories(department_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_stories_updated_at ON stories(updated_at DESC)");

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

        // 1. admin (บกกลาง / การเมือง)
        $db->exec("INSERT INTO users (employee_id, full_name, password, role_id, department_id) VALUES ('admin', 'Admin (บกกลาง)', '$hashed', 3, 1)");

        // 2. editor1 (บก / สังคม)
        $db->exec("INSERT INTO users (employee_id, full_name, password, role_id, department_id) VALUES ('editor1', 'Editor 1 (บก)', '$hashed', 2, 2)");

        // 3. reporter1 (นักข่าว / สังคม)
        $db->exec("INSERT INTO users (employee_id, full_name, password, role_id, department_id) VALUES ('reporter1', 'Reporter 1 (นักข่าว)', '$hashed', 1, 2)");

        // 4. rewrite1 (rewriter / กีฬา)
        $db->exec("INSERT INTO users (employee_id, full_name, password, role_id, department_id) VALUES ('rewrite1', 'Rewriter 1', '$hashed', 4, 4)");
    }

} catch (PDOException $e) {
    die("Database Connection failed: " . $e->getMessage());
}
