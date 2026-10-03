-- SQL dump for vendor_budget
-- Import via phpMyAdmin: Import > Choose File > vendor_budget.sql

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

CREATE DATABASE IF NOT EXISTS `vendor_budget` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `vendor_budget`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('vendor','admin','pimpinan') NOT NULL DEFAULT 'admin',
  `vendor_id` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vendors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Aktif',
  `notes` text,
  `offer_details` text DEFAULT NULL,
  `verification_status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text DEFAULT NULL,
  `verified_by` int DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `license_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` int NOT NULL,
  `license_name` varchar(100) NOT NULL,
  `price_per_user` decimal(15,2) NOT NULL DEFAULT '0.00',
  `jumlah_user` int NOT NULL DEFAULT '1',
  `harga_bulanan` decimal(15,2) DEFAULT NULL,
  `billing_cycle` enum('monthly','annual','lifetime') NOT NULL DEFAULT 'monthly',
  `notes` longtext,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  CONSTRAINT `license_prices_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `comparison_results` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` int NOT NULL,
  `user_count` int NOT NULL DEFAULT '0',
  `monthly_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `annual_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`),
  CONSTRAINT `comparison_results_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extend the existing schema without dropping or renaming existing tables/columns.
-- Existing admin/pimpinan accounts and vendor records are retained.
ALTER TABLE `users`
  MODIFY COLUMN `role` enum('vendor','admin','pimpinan') NOT NULL DEFAULT 'admin',
  ADD UNIQUE KEY `uq_users_vendor_id` (`vendor_id`),
  ADD CONSTRAINT `fk_users_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE SET NULL;

