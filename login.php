<?php
require 'includes/auth.php';
require 'config/db.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $stmt = $conn->prepare('SELECT id, username, password_hash, role, vendor_id, is_active FROM users WHERE username = ? LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
      if ((int)($user['is_active'] ?? 1) !== 1) {
        $message = 'Akun tidak aktif. Hubungi administrator.';
      } else {
        $userRole = normalizeRole((string)$user['role']);
        if (!in_array($userRole, ['vendor', 'admin', 'pimpinan'], true)) {
          $message = 'Role akun tidak valid. Hubungi administrator.';
        } elseif ($userRole === 'vendor' && (int)($user['vendor_id'] ?? 0) < 1) {
          $message = 'Akun vendor belum terhubung dengan data vendor. Hubungi administrator.';
        } else {
          session_regenerate_id(true);
          $_SESSION['user_id'] = (int)$user['id'];
          $_SESSION['username'] = $user['username'];
          $_SESSION['role'] = $userRole;
          unset($_SESSION['vendor_id']);

          if ($userRole === 'vendor') {
            $_SESSION['vendor_id'] = $user['vendor_id'] !== null ? (int)$user['vendor_id'] : null;
          }
          header('Location: dashboard.php');
          exit;
        }
      }
    }

    if ($message === '') {
      $message = 'Username atau password salah.';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - Vendor Budget System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    :root {
      --primary: #0d6efd;
      --navy: #0f172a;
      --soft: #f4f7fb;
      --text: #1e293b;
    }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: 'Poppins', sans-serif;
      background:
        radial-gradient(circle at top left, rgba(255,255,255,0.85), transparent 35%),
        linear-gradient(135deg, #dbeafe 0%, #eff6ff 42%, #ffffff 100%);
      position: relative;
      overflow: hidden;
    }

    body::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image:
        linear-gradient(rgba(13, 110, 253, 0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(13, 110, 253, 0.04) 1px, transparent 1px);
      background-size: 48px 48px;
      opacity: 0.6;
      pointer-events: none;
    }

    .login-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      position: relative;
      z-index: 1;
    }

    .login-card {
      width: 100%;
      max-width: 500px;
      border: 0;
      border-radius: 20px;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.15);
      background: rgba(255,255,255,0.97);
      padding: 40px;
      animation: fadeInUp 0.8s ease both;
    }

    .brand-logo {
      width: 100px;
      height: 100px;
      object-fit: contain;
      display: block;
      margin: 0 auto 16px;
      border-radius: 18px;
      padding: 10px;
      background: #f8fbff;
      box-shadow: 0 10px 24px rgba(13,110,253,0.12);
    }

    .company-title {
      font-size: 1.55rem;
      font-weight: 700;
      color: var(--primary);
      text-align: center;
      margin-bottom: 0.2rem;
    }

    .company-subtitle {
      font-size: 0.97rem;
      color: #6b7280;
      text-align: center;
      margin-bottom: 1rem;
    }

    .company-description {
      font-size: 0.84rem;
      color: #6b7280;
      text-align: center;
      line-height: 1.7;
      margin-bottom: 1.4rem;
    }

    .form-control {
      border-radius: 14px;
      border: 1px solid #d9e5ff;
      padding: 0.85rem 0.95rem 0.85rem 2.9rem;
      background: #fbfdff;
      transition: all 0.2s ease;
    }

    .form-control:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 0.2rem rgba(13,110,253,0.15);
    }

    .input-group-text {
      background: transparent;
      color: #6c757d;
      border: 1px solid #d9e5ff;
      border-right: 0;
      border-radius: 14px 0 0 14px;
    }

    .input-shell {
      position: relative;
      margin-bottom: 1rem;
    }

    .input-shell .form-control {
      padding-left: 2.9rem;
    }

    .input-shell i {
      position: absolute;
      left: 0.95rem;
      top: 50%;
      transform: translateY(-50%);
      color: #6b7280;
      z-index: 2;
    }

    .password-toggle {
      position: absolute;
      right: 0.95rem;
      top: 50%;
      transform: translateY(-50%);
      border: 0;
      background: transparent;
      color: #6b7280;
      z-index: 3;
    }

    .btn-login {
      width: 100%;
      height: 50px;
      border-radius: 12px;
      background: linear-gradient(135deg, #0d6efd, #2563eb);
      border: 0;
      box-shadow: 0 12px 24px rgba(13,110,253,0.24);
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      font-weight: 600;
    }

    .btn-login:hover {
      transform: translateY(-1px);
      box-shadow: 0 15px 28px rgba(13,110,253,0.32);
    }

    .helper-text {
      font-size: 0.8rem;
      color: #6b7280;
      text-align: center;
      margin-top: 1rem;
      line-height: 1.6;
    }

    .footer-note {
      text-align: center;
      margin-top: 1.6rem;
      padding-top: 1rem;
      border-top: 1px solid #e5ebf5;
      color: #6b7280;
      font-size: 0.78rem;
      line-height: 1.7;
    }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(16px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 575.98px) {
      .login-card {
        padding: 24px;
      }

      .company-title {
        font-size: 1.24rem;
      }
    }
  </style>
</head>
<body>
  <div class="login-wrapper">
    <div class="login-card">
      <div class="text-center mb-3">
        <img src="assets/logo-angkasa.jpeg" alt="Logo PT Angkasa Pura Aviasi" class="brand-logo">
      </div>

      <div class="company-title">PT Angkasa Pura Aviasi</div>
      <div class="company-subtitle">Sistem Perbandingan Vendor IT</div>
      <div class="company-description">
        PT Angkasa Pura Aviasi merupakan perusahaan pengelola Bandara Internasional Kualanamu yang berkomitmen menghadirkan layanan berstandar internasional melalui inovasi, transformasi digital, dan pengelolaan operasional yang berkelanjutan.
      </div>

      <?php if ($message): ?><div class="alert alert-danger rounded-4"><?= htmlspecialchars($message) ?></div><?php endif; ?>

      <form method="post" action="login.php">
        <div class="input-shell">
          <i class="fa-solid fa-user"></i>
          <input type="text" name="username" class="form-control" placeholder="Masukkan Username" required>
        </div>

        <div class="input-shell">
          <i class="fa-solid fa-lock"></i>
          <input type="password" name="password" id="passwordInput" class="form-control" placeholder="Masukkan Password" required>
          <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password">
            <i class="fa-regular fa-eye"></i>
          </button>
        </div>

        <button class="btn btn-login text-white mb-2">
          <i class="fa-solid fa-right-to-bracket me-2"></i>Masuk ke Dashboard
        </button>

        <div class="helper-text">
          Silakan masuk menggunakan akun yang telah diberikan oleh administrator untuk mengakses Sistem Perbandingan Vendor IT.
        </div>
      </form>

      <div class="footer-note">
        © 2026 PT Angkasa Pura Aviasi<br>
        Vendor Budget System<br>
        All Rights Reserved
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('passwordInput');

    togglePassword.addEventListener('click', function () {
      const isHidden = passwordInput.type === 'password';
      passwordInput.type = isHidden ? 'text' : 'password';
      togglePassword.innerHTML = isHidden
        ? '<i class="fa-regular fa-eye-slash"></i>'
        : '<i class="fa-regular fa-eye"></i>';
    });
  </script>
</body>
</html>
