<?php
require 'includes/auth.php';
requireLogin();
require 'config/db.php';
require_once 'includes/saw.php';

$sessionRole = normalizeRole((string)($_SESSION['role'] ?? ''));
if (!in_array($sessionRole, ['vendor', 'admin', 'pimpinan'], true)) {
  header('Location: login.php');
  exit;
}

$welcomeRole = $sessionRole === 'admin' ? 'Admin' : ($sessionRole === 'pimpinan' ? 'Pimpinan' : ucfirst($sessionRole));
$adminName = $_SESSION['username'] ?? $_SESSION['name'] ?? $welcomeRole;
$userId = (int)($_SESSION['user_id'] ?? 0);
$vendorId = null;
$vendorVerificationStatus = null;

if ($sessionRole === 'vendor') {
  $vendorAccount = $conn->prepare("SELECT v.id, v.verification_status, v.rejection_reason FROM users u LEFT JOIN vendors v ON v.id = u.vendor_id WHERE u.id = ? AND u.role = 'vendor' LIMIT 1");
  $vendorAccount->bind_param('i', $userId);
  $vendorAccount->execute();
  $vendorRecord = $vendorAccount->get_result()->fetch_assoc();
  $vendorAccount->close();
  if ($vendorRecord && $vendorRecord['id'] !== null) {
    $vendorId = (int)$vendorRecord['id'];
    $vendorVerificationStatus = (string)$vendorRecord['verification_status'];
    $vendorRejectionReason = (string)($vendorRecord['rejection_reason'] ?? '');
    $_SESSION['vendor_id'] = $vendorId;
  } else {
    $vendorRejectionReason = '';
    $_SESSION['vendor_id'] = null;
  }
}

$vendorCount = 0;
$verifiedVendorCount = 0;
if ($sessionRole === 'vendor') {
  $vendorCount = $vendorId === null ? 0 : 1;
  $verifiedVendorCount = $vendorVerificationStatus === 'verified' ? 1 : 0;
} else {
  $vendorStats = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(verification_status = 'verified'), 0) AS verified FROM vendors")->fetch_assoc();
  $vendorCount = (int)($vendorStats['total'] ?? 0);
  $verifiedVendorCount = (int)($vendorStats['verified'] ?? 0);
}

$licenseStatsSql = 'SELECT COUNT(*) AS total_license,
  COALESCE(SUM(CASE WHEN LOWER(TRIM(billing_cycle)) IN ("monthly", "bulanan") THEN 1 ELSE 0 END), 0) AS total_monthly_license,
  COALESCE(SUM(CASE WHEN LOWER(TRIM(billing_cycle)) IN ("annual", "tahunan") THEN 1 ELSE 0 END), 0) AS total_annual_license,
  COALESCE(SUM(CASE
    WHEN LOWER(TRIM(billing_cycle)) IN ("annual", "tahunan") THEN price_per_user * COALESCE(jumlah_user, 1)
    WHEN LOWER(TRIM(billing_cycle)) IN ("monthly", "bulanan") THEN price_per_user * COALESCE(jumlah_user, 1) * 12
    ELSE 0
  END), 0) AS total_annual_budget
FROM license_prices';
if ($sessionRole === 'vendor') {
  if ($vendorId === null) {
    $licenseStats = ['total_license' => 0, 'total_monthly_license' => 0, 'total_annual_license' => 0, 'total_annual_budget' => 0];
  } else {
    $licenseStatsStmt = $conn->prepare($licenseStatsSql . ' WHERE vendor_id = ?');
    $licenseStatsStmt->bind_param('i', $vendorId);
    $licenseStatsStmt->execute();
    $licenseStats = $licenseStatsStmt->get_result()->fetch_assoc();
    $licenseStatsStmt->close();
  }
} else {
  $licenseStats = $conn->query($licenseStatsSql)->fetch_assoc();
}

$licenseCount = (int)($licenseStats['total_license'] ?? 0);
$monthlyLicenseCount = (int)($licenseStats['total_monthly_license'] ?? 0);
$annualLicenseCount = (int)($licenseStats['total_annual_license'] ?? 0);
$budgetSummary = (float)($licenseStats['total_annual_budget'] ?? 0);

