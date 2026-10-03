<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/db.php';

$role = getCurrentRole();
if (!in_array($role, ['vendor', 'admin', 'pimpinan'], true)) {
    denyAuthorization();
}
$userId = (int)$_SESSION['user_id'];
$vendorId = $role === 'vendor' ? getCurrentVendorId($conn) : null;
$vendorStatus = null;
if ($role === 'vendor' && $vendorId !== null) {
    $statusStmt = $conn->prepare('SELECT verification_status FROM vendors WHERE id = ?');
    $statusStmt->bind_param('i', $vendorId);
    $statusStmt->execute();
    $vendorStatus = $statusStmt->get_result()->fetch_column();
    $statusStmt->close();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$notice = (string)($_SESSION['license_notice'] ?? '');
unset($_SESSION['license_notice']);

function decodeVendorOffer(string $notes): array
{
    $decoded = json_decode($notes, true);
    if (!is_array($decoded) || empty($decoded['__license_offer_v1'])) {
        return [
            'license_period' => '', 'quality' => '', 'features' => '', 'benefit' => '',
            'support' => '', 'warranty' => '', 'implementation' => '', 'maintenance' => '',
            'training' => '', 'ai_copilot' => '', 'additional' => '', 'other' => '',
            'additional_costs' => [],
        ];
    }
    $fields = ['license_period', 'quality', 'features', 'benefit', 'support', 'warranty', 'implementation', 'maintenance', 'training', 'ai_copilot', 'additional', 'other'];
    $offer = ['additional_costs' => is_array($decoded['additional_costs'] ?? null) ? $decoded['additional_costs'] : []];
    foreach ($fields as $field) {
        $offer[$field] = (string)($decoded[$field] ?? '');
    }
    return $offer;
}

function parseCurrencyInput($value): ?float
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    // Accept both 900000000.00 and Indonesian-style 900.000.000,00 input.
    $value = preg_replace('/\s+/', '', $value);
    if (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (float)$value : null;
}

function readOfferInput(array $input): array
{
    $fields = ['license_period', 'quality', 'features', 'benefit', 'support', 'warranty', 'implementation', 'maintenance', 'training', 'ai_copilot', 'additional', 'other'];
    $offer = ['__license_offer_v1' => 1, 'additional_costs' => []];
    foreach ($fields as $field) {
        $offer[$field] = trim((string)($input[$field] ?? ''));
    }
    $names = is_array($input['cost_name'] ?? null) ? $input['cost_name'] : [];
    $types = is_array($input['cost_type'] ?? null) ? $input['cost_type'] : [];
    $amounts = is_array($input['cost_amount'] ?? null) ? $input['cost_amount'] : [];
    $periods = is_array($input['cost_period'] ?? null) ? $input['cost_period'] : [];
    $descriptions = is_array($input['cost_notes'] ?? null) ? $input['cost_notes'] : [];
    foreach ($names as $index => $name) {
        $name = trim((string)$name);
        $amount = trim((string)($amounts[$index] ?? ''));
        $type = trim((string)($types[$index] ?? ''));
        $period = trim((string)($periods[$index] ?? ''));
        $description = trim((string)($descriptions[$index] ?? ''));
        if ($name === '' && $amount === '' && $type === '' && $period === '' && $description === '') {
            continue;
        }
        $amountValue = parseCurrencyInput($amount);
        if ($name === '' || $amountValue === null || $amountValue < 0 || $type === '' || $period === '') {
            throw new InvalidArgumentException('Setiap biaya tambahan harus memiliki nama, jenis, harga valid, dan periode.');
        }
        $offer['additional_costs'][] = [
            'name' => $name,
            'type' => $type,
            'amount' => $amountValue,
            'period' => $period,
            'notes' => $description,
        ];
    }
    return $offer;
}

function offerSummary(array $offer): string
{
    $labels = [
        'quality' => 'Kualitas/Spesifikasi', 'features' => 'Fitur', 'benefit' => 'Benefit',
        'support' => 'Support', 'warranty' => 'Garansi', 'implementation' => 'Implementasi',
        'maintenance' => 'Maintenance', 'training' => 'Training', 'ai_copilot' => 'AI/Copilot',
        'additional' => 'Benefit tambahan', 'other' => 'Catatan',
    ];
    $parts = [];
    foreach ($labels as $key => $label) {
        if (trim((string)($offer[$key] ?? '')) !== '') {
            $parts[] = $label . ': ' . trim((string)$offer[$key]);
        }
    }
    foreach (($offer['additional_costs'] ?? []) as $cost) {
        $parts[] = 'Biaya tambahan ' . (string)($cost['name'] ?? '') . ': Rp ' . number_format((float)($cost['amount'] ?? 0), 0, ',', '.') . ' / ' . (string)($cost['period'] ?? '') . (!empty($cost['notes']) ? ' (' . $cost['notes'] . ')' : '');
    }
    return implode("\n", $parts);
}

$activeBenefitCriteria = [];
$criterionResult = $conn->query("SELECT ec.id, ec.criterion_code, ec.name, rl.score, rl.level_label, rl.guidance
    FROM evaluation_criteria ec
    LEFT JOIN criterion_rubric_levels rl ON rl.criterion_id = ec.id
    WHERE ec.is_active = 1 AND ec.criterion_type = 'benefit'
    ORDER BY ec.criterion_code, rl.score");
while ($criterionResult && ($item = $criterionResult->fetch_assoc())) {
    $code = (string)$item['criterion_code'];
    if (!isset($activeBenefitCriteria[$code])) {
        $activeBenefitCriteria[$code] = ['id' => (int)$item['id'], 'name' => (string)$item['name'], 'rubric' => []];
    }
    if ($item['score'] !== null) {
        $activeBenefitCriteria[$code]['rubric'][] = ['score' => (int)$item['score'], 'label' => (string)$item['level_label'], 'guidance' => (string)$item['guidance']];
    }
}

$action = (string)($_POST['action'] ?? 'list');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['create', 'update', 'delete'], true)) {
    if ($role !== 'vendor' || $vendorId === null) {
        denyAuthorization('Hanya Vendor yang dapat mengelola penawarannya sendiri.');
    }
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Permintaan tidak valid. Silakan muat ulang halaman.');
    }
    $offerId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $offerId = $offerId !== false && $offerId !== null && $offerId > 0 ? $offerId : 0;

    try {
        $conn->begin_transaction();
        $ownerLock = $conn->prepare('SELECT id, verification_status FROM vendors WHERE id = ? FOR UPDATE');
        $ownerLock->bind_param('i', $vendorId);
        $ownerLock->execute();
        $ownerRecord = $ownerLock->get_result()->fetch_assoc();
        $ownerLock->close();
        if (!$ownerRecord) {
            throw new RuntimeException('Akun Anda tidak tertaut pada vendor aktif. Hubungi Admin.');
        }

        if ($action === 'delete') {
            if ($ownerRecord['verification_status'] !== 'pending') {
                throw new RuntimeException('Penawaran hanya dapat dihapus ketika status masih pending.');
            }
            $delete = $conn->prepare("DELETE FROM license_prices WHERE id = ? AND vendor_id = ? AND EXISTS (SELECT 1 FROM vendors WHERE id = ? AND verification_status = 'pending')");
            $delete->bind_param('iii', $offerId, $vendorId, $vendorId);
            $delete->execute();
            if ($delete->affected_rows !== 1) {
                throw new RuntimeException('Penawaran tidak ditemukan pada akun Anda atau tidak dapat dihapus.');
            }
            $delete->close();
            $_SESSION['license_notice'] = 'Penawaran berhasil dihapus.';
        } else {
            $licenseName = trim((string)($_POST['license_name'] ?? ''));
            $priceInput = trim((string)($_POST['price_per_user'] ?? ''));
            $price = parseCurrencyInput($priceInput) ?? 0;
            $userCount = filter_var($_POST['jumlah_user'] ?? null, FILTER_VALIDATE_INT);
            $cycle = (string)($_POST['billing_cycle'] ?? '');
            if ($licenseName === '' || $price <= 0 || $userCount === false || $userCount < 1 || !in_array($cycle, ['monthly', 'annual', 'lifetime'], true)) {
                throw new InvalidArgumentException('Nama produk, harga, jenis/periode lisensi, dan jumlah user wajib diisi dengan nilai valid.');
            }
            if ($cycle === 'lifetime') {
                throw new InvalidArgumentException('Lisensi lifetime belum dapat dinormalisasi ke harga bulanan; pilih periode bulanan atau tahunan untuk mengajukan perbandingan SAW.');
            }
            $offer = readOfferInput($_POST);
            $offerJson = json_encode($offer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($offerJson === false) {
                throw new RuntimeException('Data benefit tidak dapat disimpan.');
            }
            $ratings = is_array($_POST['criterion_scores'] ?? null) ? $_POST['criterion_scores'] : [];
            $ratingValues = [];
            foreach ($activeBenefitCriteria as $code => $criterion) {
                $rating = filter_var($ratings[$code] ?? null, FILTER_VALIDATE_INT);
                $allowed = array_column($criterion['rubric'], 'score');
                if ($rating === false || !in_array($rating, $allowed, true)) {
                    throw new InvalidArgumentException('Pilih nilai 1–5 sesuai rubrik untuk semua kriteria benefit aktif.');
                }
                $ratingValues[(int)$criterion['id']] = (int)$rating;
            }
            $monthlyPrice = $cycle === 'annual' ? $price / 12 : $price;

            if ($action === 'update') {
                $ownedOffer = $conn->prepare('SELECT id FROM license_prices WHERE id = ? AND vendor_id = ? FOR UPDATE');
                $ownedOffer->bind_param('ii', $offerId, $vendorId);
                $ownedOffer->execute();
                $isOwned = (bool)$ownedOffer->get_result()->fetch_assoc();
                $ownedOffer->close();
                if (!$isOwned) {
                    throw new RuntimeException('Penawaran tersebut bukan milik akun Vendor Anda.');
                }
                $save = $conn->prepare('UPDATE license_prices SET license_name = ?, price_per_user = ?, jumlah_user = ?, harga_bulanan = ?, billing_cycle = ?, notes = ? WHERE id = ? AND vendor_id = ?');
                $save->bind_param('sdidssii', $licenseName, $price, $userCount, $monthlyPrice, $cycle, $offerJson, $offerId, $vendorId);
                $save->execute();
                $save->close();
            } else {
                $save = $conn->prepare('INSERT INTO license_prices (vendor_id, license_name, price_per_user, jumlah_user, harga_bulanan, billing_cycle, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $save->bind_param('isdidss', $vendorId, $licenseName, $price, $userCount, $monthlyPrice, $cycle, $offerJson);
                $save->execute();
                $save->close();
            }
            foreach ($ratingValues as $criterionId => $rating) {
                $saveRating = $conn->prepare('INSERT INTO vendor_criteria_scores (vendor_id, criterion_id, raw_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE raw_value = VALUES(raw_value)');
                $saveRating->bind_param('iii', $vendorId, $criterionId, $rating);
                $saveRating->execute();
                $saveRating->close();
            }
            $resubmit = $conn->prepare("UPDATE vendors SET verification_status = 'pending', rejection_reason = NULL, verified_by = NULL, verified_at = NULL WHERE id = ?");
            $resubmit->bind_param('i', $vendorId);
            $resubmit->execute();
            $resubmit->close();
            $_SESSION['license_notice'] = 'Penawaran disimpan dan diajukan kembali ke Admin untuk verifikasi.';
        }
        $conn->commit();
    } catch (Throwable $exception) {
        if ($conn->errno === 0) {
            // Transaction state is still safe to roll back whether the failure was validation or SQL-related.
        }
        try { $conn->rollback(); } catch (Throwable $rollbackException) { error_log($rollbackException->getMessage()); }
        $_SESSION['license_notice'] = $exception->getMessage();
    }
    header('Location: license.php');
    exit;
}

$where = [];
$params = [];
$types = '';
$search = trim((string)($_GET['search_license'] ?? ''));
if ($search !== '') {
    $where[] = '(lp.license_name LIKE ? OR v.name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $types .= 'ss';
}
if ($role === 'vendor') {
    if ($vendorId === null) {
        $where[] = '1 = 0';
    } else {
        $where[] = 'lp.vendor_id = ?';
        $params[] = $vendorId;
        $types .= 'i';
    }
}
$sql = 'SELECT lp.*, v.name AS vendor_name, v.verification_status, v.rejection_reason FROM license_prices lp INNER JOIN vendors v ON v.id = lp.vendor_id';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY v.name, lp.id DESC';
$listStmt = $conn->prepare($sql);
if ($params !== []) {
    $listStmt->bind_param($types, ...$params);
}
$listStmt->execute();
$offerRows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$ratingMap = [];
$ratingSql = "SELECT vcs.vendor_id, ec.criterion_code, vcs.raw_value FROM vendor_criteria_scores vcs INNER JOIN evaluation_criteria ec ON ec.id = vcs.criterion_id WHERE ec.is_active = 1 AND ec.criterion_type = 'benefit'";
if ($role === 'vendor' && $vendorId !== null) {
    $ratingStmt = $conn->prepare($ratingSql . ' AND vcs.vendor_id = ?');
    $ratingStmt->bind_param('i', $vendorId);
    $ratingStmt->execute();
    $ratingResult = $ratingStmt->get_result();
} elseif ($role === 'vendor') {
    $ratingResult = false;
} else {
    $ratingResult = $conn->query($ratingSql);
}
while ($ratingResult && ($rating = $ratingResult->fetch_assoc())) {
    $ratingMap[(int)$rating['vendor_id']][(string)$rating['criterion_code']] = (int)$rating['raw_value'];
}
$canEdit = $role === 'vendor' && $vendorId !== null;
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function money(float $value): string { return 'Rp ' . number_format($value, 0, ',', '.'); }
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Data Penawaran dan Benefit Vendor</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
  <style>
    body{font-family:'Poppins',sans-serif;background:linear-gradient(180deg,#f7faff 0%,#edf3ff 100%);color:#233247}.sidebar{background:linear-gradient(180deg,#091224,#102246);min-height:100vh}.sidebar .nav-link{color:rgba(255,255,255,.9);border-radius:14px;padding:.75rem .95rem;margin-bottom:.35rem}.sidebar .nav-link:hover,.sidebar .nav-link.active{background:#0d6efd;color:#fff}.page-card,.mini-card{background:#fff;border-radius:20px;box-shadow:0 16px 36px rgba(16,24,40,.08)}.page-card{padding:25px}.mini-card{padding:1rem}.table-head{background:linear-gradient(135deg,#0d6efd,#1d4ed8);color:#fff}.table-head th{border:0}.offer-modal .modal-content{border:0;border-radius:20px}.benefit-section{border:1px solid #e5edfb;border-radius:14px;padding:16px;margin-bottom:16px}
    /* The form sits inside modal-content, so Bootstrap's default scroll selector does not
       reach the body. Make the form a flex column: the fields scroll while the save footer stays visible. */
    .offer-modal .modal-dialog{height:calc(100vh - 2rem);margin:1rem auto}.offer-modal .modal-content{height:100%;max-height:none;overflow:hidden}.offer-modal form{display:flex;height:100%;min-height:0;overflow:hidden;flex-direction:column}.offer-modal .modal-body{flex:1 1 0;min-height:0;overflow-y:scroll;-webkit-overflow-scrolling:touch}.offer-modal .modal-footer{flex:0 0 auto;background:#fff;border-top:1px solid #e5edfb;box-shadow:0 -5px 12px rgba(16,24,40,.04)}
  </style>
</head>
<body>
<div class="container-fluid"><div class="row g-0">
  <aside class="col-lg-2 col-md-3 sidebar text-white p-3 p-lg-4"><div class="fw-bold fs-5 mb-4">Vendor Budget</div><ul class="nav flex-column">
    <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i><?= $role === 'vendor' ? 'Dashboard Saya' : 'Dashboard' ?></a></li>
    <li class="nav-item"><a class="nav-link" href="vendor.php"><i class="fa-solid fa-building me-2"></i><?= $role === 'vendor' ? 'Data Vendor Saya' : 'Vendor' ?></a></li>
    <li class="nav-item"><a class="nav-link active" href="license.php"><i class="fa-solid fa-tags me-2"></i>Data Penawaran</a></li>
    <?php if ($role === 'admin'): ?><li class="nav-item"><a class="nav-link" href="user.php"><i class="fa-solid fa-users me-2"></i>Pengguna</a></li><?php endif; ?>
    <li class="nav-item"><a class="nav-link" href="report.php"><i class="fa-solid fa-file-lines me-2"></i><?= $role === 'vendor' ? 'Laporan Saya' : 'Laporan' ?></a></li>
    <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
  </ul></aside>
  <main class="col-lg-10 col-md-9 p-3 p-lg-4"><div class="page-card">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4"><div>
      <div class="small text-primary fw-semibold">Dashboard / Data Penawaran</div><h2 class="fw-bold mb-1">DATA PENAWARAN DAN BENEFIT VENDOR</h2>
      <p class="text-muted mb-0"><?= $canEdit ? 'Masukkan rincian, bukti benefit, biaya tambahan, dan nilai kriteria yang diajukan. Perubahan akan mengajukan penawaran kembali untuk verifikasi.' : 'Data hanya dapat dilihat. Admin memverifikasi tanpa mengubah data penawaran vendor.' ?></p>
      <?php if ($notice !== ''): ?><div class="alert alert-info mt-3 mb-0"><?= h($notice) ?></div><?php endif; ?>
      <?php if ($role === 'vendor' && $vendorId === null): ?><div class="alert alert-warning mt-3 mb-0">Akun belum ditautkan ke vendor yang terdaftar. Hubungi Admin melalui menu Pengguna.</div><?php endif; ?>
      <?php if ($role === 'vendor' && $vendorStatus === 'rejected'): ?><div class="alert alert-danger mt-3 mb-0">Ditolak: <?= h((string)($conn->query('SELECT rejection_reason FROM vendors WHERE id = ' . (int)$vendorId)->fetch_assoc()['rejection_reason'] ?? '')) ?></div><?php endif; ?>
    </div><div><?php if ($canEdit): ?><button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#offerModal"><i class="fa-solid fa-plus me-2"></i>Tambah Penawaran</button><?php endif; ?></div></div>
    <div class="row g-3 mb-4"><div class="col-md-4"><div class="mini-card"><div class="small text-muted">Jumlah Penawaran</div><div class="fs-4 fw-bold"><?= count($offerRows) ?></div></div></div><div class="col-md-4"><div class="mini-card"><div class="small text-muted">Vendor Terverifikasi</div><div class="fs-4 fw-bold"><?= $role === 'vendor' ? ($vendorStatus === 'verified' ? 'Ya' : 'Tidak') : (int)$conn->query("SELECT COUNT(*) FROM vendors WHERE verification_status='verified'")->fetch_row()[0] ?></div></div></div><div class="col-md-4"><div class="mini-card"><div class="small text-muted">Biaya Tambahan</div><div class="fs-6 fw-semibold">Terpisah dari harga lisensi</div></div></div></div>
    <?php if ($role !== 'vendor'): ?><form method="get" class="mb-3"><input class="form-control" name="search_license" value="<?= h($search) ?>" placeholder="Cari vendor atau produk/lisensi"></form><?php endif; ?>
    <div class="table-responsive"><table class="table table-striped table-hover align-middle"><thead class="table-head"><tr><th>Vendor / Produk</th><th>Periode</th><th>Harga Lisensi</th><th>Nilai Kriteria (diajukan)</th><th>Deskripsi Benefit</th><th>Status</th><?php if ($canEdit): ?><th>Aksi</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($offerRows as $row): $offer = decodeVendorOffer((string)$row['notes']); $scores = $ratingMap[(int)$row['vendor_id']] ?? []; $status = (string)$row['verification_status']; ?>
      <tr><td><strong><?= h((string)$row['vendor_name']) ?></strong><div><?= h((string)$row['license_name']) ?></div><small class="text-muted"><?= h($offer['license_period'] ?: ucfirst((string)$row['billing_cycle'])) ?></small></td>
      <td><?= h(ucfirst((string)$row['billing_cycle'])) ?></td><td><?= money((float)$row['price_per_user']) ?><small class="d-block text-muted">per user / <?= $row['billing_cycle'] === 'annual' ? 'tahun' : 'bulan' ?> · <?= (int)$row['jumlah_user'] ?> user</small></td>
      <td><?php foreach ($scores as $code => $score): ?><span class="badge text-bg-light me-1"><?= h($code) ?> <?= (int)$score ?>/5</span><?php endforeach; ?></td>
      <td style="min-width:260px;max-width:430px;white-space:pre-line"><?= h(offerSummary($offer) ?: '-') ?></td>
      <td><span class="badge rounded-pill <?= $status === 'verified' ? 'text-bg-success' : ($status === 'rejected' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= h($status === 'verified' ? 'Terverifikasi' : ($status === 'rejected' ? 'Ditolak' : 'Pending')) ?></span><?php if ($status === 'rejected' && !empty($row['rejection_reason'])): ?><div class="small text-danger mt-1"><?= h((string)$row['rejection_reason']) ?></div><?php endif; ?></td>
      <?php if ($canEdit): ?><td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary" data-edit-offer data-row="<?= h(json_encode(['id' => (int)$row['id'], 'license_name' => $row['license_name'], 'price_per_user' => $row['price_per_user'], 'jumlah_user' => $row['jumlah_user'], 'billing_cycle' => $row['billing_cycle'], 'offer' => $offer, 'scores' => $scores], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)) ?>"><i class="fa-solid fa-pen"></i></button><?php if ($status === 'pending'): ?><form class="d-inline" method="post" onsubmit="return confirm('Hapus penawaran ini?')"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if ($offerRows === []): ?><tr><td colspan="<?= $canEdit ? 7 : 6 ?>" class="text-center text-muted py-4">Belum ada data penawaran.</td></tr><?php endif; ?></tbody></table></div>
  </div></main>
</div></div>

<?php if ($canEdit): ?>
<div class="modal fade offer-modal" id="offerModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><form method="post" id="offerForm" class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title" id="offerTitle">Tambah Data Penawaran</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body">
  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" id="offerAction" value="create"><input type="hidden" name="id" id="offerId">
  <div class="benefit-section"><h6 class="fw-bold">Informasi Produk/Lisensi</h6><div class="row g-3"><div class="col-md-6"><label class="form-label">Nama Produk/Lisensi</label><input class="form-control" name="license_name" id="license_name" required></div><div class="col-md-3"><label class="form-label">Jenis/Periode</label><select class="form-select" name="billing_cycle" id="billing_cycle"><option value="monthly">Bulanan</option><option value="annual">Tahunan</option></select></div><div class="col-md-3"><label class="form-label">Periode/ketentuan lisensi</label><input class="form-control" name="license_period" id="license_period" placeholder="Contoh: 12 bulan"></div><div class="col-md-4"><label class="form-label">Harga per pengguna</label><input class="form-control" type="text" inputmode="decimal" name="price_per_user" id="price_per_user" placeholder="Contoh: 900.000,00" required></div><div class="col-md-4"><label class="form-label">Jumlah pengguna</label><input class="form-control" type="number" min="1" step="1" name="jumlah_user" id="jumlah_user" value="1" required></div></div></div>
  <div class="benefit-section"><h6 class="fw-bold">Deskripsi Penawaran dan Bukti Benefit</h6><p class="small text-muted">Isi deskripsi/bukti, bukan hanya angka. Informasi pada contoh tidak otomatis dianggap fakta; masukkan sesuai penawaran yang Anda berikan.</p><div class="row g-3">
    <div class="col-md-6"><label class="form-label">Kualitas / Spesifikasi</label><textarea class="form-control" rows="3" name="quality" id="quality" placeholder="Spesifikasi, kompatibilitas, performa, keamanan, storage"></textarea></div>
    <div class="col-md-6"><label class="form-label">Fitur</label><textarea class="form-control" rows="3" name="features" id="features" placeholder="Fitur produk/tambahan, upgrade, user, penyimpanan, integrasi, AI, meeting, recording, translation, automation"></textarea></div>
    <div class="col-md-6"><label class="form-label">Benefit</label><textarea class="form-control" rows="3" name="benefit" id="benefit" placeholder="Jelaskan benefit dan bukti/dokumen pendukung"></textarea></div>
    <div class="col-md-6"><label class="form-label">Dukungan Teknis / Support</label><textarea class="form-control" rows="3" name="support" id="support" placeholder="Jenis/jam support, 24/7, SLA, estimasi respon, media, remote, bantuan teknis"></textarea></div>
    <div class="col-md-6"><label class="form-label">Garansi / Jaminan Layanan</label><textarea class="form-control" rows="3" name="warranty" id="warranty" placeholder="Jenis, durasi, cakupan, SLA, ketentuan"></textarea></div>
    <div class="col-md-6"><label class="form-label">Implementasi</label><textarea class="form-control" rows="3" name="implementation" id="implementation" placeholder="Instalasi, konfigurasi, migrasi, estimasi waktu, training, pendampingan"></textarea></div>
    <div class="col-md-6"><label class="form-label">Maintenance</label><textarea class="form-control" rows="3" name="maintenance" id="maintenance" placeholder="Tersedia/tidak, periode, jenis, jadwal, cakupan"></textarea></div>
    <div class="col-md-6"><label class="form-label">Training</label><textarea class="form-control" rows="3" name="training" id="training" placeholder="Tersedia/tidak, durasi, sesi, peserta, online/offline, materi, biaya/gratis"></textarea></div>
    <div class="col-md-6"><label class="form-label">AI / Copilot (jika tersedia)</label><textarea class="form-control" rows="3" name="ai_copilot" id="ai_copilot" placeholder="Fitur, syarat aktivasi/akses, lisensi atau biaya terpisah"></textarea></div>
    <div class="col-md-6"><label class="form-label">Benefit tambahan</label><textarea class="form-control" rows="3" name="additional" id="additional" placeholder="Benefit tambahan yang diberikan vendor"></textarea></div>
    <div class="col-12"><label class="form-label">Catatan / Deskripsi lainnya</label><textarea class="form-control" rows="2" name="other" id="other"></textarea></div>
  </div></div>
  <div class="benefit-section"><div class="d-flex justify-content-between align-items-center"><div><h6 class="fw-bold mb-1">Biaya Tambahan</h6><div class="small text-muted">Dicatat terpisah dan tidak otomatis dijumlahkan ke harga lisensi.</div></div><button class="btn btn-sm btn-outline-primary" type="button" id="addCost"><i class="fa-solid fa-plus me-1"></i>Tambah biaya</button></div><div id="costRows" class="mt-3"></div></div>
  <div class="benefit-section"><h6 class="fw-bold">Nilai Kriteria yang Diajukan Vendor (1–5)</h6><p class="small text-muted">Admin memvalidasi berdasarkan bukti penawaran dan rubrik; Admin tidak mengubah nilai atau data asli Vendor. Harga C1 dihitung otomatis dari harga lisensi.</p><div class="row g-3"><?php foreach ($activeBenefitCriteria as $code => $criterion): ?><div class="col-md-6"><label class="form-label fw-semibold"><?= h($code . ' · ' . $criterion['name']) ?></label><select class="form-select" name="criterion_scores[<?= h($code) ?>]" data-score="<?= h($code) ?>" required><option value="">Pilih nilai</option><?php foreach ($criterion['rubric'] as $level): ?><option value="<?= $level['score'] ?>"><?= $level['score'] ?> — <?= h($level['label']) ?></option><?php endforeach; ?></select><div class="form-text"><?php foreach ($criterion['rubric'] as $level): ?><?= $level['score'] ?>: <?= h($level['guidance']) ?><br><?php endforeach; ?></div></div><?php endforeach; ?></div></div>
</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan dan Ajukan</button></div></form></div></div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($canEdit): ?><script>
(function(){
 const modal=new bootstrap.Modal(document.getElementById('offerModal'));
 const form=document.getElementById('offerForm');
 const rows=document.getElementById('costRows');
 function addCost(cost={}){const row=document.createElement('div');row.className='row g-2 align-items-end mb-2 cost-row';row.innerHTML='<div class="col-md-2"><label class="form-label">Nama biaya</label><input class="form-control" name="cost_name[]"></div><div class="col-md-2"><label class="form-label">Jenis biaya</label><input class="form-control" name="cost_type[]" placeholder="AI, layanan, dll"></div><div class="col-md-2"><label class="form-label">Harga</label><input class="form-control" name="cost_amount[]" type="number" min="0" step="0.01"></div><div class="col-md-2"><label class="form-label">Periode</label><input class="form-control" name="cost_period[]" placeholder="per tahun"></div><div class="col-md-3"><label class="form-label">Keterangan</label><input class="form-control" name="cost_notes[]"></div><div class="col-md-1"><button type="button" class="btn btn-outline-danger remove-cost" aria-label="Hapus biaya">×</button></div>';rows.appendChild(row);['name','type','amount','period','notes'].forEach((key,index)=>{const field=row.querySelectorAll('input')[index];const map={name:'name',type:'type',amount:'amount',period:'period',notes:'notes'};field.value=cost[map[key]]??''});row.querySelector('.remove-cost').addEventListener('click',()=>row.remove())}
 document.getElementById('addCost').addEventListener('click',()=>addCost());
 document.querySelectorAll('[data-edit-offer]').forEach(button=>button.addEventListener('click',()=>{const data=JSON.parse(button.dataset.row);form.reset();rows.innerHTML='';document.getElementById('offerTitle').textContent='Edit Penawaran';document.getElementById('offerAction').value='update';document.getElementById('offerId').value=data.id;['license_name','price_per_user','jumlah_user','billing_cycle'].forEach(key=>document.getElementById(key).value=data[key]??'');['license_period','quality','features','benefit','support','warranty','implementation','maintenance','training','ai_copilot','additional','other'].forEach(key=>document.getElementById(key).value=data.offer[key]??'');(data.offer.additional_costs||[]).forEach(cost=>addCost(cost));document.querySelectorAll('[data-score]').forEach(select=>select.value=data.scores[select.dataset.score]??'');modal.show()}));
 document.querySelector('[data-bs-target="#offerModal"]')?.addEventListener('click',()=>{form.reset();rows.innerHTML='';document.getElementById('offerTitle').textContent='Tambah Data Penawaran';document.getElementById('offerAction').value='create';document.getElementById('offerId').value='';document.getElementById('jumlah_user').value='1';document.querySelectorAll('[data-score]').forEach(select=>select.value='')});
})();
</script><?php endif; ?>
</body></html>
