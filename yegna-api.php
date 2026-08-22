<?php
/**
 * Yegna API — Single-file PHP backend
 * Mirrors all routes from the Node.js/Express backend
 * Compatible with: PHP 8.x + PDO MySQL
 */

// ── CORS ──────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── CONFIG ────────────────────────────────────────────────────────────────────
define('DB_HOST', 'mysql-db02.remote');
define('DB_PORT', 32636);
define('DB_NAME', 'yegna');
define('DB_USER', 'yegna_user');
define('DB_PASS', 'xo235Kp4&');
define('JWT_SECRET', 'yegna_jwt_super_secret_2026');
define('EMAIL_FROM', 'yegnaapp@gmail.com');
define('EMAIL_PASS', 'ubaj ojjz ysyq ephd');

// ── DATABASE ──────────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    try {
        // Use same DSN format as VerifyPay which works on this server
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 10,
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
        exit();
    }
    return $pdo;
}

// ── REQUEST HELPERS ───────────────────────────────────────────────────────────
function body(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

function method(): string {
    return $_SERVER['REQUEST_METHOD'];
}

// ── JWT ───────────────────────────────────────────────────────────────────────
function jwtEncode(array $payload, int $expirySecs = 2592000): string {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + $expirySecs;
    $body    = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64url_decode($body), true);
    if (!$payload || $payload['exp'] < time()) return null;
    return $payload;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

function generateToken(int $userId): string {
    return jwtEncode(['id' => $userId]);
}

function generateShortToken(array $payload, int $secs = 600): string {
    return jwtEncode($payload, $secs);
}

// ── AUTH MIDDLEWARE ───────────────────────────────────────────────────────────
function requireAuth(): int {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = $headers['Authorization'] ?? '';
    }
    if (!str_starts_with($auth, 'Bearer ')) {
        respond(['success' => false, 'message' => 'Authentication required.'], 401);
    }
    $token   = substr($auth, 7);
    $payload = jwtDecode($token);
    if (!$payload || empty($payload['id'])) {
        respond(['success' => false, 'message' => 'Invalid or expired token.'], 401);
    }
    return (int)$payload['id'];
}

function optionalAuth(): ?int {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = $headers['Authorization'] ?? '';
    }
    if (!str_starts_with($auth, 'Bearer ')) return null;
    $token   = substr($auth, 7);
    $payload = jwtDecode($token);
    return ($payload && !empty($payload['id'])) ? (int)$payload['id'] : null;
}

// ── EMAIL (OTP) ───────────────────────────────────────────────────────────────
function generateOTP(): string {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

function saveOTP(string $email, string $code, string $type = 'verify'): void {
    $pdo = db();
    $pdo->prepare('DELETE FROM otp_codes WHERE email = ? AND type = ?')->execute([$email, $type]);
    $expires = date('Y-m-d H:i:s', time() + 900);
    $pdo->prepare('INSERT INTO otp_codes (email, code, type, expires_at) VALUES (?, ?, ?, ?)')->execute([$email, $code, $type, $expires]);
}

function verifyOTP(string $email, string $code, string $type = 'verify'): bool {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT * FROM otp_codes WHERE email=? AND code=? AND type=? AND used=0 AND expires_at > NOW()');
    $stmt->execute([$email, $code, $type]);
    $row = $stmt->fetch();
    if (!$row) return false;
    $pdo->prepare('UPDATE otp_codes SET used=1 WHERE id=?')->execute([$row['id']]);
    return true;
}

function sendEmail(string $to, string $subject, string $html): bool {
    $from    = EMAIL_FROM;
    $pass    = EMAIL_PASS;
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: Yegna App <$from>\r\n";
    // Use PHP mail() — works on most shared hosting
    return mail($to, $subject, $html, $headers);
}

function sendVerificationEmail(string $email, string $name): void {
    $code = generateOTP();
    saveOTP($email, $code, 'verify');
    $year = date('Y');
    $html = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto">
  <div style="background:#FE4A49;padding:32px;text-align:center;border-radius:12px 12px 0 0">
    <h1 style="color:white;margin:0;font-size:28px">Yegna</h1>
    <p style="color:rgba(255,255,255,0.85);margin:8px 0 0">Discover the best of Ethiopia</p>
  </div>
  <div style="background:#fff;padding:32px;border-radius:0 0 12px 12px;border:1px solid #e5e7eb">
    <h2 style="color:#111827;margin:0 0 8px">Hi $name,</h2>
    <p style="color:#6b7280">Use the code below to verify your email. It expires in 15 minutes.</p>
    <div style="background:#f9fafb;border:2px dashed #FE4A49;border-radius:12px;padding:24px;text-align:center;margin:24px 0">
      <span style="font-size:42px;font-weight:800;letter-spacing:12px;color:#FE4A49">$code</span>
    </div>
    <p style="color:#9ca3af;font-size:13px">If you didn't request this, ignore this email.</p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">
    <p style="color:#9ca3af;font-size:12px;text-align:center">&copy; $year Yegna &middot; Addis Ababa, Ethiopia</p>
  </div>
</div>
HTML;
    sendEmail($email, "$code — Your Yegna verification code", $html);
}

function sendPasswordResetEmail(string $email, string $name): void {
    $code = generateOTP();
    saveOTP($email, $code, 'reset');
    $year = date('Y');
    $html = <<<HTML
<div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto">
  <div style="background:#FE4A49;padding:32px;text-align:center;border-radius:12px 12px 0 0">
    <h1 style="color:white;margin:0;font-size:28px">Yegna</h1>
  </div>
  <div style="background:#fff;padding:32px;border-radius:0 0 12px 12px;border:1px solid #e5e7eb">
    <h2 style="color:#111827;margin:0 0 8px">Password Reset</h2>
    <p style="color:#6b7280">Hi $name, use the code below to reset your password. Expires in 15 minutes.</p>
    <div style="background:#fff5f5;border:2px dashed #FE4A49;border-radius:12px;padding:24px;text-align:center;margin:24px 0">
      <span style="font-size:42px;font-weight:800;letter-spacing:12px;color:#FE4A49">$code</span>
    </div>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0">
    <p style="color:#9ca3af;font-size:12px;text-align:center">&copy; $year Yegna &middot; Addis Ababa, Ethiopia</p>
  </div>
</div>
HTML;
    sendEmail($email, "$code — Reset your Yegna password", $html);
}

// ── USER HELPERS ──────────────────────────────────────────────────────────────
function findUserByEmail(string $email): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE email=?');
    $s->execute([$email]);
    return $s->fetch() ?: null;
}