$recentVendorRows = [];
$recentVendorSql = 'SELECT
    v.name AS name,
    lp.license_name AS license,
    lp.billing_cycle,
    lp.price_per_user,
    lp.jumlah_user,
  COALESCE(lp.harga_bulanan, CASE WHEN lp.billing_cycle = "annual" THEN lp.price_per_user / 12 ELSE lp.price_per_user END) * COALESCE(lp.jumlah_user, 1) AS harga_total_bulan,
    lp.created_at AS date_added
FROM license_prices lp
JOIN vendors v ON v.id = lp.vendor_id
WHERE 1 = 1';
if ($sessionRole === 'vendor') {
  $recentVendorSql .= ' AND lp.vendor_id = ?';
}
$recentVendorSql .= ' ORDER BY lp.created_at DESC, lp.id DESC LIMIT 3';
if ($sessionRole === 'vendor' && $vendorId !== null) {
  $recentVendorStmt = $conn->prepare($recentVendorSql);
  $recentVendorStmt->bind_param('i', $vendorId);
  $recentVendorStmt->execute();
  $recentVendorResult = $recentVendorStmt->get_result();
} elseif ($sessionRole === 'vendor') {
  $recentVendorResult = false;
} else {
  $recentVendorResult = $conn->query($recentVendorSql);
}
if ($recentVendorResult && $recentVendorResult->num_rows > 0) {
    while ($row = $recentVendorResult->fetch_assoc()) {
        $hargaTotalBulan = (float)($row['harga_total_bulan'] ?? 0);
        $jumlahUser = (int)($row['jumlah_user'] ?? 0);
        $hargaUserBulan = $jumlahUser > 0 ? $hargaTotalBulan / $jumlahUser : null;

        $recentVendorRows[] = [
            'name' => $row['name'] ?? 'Vendor',
            'license' => $row['license'] ?? 'Lisensi',
            'harga_user_bulan' => $hargaUserBulan,
            'harga_total_bulan' => $hargaTotalBulan,
            'date_added' => $row['date_added'] ?? '-'
        ];
    }
}

$vendorChartLabels = [];
$vendorChartMonthlyValues = [];
$vendorChartAnnualValues = [];
$vendorChartSql = 'SELECT
    v.name AS vendor_name,
  COALESCE(SUM(CASE
    WHEN LOWER(TRIM(lp.billing_cycle)) IN ("annual", "tahunan")
      THEN lp.price_per_user / 12 * COALESCE(lp.jumlah_user, 1)
    WHEN LOWER(TRIM(lp.billing_cycle)) IN ("monthly", "bulanan")
      THEN lp.price_per_user * COALESCE(lp.jumlah_user, 1)
    ELSE 0
  END), 0) AS monthly_budget,
  COALESCE(SUM(CASE
    WHEN LOWER(TRIM(lp.billing_cycle)) IN ("annual", "tahunan")
      THEN lp.price_per_user * COALESCE(lp.jumlah_user, 1)
    WHEN LOWER(TRIM(lp.billing_cycle)) IN ("monthly", "bulanan")
      THEN lp.price_per_user * 12 * COALESCE(lp.jumlah_user, 1)
    ELSE 0
  END), 0) AS annual_budget
