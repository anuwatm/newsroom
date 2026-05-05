<?php
namespace App\Controllers;

use App\Core\Database;

class Controller {
    protected $db;
    protected $user;

    public function __construct() {
        $this->db = Database::getConnection();
        $this->user = $_SESSION['user'] ?? null;
    }

    protected function jsonResponse($success, $data = [], $error = null) {
        $response = ['success' => $success];
        if ($success && !empty($data)) {
            $response = array_merge($response, $data);
        }
        if (!$success && $error) {
            $response['error'] = $error;
        }
        echo json_encode($response);
        exit;
    }

    protected function getJsonPayload() {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $this->jsonResponse(false, [], 'Invalid JSON Payload');
        }
        // Validate CSRF if POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($data['csrf_token']) || $data['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
                $this->jsonResponse(false, [], 'Invalid CSRF token. Security block.');
            }
        }
        return $data;
    }
}