function findUserByUsername(string $username): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE username=?');
    $s->execute([$username]);
    return $s->fetch() ?: null;
}

function findUserById(int $id): ?array {
    $s = db()->prepare('SELECT id,name,username,email,phone,bio,avatar_url,role,points,level,is_verified,email_verified,birth_date,google_id,created_at FROM users WHERE id=?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function findUserByGoogleId(string $googleId): ?array {
    $s = db()->prepare('SELECT * FROM users WHERE google_id=?');
    $s->execute([$googleId]);
    return $s->fetch() ?: null;
}

function usernameExists(string $username): bool {
    $s = db()->prepare('SELECT id FROM users WHERE username=?');
    $s->execute([$username]);
    return (bool)$s->fetch();
}

function validateUsername(string $username): ?string {
    if (strlen($username) < 3 || strlen($username) > 30) return 'Username must be 3–30 characters.';
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $username)) return 'Username can only contain letters, numbers, _ and .';
    return null;
}

function createUser(array $data): int {
    $hash = null;
    if (!empty($data['password'])) {
        $hash = password_hash($data['password'], PASSWORD_BCRYPT);
    }
    $s = db()->prepare('INSERT INTO users (name,username,email,password_hash,phone,birth_date,google_id,avatar_url,email_verified,role) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $s->execute([
        $data['name'],
        $data['username'] ?? null,
        $data['email'],
        $hash,
        $data['phone'] ?? null,
        $data['birth_date'] ?? null,
        $data['google_id'] ?? null,
        $data['avatar_url'] ?? null,
        $data['email_verified'] ?? 0,
        $data['role'] ?? 'user',
    ]);
    return (int)db()->lastInsertId();
}

// ── ROUTE PARSING ─────────────────────────────────────────────────────────────
$uri     = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri     = preg_replace('#^/yegna-api\.php#', '', $uri);
$uri     = '/' . trim($uri, '/');
$parts   = explode('/', trim($uri, '/'));
$section = $parts[0] ?? ''; // e.g. 'api'
$group   = $parts[1] ?? ''; // e.g. 'auth'
$action  = $parts[2] ?? ''; // e.g. 'login'
$param   = $parts[3] ?? ''; // e.g. review id

// ── HEALTH ────────────────────────────────────────────────────────────────────
if ($uri === '/api/health' || $uri === '/health') {
    // Quick DB ping
    $dbOk = false;
    $dbMsg = '';
    try { db(); $dbOk = true; } catch (Exception $e) { $dbMsg = $e->getMessage(); }
    respond(['status' => 'OK', 'timestamp' => date('c'), 'version' => '2.0.0', 'environment' => 'production', 'db' => $dbOk ? 'connected' : 'failed: ' . $dbMsg]);
}

if ($uri === '/' || $uri === '') {
    respond(['message' => 'Yegna API is running!', 'version' => '2.0.0']);
}

