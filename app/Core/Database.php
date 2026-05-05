<?php
namespace App\Core;

class Database {
    public static function getConnection() {
        global $db;
        if (!isset($db)) {
            require_once __DIR__ . '/../../db.php';
        }
        return $db;
    }
}
