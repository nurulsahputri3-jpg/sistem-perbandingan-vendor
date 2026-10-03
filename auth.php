<?php
session_start();

function normalizeRole($role) {
    $role = (string)$role;
    return $role === 'manager' ? 'pimpinan' : $role;
}

function getCurrentRole(): ?string {
    if (!isset($_SESSION['role'])) {
        return null;
    }

    $role = normalizeRole($_SESSION['role']);
    return in_array($role, ['vendor', 'admin', 'pimpinan'], true) ? $role : null;
}

function isLoggedIn() {
    if (!isset($_SESSION['user_id']) || getCurrentRole() === null) {
        return false;
    }

    $_SESSION['role'] = getCurrentRole();
    return true;
}

function requireLogin() {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireRole($role) {
    $role = normalizeRole($role);
    if (!in_array($role, ['vendor', 'admin', 'pimpinan'], true)) {
        throw new InvalidArgumentException('Role yang diminta tidak dikenal.');
    }

    requireLogin();
    if (getCurrentRole() !== $role) {
        denyAuthorization('Anda tidak memiliki izin untuk mengakses halaman ini.');
    }
}

function getCurrentVendorId(?mysqli $connection = null): ?int {
    if (getCurrentRole() !== 'vendor') {
        return null;
    }

    if ($connection === null && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $connection = $GLOBALS['conn'];
    }

    if ($connection instanceof mysqli) {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $statement = $connection->prepare("SELECT vendor_id FROM users WHERE id = ? AND role = 'vendor' LIMIT 1");
        if (!$statement) {
            return null;
        }

        $statement->bind_param('i', $userId);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }

        $user = $statement->get_result()->fetch_assoc();
        $statement->close();
        $vendorId = isset($user['vendor_id']) ? (int)$user['vendor_id'] : 0;
        $_SESSION['vendor_id'] = $vendorId > 0 ? $vendorId : null;
        return $vendorId > 0 ? $vendorId : null;
    }

    $vendorId = filter_var($_SESSION['vendor_id'] ?? null, FILTER_VALIDATE_INT);
    return $vendorId !== false && $vendorId !== null && $vendorId > 0 ? $vendorId : null;
}

function getCurrentUser(?mysqli $connection = null): ?array {
    if (!isLoggedIn()) {
        return null;
    }

    $userId = (int)$_SESSION['user_id'];
    return [
        'id' => $userId,
        'user_id' => $userId,
        'username' => (string)($_SESSION['username'] ?? ''),
        'role' => getCurrentRole(),
        'vendor_id' => getCurrentVendorId($connection),
    ];
}

function requireVendorOwnership(int $vendorId, ?mysqli $connection = null): void {
    requireRole('vendor');
    if ($vendorId <= 0 || getCurrentVendorId($connection) !== $vendorId) {
        denyAuthorization('Anda tidak memiliki akses ke data vendor ini.');
    }
}

function denyAuthorization(string $message = 'Akses ditolak.'): void {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}