// ── AUTH ROUTES ───────────────────────────────────────────────────────────────
if ($group === 'auth') {

    // POST /api/auth/send-otp
    if ($action === 'send-otp' && method() === 'POST') {
        $b = body();
        $email = trim($b['email'] ?? '');
        $name  = trim($b['name'] ?? 'there');
        if (!$email) respond(['success' => false, 'message' => 'Email is required.'], 400);
        $existing = findUserByEmail($email);
        if ($existing && $existing['email_verified']) {
            respond(['success' => false, 'message' => 'An account with this email already exists. Please sign in.'], 400);
        }
        sendVerificationEmail($email, $name);
        respond(['success' => true, 'message' => 'Verification code sent to your email.']);
    }

    // POST /api/auth/verify-otp
    if ($action === 'verify-otp' && method() === 'POST') {
        $b    = body();
        $email = trim($b['email'] ?? '');
        $code  = trim($b['code'] ?? '');
        if (!$email || !$code) respond(['success' => false, 'message' => 'Email and code are required.'], 400);
        if (!verifyOTP($email, $code, 'verify')) {
            respond(['success' => false, 'message' => 'Invalid or expired code. Please request a new one.'], 400);
        }
        respond(['success' => true, 'message' => 'Email verified.']);
    }

    // GET /api/auth/check-username
    if ($action === 'check-username' && method() === 'GET') {
        $username = trim($_GET['username'] ?? '');
        $err = validateUsername($username);
        if ($err) respond(['success' => false, 'available' => false, 'message' => $err], 400);
        $taken = usernameExists($username);
        respond(['success' => true, 'available' => !$taken, 'message' => $taken ? 'Username is already taken.' : 'Username is available.']);
    }

    // POST /api/auth/register
    if ($action === 'register' && method() === 'POST') {
        $b          = body();
        $name       = trim($b['name'] ?? '');
        $email      = trim($b['email'] ?? '');
        $username   = trim($b['username'] ?? '');
        $password   = $b['password'] ?? '';
        $birth_date = $b['birth_date'] ?? null;

        if (!$name || !$email || !$username || !$password) {
            respond(['success' => false, 'message' => 'Name, email, username and password are required.'], 400);
        }
        $uErr = validateUsername($username);
        if ($uErr) respond(['success' => false, 'message' => $uErr], 400);
        if (strlen($password) < 6) respond(['success' => false, 'message' => 'Password must be at least 6 characters.'], 400);

        if (findUserByEmail($email)) respond(['success' => false, 'message' => 'An account with this email already exists.'], 400);
        if (usernameExists($username)) respond(['success' => false, 'message' => 'Username is already taken. Please choose another.'], 400);

        $userId = createUser(['name' => $name, 'email' => $email, 'username' => $username, 'password' => $password, 'birth_date' => $birth_date, 'email_verified' => 1]);
        $token  = generateToken($userId);
        $user   = findUserById($userId);
        respond(['success' => true, 'message' => 'Account created!', 'token' => $token, 'user' => $user], 201);
    }

    // POST /api/auth/login
    if ($action === 'login' && method() === 'POST') {
        $b          = body();
        $identifier = trim($b['identifier'] ?? '');
        $password   = $b['password'] ?? '';
        if (!$identifier || !$password) respond(['success' => false, 'message' => 'Username/email and password are required.'], 400);

        $user = str_contains($identifier, '@') ? findUserByEmail($identifier) : findUserByUsername($identifier);
        if (!$user) respond(['success' => false, 'message' => 'No account found with that username or email.'], 401);
        if (empty($user['password_hash'])) respond(['success' => false, 'message' => 'This account uses Google sign-in.'], 401);
        if (!password_verify($password, $user['password_hash'])) respond(['success' => false, 'message' => 'Incorrect password. Please try again.'], 401);

        $token    = generateToken($user['id']);
        $userData = findUserById($user['id']);
        respond(['success' => true, 'message' => 'Signed in.', 'token' => $token, 'user' => $userData]);
    }

    // POST /api/auth/forgot-password
    if ($action === 'forgot-password' && method() === 'POST') {
        $b     = body();
        $email = trim($b['email'] ?? '');
        if (!$email) respond(['success' => false, 'message' => 'Email is required.'], 400);
        $user = findUserByEmail($email);
        if (!$user) respond(['success' => true, 'message' => 'If an account exists, a reset code has been sent.']);
        if (empty($user['password_hash'])) respond(['success' => false, 'message' => 'This account uses Google sign-in.'], 400);
        sendPasswordResetEmail($email, $user['name']);
        respond(['success' => true, 'message' => 'Password reset code sent to your email.']);
    }

    // POST /api/auth/verify-reset-otp
    if ($action === 'verify-reset-otp' && method() === 'POST') {
        $b     = body();
        $email = trim($b['email'] ?? '');
        $code  = trim($b['code'] ?? '');
        if (!$email || !$code) respond(['success' => false, 'message' => 'Email and code required.'], 400);
        if (!verifyOTP($email, $code, 'reset')) respond(['success' => false, 'message' => 'Invalid or expired code.'], 400);
        $resetToken = generateShortToken(['email' => $email, 'type' => 'reset'], 600);
        respond(['success' => true, 'resetToken' => $resetToken]);
    }

    // POST /api/auth/reset-password
    if ($action === 'reset-password' && method() === 'POST') {
        $b           = body();
        $resetToken  = $b['resetToken'] ?? '';
        $newPassword = $b['newPassword'] ?? '';
        if (!$resetToken || !$newPassword) respond(['success' => false, 'message' => 'Reset token and new password required.'], 400);
        if (strlen($newPassword) < 6) respond(['success' => false, 'message' => 'Password must be at least 6 characters.'], 400);
        $payload = jwtDecode($resetToken);
        if (!$payload || ($payload['type'] ?? '') !== 'reset') respond(['success' => false, 'message' => 'Reset token expired or invalid.'], 400);
        $user = findUserByEmail($payload['email']);
        if (!$user) respond(['success' => false, 'message' => 'User not found.'], 404);
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $user['id']]);
        respond(['success' => true, 'message' => 'Password reset successfully. You can now sign in.']);
    }

    // GET /api/auth/me
    if ($action === 'me' && method() === 'GET') {
        $userId = requireAuth();
        $user   = findUserById($userId);
        if (!$user) respond(['success' => false, 'message' => 'User not found.'], 404);
        respond(['success' => true, 'user' => $user]);
    }
}

