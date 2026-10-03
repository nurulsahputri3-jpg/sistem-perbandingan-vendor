<?php
require 'includes/auth.php';
requireLogin();
requireRole('admin');
require 'config/db.php';

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
$validRoles = ['vendor', 'admin', 'pimpinan'];
$mutatingActions = ['create', 'update', 'toggle_status', 'delete'];
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if (in_array($action, $mutatingActions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  denyAuthorization('Tindakan akun hanya dapat dikirim melalui formulir yang sah.');
}
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  denyAuthorization('Form edit akun tidak dapat dibuka melalui URL langsung.');
}
if (in_array($action, $mutatingActions, true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Permintaan tidak valid. Silakan muat ulang halaman.');
  }

  if ($action === 'create') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = (string)($_POST['role'] ?? '');
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $vendorId = null;

    if ($role === 'vendor') {
      $requestedVendorId = filter_var($_POST['vendor_id'] ?? null, FILTER_VALIDATE_INT);
      if ($requestedVendorId !== false && $requestedVendorId !== null && $requestedVendorId > 0) {
        $vendorCheck = $conn->prepare('SELECT id FROM vendors WHERE id = ?');
        $vendorCheck->bind_param('i', $requestedVendorId);
        $vendorCheck->execute();
        if ($vendorCheck->get_result()->num_rows === 1) {
          $vendorId = $requestedVendorId;
        }
        $vendorCheck->close();
      }
    }

    if ($username === '' || strlen($password) < 8 || !in_array($role, $validRoles, true)) {
      $_SESSION['user_notice'] = 'Lengkapi username, password minimal 8 karakter, dan role yang valid.';
    } elseif ($role === 'vendor' && $vendorId === null) {
      $_SESSION['user_notice'] = 'Pilih vendor yang sudah terdaftar.';
    } else {
      $passwordHash = password_hash($password, PASSWORD_DEFAULT);
      $create = $conn->prepare('INSERT INTO users (username, password_hash, role, vendor_id, full_name, email, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
      $create->bind_param('sssiss', $username, $passwordHash, $role, $vendorId, $fullName, $email);
      try {
        $create->execute();
        $_SESSION['user_notice'] = 'Akun berhasil dibuat.';
      } catch (mysqli_sql_exception $exception) {
        $_SESSION['user_notice'] = 'Akun tidak dapat dibuat. Username harus unik dan setiap vendor hanya dapat memiliki satu akun.';
      }
      $create->close();
    }
  } elseif ($action === 'update') {
    $targetId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $targetId = ($targetId !== false && $targetId !== null && $targetId > 0) ? $targetId : 0;
    $username = trim((string)($_POST['username'] ?? ''));
    $role = (string)($_POST['role'] ?? '');
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $newPassword = (string)($_POST['password'] ?? '');
    $vendorId = null;

    if ($role === 'vendor') {
      $requestedVendorId = filter_var($_POST['vendor_id'] ?? null, FILTER_VALIDATE_INT);
      if ($requestedVendorId !== false && $requestedVendorId !== null && $requestedVendorId > 0) {
        $vendorCheck = $conn->prepare('SELECT id FROM vendors WHERE id = ?');
        $vendorCheck->bind_param('i', $requestedVendorId);
        $vendorCheck->execute();
        if ($vendorCheck->get_result()->num_rows === 1) {
          $vendorId = $requestedVendorId;
        }
        $vendorCheck->close();
      }
    }

    if ($targetId === 0 || $username === '' || !in_array($role, $validRoles, true) || ($role === 'vendor' && $vendorId === null)) {
      $_SESSION['user_notice'] = 'Data akun atau vendor tidak valid.';
    } else {
      $target = $conn->prepare('SELECT role, is_active FROM users WHERE id = ?');
      $target->bind_param('i', $targetId);
      $target->execute();
      $targetUser = $target->get_result()->fetch_assoc();
      $target->close();

      $mustKeepAdmin = $targetUser
        && normalizeRole((string)$targetUser['role']) === 'admin'
        && (int)$targetUser['is_active'] === 1
        && $role !== 'admin';
      if ($targetId === (int)$_SESSION['user_id'] && $role !== 'admin') {
        $_SESSION['user_notice'] = 'Akun admin yang sedang digunakan tidak dapat diubah rolenya.';
      } elseif ($mustKeepAdmin && (int)$conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND is_active = 1")->fetch_assoc()['total'] <= 1) {
        $_SESSION['user_notice'] = 'Admin aktif terakhir tidak dapat diubah menjadi role lain.';
      } elseif (!$targetUser) {
        $_SESSION['user_notice'] = 'Akun tidak ditemukan.';
      } else {
        try {
          if ($newPassword !== '') {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $update = $conn->prepare('UPDATE users SET username = ?, password_hash = ?, role = ?, vendor_id = ?, full_name = ?, email = ? WHERE id = ?');
            $update->bind_param('sssissi', $username, $passwordHash, $role, $vendorId, $fullName, $email, $targetId);
          } else {
            $update = $conn->prepare('UPDATE users SET username = ?, role = ?, vendor_id = ?, full_name = ?, email = ? WHERE id = ?');
            $update->bind_param('ssissi', $username, $role, $vendorId, $fullName, $email, $targetId);
          }
          $update->execute();
          $update->close();
          $_SESSION['user_notice'] = 'Akun berhasil diperbarui.';
        } catch (mysqli_sql_exception $exception) {
          $_SESSION['user_notice'] = 'Akun tidak dapat diperbarui. Username harus unik dan setiap vendor hanya dapat memiliki satu akun.';
        }
      }
    }
  } elseif ($action === 'toggle_status') {
    $targetId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $targetId = ($targetId !== false && $targetId !== null && $targetId > 0) ? $targetId : 0;
    $target = $conn->prepare('SELECT role, is_active FROM users WHERE id = ?');
    $target->bind_param('i', $targetId);
    $target->execute();
    $targetUser = $target->get_result()->fetch_assoc();
    $target->close();

    if (!$targetUser) {
      $_SESSION['user_notice'] = 'Akun tidak ditemukan.';
    } elseif ($targetId === (int)$_SESSION['user_id'] && (int)$targetUser['is_active'] === 1) {
      $_SESSION['user_notice'] = 'Akun yang sedang digunakan tidak dapat dinonaktifkan.';
    } elseif (normalizeRole((string)$targetUser['role']) === 'admin'
        && (int)$targetUser['is_active'] === 1
        && (int)$conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND is_active = 1")->fetch_assoc()['total'] <= 1) {
      $_SESSION['user_notice'] = 'Admin aktif terakhir tidak dapat dinonaktifkan.';
    } else {
      $newStatus = (int)$targetUser['is_active'] === 1 ? 0 : 1;
      $statusUpdate = $conn->prepare('UPDATE users SET is_active = ? WHERE id = ?');
      $statusUpdate->bind_param('ii', $newStatus, $targetId);
      $statusUpdate->execute();
      $statusUpdate->close();
      $_SESSION['user_notice'] = $newStatus === 1 ? 'Akun berhasil diaktifkan.' : 'Akun berhasil dinonaktifkan.';
    }
  } else {
    $targetId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $targetId = ($targetId !== false && $targetId !== null && $targetId > 0) ? $targetId : 0;
    if ($targetId === (int)$_SESSION['user_id']) {
      $_SESSION['user_notice'] = 'Akun yang sedang digunakan tidak dapat dihapus.';
    } else {
      $target = $conn->prepare('SELECT role, is_active FROM users WHERE id = ?');
      $target->bind_param('i', $targetId);
      $target->execute();
      $targetUser = $target->get_result()->fetch_assoc();
      $target->close();
      $canDelete = (bool)$targetUser;
      if ($targetUser && normalizeRole((string)$targetUser['role']) === 'admin' && (int)$targetUser['is_active'] === 1) {
        $canDelete = (int)$conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND is_active = 1")->fetch_assoc()['total'] > 1;
      }
      if ($canDelete) {
        $delete = $conn->prepare('DELETE FROM users WHERE id = ?');
        $delete->bind_param('i', $targetId);
        $delete->execute();
        $delete->close();
        $_SESSION['user_notice'] = 'Akun berhasil dihapus.';
      } else {
        $_SESSION['user_notice'] = 'Akun admin aktif terakhir tidak dapat dihapus.';
      }
    }
  }

  header('Location: user.php');
  exit;
}

