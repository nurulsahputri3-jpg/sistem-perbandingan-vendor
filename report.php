<?php
require 'includes/auth.php';
requireLogin();

$sessionRole = normalizeRole((string)($_SESSION['role'] ?? ''));
if (!in_array($sessionRole, ['vendor', 'admin', 'pimpinan'], true)) {
  header('Location: dashboard.php');
  exit;
}

require 'config/db.php';
require_once 'includes/saw.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$ownerVendorId = null;
$ownerVendorName = '';
$ownerVerificationStatus = null;
if ($sessionRole === 'vendor') {
  $ownerStmt = $conn->prepare("SELECT v.id, v.name, v.verification_status FROM users u LEFT JOIN vendors v ON v.id = u.vendor_id WHERE u.id = ? AND u.role = 'vendor' LIMIT 1");
  $ownerStmt->bind_param('i', $userId);
  $ownerStmt->execute();
  $owner = $ownerStmt->get_result()->fetch_assoc();
  $ownerStmt->close();
  if ($owner && $owner['id'] !== null) {
    $ownerVendorId = (int)$owner['id'];
    $ownerVendorName = (string)$owner['name'];
    $ownerVerificationStatus = (string)$owner['verification_status'];
    $_SESSION['vendor_id'] = $ownerVendorId;
  } else {
    $_SESSION['vendor_id'] = null;
  }
}

$selectedVendor = $sessionRole === 'vendor' ? $ownerVendorName : getReportFilterValue('vendor_filter');
$selectedLicense = getReportFilterValue('license_filter');

$licenseQuery = '
  SELECT filtered.id, filtered.vendor_id, filtered.vendor_name,
       filtered.license_name, filtered.jenis_lisensi,
     filtered.price_per_user, filtered.jumlah_user, filtered.harga_bulanan,
     filtered.verification_status, filtered.offer_details, filtered.notes
  FROM (
    SELECT lp.id, v.id AS vendor_id, v.name AS vendor_name,
         lp.license_name,
         v.verification_status,
         v.offer_details,
         lp.notes,
         CASE
           WHEN LOWER(TRIM(lp.billing_cycle)) IN ("annual", "tahunan") THEN "Tahunan"
             WHEN LOWER(TRIM(lp.billing_cycle)) IN ("monthly", "bulanan") THEN "Bulanan"
             ELSE NULL
         END AS jenis_lisensi,
         lp.price_per_user,
         COALESCE(lp.jumlah_user, 0) AS jumlah_user,
         COALESCE(lp.harga_bulanan,
           CASE WHEN LOWER(TRIM(lp.billing_cycle)) IN ("annual", "tahunan")
            THEN lp.price_per_user / 12
            ELSE lp.price_per_user
           END
         ) AS harga_bulanan
    FROM license_prices lp
    JOIN vendors v ON v.id = lp.vendor_id
  ) AS filtered
  WHERE filtered.jenis_lisensi IS NOT NULL AND filtered.verification_status = "verified"';
$licenseParams = [];
$licenseParamTypes = '';

if ($selectedVendor !== '' && $selectedVendor !== 'Filter Vendor') {
  $licenseQuery .= ' AND filtered.vendor_name = ?';
  $licenseParams[] = $selectedVendor;
  $licenseParamTypes .= 's';
}

if ($sessionRole === 'vendor') {
  if ($ownerVendorId === null) {
    $licenseQuery .= ' AND 1 = 0';
  } else {
    $licenseQuery .= ' AND filtered.vendor_id = ?';
    $licenseParams[] = $ownerVendorId;
    $licenseParamTypes .= 'i';
  }
}


$licenseQuery .= ' ORDER BY filtered.vendor_name, filtered.id DESC';
$licenseStatement = $conn->prepare($licenseQuery);
if ($licenseStatement && $licenseParams !== []) {
  $licenseStatement->bind_param($licenseParamTypes, ...$licenseParams);
}
$licenses = $licenseStatement ? $licenseStatement->execute() ? $licenseStatement->get_result() : false : false;

$rows = [];
if ($licenses) {
    while ($row = $licenses->fetch_assoc()) {
        $licenseType = (string)($row['jenis_lisensi'] ?? 'Bulanan');
        $jumlahUser = (int)($row['jumlah_user'] ?? 0);
        $hargaPaket = (float)($row['price_per_user'] ?? 0);
        $hargaPaketPerBulan = $licenseType === 'Tahunan' ? ($hargaPaket / 12) : $hargaPaket;
        $hargaPaketPerTahun = $licenseType === 'Tahunan' ? $hargaPaket : ($hargaPaket * 12);
        $anggaranTahunan = ($licenseType === 'Tahunan' ? $hargaPaket : ($hargaPaket * 12)) * $jumlahUser;
        $hargaPaketMode = $selectedLicense === 'Tahunan' ? $hargaPaketPerTahun : $hargaPaketPerBulan;
        $biayaPerUserPerBulan = null;
        $biayaPerUserPerTahun = null;

        if ($jumlahUser > 0) {
            $biayaPerUserPerBulan = $hargaPaketPerBulan;
          $biayaPerUserPerTahun = $hargaPaketPerTahun;
        }

        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'vendor_id' => (int)($row['vendor_id'] ?? 0),
            'name' => (string)($row['vendor_name'] ?? '-'),
            'license_name' => (string)($row['license_name'] ?? '-'),
            'jenis_lisensi' => $licenseType,
            'price_per_user' => $hargaPaket,
            'jumlah_user' => $jumlahUser,
            'harga_bulanan' => $hargaPaketPerBulan,
            'harga_tahunan' => $hargaPaketPerTahun,
            'harga_paket_mode' => $hargaPaketMode,
            'biaya_per_user_per_bulan' => $biayaPerUserPerBulan,
            'biaya_per_user_per_tahun' => $biayaPerUserPerTahun,
            'anggaran_tahunan' => $anggaranTahunan,
            'verification_status' => (string)($row['verification_status'] ?? 'pending'),
            'offer_details' => (string)($row['offer_details'] ?? ''),
            'notes' => (string)($row['notes'] ?? ''),
        ];
    }
}

