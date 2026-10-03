<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$dbname = getenv('DB_NAME') ?: 'vendor_budget';

try {
    $conn = new mysqli($host, $user, $pass);
    $conn->set_charset('utf8mb4');
    $conn->query("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($dbname);

    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('vendor','admin','pimpinan','manager') NOT NULL DEFAULT 'admin',
        vendor_id INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        full_name VARCHAR(100) DEFAULT NULL,
        email VARCHAR(100) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_users_vendor_id (vendor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS vendors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        contact VARCHAR(100) DEFAULT NULL,
        address TEXT DEFAULT NULL,
        category VARCHAR(100) DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Aktif',
        notes TEXT DEFAULT NULL,
        offer_details TEXT DEFAULT NULL,
        verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
        rejection_reason TEXT DEFAULT NULL,
        verified_by INT DEFAULT NULL,
        verified_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_vendors_verification_status (verification_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS license_prices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT NOT NULL,
        license_name VARCHAR(150) NOT NULL,
        price_per_user DECIMAL(15,2) NOT NULL DEFAULT 0,
        jumlah_user INT NOT NULL DEFAULT 1,
        harga_bulanan DECIMAL(15,2) DEFAULT NULL,
        billing_cycle ENUM('monthly','annual','lifetime') NOT NULL DEFAULT 'monthly',
        notes LONGTEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_license_prices_vendor (vendor_id),
        CONSTRAINT fk_license_prices_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS comparison_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT NOT NULL,
        user_count INT NOT NULL DEFAULT 0,
        monthly_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        annual_total DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_comparison_vendor (vendor_id),
        CONSTRAINT fk_comparison_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migrate older installations additively. Existing vendor, price, and user data is retained.
    $ensureColumn = static function (mysqli $connection, string $table, string $column, string $definition): void {
        $stmt = $connection->prepare('SELECT COUNT(*) AS total FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $exists = (int)$stmt->get_result()->fetch_assoc()['total'] > 0;
        $stmt->close();
        if (!$exists) {
            $connection->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    };
    $ensureColumn($conn, 'users', 'vendor_id', 'INT NULL');
    $ensureColumn($conn, 'users', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1');
    $ensureColumn($conn, 'users', 'full_name', 'VARCHAR(100) NULL');
    $ensureColumn($conn, 'users', 'email', 'VARCHAR(100) NULL');
    $conn->query("UPDATE users SET role = 'pimpinan' WHERE role = 'manager'");
    $conn->query("ALTER TABLE users MODIFY role ENUM('vendor','admin','pimpinan') NOT NULL DEFAULT 'admin'");
    $ensureColumn($conn, 'vendors', 'address', 'TEXT NULL');
    $ensureColumn($conn, 'vendors', 'status', "VARCHAR(30) NOT NULL DEFAULT 'Aktif'");
    $ensureColumn($conn, 'vendors', 'offer_details', 'TEXT NULL');
    $ensureColumn($conn, 'vendors', 'verification_status', "ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending'");
    $ensureColumn($conn, 'vendors', 'rejection_reason', 'TEXT NULL');
    $ensureColumn($conn, 'vendors', 'verified_by', 'INT NULL');
    $ensureColumn($conn, 'vendors', 'verified_at', 'TIMESTAMP NULL DEFAULT NULL');
    $ensureColumn($conn, 'license_prices', 'jumlah_user', 'INT NOT NULL DEFAULT 1');
    $ensureColumn($conn, 'license_prices', 'harga_bulanan', 'DECIMAL(15,2) NULL');
    $conn->query("ALTER TABLE license_prices MODIFY billing_cycle ENUM('monthly','annual','lifetime') NOT NULL DEFAULT 'monthly'");
    $conn->query("ALTER TABLE license_prices MODIFY notes LONGTEXT NULL");

    $conn->query("CREATE TABLE IF NOT EXISTS evaluation_criteria (
        id INT AUTO_INCREMENT PRIMARY KEY,
        criterion_code VARCHAR(10) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        criterion_type ENUM('cost','benefit') NOT NULL,
        weight DECIMAL(5,4) NOT NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT chk_evaluation_criteria_weight CHECK (weight >= 0 AND weight <= 1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS criterion_rubric_levels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        criterion_id INT NOT NULL,
        score TINYINT UNSIGNED NOT NULL,
        level_label VARCHAR(120) NOT NULL,
        guidance TEXT NOT NULL,
        UNIQUE KEY uq_criterion_rubric_score (criterion_id, score),
        CONSTRAINT fk_rubric_criterion FOREIGN KEY (criterion_id) REFERENCES evaluation_criteria(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS vendor_criteria_scores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendor_id INT NOT NULL,
        criterion_id INT NOT NULL,
        raw_value DECIMAL(15,4) NOT NULL,
        normalized_value DECIMAL(15,8) DEFAULT NULL,
        weighted_value DECIMAL(15,8) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vendor_criteria_score (vendor_id, criterion_id),
        KEY idx_vendor_scores_criterion (criterion_id),
        CONSTRAINT fk_vendor_scores_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE,
        CONSTRAINT fk_vendor_scores_criterion FOREIGN KEY (criterion_id) REFERENCES evaluation_criteria(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS decision_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        method VARCHAR(30) NOT NULL DEFAULT 'SAW',
        calculated_by INT DEFAULT NULL,
        calculated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes TEXT DEFAULT NULL,
        CONSTRAINT fk_decision_runs_user FOREIGN KEY (calculated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS decision_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        run_id INT NOT NULL,
        vendor_id INT NOT NULL,
        final_score DECIMAL(15,8) NOT NULL,
        ranking INT NOT NULL,
        UNIQUE KEY uq_decision_result_vendor (run_id, vendor_id),
        CONSTRAINT fk_decision_results_run FOREIGN KEY (run_id) REFERENCES decision_runs(id) ON DELETE CASCADE,
        CONSTRAINT fk_decision_results_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $criteria = [
        ['C1', 'Harga Lisensi', 'cost', 0.30, 'Harga bulanan ekuivalen per pengguna; biaya tambahan ditampilkan terpisah.'],
        ['C2', 'Kualitas/Spesifikasi Produk', 'benefit', 0.20, 'Skala 1–5 berdasarkan kesesuaian spesifikasi, kompatibilitas, performa, keamanan, dan storage yang didukung bukti.'],
        ['C3', 'Fitur/Benefit yang Ditawarkan', 'benefit', 0.15, 'Skala 1–5 berdasarkan fitur produk, AI, automation, meeting, storage, upgrade, training, dan benefit tambahan.'],
        ['C4', 'Dukungan Teknis/Support', 'benefit', 0.15, 'Skala 1–5 berdasarkan jam support, SLA, estimasi respon, kanal, dan technical assistance.'],
        ['C5', 'Garansi/Jaminan Layanan', 'benefit', 0.10, 'Skala 1–5 berdasarkan durasi, cakupan, SLA, dan ketentuan tertulis.'],
        ['C6', 'Kemudahan Implementasi', 'benefit', 0.10, 'Skala 1–5 berdasarkan instalasi, konfigurasi, migrasi, training, dan pendampingan.'],
    ];
    $criteriaInsert = $conn->prepare('INSERT IGNORE INTO evaluation_criteria (criterion_code, name, criterion_type, weight, description) VALUES (?, ?, ?, ?, ?)');
    foreach ($criteria as [$code, $name, $type, $weight, $description]) {
        $criteriaInsert->bind_param('sssds', $code, $name, $type, $weight, $description);
        $criteriaInsert->execute();
    }
    $criteriaInsert->close();

    $rubrics = [
        'C2' => ['Sangat Rendah|Sangat tidak sesuai kebutuhan; spesifikasi wajib, keamanan, atau kompatibilitas utama tidak terpenuhi.', 'Rendah|Kurang sesuai; banyak kekurangan pada spesifikasi, performa, keamanan, atau storage.', 'Cukup|Cukup sesuai kebutuhan minimum dan bukti spesifikasi dasar tersedia.', 'Baik|Sesuai kebutuhan, kompatibel, performa dan keamanan baik dengan bukti.', 'Sangat Baik|Sangat sesuai dan memiliki spesifikasi/keunggulan tambahan yang terbukti.'],
        'C3' => ['Sangat Rendah|Fitur sangat terbatas dan tidak ada benefit tambahan relevan.', 'Rendah|Fitur terbatas; sebagian besar kebutuhan tambahan tidak tersedia.', 'Cukup|Fitur utama cukup untuk kebutuhan dasar.', 'Baik|Fitur lengkap dan beberapa benefit tambahan tersedia.', 'Sangat Baik|Fitur sangat lengkap dengan benefit tambahan signifikan yang dibuktikan.'],
        'C4' => ['Sangat Rendah|Tidak ada support.', 'Rendah|Support sangat terbatas, tanpa kanal atau target respons jelas.', 'Cukup|Support tersedia pada jam kerja.', 'Baik|Support baik dengan SLA dan kanal eskalasi tersedia.', 'Sangat Baik|Support 24/7, SLA terukur, dan technical assistance.'],
        'C5' => ['Sangat Rendah|Tidak ada garansi atau jaminan layanan.', 'Rendah|Garansi/jaminan sangat terbatas.', 'Cukup|Garansi standar dengan cakupan dasar.', 'Baik|Garansi baik dengan durasi dan cakupan tertulis.', 'Sangat Baik|Jaminan sangat baik, cakupan dan SLA jelas.'],
        'C6' => ['Sangat Rendah|Tidak ada bantuan implementasi.', 'Rendah|Bantuan terbatas.', 'Cukup|Bantuan implementasi standar.', 'Baik|Instalasi, konfigurasi, atau migrasi tersedia.', 'Sangat Baik|Implementasi lengkap meliputi migrasi, training, dan pendampingan.'],
    ];
    $rubricInsert = $conn->prepare('INSERT IGNORE INTO criterion_rubric_levels (criterion_id, score, level_label, guidance) SELECT id, ?, ?, ? FROM evaluation_criteria WHERE criterion_code = ?');
    foreach ($rubrics as $code => $levels) {
        foreach ($levels as $index => $level) {
            [$label, $guidance] = explode('|', $level, 2);
            $score = $index + 1;
            $rubricInsert->bind_param('isss', $score, $label, $guidance, $code);
            $rubricInsert->execute();
        }
    }
    $rubricInsert->close();

    $adminHash = password_hash('admin123', PASSWORD_DEFAULT);
    $admin = $conn->prepare("INSERT IGNORE INTO users (username, password_hash, role, full_name, email) VALUES ('admin', ?, 'admin', 'Administrator', 'admin@localhost')");
    $admin->bind_param('s', $adminHash);
    $admin->execute();
    $admin->close();
    $managerHash = password_hash('manager123', PASSWORD_DEFAULT);
    $manager = $conn->prepare("INSERT IGNORE INTO users (username, password_hash, role, full_name, email) VALUES ('pimpinan', ?, 'pimpinan', 'Pimpinan IT', 'pimpinan@localhost')");
    $manager->bind_param('s', $managerHash);
    $manager->execute();
    $manager->close();

    // Add the vendor link foreign key and uniqueness constraint only when missing.
    $vendorKey = $conn->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_vendor_id'")->fetch_assoc();
    if ((int)$vendorKey['total'] === 0) {
        $conn->query('ALTER TABLE users ADD UNIQUE KEY uq_users_vendor_id (vendor_id)');
    }
    $vendorForeignKey = $conn->query("SELECT COUNT(*) AS total FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_users_vendor'")->fetch_assoc();
    if ((int)$vendorForeignKey['total'] === 0) {
        $conn->query('ALTER TABLE users ADD CONSTRAINT fk_users_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE SET NULL');
    }

    echo '<h2>Instalasi database berhasil</h2>';
    echo '<p>Skema diperbarui secara aditif; data vendor dan akun lama tetap dipertahankan.</p>';
    echo '<p>Akun awal: <strong>admin / admin123</strong> dan <strong>pimpinan / manager123</strong>. Segera ganti password setelah login.</p>';
    echo '<p>Kaitkan akun Vendor dengan vendor yang sudah ada melalui menu Pengguna.</p>';
    echo '<p><a href="login.php">Masuk ke sistem</a></p>';
    $conn->close();
} catch (Throwable $exception) {
    http_response_code(500);
    echo '<h2>Instalasi gagal</h2><pre>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}