$notice = $_SESSION['user_notice'] ?? '';
unset($_SESSION['user_notice']);

$vendorResult = $conn->query('SELECT id, name FROM vendors ORDER BY name');
$vendorOptions = $vendorResult ? $vendorResult->fetch_all(MYSQLI_ASSOC) : [];
$usersResult = $conn->query('SELECT u.*, v.name AS vendor_name FROM users u LEFT JOIN vendors v ON v.id = u.vendor_id ORDER BY u.id DESC');
$userRows = [];
$totalUser = 0;
$adminCount = 0;
$vendorCount = 0;
$pimpinanCount = 0;
$activeUserCount = 0;

while ($row = $usersResult->fetch_assoc()) {
  $userRows[] = $row;
  $totalUser++;
  $activeUserCount += ((int)$row['is_active'] === 1 ? 1 : 0);

  $rowRole = normalizeRole((string)($row['role'] ?? ''));
  if ($rowRole === 'admin') {
    $adminCount++;
  } elseif ($rowRole === 'vendor') {
    $vendorCount++;
  } elseif ($rowRole === 'pimpinan') {
    $pimpinanCount++;
  }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Management - Vendor Budget</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    :root {
      --primary: #0d6efd;
      --navy: #081427;
      --soft: #f6f9ff;
      --text: #243247;
      --muted: #7d8796;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(180deg, #f7faff 0%, #edf3ff 100%);
      color: var(--text);
    }

    .sidebar {
      background: linear-gradient(180deg, #071225, #102245);
      box-shadow: 0 18px 38px rgba(3, 12, 35, 0.22);
    }

    .sidebar .nav-link {
      border-radius: 14px;
      padding: 0.75rem 0.95rem;
      margin-bottom: 0.35rem;
      color: rgba(255,255,255,0.92);
      transition: all 0.25s ease;
    }

    .sidebar .nav-link:hover,
    .sidebar .nav-link.active {
      background: var(--primary);
      color: #fff;
      transform: translateX(4px);
      box-shadow: 0 12px 24px rgba(13, 110, 253, 0.32);
    }

    .page-card {
      background: rgba(255,255,255,0.97);
      border-radius: 20px;
      box-shadow: 0 18px 40px rgba(16, 24, 40, 0.08);
      padding: 25px;
    }

    .mini-card {
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 12px 28px rgba(16, 24, 40, 0.08);
      padding: 1rem;
      transition: transform 0.22s ease;
    }

    .mini-card:hover {
      transform: translateY(-4px);
    }

    .mini-icon {
      width: 46px;
      height: 46px;
      border-radius: 14px;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #0d6efd, #3d84ff);
      color: #fff;
    }

    .table-custom {
      border-radius: 18px;
      overflow: hidden;
      box-shadow: 0 14px 32px rgba(16, 24, 40, 0.08);
    }

    .table-head {
      background: linear-gradient(135deg, #0d6efd, #1d4ed8);
      color: #fff;
    }

    .table-custom thead th {
      border: none;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding-top: 0.95rem;
      padding-bottom: 0.95rem;
    }

    .table-custom tbody tr:hover {
      background: #f6faff;
    }

    .avatar-badge {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      background: linear-gradient(135deg, #0d6efd, #8bb6ff);
      color: #fff;
      font-weight: 700;
      box-shadow: 0 8px 20px rgba(13, 110, 253, 0.25);
    }

    .btn-icon {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .search-box {
      border-radius: 14px;
      border: 1px solid #d8e5ff;
      background: #f8fbff;
      padding: 0.7rem 0.9rem;
    }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row g-0">
    <aside class="col-lg-2 col-md-3 sidebar text-white min-vh-100 p-3 p-lg-4">
      <div class="fw-bold fs-5 mb-4">Vendor Budget</div>
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link" href="vendor.php"><i class="fa-solid fa-building me-2"></i>Vendor</a></li>
        <li class="nav-item"><a class="nav-link" href="license.php"><i class="fa-solid fa-tags me-2"></i>Harga Lisensi</a></li>
        <li class="nav-item"><a class="nav-link active" href="user.php"><i class="fa-solid fa-users me-2"></i>Pengguna</a></li>
        <li class="nav-item"><a class="nav-link" href="report.php"><i class="fa-solid fa-file-lines me-2"></i>Laporan</a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
      </ul>
    </aside>

    <main class="col-lg-10 col-md-9 p-3 p-lg-4">
      <div class="page-card">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
          <div>
            <div class="text-primary small fw-semibold">Dashboard / User</div>
            <h2 class="fw-bold mb-1">Manajemen Akun Pengguna</h2>
            <p class="text-muted mb-0">Buat dan kelola akun login, role, serta hubungan akun vendor dengan data vendor yang sudah terdaftar.</p>
            <?php if ($notice !== ''): ?><div class="alert alert-info rounded-3 mt-3 mb-0 py-2"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fa-solid fa-plus me-2"></i>Tambah User</button>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="small text-muted">Total Akun</div>
                <div class="fw-bold fs-4"><?= $totalUser ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-users"></i></div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="small text-muted">Admin</div>
                <div class="fw-bold fs-4"><?= $adminCount ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-user-shield"></i></div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="small text-muted">Vendor</div>
                <div class="fw-bold fs-4"><?= $vendorCount ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-building"></i></div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="small text-muted">Akun Aktif</div>
                <div class="fw-bold fs-4"><?= $activeUserCount ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-user-check"></i></div>
            </div>
          </div>
        </div>

        <div class="row g-2 align-items-center mb-4">
          <div class="col-md-6">
            <div class="input-group search-box">
              <span class="input-group-text bg-transparent border-0"><i class="fa-solid fa-magnifying-glass text-primary"></i></span>
              <input type="text" id="userSearch" class="form-control border-0 bg-transparent" placeholder="Search User">
            </div>
          </div>
          <div class="col-md-6">
            <select class="form-select search-box" id="roleFilter">
              <option value="">Filter Role</option>
              <option value="admin">Administrator</option>
              <option value="vendor">Vendor</option>
              <option value="pimpinan">Pimpinan</option>
            </select>
          </div>
        </div>

        <div class="table-responsive table-custom">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-head">
              <tr>
                <th>No</th>
                <th>Username</th>
                <th>Role</th>
                <th>Vendor</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($userRows as $index => $row): ?>
                <?php
                  $rowRole = normalizeRole((string)($row['role'] ?? ''));
                  $rowIsActive = (int)($row['is_active'] ?? 1) === 1;
                  $rowRoleLabel = $rowRole === 'admin' ? 'Admin' : ($rowRole === 'vendor' ? 'Vendor' : 'Pimpinan');
                ?>
                <tr data-user-row data-role="<?= htmlspecialchars($rowRole, ENT_QUOTES) ?>">
                  <td><?= $index + 1 ?></td>
                  <td class="fw-semibold"><?= htmlspecialchars($row['username']) ?></td>
                  <td>
                    <?php
                      $roleClass = $rowRole === 'admin' ? 'bg-primary' : ($rowRole === 'vendor' ? 'bg-success' : 'bg-dark');
                    ?>
                    <span class="badge <?= $roleClass ?> rounded-pill px-3 py-2"><?= htmlspecialchars($rowRoleLabel) ?></span>
                  </td>
                  <td><?= $rowRole === 'vendor' ? htmlspecialchars((string)($row['vendor_name'] ?? '-')) : '-' ?></td>
                  <td>
                    <span class="badge <?= $rowIsActive ? 'bg-success' : 'bg-secondary' ?> rounded-pill px-3 py-2"><?= $rowIsActive ? 'Aktif' : 'Nonaktif' ?></span>
                  </td>
                  <td>
                    <div class="d-flex gap-2">
                      <button type="button" class="btn btn-sm btn-outline-primary btn-icon btn-edit-user" data-bs-toggle="modal" data-bs-target="#editUserModal" data-id="<?= (int)$row['id'] ?>" data-username="<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>" data-role="<?= htmlspecialchars($rowRole, ENT_QUOTES) ?>" data-vendor-id="<?= (int)($row['vendor_id'] ?? 0) ?>" data-full-name="<?= htmlspecialchars((string)($row['full_name'] ?? ''), ENT_QUOTES) ?>" data-email="<?= htmlspecialchars((string)($row['email'] ?? ''), ENT_QUOTES) ?>" title="Atur akun"><i class="fa-solid fa-pen-to-square"></i></button>
                      <form method="post" action="user.php?action=toggle_status" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <?php if ((int)$row['id'] === (int)$_SESSION['user_id'] && $rowIsActive): ?>
                          <button type="button" class="btn btn-sm btn-outline-secondary btn-icon" title="Akun yang sedang dipakai tidak dapat dinonaktifkan" disabled><i class="fa-solid fa-lock"></i></button>
                        <?php else: ?>
                          <button type="submit" class="btn btn-sm <?= $rowIsActive ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-icon" title="<?= $rowIsActive ? 'Nonaktifkan akun' : 'Aktifkan akun' ?>"><i class="fa-solid <?= $rowIsActive ? 'fa-user-slash' : 'fa-user-check' ?>"></i></button>
                        <?php endif; ?>
                      </form>
                      <?php if ((int)$row['id'] !== (int)$_SESSION['user_id']): ?>
                        <form method="post" action="user.php?action=delete" class="d-inline" onsubmit="return confirm('Hapus pengguna ini?')">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Delete"><i class="fa-solid fa-trash"></i></button>
                        </form>
                      <?php else: ?>
                        <button class="btn btn-sm btn-outline-secondary btn-icon" title="Current User"><i class="fa-solid fa-user-check"></i></button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4">
      <div class="modal-header bg-primary text-white border-0">
        <h5 class="modal-title">Tambah User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="user.php?action=create">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <div class="mb-3">
            <label class="form-label">Nama / Username</label>
            <input class="form-control" name="username" placeholder="Username untuk login" required maxlength="50">
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input class="form-control" type="password" name="password" placeholder="Password" required minlength="8" autocomplete="new-password">
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <select class="form-select" name="role" id="newUserRole" required>
                <option value="vendor">Vendor</option>
                <option value="admin">Administrator</option>
                <option value="pimpinan">Pimpinan</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Email</label>
              <input class="form-control" name="email" placeholder="Email">
            </div>
          </div>
          <div class="mt-3" id="vendorSelectGroup">
            <label class="form-label">Vendor</label>
            <select class="form-select" name="vendor_id" id="newUserVendor">
              <option value="">Pilih vendor</option>
              <?php foreach ($vendorOptions as $vendorOption): ?>
                <option value="<?= (int)$vendorOption['id'] ?>"><?= htmlspecialchars($vendorOption['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!$vendorOptions): ?><div class="form-text">Tambahkan data vendor terlebih dahulu.</div><?php endif; ?>
          </div>
          <div class="mt-3">
            <label class="form-label">Nama Lengkap</label>
            <input class="form-control" name="full_name" placeholder="Nama Lengkap">
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4">
      <div class="modal-header bg-primary text-white border-0">
        <h5 class="modal-title">Edit User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="user.php?action=update">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="id" id="editUserId">
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input class="form-control" name="username" id="editUsername" required maxlength="50">
          </div>
          <div class="mb-3">
            <label class="form-label">Password Baru</label>
            <input class="form-control" type="password" name="password" minlength="8" placeholder="Kosongkan jika tidak ingin mengganti">
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Role</label>
              <select class="form-select" name="role" id="editUserRole" required>
                <option value="admin">Admin</option>
                <option value="vendor">Vendor</option>
                <option value="pimpinan">Pimpinan</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Vendor Terdaftar</label>
              <select class="form-select" name="vendor_id" id="editUserVendor">
                <option value="">Pilih vendor</option>
                <?php foreach ($vendorOptions as $vendorOption): ?>
                  <option value="<?= (int)$vendorOption['id'] ?>"><?= htmlspecialchars($vendorOption['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label">Nama Lengkap</label>
            <input class="form-control" name="full_name" id="editFullName">
          </div>
          <div class="mt-3">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" id="editEmail">
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const newUserRole = document.getElementById('newUserRole');
  const vendorSelectGroup = document.getElementById('vendorSelectGroup');
  const newUserVendor = document.getElementById('newUserVendor');

  function updateVendorField() {
    if (!newUserRole || !vendorSelectGroup || !newUserVendor) return;
    const isVendor = newUserRole.value === 'vendor';
    vendorSelectGroup.hidden = !isVendor;
    newUserVendor.required = isVendor;
  }

  if (newUserRole) newUserRole.addEventListener('change', updateVendorField);
  updateVendorField();

  const editUserRole = document.getElementById('editUserRole');
  const editUserVendor = document.getElementById('editUserVendor');
  function updateEditVendorField() {
    if (!editUserRole || !editUserVendor) return;
    editUserVendor.disabled = editUserRole.value !== 'vendor';
    editUserVendor.required = editUserRole.value === 'vendor';
  }
  if (editUserRole) editUserRole.addEventListener('change', updateEditVendorField);

  document.querySelectorAll('.btn-edit-user').forEach(function (button) {
    button.addEventListener('click', function () {
      document.getElementById('editUserId').value = button.dataset.id || '';
      document.getElementById('editUsername').value = button.dataset.username || '';
      document.getElementById('editUserRole').value = button.dataset.role || 'vendor';
      document.getElementById('editUserVendor').value = button.dataset.vendorId || '';
      document.getElementById('editFullName').value = button.dataset.fullName || '';
      document.getElementById('editEmail').value = button.dataset.email || '';
      updateEditVendorField();
    });
  });

  const userSearch = document.getElementById('userSearch');
  const roleFilter = document.getElementById('roleFilter');
  function filterUsers() {
    const query = (userSearch?.value || '').toLowerCase();
    const role = roleFilter?.value || '';
    document.querySelectorAll('[data-user-row]').forEach(function (row) {
      const matchesSearch = row.textContent.toLowerCase().includes(query);
      const matchesRole = !role || row.dataset.role === role;
      row.hidden = !(matchesSearch && matchesRole);
    });
  }
  userSearch?.addEventListener('input', filterUsers);
  roleFilter?.addEventListener('change', filterUsers);
</script>
</body>
</html>