function formatRupiah(float $value): string
{
    return 'Rp ' . number_format($value, 0, ',', '.');
}

function getReportFilterValue(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function applyReportFilters(array $rows, string $selectedVendor, string $selectedLicense): array
{
    $filtered = $rows;

    if ($selectedVendor !== '' && $selectedVendor !== 'Filter Vendor') {
        $filtered = array_values(array_filter($filtered, function ($row) use ($selectedVendor) {
            return strtolower((string)($row['name'] ?? '')) === strtolower($selectedVendor);
        }));
    }

    if ($selectedLicense !== '' && in_array($selectedLicense, ['Bulanan', 'Tahunan'], true)) {
      $filtered = array_values(array_filter($filtered, function ($row) use ($selectedLicense) {
        return (string)($row['jenis_lisensi'] ?? '') === $selectedLicense;
      }));
    }

    return $filtered;
}

  function formatOfferSummary(string $notes, string $vendorOffer): string
  {
    $details = [];
    $decoded = json_decode($notes, true);
    if (is_array($decoded) && !empty($decoded['__license_offer_v1'])) {
      $labels = [
        'license_period' => 'Periode lisensi',
        'quality' => 'Kualitas/Spesifikasi',
        'benefit' => 'Benefit',
        'features' => 'Fitur',
        'support' => 'Support',
        'warranty' => 'Garansi',
        'implementation' => 'Implementasi',
        'maintenance' => 'Maintenance',
        'training' => 'Training',
        'ai_copilot' => 'AI/Copilot',
        'additional' => 'Benefit tambahan',
        'other' => 'Catatan lainnya',
      ];
      foreach ($labels as $key => $label) {
        $value = trim((string)($decoded[$key] ?? ''));
        if ($value !== '') {
          $details[] = $label . ': ' . $value;
        }
      }
      foreach (($decoded['additional_costs'] ?? []) as $cost) {
        if (!is_array($cost)) {
          continue;
        }
        $details[] = 'BIAYA TAMBAHAN (tidak dijumlahkan ke harga lisensi): '
          . trim((string)($cost['name'] ?? 'Biaya')) . ' — '
          . trim((string)($cost['type'] ?? '')) . ' — Rp '
          . number_format((float)($cost['amount'] ?? 0), 0, ',', '.') . ' / '
          . trim((string)($cost['period'] ?? ''))
          . (trim((string)($cost['notes'] ?? '')) !== '' ? ' — ' . trim((string)$cost['notes']) : '');
      }
    } elseif (trim($notes) !== '') {
      $details[] = trim($notes);
    }

    if (trim($vendorOffer) !== '') {
      $details[] = trim($vendorOffer);
    }

    return implode("\n", $details);
  }

function renderPrintTable(array $rows): string
{
    if ($rows === []) {
  return '<p>Data lisensi tidak ditemukan.</p>';
    }

    $html = '<table style="width:100%; border-collapse:collapse; font-family:Arial, sans-serif; font-size:11pt; border:1px solid #000;">';
    $html .= '<thead><tr style="background:#e5e7eb; font-weight:bold; text-align:center;">';
    $html .= '<th style="border:1px solid #000; padding:6px;">No</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Vendor</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Nama Lisensi</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Jenis</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Harga</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Harga Bulanan</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Jumlah User</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Biaya per User per Bulan</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Status Verifikasi</th>';
    $html .= '<th style="border:1px solid #000; padding:6px;">Informasi Penawaran</th>';
    $html .= '</tr></thead><tbody>';

    $no = 1;
    foreach ($rows as $row) {
        $typeLabel = (string)($row['jenis_lisensi'] ?? 'Bulanan');

        $userDisplay = ($row['jumlah_user'] ?? 0) > 0
            ? htmlspecialchars((string)($row['jumlah_user'] ?? 0))
            : 'Belum ada kebutuhan user';

        $perUserDisplay = $row['biaya_per_user_per_bulan'] !== null
            ? formatRupiah((float)$row['biaya_per_user_per_bulan'])
            : 'Belum ada kebutuhan user';
        $offerSummary = formatOfferSummary((string)($row['notes'] ?? ''), (string)($row['offer_details'] ?? ''));

        $html .= '<tr>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . $no++ . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:left;">' . htmlspecialchars($row['name'] ?? '-') . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:left;">' . htmlspecialchars($row['license_name'] ?? '-') . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:left;">' . htmlspecialchars($typeLabel) . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . formatRupiah((float)($row['price_per_user'] ?? 0)) . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . formatRupiah((float)($row['harga_bulanan'] ?? 0)) . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . $userDisplay . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . $perUserDisplay . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . htmlspecialchars(ucfirst((string)($row['verification_status'] ?? 'pending'))) . '</td>';
        $html .= '<td style="border:1px solid #000; padding:6px; text-align:left;">' . nl2br(htmlspecialchars($offerSummary)) . '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

function renderDecisionReportMarkup(array $ranking, array $criteriaDefinitions): string
{
  $html = '<h2>Hasil Perhitungan dan Ranking SAW</h2>';
  $html .= '<p>Berdasarkan hasil perhitungan metode Simple Additive Weighting (SAW), vendor dengan skor akhir tertinggi memperoleh peringkat pertama.</p>';

  $html .= '<h3>Hasil Keputusan</h3><table style="width:100%; border-collapse:collapse; margin-bottom:20px;"><thead><tr>';
  foreach (['Ranking', 'Vendor', 'Harga', 'Skor Akhir', 'Status'] as $header) {
    $html .= '<th style="border:1px solid #000; padding:6px; background:#e5e7eb;">' . $header . '</th>';
  }
  $html .= '</tr></thead><tbody>';
  foreach ($ranking as $vendor) {
    $price = $vendor['criteria']['C1']['raw_value'] ?? null;
    $html .= '<tr>';
    $html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . (int)$vendor['ranking'] . '</td>';
    $html .= '<td style="border:1px solid #000; padding:6px;">' . htmlspecialchars((string)$vendor['vendor_name']) . '</td>';
    $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . ($price !== null ? formatRupiah((float)$price) : '-') . '</td>';
    $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . number_format((float)$vendor['final_score'], 4) . '</td>';
    $html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">Terverifikasi</td>';
    $html .= '</tr>';
  }
  if ($ranking === []) {
    $html .= '<tr><td colspan="5" style="border:1px solid #000; padding:6px; text-align:center;">Belum terdapat data vendor terverifikasi dengan nilai lengkap untuk dihitung.</td></tr>';
  }
  $html .= '</tbody></table>';

  $html .= '<h3>Nilai Kriteria, Normalisasi, dan Nilai Terbobot</h3><table style="width:100%; border-collapse:collapse;"><thead><tr>';
  foreach (['Vendor', 'Kriteria', 'Jenis', 'Bobot', 'Nilai', 'Normalisasi', 'Nilai Terbobot'] as $header) {
    $html .= '<th style="border:1px solid #000; padding:6px; background:#e5e7eb;">' . $header . '</th>';
  }
  $html .= '</tr></thead><tbody>';
  $hasDetails = false;
  foreach ($ranking as $vendor) {
    foreach ($criteriaDefinitions as $definition) {
      $code = (string)$definition['criterion_code'];
      $value = $vendor['criteria'][$code] ?? null;
      $hasDetails = true;
      $html .= '<tr>';
      $html .= '<td style="border:1px solid #000; padding:6px;">' . htmlspecialchars((string)$vendor['vendor_name']) . '</td>';
      $html .= '<td style="border:1px solid #000; padding:6px;">' . htmlspecialchars((string)$definition['name']) . '</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . htmlspecialchars(ucfirst((string)$definition['criterion_type'])) . '</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . number_format((float)$definition['weight'] * 100, 2) . '%</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . number_format((float)($value['raw_value'] ?? 0), 4) . '</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . number_format((float)($value['normalized_value'] ?? 0), 8) . '</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . number_format((float)($value['weighted_value'] ?? 0), 8) . '</td>';
      $html .= '</tr>';
    }
  }
  if ($ranking === []) {
    foreach ($criteriaDefinitions as $definition) {
      $hasDetails = true;
      $html .= '<tr>';
      $html .= '<td style="border:1px solid #000; padding:6px;">-</td>';
      $html .= '<td style="border:1px solid #000; padding:6px;">' . htmlspecialchars((string)$definition['name']) . '</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:center;">' . htmlspecialchars(ucfirst((string)$definition['criterion_type'])) . '</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">' . number_format((float)$definition['weight'] * 100, 2) . '%</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">-</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">-</td>';
      $html .= '<td style="border:1px solid #000; padding:6px; text-align:right;">-</td>';
      $html .= '</tr>';
    }
  }
  if (!$hasDetails) {
    $html .= '<tr><td colspan="7" style="border:1px solid #000; padding:6px; text-align:center;">Nilai SAW belum tersedia.</td></tr>';
  }
  $html .= '</tbody></table>';

  return $html;
}

function renderPrintPage(array $rows, array $ranking, array $criteriaDefinitions, array $filters): void
{
    $timestamp = date('d-m-Y H:i:s');
    $tableMarkup = renderPrintTable($rows);
  $decisionMarkup = renderDecisionReportMarkup($ranking, $criteriaDefinitions);
    echo '<!doctype html>';
    echo '<html lang="id">';
    echo '<head><meta charset="utf-8"><title>Laporan Keputusan Vendor IT - SAW</title>';
    echo '<style>';
    echo '@page { size: A4 landscape; margin: 12mm; }';
    echo '@media print { body { margin: 0; font-size: 11pt; } .print-header { margin-bottom: 12px; } .print-header img { max-width: 100px; height: auto; display: block; margin-bottom: 10px; } .print-header h1 { font-size: 18pt; font-weight: bold; margin: 0 0 4px; text-transform: uppercase; } .print-header .sub { font-size: 12pt; margin-bottom: 4px; } .print-header .stamp { font-size: 11pt; margin-bottom: 12px; } .print-footer { font-size: 10pt; margin-top: 12px; text-align: right; } }';
    echo 'body { font-family: Arial, sans-serif; padding: 24px; color: #111827; }';
    echo '</style></head>';
    echo '<body onload="window.print()">';
    echo '<div class="print-header">';
    if (file_exists(__DIR__ . '/assets/logo-angkasa.jpeg')) {
        echo '<img src="data:image/jpeg;base64,' . base64_encode(file_get_contents(__DIR__ . '/assets/logo-angkasa.jpeg')) . '" alt="PT Angkasa Pura Aviasi">';
    }
    echo '<h1>LAPORAN KEPUTUSAN VENDOR IT</h1>';
    echo '<div class="sub">Divisi IT Enterprise</div>';
    echo '<div class="sub">PT Angkasa Pura Aviasi</div>';
    echo '<div class="stamp">Tanggal Cetak: ' . htmlspecialchars($timestamp) . '</div>';
    echo '</div>';
    echo '<h2>Informasi Vendor dan Lisensi</h2>';
    echo $tableMarkup;
    echo $decisionMarkup;
    echo '<div class="print-footer">Halaman <span style="font-weight:bold;">1</span></div>';
    echo '</body></html>';
}

function exportExcelReport(array $rows, array $ranking, array $criteriaDefinitions, array $filters): void
{
  if ($rows === [] && $ranking === []) {
        header('Content-Type: text/plain; charset=utf-8');
    echo 'Data tidak ditemukan';
        return;
    }

    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Library PhpSpreadsheet belum tersedia. Jalankan composer install terlebih dahulu.';
        return;
    }

    require __DIR__ . '/vendor/autoload.php';

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Laporan');

    $sheet->mergeCells('A1:J1');
    $sheet->mergeCells('A2:J2');
    $sheet->mergeCells('A3:J3');
    $sheet->setCellValue('A1', 'LAPORAN PERBANDINGAN VENDOR IT');
    $sheet->setCellValue('A2', 'PT ANGKASA PURA AVIASI');
    $sheet->setCellValue('A3', 'Tanggal Export: ' . date('d-m-Y H:i:s'));

    $sheet->getStyle('A1:J3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A1:J3')->getFont()->setBold(true);

    $headers = ['No', 'Nama Vendor', 'Nama Lisensi', 'Jenis Lisensi', 'Harga Lisensi', 'Harga Bulanan', 'Jumlah User', 'Biaya per User per Bulan', 'Status Verifikasi', 'Informasi Penawaran'];
    $sheet->fromArray($headers, null, 'A5');

    $headerStyle = $sheet->getStyle('A5:J5');
    $headerStyle->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE));
    $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('1D4ED8');
    $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $headerStyle->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    $rowIndex = 6;
    foreach ($rows as $row) {
        $typeLabel = (string)($row['jenis_lisensi'] ?? 'Bulanan');

        $sheet->setCellValue('A' . $rowIndex, ($rowIndex - 5));
        $sheet->setCellValue('B' . $rowIndex, $row['name'] ?? '-');
        $sheet->setCellValue('C' . $rowIndex, $row['license_name'] ?? '-');
        $sheet->setCellValue('D' . $rowIndex, $typeLabel);
        $sheet->setCellValue('E' . $rowIndex, (float)($row['price_per_user'] ?? 0));
        $sheet->setCellValue('F' . $rowIndex, (float)($row['harga_bulanan'] ?? 0));
        $sheet->setCellValue('G' . $rowIndex, (int)($row['jumlah_user'] ?? 0));
        $sheet->setCellValue('H' . $rowIndex, $row['biaya_per_user_per_bulan'] !== null ? (float)$row['biaya_per_user_per_bulan'] : 0);
        $sheet->setCellValue('I' . $rowIndex, ucfirst((string)($row['verification_status'] ?? 'pending')));
        $sheet->setCellValue('J' . $rowIndex, formatOfferSummary((string)($row['notes'] ?? ''), (string)($row['offer_details'] ?? '')));

        $sheet->getStyle('E' . $rowIndex)->getNumberFormat()->setFormatCode('"Rp "#,##0');
        $sheet->getStyle('F' . $rowIndex)->getNumberFormat()->setFormatCode('"Rp "#,##0');
        $sheet->getStyle('H' . $rowIndex)->getNumberFormat()->setFormatCode('"Rp "#,##0');
        $sheet->getStyle('A' . $rowIndex . ':J' . $rowIndex)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('E' . $rowIndex)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('F' . $rowIndex)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('G' . $rowIndex)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('H' . $rowIndex)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);

        $rowIndex++;
    }

    $lastRow = $rowIndex - 1;
    $sheet->getStyle('A5:J' . max(5, $lastRow))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    $sheet->getStyle('A5:J' . max(5, $lastRow))->getAlignment()->setWrapText(true);
    $sheet->getStyle('A5:J' . max(5, $lastRow))->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    foreach (range('A', 'J') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $sheet->freezePane('A6');

    $rankingSheet = $spreadsheet->createSheet();
    $rankingSheet->setTitle('Hasil SAW');
    $rankingSheet->mergeCells('A1:E1');
    $rankingSheet->setCellValue('A1', 'HASIL KEPUTUSAN VENDOR - METODE SAW');
    $rankingSheet->mergeCells('A2:E2');
    $rankingSheet->setCellValue('A2', 'Berdasarkan hasil perhitungan metode Simple Additive Weighting (SAW), vendor dengan skor akhir tertinggi memperoleh peringkat pertama.');
    $rankingSheet->getStyle('A1:E2')->getFont()->setBold(true);
    $rankingSheet->getStyle('A1:E2')->getAlignment()->setWrapText(true);
    $rankingSheet->fromArray(['Ranking', 'Vendor', 'Harga (C1)', 'Skor Akhir', 'Status'], null, 'A4');
    $rankingSheet->getStyle('A4:E4')->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE));
    $rankingSheet->getStyle('A4:E4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('1D4ED8');
    $rankingRow = 5;
    foreach ($ranking as $vendor) {
      $rankingSheet->setCellValue('A' . $rankingRow, (int)$vendor['ranking']);
      $rankingSheet->setCellValue('B' . $rankingRow, (string)$vendor['vendor_name']);
      $rankingSheet->setCellValue('C' . $rankingRow, isset($vendor['criteria']['C1']) ? (float)$vendor['criteria']['C1']['raw_value'] : null);
      $rankingSheet->setCellValue('D' . $rankingRow, (float)$vendor['final_score']);
      $rankingSheet->setCellValue('E' . $rankingRow, 'Terverifikasi');
      $rankingRow++;
    }
    if ($ranking === []) {
      $rankingSheet->mergeCells('A5:E5');
      $rankingSheet->setCellValue('A5', 'Belum terdapat data vendor terverifikasi dengan nilai lengkap untuk dihitung.');
    }
    $rankingSheet->getStyle('C5:C' . max(5, $rankingRow - 1))->getNumberFormat()->setFormatCode('"Rp "#,##0');
    $rankingSheet->getStyle('D5:D' . max(5, $rankingRow - 1))->getNumberFormat()->setFormatCode('0.0000');
    $rankingSheet->getStyle('A4:E' . max(5, $rankingRow - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    foreach (range('A', 'E') as $column) {
      $rankingSheet->getColumnDimension($column)->setAutoSize(true);
    }
    $rankingSheet->freezePane('A5');

    $criteriaSheet = $spreadsheet->createSheet();
    $criteriaSheet->setTitle('Detail Kriteria SAW');
    $criteriaSheet->fromArray(['Ranking', 'Vendor', 'Kriteria', 'Jenis', 'Bobot', 'Nilai', 'Normalisasi', 'Nilai Terbobot', 'Skor Akhir'], null, 'A1');
    $criteriaSheet->getStyle('A1:I1')->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_WHITE));
    $criteriaSheet->getStyle('A1:I1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('1D4ED8');
    $criteriaRow = 2;
    foreach ($ranking as $vendor) {
      foreach ($criteriaDefinitions as $definition) {
        $code = (string)$definition['criterion_code'];
        $value = $vendor['criteria'][$code] ?? [];
        $criteriaSheet->fromArray([
          (int)$vendor['ranking'],
          (string)$vendor['vendor_name'],
          (string)$definition['name'],
          ucfirst((string)$definition['criterion_type']),
          (float)$definition['weight'],
          (float)($value['raw_value'] ?? 0),
          (float)($value['normalized_value'] ?? 0),
          (float)($value['weighted_value'] ?? 0),
          (float)$vendor['final_score'],
        ], null, 'A' . $criteriaRow);
        $criteriaRow++;
      }
    }
    if ($ranking === []) {
      if ($criteriaDefinitions === []) {
        $criteriaSheet->mergeCells('A2:I2');
        $criteriaSheet->setCellValue('A2', 'Kriteria SAW aktif belum tersedia.');
      } else {
        foreach ($criteriaDefinitions as $definition) {
          $criteriaSheet->fromArray([
            '-',
            '-',
            (string)$definition['name'],
            ucfirst((string)$definition['criterion_type']),
            (float)$definition['weight'],
            '-',
            '-',
            '-',
            '-',
          ], null, 'A' . $criteriaRow);
          $criteriaRow++;
        }
      }
    }
    $criteriaSheet->getStyle('E2:E' . max(2, $criteriaRow - 1))->getNumberFormat()->setFormatCode('0.00%');
    $criteriaSheet->getStyle('F2:F' . max(2, $criteriaRow - 1))->getNumberFormat()->setFormatCode('#,##0.0000');
    $criteriaSheet->getStyle('G2:I' . max(2, $criteriaRow - 1))->getNumberFormat()->setFormatCode('0.00000000');
    $criteriaSheet->getStyle('A1:I' . max(2, $criteriaRow - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    foreach (range('A', 'I') as $column) {
      $criteriaSheet->getColumnDimension($column)->setAutoSize(true);
    }
    $criteriaSheet->freezePane('A2');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Laporan_Vendor_IT_' . date('Ymd') . '.xlsx"');
    $writer->save('php://output');
}

$reportRows = applyReportFilters($rows, $selectedVendor, $selectedLicense);

$criteriaDefinitions = [];
$criteriaResult = $conn->query('SELECT id AS criterion_id, criterion_code, name, criterion_type, weight FROM evaluation_criteria WHERE is_active = 1 ORDER BY criterion_code');
if ($criteriaResult) {
  while ($criterion = $criteriaResult->fetch_assoc()) {
    $criteriaDefinitions[] = [
      'id' => (int)$criterion['criterion_id'],
      'criterion_code' => (string)$criterion['criterion_code'],
      'name' => (string)$criterion['name'],
      'criterion_type' => (string)$criterion['criterion_type'],
      'weight' => (float)$criterion['weight'],
    ];
  }
}

try {
  $allDecisionRows = getVendorRanking($conn);
  $sawError = '';
} catch (Throwable $exception) {
  error_log('Report SAW error: ' . $exception->getMessage());
  $allDecisionRows = [];
  $sawError = 'Perhitungan SAW belum tersedia. Periksa bobot dan kelengkapan nilai semua kriteria.';
}

$decisionRows = $allDecisionRows;
if ($sessionRole === 'vendor') {
  $decisionRows = $ownerVendorId === null ? [] : array_values(array_filter($allDecisionRows, static function (array $vendor) use ($ownerVendorId): bool {
    return $vendor['vendor_id'] === $ownerVendorId;
  }));
} elseif ($selectedVendor !== '' && $selectedVendor !== 'Filter Vendor') {
  $decisionRows = array_values(array_filter($allDecisionRows, static function (array $vendor) use ($selectedVendor): bool {
    return strcasecmp((string)$vendor['vendor_name'], $selectedVendor) === 0;
  }));
}

$verifiedVendorCount = 0;
if ($sessionRole === 'vendor') {
  $verifiedVendorCount = $ownerVendorId !== null
    ? (int)($ownerVerificationStatus === 'verified' ? 1 : 0)
    : 0;
} else {
  $verifiedCountResult = $conn->query("SELECT COUNT(*) AS total FROM vendors WHERE verification_status = 'verified'");
  $verifiedVendorCount = $verifiedCountResult ? (int)$verifiedCountResult->fetch_assoc()['total'] : 0;
}

$reportVendorOptions = [];
$vendorOptionsResult = $sessionRole === 'vendor'
  ? ($ownerVendorId !== null ? $conn->query('SELECT id, name FROM vendors WHERE id = ' . (int)$ownerVendorId) : false)
  : $conn->query('SELECT id, name FROM vendors ORDER BY name');
if ($vendorOptionsResult) {
  while ($option = $vendorOptionsResult->fetch_assoc()) {
    $reportVendorOptions[] = $option;
  }
}

$totalVendor = 0;
$vendorTotals = [];
foreach ($reportRows as $row) {
    $vendorId = (int)($row['vendor_id'] ?? 0);
    $vendorName = (string)($row['name'] ?? '-');
    $vendorTotals[$vendorId]['name'] = $vendorName;
    $vendorTotals[$vendorId]['total'] = (float)($vendorTotals[$vendorId]['total'] ?? 0) + ((float)($row['anggaran_tahunan'] ?? 0));
}
$totalVendor = count($vendorTotals);

$totalBudget = 0;
foreach ($reportRows as $row) {
    $totalBudget += (float)($row['anggaran_tahunan'] ?? 0);
}

$minVendor = null;
$maxVendor = null;
foreach ($vendorTotals as $vendorId => $vendorData) {
    if ($minVendor === null || $vendorData['total'] < $minVendor['total']) {
        $minVendor = $vendorData;
    }
    if ($maxVendor === null || $vendorData['total'] > $maxVendor['total']) {
        $maxVendor = $vendorData;
    }
}

$totalLisensi = count($reportRows);
$periodLabel = $selectedLicense === 'Tahunan' ? 'Tahun' : 'Bulan';
$packagePeriodHeading = 'Harga Paket per ' . $periodLabel;
$costPerUserPeriodHeading = 'Biaya per User per ' . $periodLabel;
$monthlyCount = count(array_filter($reportRows, function ($row) {
  return ($row['jenis_lisensi'] ?? '') === 'Bulanan';
}));
$annualCount = count(array_filter($reportRows, function ($row) {
  return ($row['jenis_lisensi'] ?? '') === 'Tahunan';
}));
$chartLabels = [];
$chartMonthly = [];
$chartAnnual = [];
foreach ($vendorTotals as $vendorId => $vendorData) {
    $chartLabels[] = $vendorData['name'];
    $annualTotal = (float)$vendorData['total'];
    $chartMonthly[] = $annualTotal / 12;
    $chartAnnual[] = $annualTotal;
}
  $topDecisionRow = $sessionRole === 'vendor'
    ? ($decisionRows[0] ?? null)
    : ($allDecisionRows[0] ?? null);

$action = $_REQUEST['action'] ?? '';
if ($action === 'print') {
  renderPrintPage($reportRows, $decisionRows, $criteriaDefinitions, [
        'vendor' => $selectedVendor,
        'license' => $selectedLicense,
    ]);
    exit;
}

if ($action === 'excel') {
  exportExcelReport($reportRows, $decisionRows, $criteriaDefinitions, [
        'vendor' => $selectedVendor,
        'license' => $selectedLicense,
    ]);
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Laporan Vendor Budget</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --primary: #0d6efd;
      --navy: #071326;
      --soft: #f6f9ff;
      --text: #243247;
      --muted: #7e899a;
    }

    body {
      font-family: 'Poppins', sans-serif;
      background: linear-gradient(180deg, #f8fbff 0%, #edf4ff 100%);
      color: var(--text);
    }

    .sidebar {
      background: linear-gradient(180deg, #071123, #102245);
      box-shadow: 0 18px 40px rgba(3, 12, 35, 0.22);
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
      padding: 25px;
      box-shadow: 0 16px 36px rgba(16, 24, 40, 0.08);
    }

    .mini-card {
      background: #fff;
      border-radius: 18px;
      padding: 1rem;
      box-shadow: 0 12px 28px rgba(16, 24, 40, 0.08);
      transition: transform 0.22s ease;
    }

    .mini-card:hover {
      transform: translateY(-4px);
    }

    .mini-icon {
      width: 46px;
      height: 46px;
      display: grid;
      place-items: center;
      border-radius: 14px;
      color: #fff;
      background: linear-gradient(135deg, #0d6efd, #4c9dff);
    }

    .chart-card,
    .table-card {
      background: #fff;
      border-radius: 20px;
      padding: 1.25rem;
      box-shadow: 0 14px 30px rgba(16, 24, 40, 0.08);
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
      letter-spacing: 0.04em;
      text-transform: uppercase;
      padding-top: 0.95rem;
      padding-bottom: 0.95rem;
    }

    .table-custom tbody tr:hover {
      background: #f6faff;
    }

    .filter-box {
      border-radius: 14px;
      background: #f8fbff;
      border: 1px solid #d9e6ff;
      padding: 0.72rem 0.9rem;
    }

    @media print {
      body * {
        visibility: hidden;
      }

      .report-print-area,
      .report-print-area * {
        visibility: visible;
      }

      .report-print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
      }

      .sidebar,
      .nav,
      .action-buttons,
      .btn,
      .filter-box,
      .chart-card,
      .page-card > .d-flex,
      .mini-card,
      .table-card .d-flex {
        display: none !important;
      }
    }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row g-0">
    <?php if ($sessionRole === 'admin'): ?>
      <aside class="col-lg-2 col-md-3 sidebar text-white min-vh-100 p-3 p-lg-4">
        <div class="fw-bold fs-5 mb-4">Vendor Budget</div>
        <ul class="nav flex-column">
          <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="vendor.php"><i class="fa-solid fa-building me-2"></i>Vendor</a></li>
          <li class="nav-item"><a class="nav-link" href="license.php"><i class="fa-solid fa-tags me-2"></i>Harga Lisensi</a></li>
          <li class="nav-item"><a class="nav-link" href="user.php"><i class="fa-solid fa-users me-2"></i>Pengguna</a></li>
          <li class="nav-item"><a class="nav-link active" href="report.php"><i class="fa-solid fa-file-lines me-2"></i>Laporan</a></li>
          <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
        </ul>
      </aside>
    <?php elseif ($sessionRole === 'pimpinan'): ?>
      <aside class="col-lg-2 col-md-3 sidebar text-white min-vh-100 p-3 p-lg-4">
        <div class="fw-bold fs-5 mb-4">Vendor Budget</div>
        <ul class="nav flex-column">
          <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link active" href="report.php"><i class="fa-solid fa-file-lines me-2"></i>Laporan</a></li>
          <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
        </ul>
      </aside>
    <?php elseif ($sessionRole === 'vendor'): ?>
      <aside class="col-lg-2 col-md-3 sidebar text-white min-vh-100 p-3 p-lg-4">
        <div class="fw-bold fs-5 mb-4">Vendor Budget</div>
        <ul class="nav flex-column">
          <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard Saya</a></li>
          <li class="nav-item"><a class="nav-link" href="vendor.php"><i class="fa-solid fa-building me-2"></i>Data Vendor Saya</a></li>
          <li class="nav-item"><a class="nav-link" href="license.php"><i class="fa-solid fa-tags me-2"></i>Data Penawaran</a></li>
          <li class="nav-item"><a class="nav-link active" href="report.php"><i class="fa-solid fa-file-lines me-2"></i>Laporan Saya</a></li>
          <li class="nav-item"><a class="nav-link" href="logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a></li>
        </ul>
      </aside>
    <?php endif; ?>

    <main class="col-lg-10 col-md-9 p-3 p-lg-4">
      <div class="page-card">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
          <div>
            <div class="small text-primary fw-semibold">Dashboard / Report</div>
            <h2 class="fw-bold mb-1">Laporan Keputusan Vendor</h2>
            <p class="text-muted mb-0">Perbandingan penawaran dan hasil keputusan vendor menggunakan metode SAW.</p>
          </div>
          <div class="d-flex gap-2 flex-wrap action-buttons">
            <form method="get" action="report.php" class="d-inline-block" target="_blank">
              <input type="hidden" name="action" value="print">
              <input type="hidden" name="vendor_filter" value="<?= htmlspecialchars($selectedVendor) ?>">
              <input type="hidden" name="license_filter" value="<?= htmlspecialchars($selectedLicense) ?>">
              <button type="submit" class="btn btn-outline-primary rounded-pill px-3"><i class="fa-solid fa-print me-2"></i>Print</button>
            </form>
            <form method="get" action="report.php" class="d-inline-block">
              <input type="hidden" name="action" value="excel">
              <input type="hidden" name="vendor_filter" value="<?= htmlspecialchars($selectedVendor) ?>">
              <input type="hidden" name="license_filter" value="<?= htmlspecialchars($selectedLicense) ?>">
              <button type="submit" class="btn btn-primary rounded-pill px-3"><i class="fa-solid fa-file-excel me-2"></i>Export Excel</button>
            </form>
          </div>
        </div>

        <div class="row g-3 mb-4">
          <div class="col-md-6 col-xl-3">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="small text-muted">Total Vendor</div>
                <div class="fw-bold fs-4"><?= $totalVendor ?></div>
                <div class="small text-success">Terverifikasi: <?= $verifiedVendorCount ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-building"></i></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="small text-muted">Total Lisensi</div>
                <div class="fw-bold fs-4"><?= $totalLisensi ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-key"></i></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="small text-muted">Total Budget</div>
                <div class="fw-bold fs-4">Rp <?= number_format((float)$totalBudget, 0, ',', '.') ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-wallet"></i></div>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="mini-card d-flex align-items-center justify-content-between">
              <div>
                <div class="small text-muted"><?= $sessionRole === 'vendor' ? 'Peringkat Vendor Saya' : 'Vendor Peringkat 1 SAW' ?></div>
                <div class="fw-bold fs-6"><?= htmlspecialchars($topDecisionRow['vendor_name'] ?? '-') ?></div>
                <div class="small text-primary">Skor akhir: <?= $topDecisionRow ? number_format((float)$topDecisionRow['final_score'], 4) : '-' ?><?= $topDecisionRow ? ' | Ranking #' . (int)$topDecisionRow['ranking'] : '' ?></div>
              </div>
              <div class="mini-icon"><i class="fa-solid fa-crown"></i></div>
            </div>
          </div>
        </div>

        <form method="get" action="report.php" class="row g-3 mb-4">
          <div class="col-md-3">
            <select class="form-select filter-box" name="vendor_filter">
              <option value="">Filter Vendor</option>
              <?php foreach ($reportVendorOptions as $vendorOption): ?>
                <option value="<?= htmlspecialchars($vendorOption['name']) ?>" <?= $selectedVendor === $vendorOption['name'] ? 'selected' : '' ?>><?= htmlspecialchars($vendorOption['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <select class="form-select filter-box" name="license_filter">
              <option value="">Filter Jenis Lisensi</option>
              <option value="Bulanan" <?= $selectedLicense === 'Bulanan' ? 'selected' : '' ?>>Bulanan</option>
              <option value="Tahunan" <?= $selectedLicense === 'Tahunan' ? 'selected' : '' ?>>Tahunan</option>
            </select>
          </div>
        </form>

        <div class="row g-4 mb-4">
          <div class="col-12 col-xl-7">
            <div class="chart-card h-100">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Grafik Budget Vendor</h5>
                <span class="badge bg-light text-primary rounded-pill px-3 py-2">Chart.js</span>
              </div>
              <canvas id="budgetChart" height="140"></canvas>
            </div>
          </div>
          <div class="col-12 col-xl-5">
            <div class="chart-card h-100">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Grafik Distribusi Lisensi</h5>
                <span class="badge bg-light text-primary rounded-pill px-3 py-2">Chart.js</span>
              </div>
              <canvas id="licenseChart" height="180"></canvas>
            </div>
          </div>
        </div>

        <div class="table-card mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">Hasil Perhitungan dan Ranking SAW</h5>
            <span class="badge bg-primary rounded-pill px-3 py-2">SAW</span>
          </div>
          <p class="text-muted">Laporan penawaran dan ranking hanya mencakup vendor terverifikasi. Biaya tambahan ditampilkan terpisah dan tidak otomatis dijumlahkan ke harga lisensi. Ranking menggunakan hasil metode SAW yang sama dengan dashboard; filter jenis lisensi hanya memengaruhi daftar informasi penawaran, bukan perhitungan ranking.</p>
          <?php if ($verifiedVendorCount === 0): ?>
            <div class="alert alert-info">Belum terdapat data vendor terverifikasi yang dapat dihitung.</div>
          <?php elseif ($decisionRows === []): ?>
            <div class="alert alert-warning">
              <?= $sawError !== '' ? htmlspecialchars($sawError) : 'Belum ada vendor dengan nilai lengkap untuk seluruh kriteria aktif pada hasil SAW.' ?>
            </div>
          <?php endif; ?>
          <div class="table-responsive table-custom mb-4">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-head">
                <tr><th>Ranking</th><th>Vendor</th><th>Harga</th><th>Skor Akhir</th><th>Status</th></tr>
              </thead>
              <tbody>
                <?php foreach ($decisionRows as $decision): ?>
                  <tr>
                    <td><span class="badge bg-primary rounded-pill">#<?= (int)$decision['ranking'] ?></span></td>
                    <td class="fw-semibold"><?= htmlspecialchars($decision['vendor_name']) ?></td>
                    <td><?= isset($decision['criteria']['C1']) ? formatRupiah((float)$decision['criteria']['C1']['raw_value']) : '-' ?></td>
                    <td class="fw-bold text-primary"><?= number_format((float)$decision['final_score'], 4) ?></td>
                    <td><span class="badge bg-success rounded-pill">Terverifikasi</span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if ($decisionRows === []): ?>
                  <tr><td colspan="5" class="text-center text-muted py-3">Belum ada hasil keputusan yang dapat ditampilkan.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <h6 class="fw-bold mb-3">Kriteria Penilaian dan Rincian Nilai</h6>
          <div class="table-responsive table-custom">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-head">
                <tr><th>Vendor</th><th>Kriteria</th><th>Jenis</th><th>Bobot</th><th>Nilai</th><th>Normalisasi</th><th>Nilai Terbobot</th></tr>
              </thead>
              <tbody>
                <?php if ($decisionRows !== []): ?>
                  <?php foreach ($decisionRows as $decision): ?>
                    <?php foreach ($criteriaDefinitions as $criterion): ?>
                      <?php $scoreDetail = $decision['criteria'][$criterion['criterion_code']] ?? null; ?>
                      <tr>
                        <td><?= htmlspecialchars($decision['vendor_name']) ?></td>
                        <td><?= htmlspecialchars($criterion['name']) ?></td>
                        <td><?= htmlspecialchars(ucfirst($criterion['criterion_type'])) ?></td>
                        <td><?= number_format((float)$criterion['weight'] * 100, 2) ?>%</td>
                        <td><?= $scoreDetail ? number_format((float)$scoreDetail['raw_value'], 4) : '-' ?></td>
                        <td><?= $scoreDetail ? number_format((float)$scoreDetail['normalized_value'], 8) : '-' ?></td>
                        <td><?= $scoreDetail ? number_format((float)$scoreDetail['weighted_value'], 8) : '-' ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <?php foreach ($criteriaDefinitions as $criterion): ?>
                    <tr>
                      <td>-</td>
                      <td><?= htmlspecialchars($criterion['name']) ?></td>
                      <td><?= htmlspecialchars(ucfirst($criterion['criterion_type'])) ?></td>
                      <td><?= number_format((float)$criterion['weight'] * 100, 2) ?>%</td>
                      <td>-</td><td>-</td><td>-</td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if ($criteriaDefinitions === []): ?><tr><td colspan="7" class="text-center text-muted">Kriteria aktif belum tersedia.</td></tr><?php endif; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="alert alert-light border mt-4 mb-0">
            <strong>Kesimpulan sistem:</strong> Berdasarkan hasil perhitungan metode Simple Additive Weighting (SAW), vendor dengan skor akhir tertinggi memperoleh peringkat pertama.
            <?php if ($topDecisionRow): ?><div class="mt-1">Hasil saat ini: <strong><?= htmlspecialchars($topDecisionRow['vendor_name']) ?></strong> dengan skor <?= number_format((float)$topDecisionRow['final_score'], 4) ?> pada ranking #<?= (int)$topDecisionRow['ranking'] ?>.</div><?php endif; ?>
          </div>
        </div>

        <div class="table-card">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0">Hasil Perbandingan</h5>
            <span class="small text-muted">Data dari query laporan yang sedang berjalan</span>
          </div>
          <div class="table-responsive table-custom">
            <?php if ($reportRows === []): ?>
              <div class="alert alert-warning mb-0">Data tidak ditemukan</div>
            <?php else: ?>
              <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-head">
                  <tr>
                    <th>Vendor</th>
                    <th>Nama Lisensi</th>
                    <th>Jenis Lisensi</th>
                    <th>Harga Paket</th>
                    <th>Kebutuhan User</th>
                    <th><?= htmlspecialchars($packagePeriodHeading) ?></th>
                    <th><?= htmlspecialchars($costPerUserPeriodHeading) ?></th>
                    <th>Total Anggaran Tahunan</th>
                    <th>Status Verifikasi</th>
                    <th>Informasi Penawaran</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($reportRows as $row): ?>
                    <?php
                        $typeLabel = (string)($row['jenis_lisensi'] ?? 'Bulanan');
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($row['name']) ?></td>
                      <td><?= htmlspecialchars($row['license_name']) ?></td>
                      <td><?= htmlspecialchars($typeLabel) ?></td>
                      <td><?= formatRupiah((float)$row['harga_paket_mode']) ?></td>
                      <td><?= ((int)($row['jumlah_user'] ?? 0) > 0 ? htmlspecialchars((string)($row['jumlah_user'] ?? 0)) : 'Belum ada kebutuhan user') ?></td>
                      <td><?= formatRupiah((float)($selectedLicense === 'Tahunan' ? $row['harga_tahunan'] : $row['harga_bulanan'])) ?></td>
                      <td><?= (($selectedLicense === 'Tahunan' ? $row['biaya_per_user_per_tahun'] : $row['biaya_per_user_per_bulan']) !== null ? formatRupiah((float)($selectedLicense === 'Tahunan' ? $row['biaya_per_user_per_tahun'] : $row['biaya_per_user_per_bulan'])) : 'Belum ada kebutuhan user') ?></td>
                      <td><?= formatRupiah((float)($row['anggaran_tahunan'] ?? 0)) ?></td>
                      <td><span class="badge <?= $row['verification_status'] === 'verified' ? 'bg-success' : ($row['verification_status'] === 'rejected' ? 'bg-danger' : 'bg-warning text-dark') ?> rounded-pill"><?= htmlspecialchars(ucfirst($row['verification_status'])) ?></span></td>
                      <td><?= nl2br(htmlspecialchars(formatOfferSummary($row['notes'], $row['offer_details']))) ?: '-' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const budgetChart = new Chart(document.getElementById('budgetChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($chartLabels) ?>,
      datasets: [
        {
          label: 'Biaya Bulanan',
          data: <?= json_encode($chartMonthly) ?>,
          backgroundColor: '#0d6efd',
          borderRadius: 10,
          maxBarThickness: 32
        },
        {
          label: 'Biaya Tahunan',
          data: <?= json_encode($chartAnnual) ?>,
          backgroundColor: '#198754',
          borderRadius: 10,
          maxBarThickness: 32
        }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: 'top' }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function (value) {
              return 'Rp ' + Number(value).toLocaleString('id-ID');
            }
          }
        }
      }
    }
  });

  const licenseChart = new Chart(document.getElementById('licenseChart'), {
    type: 'doughnut',
    data: {
      labels: ['Bulanan', 'Tahunan'],
      datasets: [{
        data: [<?= $monthlyCount ?>, <?= $annualCount ?>],
        backgroundColor: ['#0d6efd', '#198754']
      }]
    },
    options: {
      responsive: true,
      cutout: '60%',
      plugins: {
        legend: { position: 'bottom' }
      }
    }
  });

  const reportFilterForm = document.querySelector('form[action="report.php"].row');
  if (reportFilterForm) {
    reportFilterForm.querySelectorAll('select').forEach((select) => {
      select.addEventListener('change', () => reportFilterForm.submit());
    });
  }
</script>
</body>
</html>
