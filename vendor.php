<?php
require 'includes/auth.php';
requireLogin();

$role = normalizeRole((string)($_SESSION['role'] ?? ''));
if (!in_array($role, ['vendor', 'admin', 'pimpinan'], true)) {
  header('Location: dashboard.php');
  exit;
}
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$restrictedActions = ['create', 'edit', 'update', 'delete', 'verify', 'reject'];
if (in_array($action, $restrictedActions, true)) {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action === 'edit') {
    denyAuthorization('Tindakan ini tidak dapat dijalankan melalui URL langsung.');
  }

  $adminForbiddenActions = ['create', 'edit', 'update', 'delete'];
  if (($role === 'admin' && in_array($action, $adminForbiddenActions, true))
      || $role === 'pimpinan'
      || ($role === 'vendor' && in_array($action, ['verify', 'reject'], true))) {
    denyAuthorization('Role Anda tidak memiliki izin untuk tindakan ini.');
  }
}
require 'config/db.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$ownerVendorId = null;
if ($role === 'vendor') {
  $ownerStmt = $conn->prepare('SELECT vendor_id FROM users WHERE id = ? AND role = ? LIMIT 1');
  $vendorRole = 'vendor';
  $ownerStmt->bind_param('is', $userId, $vendorRole);
  $ownerStmt->execute();
  $ownerRecord = $ownerStmt->get_result()->fetch_assoc();
  $ownerStmt->close();
  if ($ownerRecord && (int)$ownerRecord['vendor_id'] > 0) {
    $ownerVendorId = (int)$ownerRecord['vendor_id'];
  }
  $requestedVendorId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
  if ($requestedVendorId !== false && $requestedVendorId !== null
      && ($ownerVendorId === null || $requestedVendorId !== $ownerVendorId)) {
    denyAuthorization('Anda tidak memiliki akses ke data vendor ini.');
  }
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$notice = $_SESSION['vendor_notice'] ?? '';
unset($_SESSION['vendor_notice']);

function normalizeVendorAddress($address): ?string {
    if ($address === null) {
        return null;
    }

    $address = trim((string) $address);
    return $address === '' ? null : $address;
}

function formatVendorAddress($address): string {
    $normalizedAddress = normalizeVendorAddress($address);
    if ($normalizedAddress === null) {
        return 'Alamat belum diisi';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($normalizedAddress) : strlen($normalizedAddress);
    if ($length <= 45) {
        return $normalizedAddress;
    }

    $shortenedAddress = function_exists('mb_substr') ? mb_substr($normalizedAddress, 0, 45) : substr($normalizedAddress, 0, 45);
    return rtrim($shortenedAddress) . '...';
}

$isPostAction = in_array($action, ['create', 'update', 'delete', 'verify', 'reject'], true)
  && $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPostAction) {
  if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Permintaan tidak valid. Silakan muat ulang halaman.');
  }

  $requestedId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
  $vendorId = ($requestedId !== false && $requestedId !== null && $requestedId > 0) ? $requestedId : 0;

  if ($action === 'create' && $role === 'vendor' && $ownerVendorId === null) {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $offerDetails = trim($_POST['offer_details'] ?? '');

    if ($name !== '' && $contact !== '') {
      $conn->begin_transaction();
      try {
        $create = $conn->prepare("INSERT INTO vendors (name, contact, address, category, notes, offer_details, status, verification_status) VALUES (?, ?, ?, ?, ?, ?, 'Aktif', 'pending')");
        $create->bind_param('ssssss', $name, $contact, $address, $category, $notes, $offerDetails);
        $create->execute();
        $newVendorId = (int)$conn->insert_id;
        $link = $conn->prepare("UPDATE users SET vendor_id = ? WHERE id = ? AND role = 'vendor' AND vendor_id IS NULL");
        $link->bind_param('ii', $newVendorId, $userId);
        $link->execute();
        if ($link->affected_rows !== 1) {
          throw new RuntimeException('Akun vendor sudah terhubung dengan data vendor lain.');
        }
        $conn->commit();
        $_SESSION['vendor_notice'] = 'Penawaran berhasil diajukan dengan status pending.';
      } catch (Throwable $exception) {
        $conn->rollback();
        $_SESSION['vendor_notice'] = 'Penawaran tidak dapat dibuat. Silakan periksa data atau hubungi admin.';
      }
    } else {
      $_SESSION['vendor_notice'] = 'Nama dan kontak vendor wajib diisi.';
    }
  } elseif ($action === 'update' && $role === 'vendor' && $ownerVendorId !== null) {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $offerDetails = trim($_POST['offer_details'] ?? '');

    if ($name !== '' && $contact !== '') {
      // Vendor may revise its own submission at any time. A revision always returns it to
      // pending so Admin can verify the latest data rather than an outdated version.
      $update = $conn->prepare("UPDATE vendors SET name = ?, contact = ?, address = ?, category = ?, notes = ?, offer_details = ?, verification_status = 'pending', rejection_reason = NULL, verified_by = NULL, verified_at = NULL WHERE id = ? AND id = (SELECT vendor_id FROM users WHERE id = ? AND role = 'vendor')");
      $update->bind_param('ssssssii', $name, $contact, $address, $category, $notes, $offerDetails, $ownerVendorId, $userId);
      $update->execute();
      $statusCheck = $conn->prepare("SELECT verification_status FROM vendors WHERE id = ? AND id = (SELECT vendor_id FROM users WHERE id = ? AND role = 'vendor')");
      $statusCheck->bind_param('ii', $ownerVendorId, $userId);
      $statusCheck->execute();
      $updatedRecord = $statusCheck->get_result()->fetch_assoc();
      $statusCheck->close();
      $_SESSION['vendor_notice'] = $update->affected_rows >= 0 && $updatedRecord && $updatedRecord['verification_status'] === 'pending'
        ? 'Perubahan disimpan. Penawaran berstatus pending untuk verifikasi ulang.'
        : 'Data tidak dapat disimpan karena penawaran tidak tersedia.';
      $update->close();
    } else {
      $_SESSION['vendor_notice'] = 'Nama dan kontak vendor wajib diisi.';
    }
  } elseif ($action === 'delete' && $role === 'vendor' && $ownerVendorId !== null) {
    $deleteId = $ownerVendorId;
    $conn->begin_transaction();
    try {
      $pendingCheck = $conn->prepare("SELECT id FROM vendors WHERE id = ? AND id = (SELECT vendor_id FROM users WHERE id = ? AND role = 'vendor') AND verification_status = 'pending' FOR UPDATE");
      $pendingCheck->bind_param('ii', $deleteId, $userId);
      $pendingCheck->execute();
      $isPending = (bool)$pendingCheck->get_result()->fetch_assoc();
      $pendingCheck->close();
      if (!$isPending) {
        throw new RuntimeException('Hanya penawaran pending yang dapat dihapus.');
      }

          $dependentTables = ['license', 'license_prices', 'comparison_results', 'vendor_criteria_scores', 'decision_results'];
      foreach ($dependentTables as $tableName) {
        $tableCheck = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $tableCheck->bind_param('s', $tableName);
        $tableCheck->execute();
        $exists = (int)$tableCheck->get_result()->fetch_assoc()['total'] > 0;
        $tableCheck->close();
        if ($exists) {
          $columnCheck = $conn->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = \'vendor_id\'');
          $columnCheck->bind_param('s', $tableName);
          $columnCheck->execute();
          $hasVendorColumn = (int)$columnCheck->get_result()->fetch_assoc()['total'] > 0;
          $columnCheck->close();
          if ($hasVendorColumn) {
            $dependencyCheck = $conn->prepare("SELECT COUNT(*) AS total FROM `$tableName` WHERE vendor_id = ?");
            $dependencyCheck->bind_param('i', $deleteId);
            $dependencyCheck->execute();
            $hasDependencies = (int)$dependencyCheck->get_result()->fetch_assoc()['total'] > 0;
            $dependencyCheck->close();
            if ($hasDependencies) {
              throw new RuntimeException('Data vendor sudah memiliki data perbandingan/penilaian sehingga tidak dapat dihapus.');
            }
          }
        }
      }

      $delete = $conn->prepare("DELETE FROM vendors WHERE id = ? AND id = (SELECT vendor_id FROM users WHERE id = ? AND role = 'vendor') AND verification_status = 'pending'");
      $delete->bind_param('ii', $deleteId, $userId);
      $delete->execute();
      if ($delete->affected_rows !== 1) {
        throw new RuntimeException('Penawaran sudah berubah dan tidak dapat dihapus.');
      }
      $conn->commit();
      $_SESSION['vendor_notice'] = 'Penawaran pending berhasil dihapus. Anda dapat mengajukan data baru.';
    } catch (Throwable $exception) {
      $conn->rollback();
      $_SESSION['vendor_notice'] = $exception->getMessage();
    }
  } elseif ($action === 'verify' && $role === 'admin' && $vendorId > 0) {
    $conn->begin_transaction();
    try {
      $pendingVendor = $conn->prepare("SELECT id FROM vendors WHERE id = ? AND verification_status = 'pending' FOR UPDATE");
      $pendingVendor->bind_param('i', $vendorId);
      $pendingVendor->execute();
      $canReview = (bool)$pendingVendor->get_result()->fetch_assoc();
      $pendingVendor->close();
      if (!$canReview) {
        throw new RuntimeException('Hanya penawaran pending yang dapat diverifikasi.');
      }

      $activeBenefits = (int)$conn->query("SELECT COUNT(*) AS total FROM evaluation_criteria WHERE is_active = 1 AND criterion_type = 'benefit'")->fetch_assoc()['total'];
      $ratedBenefitsStmt = $conn->prepare("SELECT COUNT(DISTINCT vcs.criterion_id) AS total
        FROM vendor_criteria_scores vcs
        INNER JOIN evaluation_criteria ec ON ec.id = vcs.criterion_id
        WHERE vcs.vendor_id = ? AND ec.is_active = 1 AND ec.criterion_type = 'benefit'");
      $ratedBenefitsStmt->bind_param('i', $vendorId);
      $ratedBenefitsStmt->execute();
      $ratedBenefits = (int)$ratedBenefitsStmt->get_result()->fetch_assoc()['total'];
      $ratedBenefitsStmt->close();

      $priceStmt = $conn->prepare("SELECT COUNT(*) AS total FROM license_prices
        WHERE vendor_id = ? AND price_per_user > 0
          AND LOWER(TRIM(billing_cycle)) IN ('monthly', 'annual', 'bulanan', 'tahunan')");
      $priceStmt->bind_param('i', $vendorId);
      $priceStmt->execute();
      $hasComparablePrice = (int)$priceStmt->get_result()->fetch_assoc()['total'] > 0;
      $priceStmt->close();

      $offerEvidenceStmt = $conn->prepare("SELECT notes FROM license_prices
        WHERE vendor_id = ? AND price_per_user > 0
          AND LOWER(TRIM(billing_cycle)) IN ('monthly', 'annual', 'bulanan', 'tahunan')");
      $offerEvidenceStmt->bind_param('i', $vendorId);
      $offerEvidenceStmt->execute();
      $hasOfferEvidence = false;
      $evidenceFields = ['quality', 'features', 'benefit', 'support', 'warranty', 'implementation'];
      $evidenceRows = $offerEvidenceStmt->get_result();
      while ($evidenceRow = $evidenceRows->fetch_assoc()) {
        $offer = json_decode((string)$evidenceRow['notes'], true);
        if (!is_array($offer) || empty($offer['__license_offer_v1'])) {
          continue;
        }
        $completeEvidence = true;
        foreach ($evidenceFields as $field) {
          if (trim((string)($offer[$field] ?? '')) === '') {
            $completeEvidence = false;
            break;
          }
        }
        if ($completeEvidence) {
          $hasOfferEvidence = true;
          break;
        }
      }
      $offerEvidenceStmt->close();

      if (!$hasComparablePrice || $ratedBenefits !== $activeBenefits || !$hasOfferEvidence) {
        throw new RuntimeException('Lengkapi harga lisensi, deskripsi/bukti untuk kualitas, fitur, benefit, support, garansi, implementasi, dan seluruh nilai benefit sebelum verifikasi.');
      }

      $verified = 'verified';
      $verify = $conn->prepare("UPDATE vendors SET verification_status = ?, rejection_reason = NULL, verified_by = ?, verified_at = CURRENT_TIMESTAMP WHERE id = ? AND verification_status = 'pending'");
      $verify->bind_param('sii', $verified, $userId, $vendorId);
      $verify->execute();
      if ($verify->affected_rows !== 1) {
        throw new RuntimeException('Vendor tidak dapat diverifikasi. Muat ulang daftar dan coba lagi.');
      }
      $verify->close();
      $conn->commit();
      $_SESSION['vendor_notice'] = 'Vendor berhasil diverifikasi.';
    } catch (Throwable $exception) {
      $conn->rollback();
      $_SESSION['vendor_notice'] = $exception->getMessage();
    }
  } elseif ($action === 'reject' && $role === 'admin' && $vendorId > 0) {
    $reason = trim($_POST['rejection_reason'] ?? '');
    if ($reason !== '') {
      $rejected = 'rejected';
      $reject = $conn->prepare("UPDATE vendors SET verification_status = ?, rejection_reason = ?, verified_by = ?, verified_at = NULL WHERE id = ? AND verification_status = 'pending'");
      $reject->bind_param('ssii', $rejected, $reason, $userId, $vendorId);
      $reject->execute();
      $_SESSION['vendor_notice'] = $reject->affected_rows === 1 ? 'Penawaran ditolak dan alasan disimpan.' : 'Hanya penawaran pending yang dapat ditolak.';
      $reject->close();
    } else {
      $_SESSION['vendor_notice'] = 'Alasan penolakan wajib diisi.';
    }
  } else {
    denyAuthorization('Anda tidak memiliki izin untuk melakukan tindakan ini.');
  }

  header('Location: vendor.php');
  exit;
}

if ($role === 'vendor') {
  if ($ownerVendorId === null) {
    $vendors = false;
  } else {
    $vendorsStmt = $conn->prepare("SELECT v.*, p.price_summary FROM vendors v LEFT JOIN (SELECT vendor_id, GROUP_CONCAT(CONCAT(license_name, ': ', CAST(price_per_user AS CHAR), ' / ', billing_cycle) SEPARATOR '; ') AS price_summary FROM license_prices GROUP BY vendor_id) p ON p.vendor_id = v.id WHERE v.id = ? AND v.id = (SELECT vendor_id FROM users WHERE id = ? AND role = 'vendor') ORDER BY v.id DESC");
    $vendorsStmt->bind_param('ii', $ownerVendorId, $userId);
    $vendorsStmt->execute();
    $vendors = $vendorsStmt->get_result();
  }
} else {
  $vendors = $conn->query("SELECT v.*, p.price_summary FROM vendors v LEFT JOIN (SELECT vendor_id, GROUP_CONCAT(CONCAT(license_name, ': ', CAST(price_per_user AS CHAR), ' / ', billing_cycle) SEPARATOR '; ') AS price_summary FROM license_prices GROUP BY vendor_id) p ON p.vendor_id = v.id ORDER BY v.id DESC");
}
$vendorSearch = trim((string)($_GET['search'] ?? ''));
$vendorStatusFilter = trim((string)($_GET['status'] ?? ''));
if (!in_array($vendorStatusFilter, ['', 'Aktif', 'Tidak Aktif'], true)) {
  $vendorStatusFilter = '';
}
$vendorTotal = 0;
$activeVendorCount = 0;
$recentVendorCount = 0;
$vendorList = [];
if ($vendors) {
    $vendors->data_seek(0);
    while ($row = $vendors->fetch_assoc()) {
        $row['address'] = normalizeVendorAddress($row['address'] ?? null);
        $row['status'] = isset($row['status']) && trim((string) $row['status']) !== '' ? trim((string) $row['status']) : 'Aktif';
        $row['contact'] = trim((string) ($row['contact'] ?? ''));
        $row['contact'] = $row['contact'] === '' ? '-' : $row['contact'];
        $row['verification_status'] = $row['verification_status'] ?? 'pending';
        $searchableVendor = implode(' ', [$row['name'], $row['contact'], (string)($row['category'] ?? '')]);
        if ($vendorSearch !== '' && stripos($searchableVendor, $vendorSearch) === false) {
            continue;
        }
        if ($vendorStatusFilter !== '' && $row['status'] !== $vendorStatusFilter) {
            continue;
        }
        $vendorList[] = $row;
        $activeVendorCount += ($row['status'] === 'Aktif' ? 1 : 0);
        if (count($vendorList) <= 3) {
            $recentVendorCount++;
        }
    }
}
$vendorTotal = count($vendorList);
if (isset($vendorsStmt)) {
    $vendorsStmt->close();
  }
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vendor Management - Vendor Budget</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    :root {
      --primary: #0d6efd;
      --navy: #0a1023;
      --soft: #f5f7fb;
      --text: #243247;
      --muted: #79839a;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(180deg, #f7faff 0%, #eef4ff 100%);
      color: var(--text);
    }

    .sidebar {
      background: linear-gradient(180deg, #061226, #0d1b3d);
      box-shadow: 0 20px 40px rgba(3, 12, 35, 0.2);
    }

    .sidebar .nav-link {
      border-radius: 14px;
      padding: 0.75rem 0.95rem;
      margin-bottom: 0.35rem;
      color: rgba(255,255,255,0.9);
      transition: all 0.25s ease;
    }

    .sidebar .nav-link:hover,
    .sidebar .nav-link.active {
      background: var(--primary);
      color: #fff;
      transform: translateX(4px);
      box-shadow: 0 12px 24px rgba(13,110,253,0.35);
    }

    .page-card {
      background: rgba(255,255,255,0.96);
      border-radius: 20px;
      box-shadow: 0 16px 38px rgba(17, 24, 39, 0.08);
      padding: 25px;
    }

    .mini-card {
      border: 0;
      border-radius: 18px;
      box-shadow: 0 12px 28px rgba(16, 24, 40, 0.08);
      padding: 1rem;
      background: #fff;
      transition: transform 0.2s ease;
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
      color: #fff;
      background: linear-gradient(135deg, #0d6efd, #3a87ff);
    }

    .table-head {
      background: linear-gradient(135deg, #0d6efd, #1d4ed8);
      color: #fff;
    }

    .table-custom {
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 14px 30px rgba(16, 24, 40, 0.08);
    }

    .table-custom thead th {
      border: none;
      font-size: 0.82rem;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding-top: 0.9rem;
      padding-bottom: 0.9rem;
    }

    .table-custom tbody tr:hover {
      background: #f7fbff;
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
      border: 1px solid #dbe7ff;
      padding: 0.8rem 1rem;
      background: #f9fbff;
    }

    .modal-card {
      border-radius: 20px;
      overflow: hidden;
    }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row g-0">
    <aside class="col-lg-2 col-md-3 sidebar text-white min-vh-100 p-3 p-lg-4">
      <div class="brand mb-4">
        <div class="fw-bold fs-5">Vendor Budget</div>
        <small class="text-white-50">ERP Workspace</small>
      </div>
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i><?= $role === 'vendor' ? 'Dashboard Saya' : 'Dashboard' ?></a></li>
        <li class="nav-item"><a class="nav-link active" href="vendor.php"><i class="fa-solid fa-building me-2"></i><?= $role === 'vendor' ? 'Data Vendor Saya' : 'Vendor' ?></a></li>
        <li class="nav-item"><a class="nav-link" href="license.php"><i class="fa-solid fa-tags me-2"></i><?= $role === 'vendor' ? 'Data Penawaran' : 'Harga Lisensi' ?></a></li>
        <?php if ($role === 'admin'): ?><li class="nav-item"><a class="nav-link" href="user.php"><i class="fa-solid fa-users me-2"></i>Pengguna</a></li><?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="report.php"><i class="fa-solid fa-file-lines me-2"></i><?= $role === 'vendor' ? 'Laporan Saya' : 'Laporan' ?></a></li>
        <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
      </ul>
    </aside>

    <main class="col-lg-10 col-md-9 p-3 p-lg-4">
      <div class="page-card">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
          <div>
            <div class="text-primary small fw-semibold mb-1">Dashboard / Vendor</div>
            <h2 class="fw-bold mb-1"><?= $role === 'vendor' ? 'Penawaran Vendor Saya' : 'Vendor Management' ?></h2>
            <p class="text-muted mb-0"><?= $role === 'admin' ? 'Periksa penawaran dan lakukan verifikasi tanpa mengubah data penawaran.' : ($role === 'pimpinan' ? 'Lihat data vendor dan status penawarannya.' : 'Kelola data dan penawaran milik akun vendor Anda.') ?></p>
            <?php if ($notice !== ''): ?><div class="alert alert-info rounded-3 mt-3 mb-0 py-2"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
            <?php if ($role === 'vendor' && $ownerVendorId === null): ?><div class="alert alert-warning rounded-3 mt-3 mb-0 py-2">Akun Anda belum ditautkan ke vendor. Hubungi Admin untuk memilih vendor yang sudah terdaftar.</div><?php endif; ?>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary rounded-pill px-3" href="vendor.php"><i class="fa-solid fa-arrows-rotate me-2"></i>Refresh Data</a>
            <?php if ($role === 'admin' || $role === 'pimpinan'): ?><button class="btn btn-outline-info rounded-pill px-3" type="button"><i class="fa-solid fa-file-excel me-2"></i>Export Excel</button><?php endif; ?>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Total Vendor</div>
                <div class="fw-bold fs-4"><?= $vendorTotal ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-building"></i></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Vendor Aktif</div>
                <div class="fw-bold fs-4"><?= $activeVendorCount ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-circle-check"></i></div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="text-muted small">Vendor Baru</div>
                <div class="fw-bold fs-4"><?= $recentVendorCount ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-user-plus"></i></div>
            </div>
          </div>
        </div>

        <form method="get" class="row g-2 mb-4 align-items-center">
          <div class="col-md-5">
            <div class="input-group search-box">
              <span class="input-group-text bg-transparent border-0"><i class="fa-solid fa-magnifying-glass text-primary"></i></span>
              <input type="search" name="search" value="<?= htmlspecialchars($vendorSearch, ENT_QUOTES) ?>" class="form-control border-0 bg-transparent" placeholder="Cari nama, kontak, atau kategori vendor">
            </div>
          </div>
          <div class="col-md-3">
            <select name="status" class="form-select search-box">
              <option value="">Semua status</option>
              <option value="Aktif" <?= $vendorStatusFilter === 'Aktif' ? 'selected' : '' ?>>Aktif</option>
              <option value="Tidak Aktif" <?= $vendorStatusFilter === 'Tidak Aktif' ? 'selected' : '' ?>>Tidak Aktif</option>
            </select>
          </div>
          <div class="col-md-4 text-md-end">
            <button type="submit" class="btn btn-outline-primary rounded-pill px-3"><i class="fa-solid fa-magnifying-glass me-1"></i>Cari</button>
            <?php if ($vendorSearch !== '' || $vendorStatusFilter !== ''): ?><a href="vendor.php" class="btn btn-outline-secondary rounded-pill px-3 ms-1">Reset</a><?php endif; ?>
          </div>
        </form>

        <div class="table-responsive table-custom">
          <table class="table table-striped table-hover align-middle mb-0">
            <thead class="table-head">
              <tr>
                <th>No</th>
                <th>Nama Vendor</th>
                <th>Penawaran/Benefit</th>
                <th>Alamat</th>
                <th>Kontak</th>
                <th>Verifikasi</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($vendorList): ?>
                <?php foreach ($vendorList as $index => $row): ?>
                  <tr>
                    <td><?= $index + 1 ?></td>
                    <td><div class="fw-semibold"><?= htmlspecialchars($row['name']) ?></div></td>
                    <td><div class="text-truncate" style="max-width: 240px" title="<?= htmlspecialchars((string)($row['offer_details'] ?? ''), ENT_QUOTES) ?>"><?= htmlspecialchars(trim((string)($row['offer_details'] ?? '')) ?: '-') ?></div>
                      <?php if (!empty($row['price_summary'])): ?><div class="small text-muted mt-1">Harga: <?= htmlspecialchars($row['price_summary']) ?></div><?php endif; ?>
                      <?php if (($row['verification_status'] ?? '') === 'rejected' && !empty($row['rejection_reason'])): ?><div class="small text-danger mt-1">Alasan: <?= htmlspecialchars($row['rejection_reason']) ?></div><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(formatVendorAddress($row['address'])) ?></td>
                    <td><?= htmlspecialchars($row['contact']) ?></td>
                    <td>
                      <?php
                        $verificationStatus = (string)$row['verification_status'];
                        $verificationClass = $verificationStatus === 'verified' ? 'bg-success' : ($verificationStatus === 'rejected' ? 'bg-danger' : 'bg-warning text-dark');
                        $verificationLabel = $verificationStatus === 'verified' ? 'Terverifikasi' : ($verificationStatus === 'rejected' ? 'Ditolak' : 'Pending');
                      ?>
                      <span class="badge <?= $verificationClass ?> rounded-pill px-3 py-2"><?= htmlspecialchars($verificationLabel) ?></span>
                    </td>
                    <td>
                      <?php
                        $status = strtolower(trim((string) ($row['status'] ?? 'Aktif')));
                        $badgeClass = $status === 'tidak aktif' ? 'bg-danger' : 'bg-success';
                        $statusLabel = $status === 'tidak aktif' ? 'Tidak Aktif' : 'Aktif';
                      ?>
                      <span class="badge <?= $badgeClass ?> rounded-pill px-3 py-2"><?= $statusLabel ?></span>
                    </td>
                    <td>
                      <div class="d-flex gap-2">
                        <?php if ($role === 'vendor'): ?>
                          <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 btn-edit-vendor" data-bs-toggle="modal" data-bs-target="#editVendorModal" data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>" data-contact="<?= htmlspecialchars($row['contact'], ENT_QUOTES) ?>" data-address="<?= htmlspecialchars((string)($row['address'] ?? ''), ENT_QUOTES) ?>" data-category="<?= htmlspecialchars((string)($row['category'] ?? ''), ENT_QUOTES) ?>" data-notes="<?= htmlspecialchars((string)($row['notes'] ?? ''), ENT_QUOTES) ?>" data-offer="<?= htmlspecialchars((string)($row['offer_details'] ?? ''), ENT_QUOTES) ?>"><i class="fa-solid fa-pen me-1"></i>Edit Data Vendor</button>
                          <a class="btn btn-sm btn-outline-secondary rounded-pill px-3" href="license.php"><i class="fa-solid fa-tags me-1"></i>Isi/Edit Penawaran</a>
                        <?php endif; ?>
                        <?php if (in_array($role, ['admin', 'pimpinan'], true)): ?>
                          <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 btn-view-vendor" data-bs-toggle="modal" data-bs-target="#viewVendorModal" data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>" data-contact="<?= htmlspecialchars($row['contact'], ENT_QUOTES) ?>" data-address="<?= htmlspecialchars((string)($row['address'] ?? ''), ENT_QUOTES) ?>" data-category="<?= htmlspecialchars((string)($row['category'] ?? ''), ENT_QUOTES) ?>" data-offer="<?= htmlspecialchars((string)($row['offer_details'] ?? ''), ENT_QUOTES) ?>" data-prices="<?= htmlspecialchars((string)($row['price_summary'] ?? ''), ENT_QUOTES) ?>" data-status="<?= htmlspecialchars($verificationLabel, ENT_QUOTES) ?>" data-reason="<?= htmlspecialchars((string)($row['rejection_reason'] ?? ''), ENT_QUOTES) ?>">Lihat</button>
                        <?php endif; ?>
                        <?php if ($role === 'admin' && $verificationStatus === 'pending'): ?>
                          <form method="post" action="vendor.php?action=verify" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-success rounded-pill px-3"><i class="fa-solid fa-check me-1"></i>Verifikasi</button>
                          </form>
                          <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 btn-reject-vendor" data-bs-toggle="modal" data-bs-target="#rejectVendorModal" data-id="<?= (int)$row['id'] ?>" data-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>">Tolak</button>
                        <?php endif; ?>
                        <?php if ($role === 'admin' && $verificationStatus === 'verified'): ?>
                          <span class="text-success small"><i class="fa-solid fa-circle-check me-1"></i>Terverifikasi</span>
                        <?php elseif ($role === 'vendor' && $verificationStatus === 'verified'): ?>
                          <span class="text-muted small"><i class="fa-solid fa-lock me-1"></i>Terkunci</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="8" class="text-center py-4 text-muted">Belum ada data vendor.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </main>
  </div>
</div>

<?php if (false): ?>
<div class="modal fade" id="addVendorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-card">
      <div class="modal-header border-0 bg-primary text-white">
        <h5 class="modal-title">Tambah Vendor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="vendor.php?action=create">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <div class="mb-3">
            <label class="form-label">Nama Vendor</label>
            <input class="form-control" name="name" placeholder="Nama Vendor" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Kontak / PIC</label>
            <input class="form-control" name="contact" placeholder="Kontak / PIC" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Alamat Vendor</label>
            <textarea class="form-control" name="address" rows="3" placeholder="Alamat Vendor"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Kategori</label>
            <input class="form-control" name="category" placeholder="Kategori vendor">
          </div>
          <div class="mb-3">
            <label class="form-label">Informasi Penawaran / Benefit</label>
            <textarea class="form-control" name="offer_details" rows="4" placeholder="Jelaskan spesifikasi, benefit, support, garansi, dan implementasi"></textarea>
          </div>
          <input type="hidden" name="notes" value="">
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="editVendorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-card">
      <div class="modal-header border-0 bg-primary text-white">
        <h5 class="modal-title">Edit Vendor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="vendor.php?action=update">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <div class="mb-3">
            <label class="form-label">Nama Vendor</label>
            <input class="form-control" name="name" id="editVendorName" placeholder="Nama Vendor" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Kontak / PIC</label>
            <input class="form-control" name="contact" id="editVendorContact" placeholder="Kontak / PIC" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Alamat Vendor</label>
            <textarea class="form-control" name="address" id="editVendorAddress" rows="3" placeholder="Alamat Vendor"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Kategori</label>
            <input class="form-control" name="category" id="editVendorCategory" placeholder="Kategori vendor">
          </div>
          <div class="mb-3">
            <label class="form-label">Informasi Penawaran / Benefit</label>
            <textarea class="form-control" name="offer_details" id="editVendorOffer" rows="4" placeholder="Spesifikasi, benefit, support, garansi, dan implementasi"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Catatan</label>
            <textarea class="form-control" name="notes" id="editVendorNotes" rows="2" placeholder="Catatan tambahan"></textarea>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if (in_array($role, ['admin', 'pimpinan'], true)): ?>
<div class="modal fade" id="viewVendorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content modal-card">
      <div class="modal-header border-0 bg-primary text-white">
        <h5 class="modal-title">Detail Vendor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Nama Vendor</dt><dd class="col-sm-8" id="viewVendorName">-</dd>
          <dt class="col-sm-4">Kontak</dt><dd class="col-sm-8" id="viewVendorContact">-</dd>
          <dt class="col-sm-4">Alamat</dt><dd class="col-sm-8" id="viewVendorAddress">-</dd>
          <dt class="col-sm-4">Kategori</dt><dd class="col-sm-8" id="viewVendorCategory">-</dd>
          <dt class="col-sm-4">Informasi Penawaran / Benefit</dt><dd class="col-sm-8" id="viewVendorOffer">-</dd>
          <dt class="col-sm-4">Harga Lisensi</dt><dd class="col-sm-8" id="viewVendorPrices">-</dd>
          <dt class="col-sm-4">Status Verifikasi</dt><dd class="col-sm-8" id="viewVendorVerification">-</dd>
          <dt class="col-sm-4">Alasan Penolakan</dt><dd class="col-sm-8" id="viewVendorReason">-</dd>
        </dl>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
<div class="modal fade" id="rejectVendorModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-card">
      <div class="modal-header border-0 bg-danger text-white">
        <h5 class="modal-title">Tolak Penawaran Vendor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="vendor.php?action=reject">
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
          <input type="hidden" name="id" id="rejectVendorId">
          <p class="mb-3">Penawaran: <strong id="rejectVendorName"></strong></p>
          <label class="form-label" for="rejectionReason">Alasan penolakan</label>
          <textarea class="form-control" name="rejection_reason" id="rejectionReason" rows="4" required></textarea>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger">Tolak Penawaran</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    const editButtons = document.querySelectorAll('.btn-edit-vendor');
    const editVendorName = document.getElementById('editVendorName');
    const editVendorContact = document.getElementById('editVendorContact');
    const editVendorAddress = document.getElementById('editVendorAddress');
    const editVendorCategory = document.getElementById('editVendorCategory');
    const editVendorNotes = document.getElementById('editVendorNotes');
    const editVendorOffer = document.getElementById('editVendorOffer');

    editButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        editVendorName.value = button.dataset.name || '';
        editVendorContact.value = button.dataset.contact || '';
        editVendorAddress.value = button.dataset.address || '';
        editVendorCategory.value = button.dataset.category || '';
        editVendorNotes.value = button.dataset.notes || '';
        editVendorOffer.value = button.dataset.offer || '';
      });
    });

    const rejectButtons = document.querySelectorAll('.btn-reject-vendor');
    const rejectVendorId = document.getElementById('rejectVendorId');
    const rejectVendorName = document.getElementById('rejectVendorName');
    rejectButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        rejectVendorId.value = button.dataset.id || '';
        rejectVendorName.textContent = button.dataset.name || '';
      });
    });

    const viewButtons = document.querySelectorAll('.btn-view-vendor');
    const viewFields = {
      name: document.getElementById('viewVendorName'),
      contact: document.getElementById('viewVendorContact'),
      address: document.getElementById('viewVendorAddress'),
      category: document.getElementById('viewVendorCategory'),
      offer: document.getElementById('viewVendorOffer'),
      prices: document.getElementById('viewVendorPrices'),
      status: document.getElementById('viewVendorVerification'),
      reason: document.getElementById('viewVendorReason')
    };
    viewButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        Object.keys(viewFields).forEach(function (key) {
          const field = viewFields[key];
          if (field) {
            field.textContent = button.dataset[key] || '-';
          }
        });
      });
    });
  });
</script>
</body>
</html>