ALTER TABLE `vendors`
  ADD KEY `idx_vendors_verification_status` (`verification_status`),
  ADD CONSTRAINT `fk_vendors_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `evaluation_criteria` (
  `id` int NOT NULL AUTO_INCREMENT,
  `criterion_code` varchar(10) NOT NULL,
  `name` varchar(150) NOT NULL,
  `criterion_type` enum('cost','benefit') NOT NULL,
  `weight` decimal(5,4) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_evaluation_criteria_code` (`criterion_code`),
  CONSTRAINT `chk_evaluation_criteria_weight` CHECK (`weight` >= 0 AND `weight` <= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `criterion_rubric_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `criterion_id` int NOT NULL,
  `score` tinyint unsigned NOT NULL,
  `level_label` varchar(120) NOT NULL,
  `guidance` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_criterion_rubric_score` (`criterion_id`,`score`),
  CONSTRAINT `fk_rubric_criterion` FOREIGN KEY (`criterion_id`) REFERENCES `evaluation_criteria` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `vendor_criteria_scores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` int NOT NULL,
  `criterion_id` int NOT NULL,
  `raw_value` decimal(15,4) NOT NULL,
  `normalized_value` decimal(15,8) DEFAULT NULL,
  `weighted_value` decimal(15,8) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vendor_criteria_score` (`vendor_id`,`criterion_id`),
  KEY `idx_vendor_scores_criterion` (`criterion_id`),
  CONSTRAINT `fk_vendor_scores_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vendor_scores_criterion` FOREIGN KEY (`criterion_id`) REFERENCES `evaluation_criteria` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `decision_runs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `method` varchar(30) NOT NULL DEFAULT 'SAW',
  `calculated_by` int DEFAULT NULL,
  `calculated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_decision_runs_calculated_by` (`calculated_by`),
  CONSTRAINT `fk_decision_runs_user` FOREIGN KEY (`calculated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `decision_results` (
  `id` int NOT NULL AUTO_INCREMENT,
  `run_id` int NOT NULL,
  `vendor_id` int NOT NULL,
  `final_score` decimal(15,8) NOT NULL,
  `ranking` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_decision_result_vendor` (`run_id`,`vendor_id`),
  KEY `idx_decision_result_ranking` (`run_id`,`ranking`),
  CONSTRAINT `fk_decision_results_run` FOREIGN KEY (`run_id`) REFERENCES `decision_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_decision_results_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SAW input: only verified vendors participate in normalization.
-- Cost = minimum verified value / vendor value; benefit = vendor value / maximum verified value.
CREATE OR REPLACE VIEW `saw_verified_vendor_scores` AS
SELECT
  vcs.`vendor_id`,
  vcs.`criterion_id`,
  vcs.`raw_value`,
  ec.`weight`,
  CASE ec.`criterion_type`
    WHEN 'cost' THEN (
      SELECT MIN(vcs_min.`raw_value`)
      FROM `vendor_criteria_scores` vcs_min
      INNER JOIN `vendors` v_min ON v_min.`id` = vcs_min.`vendor_id`
      WHERE vcs_min.`criterion_id` = vcs.`criterion_id`
        AND v_min.`verification_status` = 'verified'
    ) / NULLIF(vcs.`raw_value`, 0)
    WHEN 'benefit' THEN vcs.`raw_value` / NULLIF((
      SELECT MAX(vcs_max.`raw_value`)
      FROM `vendor_criteria_scores` vcs_max
      INNER JOIN `vendors` v_max ON v_max.`id` = vcs_max.`vendor_id`
      WHERE vcs_max.`criterion_id` = vcs.`criterion_id`
        AND v_max.`verification_status` = 'verified'
    ), 0)
  END AS `normalized_value`,
  CASE ec.`criterion_type`
    WHEN 'cost' THEN (
      SELECT MIN(vcs_min.`raw_value`)
      FROM `vendor_criteria_scores` vcs_min
      INNER JOIN `vendors` v_min ON v_min.`id` = vcs_min.`vendor_id`
      WHERE vcs_min.`criterion_id` = vcs.`criterion_id`
        AND v_min.`verification_status` = 'verified'
    ) / NULLIF(vcs.`raw_value`, 0) * ec.`weight`
    WHEN 'benefit' THEN vcs.`raw_value` / NULLIF((
      SELECT MAX(vcs_max.`raw_value`)
      FROM `vendor_criteria_scores` vcs_max
      INNER JOIN `vendors` v_max ON v_max.`id` = vcs_max.`vendor_id`
      WHERE vcs_max.`criterion_id` = vcs.`criterion_id`
        AND v_max.`verification_status` = 'verified'
    ), 0) * ec.`weight`
  END AS `weighted_value`
FROM `vendor_criteria_scores` vcs
INNER JOIN `vendors` v ON v.`id` = vcs.`vendor_id`
INNER JOIN `evaluation_criteria` ec ON ec.`id` = vcs.`criterion_id`
WHERE v.`verification_status` = 'verified'
  AND ec.`is_active` = 1;

-- Final SAW totals are available only when every active criterion has a value
-- and the active criterion weights sum to exactly 1.00.
CREATE OR REPLACE VIEW `saw_verified_vendor_totals` AS
SELECT
  svs.`vendor_id`,
  SUM(svs.`weighted_value`) AS `final_score`
FROM `saw_verified_vendor_scores` svs
GROUP BY svs.`vendor_id`
HAVING COUNT(DISTINCT svs.`criterion_id`) = (
    SELECT COUNT(*)
    FROM `evaluation_criteria`
    WHERE `is_active` = 1
  )
  AND (
    SELECT COALESCE(SUM(`weight`), 0)
    FROM `evaluation_criteria`
    WHERE `is_active` = 1
  ) = 1.0000;

-- Seed the initial criteria; IGNORE preserves any existing customized criteria.
INSERT IGNORE INTO `evaluation_criteria` (`criterion_code`, `name`, `criterion_type`, `weight`, `description`) VALUES
('C1', 'Harga Lisensi', 'cost', 0.3000, 'Biaya lisensi bulanan per pengguna; harga tahunan dikonversi ke ekuivalen bulanan. Biaya tambahan terpisah dan tidak otomatis dijumlahkan.'),
('C2', 'Kualitas/Spesifikasi Produk', 'benefit', 0.2000, 'Skala 1-5 berdasarkan spesifikasi, kompatibilitas, performa, keamanan, storage, dan kesesuaian kebutuhan dengan bukti.'),
('C3', 'Fitur/Benefit yang Ditawarkan', 'benefit', 0.1500, 'Skala 1-5 berdasarkan kelengkapan fitur, AI, automation, meeting, storage, upgrade, training, dan benefit tambahan.'),
('C4', 'Dukungan Teknis/Support', 'benefit', 0.1500, 'Skala 1-5 berdasarkan jenis/jam support, SLA, respon, media, remote support, dan bantuan teknis.'),
('C5', 'Garansi/Jaminan Layanan', 'benefit', 0.1000, 'Skala 1-5 berdasarkan jenis, durasi, cakupan, SLA, dan ketentuan tertulis.'),
('C6', 'Kemudahan Implementasi', 'benefit', 0.1000, 'Skala 1-5 berdasarkan instalasi, konfigurasi, migrasi, estimasi waktu, training, dan pendampingan.');

INSERT IGNORE INTO `criterion_rubric_levels` (`criterion_id`, `score`, `level_label`, `guidance`)
SELECT ec.`id`, rubric.`score`, rubric.`level_label`, rubric.`guidance`
FROM `evaluation_criteria` ec
INNER JOIN (
  SELECT 'C2' AS criterion_code, 1 AS score, 'Sangat Rendah' AS level_label, 'Sangat tidak sesuai kebutuhan; spesifikasi wajib, keamanan, atau kompatibilitas utama tidak terpenuhi.' AS guidance UNION ALL
  SELECT 'C2', 2, 'Rendah', 'Kurang sesuai; banyak kekurangan pada spesifikasi, performa, keamanan, atau storage.' UNION ALL
  SELECT 'C2', 3, 'Cukup', 'Cukup sesuai kebutuhan minimum dan bukti spesifikasi dasar tersedia.' UNION ALL
  SELECT 'C2', 4, 'Baik', 'Sesuai kebutuhan, kompatibel, performa dan keamanan baik dengan bukti.' UNION ALL
  SELECT 'C2', 5, 'Sangat Baik', 'Sangat sesuai dan memiliki spesifikasi/keunggulan tambahan yang terbukti.' UNION ALL
  SELECT 'C3', 1, 'Sangat Rendah', 'Fitur sangat terbatas dan tidak ada benefit tambahan relevan.' UNION ALL
  SELECT 'C3', 2, 'Rendah', 'Fitur terbatas; sebagian besar kebutuhan tambahan tidak tersedia.' UNION ALL
  SELECT 'C3', 3, 'Cukup', 'Fitur utama cukup untuk kebutuhan dasar.' UNION ALL
  SELECT 'C3', 4, 'Baik', 'Fitur lengkap dan beberapa benefit tambahan tersedia.' UNION ALL
  SELECT 'C3', 5, 'Sangat Baik', 'Fitur sangat lengkap dengan benefit tambahan signifikan yang dibuktikan.' UNION ALL
  SELECT 'C4', 1, 'Tidak tersedia', 'Tidak ada dukungan teknis yang dijanjikan.' UNION ALL
  SELECT 'C4', 2, 'Terbatas', 'Dukungan berdasarkan upaya terbaik, tanpa SLA dan tanpa jam layanan yang jelas.' UNION ALL
  SELECT 'C4', 3, 'Jam kerja', 'Dukungan tersedia pada jam kerja dengan kanal dan target respons tertulis.' UNION ALL
  SELECT 'C4', 4, 'Baik / SLA', 'Dukungan baik dengan SLA dan kanal eskalasi tersedia.' UNION ALL
  SELECT 'C4', 5, 'Sangat Baik', 'Dukungan 24/7, SLA terukur, dan technical assistance.' UNION ALL
  SELECT 'C5', 1, 'Sangat Rendah', 'Tidak ada garansi atau jaminan layanan.' UNION ALL
  SELECT 'C5', 2, 'Rendah', 'Garansi atau jaminan sangat terbatas.' UNION ALL
  SELECT 'C5', 3, 'Cukup', 'Garansi standar dengan cakupan dasar.' UNION ALL
  SELECT 'C5', 4, 'Baik', 'Garansi baik dengan durasi dan cakupan tertulis.' UNION ALL
  SELECT 'C5', 5, 'Sangat Baik', 'Jaminan sangat baik, cakupan dan SLA jelas.' UNION ALL
  SELECT 'C6', 1, 'Sangat Rendah', 'Tidak ada bantuan implementasi.' UNION ALL
  SELECT 'C6', 2, 'Rendah', 'Bantuan implementasi terbatas.' UNION ALL
  SELECT 'C6', 3, 'Cukup', 'Bantuan implementasi standar.' UNION ALL
  SELECT 'C6', 4, 'Baik', 'Instalasi, konfigurasi, atau migrasi tersedia.' UNION ALL
  SELECT 'C6', 5, 'Sangat Baik', 'Implementasi lengkap meliputi migrasi, training, dan pendampingan.'
) AS rubric ON rubric.`criterion_code` = ec.`criterion_code`;

INSERT IGNORE INTO `users` (`id`, `username`, `password_hash`, `role`, `full_name`, `email`) VALUES
(1, 'admin', '$2y$10$3k6W4X3e9i8tK1aR8hUtW.6xLQBrEq2Zc2s8aI4m77s5GgA8dbvO', 'admin', 'Administrator', 'admin@angkasa-pura.co.id'),
(2, 'manager', '$2y$10$h5xvBhSI8BsI2lS1T7bY6.9m5R2Wh0PzS1PZxS0hEfY/Wq2yQEuES', 'pimpinan', 'Manager IT', 'manager@angkasa-pura.co.id');

INSERT IGNORE INTO `vendors` (`id`, `name`, `contact`, `category`, `notes`) VALUES
(1, 'Microsoft', 'Sales Team', 'Cloud', 'Vendor lisensi Office/Teams'),
(2, 'Oracle', 'Enterprise Sales', 'Database', 'Vendor database dan middleware');

INSERT IGNORE INTO `license_prices` (`id`, `vendor_id`, `license_name`, `price_per_user`, `billing_cycle`, `notes`) VALUES
(1, 1, 'Microsoft 365 User License', 150000.00, 'monthly', 'Harga per pengguna per bulan'),
(2, 2, 'Oracle Database License', 220000.00, 'monthly', 'Harga per pengguna per bulan');

INSERT IGNORE INTO `comparison_results` (`id`, `vendor_id`, `user_count`, `monthly_total`, `annual_total`) VALUES
(1, 1, 50, 7500000.00, 90000000.00),
(2, 2, 50, 11000000.00, 132000000.00);

COMMIT;