FROM vendors v
LEFT JOIN license_prices lp ON lp.vendor_id = v.id
GROUP BY v.id, v.name
ORDER BY v.name';
if ($sessionRole === 'vendor') {
  if ($vendorId === null) {
    $vendorChartResult = false;
  } else {
    $vendorChartSql = str_replace('GROUP BY v.id, v.name', 'WHERE v.id = ? GROUP BY v.id, v.name', $vendorChartSql);
    $vendorChartStmt = $conn->prepare($vendorChartSql);
    $vendorChartStmt->bind_param('i', $vendorId);
    $vendorChartStmt->execute();
    $vendorChartResult = $vendorChartStmt->get_result();
  }
} else {
    $vendorChartResult = $conn->query($vendorChartSql);
}
if ($vendorChartResult && $vendorChartResult->num_rows > 0) {
    while ($chartRow = $vendorChartResult->fetch_assoc()) {
        $vendorChartLabels[] = $chartRow['vendor_name'] ?? 'Vendor';
    $vendorChartMonthlyValues[] = (float)($chartRow['monthly_budget'] ?? 0);
    $vendorChartAnnualValues[] = (float)($chartRow['annual_budget'] ?? 0);
    }
}

  try {
    $allVendorRanking = getVendorRanking($conn);
  } catch (Throwable $exception) {
    error_log('Dashboard SAW error: ' . $exception->getMessage());
    $allVendorRanking = [];
  }
  $dashboardRanking = $sessionRole === 'vendor'
    ? array_values(array_filter($allVendorRanking, static fn(array $vendor): bool => $vendor['vendor_id'] === $vendorId))
    : $allVendorRanking;
  $topRankedVendor = $sessionRole === 'vendor'
    ? ($dashboardRanking[0] ?? null)
    : ($allVendorRanking[0] ?? null);
  $rankingChartLabels = array_map(static fn(array $vendor): string => $vendor['vendor_name'], $dashboardRanking);
  $rankingChartScores = array_map(static fn(array $vendor): float => (float)$vendor['final_score'], $dashboardRanking);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard - Vendor Budget</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --primary: #0d6efd;
      --navy: #0a1023;
      --soft: #f5f7fb;
      --text: #233148;
      --muted: #7b8599;
      --radius: 18px;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(180deg, #f6f9ff 0%, #eef3fb 100%);
      color: var(--text);
    }

    .dashboard-shell {
      min-height: 100vh;
    }

    .sidebar {
      background: #1E293B;
      box-shadow: 0 18px 38px rgba(15, 23, 42, 0.28);
      border-right: 1px solid rgba(255,255,255,0.08);
      position: sticky;
      top: 0;
      height: 100vh;
      overflow-y: auto;
    }

    .sidebar-logo {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 0.9rem 0.7rem;
      border-radius: 16px;
      background: rgba(255,255,255,0.04);
      border: 1px solid rgba(255,255,255,0.08);
      margin-bottom: 1rem;
    }

    .sidebar-logo .logo-mark {
      width: 64px;
      height: 64px;
      padding: 0.35rem;
      border-radius: 14px;
      background: rgba(255,255,255,0.94);
      display: grid;
      place-items: center;
      box-shadow: 0 10px 22px rgba(13, 110, 253, 0.35);
    }

    .sidebar-logo .logo-mark img,
    .top-navbar .brand-mark img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
    }

    .sidebar-logo .brand {
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.25;
      letter-spacing: 0.2px;
    }

    .sidebar-logo small {
      display: block;
      color: rgba(255,255,255,0.72);
      font-size: 0.72rem;
      margin-top: 2px;
    }

    .sidebar-profile {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 1rem 0 1.25rem;
      padding: 12px 14px;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 18px;
    }

    .sidebar-profile img {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      object-fit: cover;
      box-shadow: 0 6px 14px rgba(13,110,253,0.25);
    }

    .sidebar-profile .name {
      font-size: 0.9rem;
      font-weight: 700;
      margin: 0;
    }

    .sidebar-profile .role {
      font-size: 0.76rem;
      color: rgba(255,255,255,0.72);
      margin: 0;
    }

    .sidebar .nav-link {
      border-radius: 14px;
      padding: 0.82rem 0.95rem;
      margin-bottom: 0.45rem;
      color: rgba(255,255,255,0.86);
      transition: all 0.25s ease;
      display: flex;
      align-items: center;
      gap: 12px;
      font-weight: 500;
    }

    .sidebar .nav-link:hover {
      background: rgba(13, 110, 253, 0.15);
      color: #fff;
      transform: translateX(4px);
      box-shadow: 0 8px 20px rgba(0,0,0,0.18);
    }

    .sidebar .nav-link.active {
      background: #0d6efd;
      color: #fff;
      transform: translateX(4px);
      box-shadow: 0 10px 24px rgba(13, 110, 253, 0.35);
    }

    .header-card {
      border: 0;
      border-radius: 20px;
      background: rgba(255,255,255,0.9);
      backdrop-filter: blur(10px);
      box-shadow: 0 18px 40px rgba(15, 29, 58, 0.08);
    }

    .top-navbar {
      position: sticky;
      top: 0;
      z-index: 1030;
      background: rgba(255,255,255,0.96);
      backdrop-filter: blur(10px);
      border-radius: 18px;
      box-shadow: 0 12px 24px rgba(15, 29, 58, 0.08);
      border: 1px solid rgba(13,110,253,0.08);
      margin-bottom: 1rem;
    }

    .top-navbar .brand-mark {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 240px;
    }

    .top-navbar .brand-mark .logo-icon {
      width: 52px;
      height: 52px;
      display: grid;
      place-items: center;
      padding: 0.3rem;
      border-radius: 12px;
      background: rgba(255,255,255,0.98);
      box-shadow: 0 10px 20px rgba(13,110,253,0.12);
    }

    .top-navbar .brand-text {
      display: flex;
      flex-direction: column;
      line-height: 1.15;
    }

    .top-navbar .brand-text strong {
      font-size: 0.98rem;
    }

    .top-navbar .brand-text span {
      font-size: 0.74rem;
      color: var(--muted);
    }

    .top-navbar .page-breadcrumb {
      font-size: 0.82rem;
      color: var(--muted);
      margin-top: 4px;
    }

    .profile-badge {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 10px 16px;
      border-radius: 18px;
      background: linear-gradient(135deg, #f8fbff, #edf5ff);
      border: 1px solid rgba(13,110,253,0.12);
    }

    .profile-badge img {
      width: 56px;
      height: 56px;
      object-fit: cover;
      border-radius: 50%;
      box-shadow: 0 10px 20px rgba(13,110,253,0.18);
    }

    .stat-card {
      position: relative;
      overflow: hidden;
      border: 0;
      border-radius: 18px;
      box-shadow: 0 16px 35px rgba(16, 24, 40, 0.08);
      min-height: 185px;
      transition: transform 0.25s ease, box-shadow 0.25s ease;
      animation: fadeInUp 0.7s ease both;
    }

    .stat-card:hover {
      transform: translateY(-6px) scale(1.01);
      box-shadow: 0 22px 42px rgba(16, 24, 40, 0.16);
    }

    .stat-card .icon-wrap {
      width: 62px;
      height: 62px;
      display: grid;
      place-items: center;
      border-radius: 18px;
      background: rgba(255,255,255,0.2);
      backdrop-filter: blur(4px);
    }

    .stat-card .stat-number {
      font-size: 1.8rem;
      font-weight: 700;
      line-height: 1.2;
    }

    .gradient-primary { background: linear-gradient(135deg, #0d6efd, #3b82f6); color: #fff; }
    .gradient-success { background: linear-gradient(135deg, #198754, #28a745); color: #fff; }
    .gradient-warning { background: linear-gradient(135deg, #fdc500, #ffb703); color: #111; }
    .gradient-info { background: linear-gradient(135deg, #0dcaf0, #38bdf8); color: #fff; }

    .summary-card,
    .chart-card,
    .table-card,
    .activity-card,
    .quick-card {
      border: 0;
      border-radius: 20px;
      background: rgba(255,255,255,0.96);
      box-shadow: 0 16px 36px rgba(16, 24, 40, 0.08);
    }

    .wrap-icon {
      width: 44px;
      height: 44px;
      border-radius: 14px;
      display: grid;
      place-items: center;
      background: #eaf2ff;
      color: var(--primary);
    }

    .activity-list {
      position: relative;
      padding-left: 0;
      list-style: none;
    }

    .activity-list li {
      position: relative;
      padding-left: 2.1rem;
      margin-bottom: 1rem;
    }

    .activity-list li::before {
      content: '\f058';
      font-family: 'Font Awesome 6 Free';
      font-weight: 900;
      position: absolute;
      left: 0;
      top: 2px;
      color: #198754;
    }

    .action-btn {
      border-radius: 14px;
      padding: 0.8rem 1rem;
      font-weight: 600;
    }

    .table thead th {
      background: #f8fbff;
      color: #466082;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .table tbody tr {
      transition: 0.2s ease;
    }

    .table tbody tr:hover {
      background: #f6faff;
    }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(18px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 991.98px) {
      .sidebar {
        min-height: auto;
      }
    }
  </style>
</head>
<body>
  <div class="container-fluid dashboard-shell">
    <div class="row g-0">
      <aside class="col-12 col-lg-2 sidebar text-white p-3 p-lg-4">
        <div class="sidebar-logo">
          <div class="logo-mark">
            <img src="assets/logo-angkasa.jpeg" alt="Logo PT Angkasa Pura Aviasi">
          </div>
          <div>
            <div class="brand">PT Angkasa Pura Aviasi</div>
            <small>Vendor Budget System</small>
          </div>
        </div>

        <div class="sidebar-profile">
          <img src="https://ui-avatars.com/api/?name=<?= rawurlencode($adminName) ?>&background=0d6efd&color=fff" alt="Avatar pengguna">
          <div>
            <p class="name"><?= htmlspecialchars($adminName) ?></p>
            <p class="role">Role <?= htmlspecialchars($welcomeRole) ?></p>
          </div>
        </div>

        <ul class="nav flex-column">
          <?php if ($sessionRole === 'admin'): ?>
            <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="vendor.php"><i class="fa-solid fa-building"></i><span>Vendor</span></a></li>
            <li class="nav-item"><a class="nav-link" href="license.php"><i class="fa-solid fa-key"></i><span>Harga Lisensi</span></a></li>
            <li class="nav-item"><a class="nav-link" href="user.php"><i class="fa-solid fa-users"></i><span>Pengguna</span></a></li>
            <li class="nav-item"><a class="nav-link" href="report.php"><i class="fa-solid fa-file-lines"></i><span>Laporan</span></a></li>
            <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a></li>
          <?php elseif ($sessionRole === 'pimpinan'): ?>
            <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
            <li class="nav-item"><a class="nav-link" href="report.php"><i class="fa-solid fa-file-lines"></i><span>Laporan</span></a></li>
            <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a></li>
          <?php elseif ($sessionRole === 'vendor'): ?>
            <li class="nav-item"><a class="nav-link active" href="dashboard.php"><i class="fa-solid fa-house"></i><span>Dashboard Saya</span></a></li>
            <li class="nav-item"><a class="nav-link" href="vendor.php"><i class="fa-solid fa-building"></i><span>Data Vendor Saya</span></a></li>
            <li class="nav-item"><a class="nav-link" href="license.php"><i class="fa-solid fa-key"></i><span>Data Penawaran</span></a></li>
            <li class="nav-item"><a class="nav-link" href="report.php"><i class="fa-solid fa-file-lines"></i><span>Laporan Saya</span></a></li>
            <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a></li>
          <?php endif; ?>
        </ul>
      </aside>

      <main class="col-12 col-lg-10 p-3 p-lg-4">
        <nav class="navbar navbar-expand-lg top-navbar p-3">
          <div class="container-fluid px-0">
            <div class="brand-mark me-3">
              <div class="logo-icon">
                <img src="assets/logo-angkasa.jpeg" alt="Logo PT Angkasa Pura Aviasi">
              </div>
              <div class="brand-text">
                <strong>PT Angkasa Pura Aviasi</strong>
                <span>Sistem Perbandingan Vendor IT</span>
                <div class="page-breadcrumb">Dashboard / Overview</div>
              </div>
            </div>

            <div class="ms-auto d-flex flex-column flex-lg-row align-items-lg-center gap-2">
              <div class="d-flex flex-column align-items-lg-end text-center text-lg-end me-lg-2">
                <span class="small text-primary fw-semibold" id="realtimeDate"></span>
                <span class="small text-primary fw-semibold" id="realtimeClock"></span>
              </div>

              <div class="profile-badge">
                <img src="https://ui-avatars.com/api/?name=<?= rawurlencode($adminName) ?>&background=0d6efd&color=fff" alt="Avatar pengguna">
                <div>
                  <div class="fw-bold text-dark"><?= htmlspecialchars($adminName) ?></div>
                  <div class="small text-muted">Role <?= htmlspecialchars($welcomeRole) ?></div>
                </div>
              </div>

              <a href="logout.php" class="btn btn-outline-danger rounded-pill px-3">
                <i class="fa-solid fa-right-from-bracket me-2"></i>Logout
              </a>
            </div>
          </div>
        </nav>

        <div class="header-card p-3 p-lg-4 mb-4">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
              <div class="text-uppercase small text-primary fw-semibold mb-1">Dashboard</div>
              <h2 class="mb-1 fw-bold">Dashboard</h2>
              <div class="mt-3 fw-semibold fs-5">Selamat Datang, <?= htmlspecialchars($welcomeRole) ?> 👋</div>
              <?php if ($sessionRole === 'vendor' && $vendorId === null): ?>
                <div class="alert alert-warning mt-3 mb-0">Akun vendor belum terhubung dengan data vendor. Hubungi admin untuk menyelesaikan tautan akun.</div>
              <?php elseif ($sessionRole === 'vendor'): ?>
                <?php
                  $statusClass = $vendorVerificationStatus === 'verified' ? 'success' : ($vendorVerificationStatus === 'rejected' ? 'danger' : 'warning');
                  $statusLabel = ucfirst((string)$vendorVerificationStatus);
                ?>
                <div class="alert alert-<?= $statusClass ?> mt-3 mb-0">
                  Status penawaran: <strong><?= htmlspecialchars($statusLabel) ?></strong>
                  <?php if ($vendorVerificationStatus === 'rejected' && $vendorRejectionReason !== ''): ?>
                    <div class="mt-1">Alasan penolakan: <?= htmlspecialchars($vendorRejectionReason) ?></div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="row g-4 mb-4">
          <div class="col-12 col-xl-8">
            <div class="table-card p-4 h-100">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold">Ranking Vendor Berdasarkan Metode SAW</h5>
                <span class="badge bg-light text-primary rounded-pill px-3 py-2">SAW</span>
              </div>
              <p class="text-muted small mb-3">Ranking vendor dihitung berdasarkan metode Simple Additive Weighting (SAW) dengan mempertimbangkan harga dan indikator benefit.</p>
              <?php if ($verifiedVendorCount === 0): ?>
                <div class="alert alert-info mb-3">Belum terdapat data vendor terverifikasi yang dapat dihitung.</div>
              <?php elseif ($dashboardRanking === []): ?>
                <div class="alert alert-warning mb-3">Belum ada hasil ranking. Pastikan vendor terverifikasi memiliki nilai untuk seluruh kriteria aktif dan total bobot kriteria adalah 1.00.</div>
              <?php endif; ?>
              <div class="table-responsive">
                <table class="table align-middle table-hover mb-0">
                  <thead>
                    <tr>
                      <th>Ranking</th>
                      <th>Vendor</th>
                      <th>Harga</th>
                      <th>Kualitas</th>
                      <th>Fitur/Benefit</th>
                      <th>Support</th>
                      <th>Garansi</th>
                      <th>Implementasi</th>
                      <th>Skor Akhir</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($dashboardRanking as $rankedVendor): ?>
                      <?php $criterionValues = $rankedVendor['criteria']; ?>
                      <tr>
                        <td><span class="badge bg-primary rounded-pill">#<?= (int)$rankedVendor['ranking'] ?></span></td>
                        <td class="fw-semibold"><?= htmlspecialchars($rankedVendor['vendor_name']) ?></td>
                        <td>Rp <?= isset($criterionValues['C1']) ? number_format((float)$criterionValues['C1']['raw_value'], 0, ',', '.') : '-' ?></td>
                        <td><?= isset($criterionValues['C2']) ? number_format((float)$criterionValues['C2']['raw_value'], 2) : '-' ?></td>
                        <td><?= isset($criterionValues['C3']) ? number_format((float)$criterionValues['C3']['raw_value'], 2) : '-' ?></td>
                        <td><?= isset($criterionValues['C4']) ? number_format((float)$criterionValues['C4']['raw_value'], 2) : '-' ?></td>
                        <td><?= isset($criterionValues['C5']) ? number_format((float)$criterionValues['C5']['raw_value'], 2) : '-' ?></td>
                        <td><?= isset($criterionValues['C6']) ? number_format((float)$criterionValues['C6']['raw_value'], 2) : '-' ?></td>
                        <td class="fw-bold text-primary"><?= number_format((float)$rankedVendor['final_score'], 4) ?></td>
                        <td><span class="badge bg-success rounded-pill">Terverifikasi</span></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if ($dashboardRanking === []): ?>
                      <tr><td colspan="10" class="text-center text-muted py-3">Tidak ada vendor yang memenuhi kriteria untuk ditampilkan.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="col-12 col-xl-4">
            <div class="chart-card p-4 h-100">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold">Skor Akhir Vendor</h5>
                <span class="badge bg-light text-primary rounded-pill px-3 py-2">SAW</span>
              </div>
              <?php if ($dashboardRanking !== []): ?>
                <canvas id="sawScoreChart" height="260"></canvas>
              <?php else: ?>
                <div class="d-flex align-items-center justify-content-center text-muted text-center h-100">Belum ada skor akhir vendor untuk digrafikkan.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="row g-4 mb-4">
          <div class="col-12 col-sm-6 col-xl-4">
            <div class="stat-card gradient-primary p-4 d-flex flex-column justify-content-between">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <div class="small text-white-50"><?= $sessionRole === 'vendor' ? 'Vendor Saya' : 'Jumlah Vendor' ?></div>
                  <div class="stat-number"><?= htmlspecialchars($vendorCount) ?></div>
                </div>
                <div class="icon-wrap"><i class="fa-solid fa-building fa-2x"></i></div>
              </div>
              <div class="small text-white-50"><?= $sessionRole === 'vendor' ? 'Data vendor yang terhubung ke akun' : 'Total vendor terdaftar di sistem' ?></div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-xl-4">
            <div class="stat-card gradient-success p-4 d-flex flex-column justify-content-between">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <div class="small text-white-50">Vendor Terverifikasi</div>
                  <div class="stat-number"><?= htmlspecialchars($verifiedVendorCount) ?></div>
                </div>
                <div class="icon-wrap"><i class="fa-solid fa-circle-check fa-2x"></i></div>
              </div>
              <div class="small text-white-50"><?= $sessionRole === 'vendor' ? 'Status verifikasi penawaran Anda' : 'Data yang dapat dipertimbangkan oleh SAW' ?></div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-xl-4">
            <div class="stat-card gradient-warning p-4 d-flex flex-column justify-content-between">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <div class="small text-dark-50"><?= $sessionRole === 'vendor' ? 'Penawaran / Lisensi Saya' : 'Total Lisensi / Penawaran' ?></div>
                  <div class="stat-number"><?= htmlspecialchars($licenseCount) ?></div>
                </div>
                <div class="icon-wrap"><i class="fa-solid fa-key fa-2x"></i></div>
              </div>
              <div class="small text-dark-50">Data lisensi yang tercatat oleh sistem</div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-xl-4">
            <div class="stat-card gradient-info p-4 d-flex flex-column justify-content-between">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <div class="small text-white-50"><?= $sessionRole === 'vendor' ? 'Anggaran Tahunan Saya' : 'Total Anggaran Tahunan' ?></div>
                  <div class="stat-number">Rp <?= number_format((float) $budgetSummary, 0, ',', '.') ?></div>
                </div>
                <div class="icon-wrap"><i class="fa-solid fa-wallet fa-2x"></i></div>
              </div>
              <div class="small text-white-50"><?= $sessionRole === 'vendor' ? 'Dihitung dari harga dan jumlah pengguna Anda' : 'Dihitung dari harga dan jumlah pengguna vendor' ?></div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-xl-4">
            <div class="stat-card gradient-primary p-4 d-flex flex-column justify-content-between">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <div class="small text-white-50"><?= $sessionRole === 'vendor' ? 'Peringkat Saya (SAW)' : 'Vendor Peringkat 1 (SAW)' ?></div>
                  <div class="stat-number fs-4"><?= htmlspecialchars($topRankedVendor['vendor_name'] ?? '-') ?></div>
                </div>
                <div class="icon-wrap"><i class="fa-solid fa-trophy fa-2x"></i></div>
              </div>
              <div class="small text-white-50"><?= $topRankedVendor ? 'Ranking #' . (int)$topRankedVendor['ranking'] : 'Belum ada hasil ranking yang lengkap' ?></div>
            </div>
          </div>

          <div class="col-12 col-sm-6 col-xl-4">
            <div class="stat-card gradient-success p-4 d-flex flex-column justify-content-between">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <div class="small text-white-50"><?= $sessionRole === 'vendor' ? 'Skor Akhir Saya' : 'Skor Akhir Peringkat 1' ?></div>
                  <div class="stat-number"><?= $topRankedVendor ? number_format((float)$topRankedVendor['final_score'], 4) : '-' ?></div>
                </div>
                <div class="icon-wrap"><i class="fa-solid fa-chart-line fa-2x"></i></div>
              </div>
              <div class="small text-white-50">Jumlah nilai terbobot dari seluruh kriteria aktif</div>
            </div>
          </div>
        </div>

        <div class="row g-4 mb-4">
          <div class="col-12 col-xl-8">
            <div class="chart-card p-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold">Perbandingan Budget Vendor</h5>
                <span class="badge bg-light text-primary rounded-pill px-3 py-2">Chart.js</span>
              </div>
              <canvas id="budgetChart" height="110"></canvas>
            </div>
          </div>
          <div class="col-12 col-xl-4">
            <div class="chart-card p-4 h-100">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold">Distribusi Lisensi Vendor</h5>
                <span class="badge bg-light text-primary rounded-pill px-3 py-2">Pie</span>
              </div>
              <canvas id="licenseChart" height="240"></canvas>
            </div>
          </div>
        </div>

        <div class="row g-4 mb-4">
          <div class="col-12">
            <div class="table-card p-4">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-bold">Vendor Terbaru</h5>
                <span class="text-muted small">3 data terbaru</span>
              </div>
              <div class="table-responsive">
                <table class="table align-middle table-hover rounded overflow-hidden">
                  <thead>
                    <tr>
                      <th>No</th>
                      <th>Nama Vendor</th>
                      <th>Jenis Lisensi</th>
                      <th>Harga User / Bulan</th>
                      <th>Harga Total / Bulan</th>
                      <th>Tanggal Ditambahkan</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recentVendorRows as $index => $vendorRow): ?>
                      <tr>
                        <td><?= htmlspecialchars($index + 1) ?></td>
                        <td><?= htmlspecialchars($vendorRow['name']) ?></td>
                        <td><?= htmlspecialchars($vendorRow['license']) ?></td>
                        <td><?= $vendorRow['harga_user_bulan'] !== null ? 'Rp ' . number_format((float) $vendorRow['harga_user_bulan'], 0, ',', '.') : '-' ?></td>
                        <td>Rp <?= number_format((float) $vendorRow['harga_total_bulan'], 0, ',', '.') ?></td>
                        <td><?= htmlspecialchars($vendorRow['date_added']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const now = new Date();
    const dateEl = document.getElementById('realtimeDate');
    const clockEl = document.getElementById('realtimeClock');

    function updateClock() {
      const current = new Date();
      dateEl.textContent = current.toLocaleDateString('id-ID', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric'
      });
      clockEl.textContent = current.toLocaleTimeString('id-ID');
    }

    updateClock();
    setInterval(updateClock, 1000);

    const budgetChart = new Chart(document.getElementById('budgetChart'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($vendorChartLabels) ?>,
        datasets: [
          {
            label: 'Anggaran Bulanan',
            data: <?= json_encode($vendorChartMonthlyValues) ?>,
            backgroundColor: '#0d6efd',
            borderRadius: 12,
            maxBarThickness: 38
          },
          {
            label: 'Anggaran Tahunan',
            data: <?= json_encode($vendorChartAnnualValues) ?>,
            backgroundColor: '#198754',
            borderRadius: 12,
            maxBarThickness: 38
          }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function(value) {
                return 'Rp ' + value.toLocaleString('id-ID');
              }
            }
          }
        }
      }
    });

    const sawScoreCanvas = document.getElementById('sawScoreChart');
    if (sawScoreCanvas) {
      new Chart(sawScoreCanvas, {
        type: 'bar',
        data: {
          labels: <?= json_encode($rankingChartLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
          datasets: [{
            label: 'Skor Akhir SAW',
            data: <?= json_encode($rankingChartScores) ?>,
            backgroundColor: '#0d6efd',
            borderRadius: 12,
            maxBarThickness: 42
          }]
        },
        options: {
          responsive: true,
          indexAxis: 'y',
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (context) => 'Skor: ' + Number(context.raw).toFixed(4) } }
          },
          scales: {
            x: {
              beginAtZero: true,
              max: 1,
              ticks: { callback: (value) => Number(value).toFixed(2) }
            }
          }
        }
      });
    }

    const licenseChart = new Chart(document.getElementById('licenseChart'), {
      type: 'doughnut',
      data: {
        labels: ['Bulanan', 'Tahunan'],
        datasets: [{
          data: [<?= (int)$monthlyLicenseCount ?>, <?= (int)$annualLicenseCount ?>],
          backgroundColor: ['#0d6efd', '#198754']
        }]
      },
      options: {
        responsive: true,
        cutout: '65%',
        plugins: {
          legend: {
            position: 'bottom'
          }
        }
      }
    });
  </script>
</body>
</html>