// ── BUSINESS ROUTES ───────────────────────────────────────────────────────────
if ($group === 'businesses') {
    $userId = optionalAuth();

    // GET /api/businesses/trending
    if ($action === 'trending' && method() === 'GET') {
        $s = db()->prepare('SELECT * FROM businesses WHERE is_active=1 ORDER BY review_count DESC, rating DESC LIMIT 10');
        $s->execute();
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // GET /api/businesses/top-rated
    if ($action === 'top-rated' && method() === 'GET') {
        $s = db()->prepare('SELECT * FROM businesses WHERE is_active=1 AND review_count>0 ORDER BY rating DESC, review_count DESC LIMIT 10');
        $s->execute();
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // GET /api/businesses/recently-added
    if ($action === 'recently-added' && method() === 'GET') {
        $s = db()->prepare('SELECT * FROM businesses WHERE is_active=1 ORDER BY created_at DESC LIMIT 10');
        $s->execute();
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // GET /api/businesses/categories
    if ($action === 'categories' && method() === 'GET') {
        $s = db()->query('SELECT * FROM categories ORDER BY name');
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // GET /api/businesses/nearby
    if ($action === 'nearby' && method() === 'GET') {
        $lat    = (float)($_GET['lat'] ?? 0);
        $lng    = (float)($_GET['lng'] ?? 0);
        $radius = (float)($_GET['radius'] ?? 10);
        if (!$lat || !$lng) respond(['success' => false, 'message' => 'Latitude and longitude are required.'], 400);
        $s = db()->prepare(
            'SELECT *, ( 6371 * acos( cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)) ) ) AS distance
             FROM businesses WHERE is_active=1 HAVING distance < ? ORDER BY distance LIMIT 30'
        );
        $s->execute([$lat, $lng, $lat, $radius]);
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // POST /api/businesses/favorite
    if ($action === 'favorite' && method() === 'POST') {
        $userId = requireAuth();
        $b      = body();
        $bizId  = (int)($b['businessId'] ?? 0);
        if (!$bizId) respond(['success' => false, 'message' => 'Business ID is required.'], 400);
        $existing = db()->prepare('SELECT id FROM favorites WHERE user_id=? AND business_id=?');
        $existing->execute([$userId, $bizId]);
        if ($existing->fetch()) {
            db()->prepare('DELETE FROM favorites WHERE user_id=? AND business_id=?')->execute([$userId, $bizId]);
            respond(['success' => true, 'data' => ['isFavorite' => false]]);
        } else {
            db()->prepare('INSERT INTO favorites (user_id,business_id) VALUES (?,?)')->execute([$userId, $bizId]);
            respond(['success' => true, 'data' => ['isFavorite' => true]]);
        }
    }

    // GET /api/businesses/search
    if ($action === 'search' && method() === 'GET') {
        $q         = '%' . trim($_GET['q'] ?? '') . '%';
        $category  = $_GET['category'] ?? null;
        $city      = $_GET['city'] ?? null;
        $minRating = (float)($_GET['minRating'] ?? 0);
        $limit     = (int)($_GET['limit'] ?? 30);
        $offset    = (int)($_GET['offset'] ?? 0);

        $sql    = 'SELECT * FROM businesses WHERE is_active=1 AND (name LIKE ? OR description LIKE ? OR category LIKE ?)';
        $params = [$q, $q, $q];
        if ($category) { $sql .= ' AND category=?'; $params[] = $category; }
        if ($city)     { $sql .= ' AND city=?';     $params[] = $city; }
        if ($minRating) { $sql .= ' AND rating>=?'; $params[] = $minRating; }
        $sql .= ' ORDER BY rating DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $s = db()->prepare($sql);
        $s->execute($params);
        $results = $s->fetchAll();
        respond(['success' => true, 'data' => $results, 'count' => count($results)]);
    }

    // GET /api/businesses/:id/reviews
    if ($param === 'reviews' && method() === 'GET') {
        $bizId  = (int)$action;
        $limit  = (int)($_GET['limit'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);
        $s = db()->prepare(
            'SELECT r.*, u.name as user_name, u.avatar_url FROM reviews r
             JOIN users u ON r.user_id=u.id WHERE r.business_id=?
             ORDER BY r.created_at DESC LIMIT ? OFFSET ?'
        );
        $s->execute([$bizId, $limit, $offset]);
        $total = db()->prepare('SELECT COUNT(*) as c FROM reviews WHERE business_id=?');
        $total->execute([$bizId]);
        respond(['success' => true, 'data' => $s->fetchAll(), 'total' => $total->fetch()['c']]);
    }

    // POST /api/businesses/:id/reviews
    if ($param === 'reviews' && method() === 'POST') {
        $userId = requireAuth();
        $bizId  = (int)$action;
        $b      = body();
        $rating  = (int)($b['rating'] ?? 0);
        $content = trim($b['content'] ?? '');
        $title   = trim($b['title'] ?? '');
        if (!$rating || !$content) respond(['success' => false, 'message' => 'Rating and content are required.'], 400);
        if ($rating < 1 || $rating > 5) respond(['success' => false, 'message' => 'Rating must be between 1 and 5.'], 400);
        $check = db()->prepare('SELECT id FROM reviews WHERE business_id=? AND user_id=?');
        $check->execute([$bizId, $userId]);
        if ($check->fetch()) respond(['success' => false, 'message' => 'You have already reviewed this business.'], 400);
        $s = db()->prepare('INSERT INTO reviews (business_id,user_id,rating,title,content) VALUES (?,?,?,?,?)');
        $s->execute([$bizId, $userId, $rating, $title ?: null, $content]);
        $reviewId = (int)db()->lastInsertId();
        // Update business rating
        $avg = db()->prepare('SELECT AVG(rating) as avg, COUNT(*) as cnt FROM reviews WHERE business_id=?');
        $avg->execute([$bizId]);
        $r = $avg->fetch();
        db()->prepare('UPDATE businesses SET rating=?, review_count=? WHERE id=?')->execute([round($r['avg'], 2), $r['cnt'], $bizId]);
        // Award points
        db()->prepare('UPDATE users SET points=points+10 WHERE id=?')->execute([$userId]);
        respond(['success' => true, 'message' => 'Review added successfully.', 'data' => ['id' => $reviewId]], 201);
    }

    // GET /api/businesses (list with filters)
    if ($action === '' && method() === 'GET') {
        $category  = $_GET['category'] ?? null;
        $city      = $_GET['city'] ?? null;
        $search    = $_GET['search'] ?? null;
        $minRating = (float)($_GET['minRating'] ?? 0);
        $limit     = (int)($_GET['limit'] ?? 20);
        $offset    = (int)($_GET['offset'] ?? 0);

        $sql    = 'SELECT * FROM businesses WHERE is_active=1';
        $params = [];
        if ($category)  { $sql .= ' AND category=?'; $params[] = $category; }
        if ($city)      { $sql .= ' AND city=?';     $params[] = $city; }
        if ($search)    { $sql .= ' AND (name LIKE ? OR description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($minRating) { $sql .= ' AND rating>=?';  $params[] = $minRating; }
        $sql .= ' ORDER BY rating DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        $s = db()->prepare($sql);
        $s->execute($params);
        $results = $s->fetchAll();
        respond(['success' => true, 'data' => $results, 'count' => count($results)]);
    }

    // GET /api/businesses/:id
    if ($action && $param === '' && method() === 'GET' && is_numeric($action)) {
        $bizId = (int)$action;
        $s = db()->prepare('SELECT * FROM businesses WHERE id=? AND is_active=1');
        $s->execute([$bizId]);
        $biz = $s->fetch();
        if (!$biz) respond(['success' => false, 'message' => 'Business not found.'], 404);

        $hours = db()->prepare('SELECT * FROM business_hours WHERE business_id=? ORDER BY FIELD(day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday")');
        $hours->execute([$bizId]);
        $biz['hours'] = $hours->fetchAll();

        $photos = db()->prepare('SELECT * FROM photos WHERE business_id=? ORDER BY is_primary DESC');
        $photos->execute([$bizId]);
        $biz['photos'] = $photos->fetchAll();

        if ($userId) {
            $fav = db()->prepare('SELECT id FROM favorites WHERE user_id=? AND business_id=?');
            $fav->execute([$userId, $bizId]);
            $biz['is_favorite'] = (bool)$fav->fetch();
        } else {
            $biz['is_favorite'] = false;
        }
        respond(['success' => true, 'data' => $biz]);
    }
}

// ── USER ROUTES ───────────────────────────────────────────────────────────────
if ($group === 'user') {
    $userId = requireAuth();

    // GET /api/user/profile
    if ($action === 'profile' && method() === 'GET') {
        $user  = findUserById($userId);
        $favs  = db()->prepare('SELECT b.* FROM businesses b JOIN favorites f ON f.business_id=b.id WHERE f.user_id=? ORDER BY f.created_at DESC');
        $favs->execute([$userId]);
        $revs  = db()->prepare('SELECT r.*, b.name as business_name FROM reviews r JOIN businesses b ON r.business_id=b.id WHERE r.user_id=? ORDER BY r.created_at DESC');
        $revs->execute([$userId]);
        $vis   = db()->prepare('SELECT b.* FROM businesses b JOIN visits v ON v.business_id=b.id WHERE v.user_id=? ORDER BY v.visited_at DESC');
        $vis->execute([$userId]);
        $stats = db()->prepare('SELECT (SELECT COUNT(*) FROM reviews WHERE user_id=?) as reviews, (SELECT COUNT(*) FROM favorites WHERE user_id=?) as favorites, (SELECT COUNT(*) FROM visits WHERE user_id=?) as visits');
        $stats->execute([$userId, $userId, $userId]);
        respond(['success' => true, 'data' => ['user' => $user, 'stats' => $stats->fetch(), 'favorites' => $favs->fetchAll(), 'reviews' => $revs->fetchAll(), 'visited' => $vis->fetchAll()]]);
    }

    // PUT /api/user/profile
    if ($action === 'profile' && in_array(method(), ['PUT', 'PATCH'])) {
        $b      = body();
        $fields = ['name', 'email', 'phone', 'bio', 'avatar_url', 'username', 'birth_date'];
        $sets   = [];
        $vals   = [];
        foreach ($fields as $f) {
            if (isset($b[$f])) { $sets[] = "$f=?"; $vals[] = $b[$f]; }
        }
        if ($sets) {
            $vals[] = $userId;
            db()->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
        }
        respond(['success' => true, 'message' => 'Profile updated.', 'data' => findUserById($userId)]);
    }

    // GET /api/user/favorites
    if ($action === 'favorites' && method() === 'GET') {
        $s = db()->prepare('SELECT b.* FROM businesses b JOIN favorites f ON f.business_id=b.id WHERE f.user_id=? ORDER BY f.created_at DESC');
        $s->execute([$userId]);
        $rows = $s->fetchAll();
        respond(['success' => true, 'data' => $rows, 'count' => count($rows)]);
    }

    // GET /api/user/visited
    if ($action === 'visited' && method() === 'GET') {
        $s = db()->prepare('SELECT b.* FROM businesses b JOIN visits v ON v.business_id=b.id WHERE v.user_id=? ORDER BY v.visited_at DESC');
        $s->execute([$userId]);
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // POST /api/user/visit
    if ($action === 'visit' && method() === 'POST') {
        $b      = body();
        $bizId  = (int)($b['businessId'] ?? 0);
        if (!$bizId) respond(['success' => false, 'message' => 'Business ID is required.'], 400);
        try {
            db()->prepare('INSERT INTO visits (user_id,business_id) VALUES (?,?)')->execute([$userId, $bizId]);
            respond(['success' => true, 'message' => 'Check-in recorded!']);
        } catch (PDOException $e) {
            respond(['success' => true, 'message' => 'Already checked in here.', 'already_visited' => true]);
        }
    }

    // GET /api/user/reviews
    if ($action === 'reviews' && method() === 'GET') {
        $s = db()->prepare('SELECT r.*, b.name as business_name FROM reviews r JOIN businesses b ON r.business_id=b.id WHERE r.user_id=? ORDER BY r.created_at DESC');
        $s->execute([$userId]);
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // GET /api/user/stats
    if ($action === 'stats' && method() === 'GET') {
        $s = db()->prepare('SELECT (SELECT COUNT(*) FROM reviews WHERE user_id=?) as reviews, (SELECT COUNT(*) FROM favorites WHERE user_id=?) as favorites, (SELECT COUNT(*) FROM visits WHERE user_id=?) as visits');
        $s->execute([$userId, $userId, $userId]);
        respond(['success' => true, 'data' => $s->fetch()]);
    }
}

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────
if ($group === 'notifications') {
    $userId = requireAuth();

    // GET /api/notifications
    if ($action === '' && method() === 'GET') {
        $limit  = (int)($_GET['limit'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);
        $s = db()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $s->execute([$userId, $limit, $offset]);
        $unread = db()->prepare('SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0');
        $unread->execute([$userId]);
        respond(['success' => true, 'data' => $s->fetchAll(), 'unreadCount' => $unread->fetch()['c']]);
    }

    // PATCH /api/notifications/:id/read
    if ($action && $param === 'read' && method() === 'PATCH') {
        db()->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$action, $userId]);
        respond(['success' => true, 'message' => 'Notification marked as read.']);
    }

    // PATCH /api/notifications/read-all
    if ($action === 'read-all' && method() === 'PATCH') {
        db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$userId]);
        respond(['success' => true, 'message' => 'All notifications marked as read.']);
    }
}

// ── SEARCH ────────────────────────────────────────────────────────────────────
if ($group === 'search') {
    $q         = '%' . trim($_GET['q'] ?? '') . '%';
    $category  = $_GET['category'] ?? null;
    $city      = $_GET['city'] ?? null;
    $minRating = (float)($_GET['minRating'] ?? 0);
    $limit     = (int)($_GET['limit'] ?? 20);
    $offset    = (int)($_GET['offset'] ?? 0);

    $sql    = 'SELECT * FROM businesses WHERE is_active=1 AND (name LIKE ? OR description LIKE ? OR category LIKE ?)';
    $params = [$q, $q, $q];
    if ($category)  { $sql .= ' AND category=?'; $params[] = $category; }
    if ($city)      { $sql .= ' AND city=?';     $params[] = $city; }
    if ($minRating) { $sql .= ' AND rating>=?';  $params[] = $minRating; }
    $sql .= ' ORDER BY rating DESC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;
    $s = db()->prepare($sql);
    $s->execute($params);
    $results = $s->fetchAll();
    respond(['success' => true, 'data' => $results, 'count' => count($results)]);
}

// ── SOCIAL ROUTES ─────────────────────────────────────────────────────────────
if ($group === 'social') {
    $userId = requireAuth();

    // POST /api/social/follow/:userId
    if ($action === 'follow' && $param && method() === 'POST') {
        $targetId = (int)$param;
        if ($targetId === $userId) respond(['success' => false, 'message' => "You can't follow yourself."], 400);
        db()->prepare('INSERT IGNORE INTO follows (follower_id,following_id) VALUES (?,?)')->execute([$userId, $targetId]);
        respond(['success' => true, 'data' => ['is_following' => true]]);
    }

    // DELETE /api/social/follow/:userId
    if ($action === 'follow' && $param && method() === 'DELETE') {
        $targetId = (int)$param;
        db()->prepare('DELETE FROM follows WHERE follower_id=? AND following_id=?')->execute([$userId, $targetId]);
        respond(['success' => true, 'data' => ['is_following' => false]]);
    }

    // GET /api/social/users/:userId/followers
    if ($action === 'users' && $param && is_numeric($param)) {
        $targetId = (int)$param;
        $sub      = $parts[4] ?? '';
        if ($sub === 'followers') {
            $s = db()->prepare('SELECT u.id,u.name,u.avatar_url,u.bio FROM follows f JOIN users u ON f.follower_id=u.id WHERE f.following_id=? ORDER BY f.created_at DESC');
            $s->execute([$targetId]);
            respond(['success' => true, 'data' => $s->fetchAll()]);
        }
        if ($sub === 'following') {
            $s = db()->prepare('SELECT u.id,u.name,u.avatar_url,u.bio FROM follows f JOIN users u ON f.following_id=u.id WHERE f.follower_id=? ORDER BY f.created_at DESC');
            $s->execute([$targetId]);
            respond(['success' => true, 'data' => $s->fetchAll()]);
        }
        // GET /api/social/users/:userId (public profile)
        $s = db()->prepare('SELECT id,name,avatar_url,bio,level,points,is_verified,created_at FROM users WHERE id=?');
        $s->execute([$targetId]);
        $user = $s->fetch();
        if (!$user) respond(['success' => false, 'message' => 'User not found.'], 404);
        $followers = db()->prepare('SELECT COUNT(*) as c FROM follows WHERE following_id=?'); $followers->execute([$targetId]);
        $following = db()->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=?');  $following->execute([$targetId]);
        $isFollowing = db()->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=? AND following_id=?'); $isFollowing->execute([$userId, $targetId]);
        $reviews = db()->prepare('SELECT r.*,b.name as business_name FROM reviews r JOIN businesses b ON r.business_id=b.id WHERE r.user_id=? ORDER BY r.created_at DESC LIMIT 20'); $reviews->execute([$targetId]);
        respond(['success' => true, 'data' => ['user' => array_merge($user, ['followers' => $followers->fetch()['c'], 'following' => $following->fetch()['c']]), 'is_following' => (bool)$isFollowing->fetch()['c'], 'reviews' => $reviews->fetchAll()]]);
    }

    // GET /api/social/feed
    if ($action === 'feed' && method() === 'GET') {
        $limit  = (int)($_GET['limit'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);
        $s = db()->prepare(
            'SELECT af.*,u.name as user_name,u.avatar_url,b.name as business_name,b.category,b.image_url,b.rating as business_rating
             FROM activity_feed af
             JOIN users u ON af.user_id=u.id
             JOIN businesses b ON af.business_id=b.id
             JOIN follows f ON f.follower_id=? AND f.following_id=af.user_id
             WHERE af.visibility="everyone"
             ORDER BY af.created_at DESC LIMIT ? OFFSET ?'
        );
        $s->execute([$userId, $limit, $offset]);
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // GET /api/social/privacy
    if ($action === 'privacy' && method() === 'GET') {
        $s = db()->prepare('SELECT * FROM user_privacy WHERE user_id=?');
        $s->execute([$userId]);
        $priv = $s->fetch();
        if (!$priv) {
            db()->prepare('INSERT IGNORE INTO user_privacy (user_id) VALUES (?)')->execute([$userId]);
            $priv = ['user_id' => $userId, 'activity_visibility' => 'everyone', 'reviews_visibility' => 'everyone'];
        }
        respond(['success' => true, 'data' => $priv]);
    }

    // PUT /api/social/privacy
    if ($action === 'privacy' && in_array(method(), ['PUT', 'PATCH'])) {
        $b       = body();
        $allowed = ['activity_visibility','reviews_visibility','photos_visibility','visited_visibility','saved_visibility','followers_visibility'];
        $sets    = [];
        $vals    = [];
        foreach ($allowed as $f) {
            if (isset($b[$f])) { $sets[] = "$f=?"; $vals[] = $b[$f]; }
        }
        db()->prepare('INSERT IGNORE INTO user_privacy (user_id) VALUES (?)')->execute([$userId]);
        if ($sets) { $vals[] = $userId; db()->prepare('UPDATE user_privacy SET ' . implode(',', $sets) . ' WHERE user_id=?')->execute($vals); }
        respond(['success' => true, 'message' => 'Privacy updated.']);
    }

    // GET /api/social/search-users
    if ($action === 'search-users' && method() === 'GET') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $s = db()->prepare('SELECT id,name,avatar_url,bio FROM users WHERE name LIKE ? AND id!=? ORDER BY name LIMIT 30');
        $s->execute([$q, $userId]);
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }
}

// ── OWNER ROUTES ──────────────────────────────────────────────────────────────
if ($group === 'owner') {
    $userId = requireAuth();

    // GET /api/owner/businesses
    if ($action === 'businesses' && $param === '' && method() === 'GET') {
        $s = db()->prepare('SELECT * FROM businesses WHERE owner_id=? ORDER BY created_at DESC');
        $s->execute([$userId]);
        respond(['success' => true, 'data' => $s->fetchAll()]);
    }

    // POST /api/owner/businesses
    if ($action === 'businesses' && $param === '' && method() === 'POST') {
        $b = body();
        if (!($b['name'] ?? '') || !($b['category'] ?? '') || !($b['address'] ?? '') || !($b['city'] ?? '')) {
            respond(['success' => false, 'message' => 'Name, category, address and city are required.'], 400);
        }
        $s = db()->prepare('INSERT INTO businesses (name,category,description,address,city,phone,website,price_range,latitude,longitude,owner_id,is_active,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,"pending")');
        $s->execute([$b['name'], $b['category'], $b['description'] ?? null, $b['address'], $b['city'], $b['phone'] ?? null, $b['website'] ?? null, $b['price_range'] ?? null, $b['latitude'] ?? null, $b['longitude'] ?? null, $userId]);
        respond(['success' => true, 'message' => 'Business submitted for review.', 'data' => ['id' => (int)db()->lastInsertId()]], 201);
    }

    // GET /api/owner/businesses/:id
    if ($action === 'businesses' && $param && method() === 'GET') {
        $bizId = (int)$param;
        $s = db()->prepare('SELECT * FROM businesses WHERE id=? AND owner_id=?');
        $s->execute([$bizId, $userId]);
        $biz = $s->fetch();
        if (!$biz) respond(['success' => false, 'message' => 'Business not found.'], 404);
        $hours = db()->prepare('SELECT * FROM business_hours WHERE business_id=?'); $hours->execute([$bizId]);
        $photos = db()->prepare('SELECT * FROM photos WHERE business_id=?'); $photos->execute([$bizId]);
        respond(['success' => true, 'data' => array_merge($biz, ['hours' => $hours->fetchAll(), 'photos' => $photos->fetchAll()])]);
    }

    // PUT /api/owner/businesses/:id
    if ($action === 'businesses' && $param && in_array(method(), ['PUT', 'PATCH'])) {
        $bizId = (int)$param;
        $check = db()->prepare('SELECT id FROM businesses WHERE id=? AND owner_id=?'); $check->execute([$bizId, $userId]);
        if (!$check->fetch()) respond(['success' => false, 'message' => 'Not authorized.'], 403);
        $b = body();
        db()->prepare('UPDATE businesses SET name=?,category=?,description=?,address=?,city=?,phone=?,website=?,price_range=?,latitude=?,longitude=? WHERE id=?')
            ->execute([$b['name'], $b['category'], $b['description'] ?? null, $b['address'], $b['city'], $b['phone'] ?? null, $b['website'] ?? null, $b['price_range'] ?? null, $b['latitude'] ?? null, $b['longitude'] ?? null, $bizId]);
        respond(['success' => true, 'message' => 'Business updated.']);
    }
}

// ── REVIEWS ROUTES ────────────────────────────────────────────────────────────
if ($group === 'reviews') {
    $userId = requireAuth();

    // PUT /api/reviews/:id
    if ($action && $param === '' && in_array(method(), ['PUT', 'PATCH'])) {
        $reviewId = (int)$action;
        $b = body();
        $existing = db()->prepare('SELECT * FROM reviews WHERE id=? AND user_id=?'); $existing->execute([$reviewId, $userId]);
        $rev = $existing->fetch();
        if (!$rev) respond(['success' => false, 'message' => 'Review not found or unauthorized.'], 404);
        db()->prepare('UPDATE reviews SET rating=?,title=?,content=? WHERE id=?')
            ->execute([$b['rating'] ?? $rev['rating'], $b['title'] ?? $rev['title'], $b['content'] ?? $rev['content'], $reviewId]);
        $avg = db()->prepare('SELECT AVG(rating) as avg,COUNT(*) as cnt FROM reviews WHERE business_id=?'); $avg->execute([$rev['business_id']]);
        $r = $avg->fetch();
        db()->prepare('UPDATE businesses SET rating=?,review_count=? WHERE id=?')->execute([round($r['avg'], 2), $r['cnt'], $rev['business_id']]);
        respond(['success' => true, 'message' => 'Review updated.']);
    }

    // DELETE /api/reviews/:id
    if ($action && $param === '' && method() === 'DELETE') {
        $reviewId = (int)$action;
        $existing = db()->prepare('SELECT * FROM reviews WHERE id=? AND user_id=?'); $existing->execute([$reviewId, $userId]);
        $rev = $existing->fetch();
        if (!$rev) respond(['success' => false, 'message' => 'Review not found or unauthorized.'], 404);
        db()->prepare('DELETE FROM reviews WHERE id=?')->execute([$reviewId]);
        $avg = db()->prepare('SELECT AVG(rating) as avg,COUNT(*) as cnt FROM reviews WHERE business_id=?'); $avg->execute([$rev['business_id']]);
        $r = $avg->fetch();
        db()->prepare('UPDATE businesses SET rating=?,review_count=? WHERE id=?')->execute([round($r['avg'] ?? 0, 2), $r['cnt'], $rev['business_id']]);
        respond(['success' => true, 'message' => 'Review deleted.']);
    }
}

// ── 404 ───────────────────────────────────────────────────────────────────────
respond(['success' => false, 'message' => 'Route not found: ' . $uri], 404);
