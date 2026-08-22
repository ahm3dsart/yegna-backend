<?php
// ── CORS ──────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── DATABASE (same pattern as VerifyPay) ─────────────────────────────────────
$db_host   = 'mysql-db02.remote:32636';
$db_name   = 'yegna';
$db_user   = 'ahmed';
$db_pass   = 'Uwk_9832i';
$JWT_SECRET = 'yegna_jwt_super_secret_2026';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// ── ROUTE PARSING (same pattern as VerifyPay) ─────────────────────────────────
$path = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Strip yegna-api.php prefix
if (strpos($path, 'yegna-api.php/api/') === 0) {
    $endpoint = substr($path, strlen('yegna-api.php/api/'));
} elseif (strpos($path, 'yegna-api.php/') === 0) {
    $endpoint = substr($path, strlen('yegna-api.php/'));
} elseif (strpos($path, 'api/') === 0) {
    $endpoint = substr($path, strlen('api/'));
} else {
    $endpoint = $path;
}

$endpoint = rtrim($endpoint, '/');
$parts    = explode('/', $endpoint);
$base     = $parts[0] ?? '';   // e.g. 'health', 'auth', 'businesses'
$sub      = $parts[1] ?? '';   // e.g. 'login', 'register', '123'
$subsub   = $parts[2] ?? '';   // e.g. 'reviews', 'photos'
$subsubid = $parts[3] ?? '';   // e.g. photo id

$method = $_SERVER['REQUEST_METHOD'];

// ── JWT ───────────────────────────────────────────────────────────────────────
function b64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function b64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}
function jwt_encode($payload, $secret, $expiry = 2592000) {
    $header  = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + $expiry;
    $body    = b64url_encode(json_encode($payload));
    $sig     = b64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}
function jwt_decode($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    list($header, $body, $sig) = $parts;
    $expected = b64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(b64url_decode($body), true);
    if (!$payload || $payload['exp'] < time()) return null;
    return $payload;
}

// ── AUTH HELPERS ──────────────────────────────────────────────────────────────
function get_auth_user_id($secret) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        $auth = $h['Authorization'] ?? '';
    }
    if (strpos($auth, 'Bearer ') !== 0) return null;
    $payload = jwt_decode(substr($auth, 7), $secret);
    return ($payload && isset($payload['id'])) ? (int)$payload['id'] : null;
}
function require_auth($secret) {
    $id = get_auth_user_id($secret);
    if (!$id) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    return $id;
}

// ── OTP / EMAIL ───────────────────────────────────────────────────────────────
function save_otp($pdo, $email, $code, $type = 'verify') {
    $pdo->prepare('DELETE FROM otp_codes WHERE email=? AND type=?')->execute([$email, $type]);
    $expires = date('Y-m-d H:i:s', time() + 900);
    $pdo->prepare('INSERT INTO otp_codes (email,code,type,expires_at) VALUES (?,?,?,?)')->execute([$email, $code, $type, $expires]);
}
function verify_otp($pdo, $email, $code, $type = 'verify') {
    $s = $pdo->prepare('SELECT * FROM otp_codes WHERE email=? AND code=? AND type=? AND used=0 AND expires_at>NOW()');
    $s->execute([$email, $code, $type]);
    $row = $s->fetch();
    if (!$row) return false;
    $pdo->prepare('UPDATE otp_codes SET used=1 WHERE id=?')->execute([$row['id']]);
    return true;
}
function send_otp_email($email, $name, $code, $type = 'verify') {
    $subject = $type === 'reset' ? "$code — Reset your Yegna password" : "$code — Your Yegna verification code";
    $year = date('Y');
    $title = $type === 'reset' ? 'Password Reset' : 'Verify Your Email';
    $msg   = $type === 'reset'
        ? "Hi $name, use the code below to reset your password. Expires in 15 minutes."
        : "Hi $name, use the code below to verify your email. Expires in 15 minutes.";
    $html = "<div style='font-family:Arial,sans-serif;max-width:500px;margin:0 auto'><div style='background:#FE4A49;padding:32px;text-align:center;border-radius:12px 12px 0 0'><h1 style='color:white;margin:0'>Yegna</h1></div><div style='background:#fff;padding:32px;border-radius:0 0 12px 12px;border:1px solid #e5e7eb'><h2>$title</h2><p style='color:#6b7280'>$msg</p><div style='background:#f9fafb;border:2px dashed #FE4A49;border-radius:12px;padding:24px;text-align:center;margin:24px 0'><span style='font-size:42px;font-weight:800;letter-spacing:12px;color:#FE4A49'>$code</span></div><p style='color:#9ca3af;font-size:12px;text-align:center'>&copy; $year Yegna</p></div></div>";
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: Yegna App <yegnaapp@gmail.com>\r\n";
    mail($email, $subject, $html, $headers);
}

// ── USER HELPERS ──────────────────────────────────────────────────────────────
function find_user_by_email($pdo, $email) {
    $s = $pdo->prepare('SELECT * FROM users WHERE email=?'); $s->execute([$email]); return $s->fetch() ?: null;
}
function find_user_by_username($pdo, $u) {
    $s = $pdo->prepare('SELECT * FROM users WHERE username=?'); $s->execute([$u]); return $s->fetch() ?: null;
}
function find_user_by_id($pdo, $id) {
    $s = $pdo->prepare('SELECT id,name,username,email,phone,bio,avatar_url,role,points,level,is_verified,email_verified,birth_date,google_id,created_at FROM users WHERE id=?');
    $s->execute([$id]); return $s->fetch() ?: null;
}
function username_exists($pdo, $u) {
    $s = $pdo->prepare('SELECT id FROM users WHERE username=?'); $s->execute([$u]); return (bool)$s->fetch();
}
function validate_username($u) {
    if (strlen($u) < 3 || strlen($u) > 30) return 'Username must be 3-30 characters.';
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $u)) return 'Username can only contain letters, numbers, _ and .';
    return null;
}
function create_user($pdo, $data) {
    $hash = null;
    if (!empty($data['password'])) $hash = password_hash($data['password'], PASSWORD_BCRYPT);
    $s = $pdo->prepare('INSERT INTO users (name,username,email,password_hash,phone,birth_date,google_id,avatar_url,email_verified,role) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $s->execute([$data['name'], $data['username'] ?? null, $data['email'], $hash, $data['phone'] ?? null, $data['birth_date'] ?? null, $data['google_id'] ?? null, $data['avatar_url'] ?? null, $data['email_verified'] ?? 0, $data['role'] ?? 'user']);
    return (int)$pdo->lastInsertId();
}

// ══════════════════════════════════════════════════════════════════════════════
// ROUTES
// ══════════════════════════════════════════════════════════════════════════════

// ── HEALTH ────────────────────────────────────────────────────────────────────
if ($base === 'health' || $base === '') {
    echo json_encode(['status' => 'OK', 'timestamp' => date('c'), 'version' => '2.0.0', 'db' => 'connected']);
    exit;
}

// ── AUTH ──────────────────────────────────────────────────────────────────────
if ($base === 'auth') {

    if ($sub === 'send-otp' && $method === 'POST') {
        $email = trim($input['email'] ?? '');
        $name  = trim($input['name'] ?? 'there');
        if (!$email) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Email is required.']); exit; }
        $existing = find_user_by_email($pdo, $email);
        if ($existing && $existing['email_verified']) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Account already exists. Please sign in.']); exit; }
        $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        save_otp($pdo, $email, $code, 'verify');
        send_otp_email($email, $name, $code, 'verify');
        echo json_encode(['success' => true, 'message' => 'Verification code sent to your email.']);
        exit;
    }

    if ($sub === 'verify-otp' && $method === 'POST') {
        $email = trim($input['email'] ?? '');
        $code  = trim($input['code'] ?? '');
        if (!$email || !$code) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Email and code required.']); exit; }
        if (!verify_otp($pdo, $email, $code, 'verify')) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid or expired code.']); exit; }
        echo json_encode(['success' => true, 'message' => 'Email verified.']);
        exit;
    }

    if ($sub === 'check-username' && $method === 'GET') {
        $u   = trim($_GET['username'] ?? '');
        $err = validate_username($u);
        if ($err) { http_response_code(400); echo json_encode(['success' => false, 'available' => false, 'message' => $err]); exit; }
        $taken = username_exists($pdo, $u);
        echo json_encode(['success' => true, 'available' => !$taken, 'message' => $taken ? 'Username taken.' : 'Username available.']);
        exit;
    }

    if ($sub === 'register' && $method === 'POST') {
        $name     = trim($input['name'] ?? '');
        $email    = trim($input['email'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $bd       = $input['birth_date'] ?? null;
        if (!$name || !$email || !$username || !$password) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Name, email, username and password are required.']); exit; }
        $uerr = validate_username($username);
        if ($uerr) { http_response_code(400); echo json_encode(['success' => false, 'message' => $uerr]); exit; }
        if (strlen($password) < 6) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']); exit; }
        if (find_user_by_email($pdo, $email)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Email already exists.']); exit; }
        if (username_exists($pdo, $username)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Username taken.']); exit; }
        $uid   = create_user($pdo, ['name' => $name, 'email' => $email, 'username' => $username, 'password' => $password, 'birth_date' => $bd, 'email_verified' => 1]);
        $token = jwt_encode(['id' => $uid], $JWT_SECRET);
        $user  = find_user_by_id($pdo, $uid);
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Account created!', 'token' => $token, 'user' => $user]);
        exit;
    }

    if ($sub === 'login' && $method === 'POST') {
        $identifier = trim($input['identifier'] ?? '');
        $password   = $input['password'] ?? '';
        if (!$identifier || !$password) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Username/email and password required.']); exit; }
        $user = strpos($identifier, '@') !== false ? find_user_by_email($pdo, $identifier) : find_user_by_username($pdo, $identifier);
        if (!$user) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'No account found.']); exit; }
        if (empty($user['password_hash'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'This account uses Google sign-in.']); exit; }
        if (!password_verify($password, $user['password_hash'])) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Incorrect password.']); exit; }
        $token    = jwt_encode(['id' => $user['id']], $JWT_SECRET);
        $userData = find_user_by_id($pdo, $user['id']);
        echo json_encode(['success' => true, 'message' => 'Signed in.', 'token' => $token, 'user' => $userData]);
        exit;
    }

    if ($sub === 'forgot-password' && $method === 'POST') {
        $email = trim($input['email'] ?? '');
        if (!$email) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Email required.']); exit; }
        $user = find_user_by_email($pdo, $email);
        if (!$user) { echo json_encode(['success' => true, 'message' => 'If an account exists, a reset code has been sent.']); exit; }
        $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        save_otp($pdo, $email, $code, 'reset');
        send_otp_email($email, $user['name'], $code, 'reset');
        echo json_encode(['success' => true, 'message' => 'Password reset code sent.']);
        exit;
    }

    if ($sub === 'verify-reset-otp' && $method === 'POST') {
        $email = trim($input['email'] ?? '');
        $code  = trim($input['code'] ?? '');
        if (!$email || !$code) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Email and code required.']); exit; }
        if (!verify_otp($pdo, $email, $code, 'reset')) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid or expired code.']); exit; }
        $resetToken = jwt_encode(['email' => $email, 'type' => 'reset'], $JWT_SECRET, 600);
        echo json_encode(['success' => true, 'resetToken' => $resetToken]);
        exit;
    }

    if ($sub === 'reset-password' && $method === 'POST') {
        $resetToken  = $input['resetToken'] ?? '';
        $newPassword = $input['newPassword'] ?? '';
        if (!$resetToken || !$newPassword) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Token and new password required.']); exit; }
        if (strlen($newPassword) < 6) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']); exit; }
        $payload = jwt_decode($resetToken, $JWT_SECRET);
        if (!$payload || ($payload['type'] ?? '') !== 'reset') { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid or expired token.']); exit; }
        $user = find_user_by_email($pdo, $payload['email']);
        if (!$user) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $user['id']]);
        echo json_encode(['success' => true, 'message' => 'Password reset successfully.']);
        exit;
    }

    if ($sub === 'me' && $method === 'GET') {
        $uid  = require_auth($JWT_SECRET);
        $user = find_user_by_id($pdo, $uid);
        if (!$user) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        echo json_encode(['success' => true, 'user' => $user]);
        exit;
    }
}

// ── BUSINESSES ────────────────────────────────────────────────────────────────
if ($base === 'businesses') {
    $uid = get_auth_user_id($JWT_SECRET);

    if ($sub === 'trending' && $method === 'GET') {
        $s = $pdo->query('SELECT * FROM businesses WHERE is_active=1 ORDER BY review_count DESC, rating DESC LIMIT 10');
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'top-rated' && $method === 'GET') {
        $s = $pdo->query('SELECT * FROM businesses WHERE is_active=1 AND review_count>0 ORDER BY rating DESC, review_count DESC LIMIT 10');
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'recently-added' && $method === 'GET') {
        $s = $pdo->query('SELECT * FROM businesses WHERE is_active=1 ORDER BY created_at DESC LIMIT 10');
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'categories' && $method === 'GET') {
        $s = $pdo->query('SELECT * FROM categories ORDER BY name');
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'nearby' && $method === 'GET') {
        $lat = (float)($_GET['lat'] ?? 0);
        $lng = (float)($_GET['lng'] ?? 0);
        $rad = (float)($_GET['radius'] ?? 10);
        if (!$lat || !$lng) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'lat and lng required.']); exit; }
        $s = $pdo->prepare('SELECT *, (6371*acos(cos(radians(?))*cos(radians(latitude))*cos(radians(longitude)-radians(?))+sin(radians(?))*sin(radians(latitude)))) AS distance FROM businesses WHERE is_active=1 HAVING distance<? ORDER BY distance LIMIT 30');
        $s->execute([$lat, $lng, $lat, $rad]);
        $rows = $s->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]); exit;
    }
    if ($sub === 'favorite' && $method === 'POST') {
        $uid   = require_auth($JWT_SECRET);
        $bizId = (int)($input['businessId'] ?? 0);
        if (!$bizId) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'businessId required.']); exit; }
        $chk = $pdo->prepare('SELECT id FROM favorites WHERE user_id=? AND business_id=?'); $chk->execute([$uid, $bizId]);
        if ($chk->fetch()) {
            $pdo->prepare('DELETE FROM favorites WHERE user_id=? AND business_id=?')->execute([$uid, $bizId]);
            echo json_encode(['success' => true, 'data' => ['isFavorite' => false]]);
        } else {
            $pdo->prepare('INSERT INTO favorites (user_id,business_id) VALUES (?,?)')->execute([$uid, $bizId]);
            echo json_encode(['success' => true, 'data' => ['isFavorite' => true]]);
        }
        exit;
    }
    if ($sub === 'search' && $method === 'GET') {
        $q   = '%' . trim($_GET['q'] ?? '') . '%';
        $cat = $_GET['category'] ?? null;
        $city = $_GET['city'] ?? null;
        $min = (float)($_GET['minRating'] ?? 0);
        $lim = (int)($_GET['limit'] ?? 30);
        $off = (int)($_GET['offset'] ?? 0);
        $sql = 'SELECT * FROM businesses WHERE is_active=1 AND (name LIKE ? OR description LIKE ? OR category LIKE ?)';
        $params = [$q, $q, $q];
        if ($cat)  { $sql .= ' AND category=?'; $params[] = $cat; }
        if ($city) { $sql .= ' AND city=?'; $params[] = $city; }
        if ($min)  { $sql .= ' AND rating>=?'; $params[] = $min; }
        $sql .= ' ORDER BY rating DESC LIMIT ? OFFSET ?';
        $params[] = $lim; $params[] = $off;
        $s = $pdo->prepare($sql); $s->execute($params); $rows = $s->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]); exit;
    }
    // GET /businesses/:id/reviews
    if (is_numeric($sub) && $subsub === 'reviews' && $method === 'GET') {
        $bizId = (int)$sub;
        $lim = (int)($_GET['limit'] ?? 20); $off = (int)($_GET['offset'] ?? 0);
        $s = $pdo->prepare('SELECT r.*,u.name as user_name,u.avatar_url FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.business_id=? ORDER BY r.created_at DESC LIMIT ? OFFSET ?');
        $s->execute([$bizId, $lim, $off]);
        $tot = $pdo->prepare('SELECT COUNT(*) as c FROM reviews WHERE business_id=?'); $tot->execute([$bizId]);
        echo json_encode(['success' => true, 'data' => $s->fetchAll(), 'total' => $tot->fetch()['c']]); exit;
    }
    // POST /businesses/:id/reviews
    if (is_numeric($sub) && $subsub === 'reviews' && $method === 'POST') {
        $uid   = require_auth($JWT_SECRET);
        $bizId = (int)$sub;
        $rating  = (int)($input['rating'] ?? 0);
        $content = trim($input['content'] ?? '');
        $title   = trim($input['title'] ?? '');
        if (!$rating || !$content) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Rating and content required.']); exit; }
        if ($rating < 1 || $rating > 5) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Rating must be 1-5.']); exit; }
        $chk = $pdo->prepare('SELECT id FROM reviews WHERE business_id=? AND user_id=?'); $chk->execute([$bizId, $uid]);
        if ($chk->fetch()) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Already reviewed.']); exit; }
        $pdo->prepare('INSERT INTO reviews (business_id,user_id,rating,title,content) VALUES (?,?,?,?,?)')->execute([$bizId, $uid, $rating, $title ?: null, $content]);
        $rid = (int)$pdo->lastInsertId();
        $avg = $pdo->prepare('SELECT AVG(rating) as a, COUNT(*) as c FROM reviews WHERE business_id=?'); $avg->execute([$bizId]);
        $r = $avg->fetch();
        $pdo->prepare('UPDATE businesses SET rating=?,review_count=? WHERE id=?')->execute([round($r['a'], 2), $r['c'], $bizId]);
        $pdo->prepare('UPDATE users SET points=points+10 WHERE id=?')->execute([$uid]);
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Review added.', 'data' => ['id' => $rid]]); exit;
    }
    // GET /businesses/:id
    if (is_numeric($sub) && $subsub === '' && $method === 'GET') {
        $bizId = (int)$sub;
        $s = $pdo->prepare('SELECT * FROM businesses WHERE id=? AND is_active=1'); $s->execute([$bizId]);
        $biz = $s->fetch();
        if (!$biz) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Business not found.']); exit; }
        $hrs = $pdo->prepare('SELECT * FROM business_hours WHERE business_id=? ORDER BY FIELD(day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday")'); $hrs->execute([$bizId]);
        $biz['hours'] = $hrs->fetchAll();
        $phs = $pdo->prepare('SELECT * FROM photos WHERE business_id=? ORDER BY is_primary DESC'); $phs->execute([$bizId]);
        $biz['photos'] = $phs->fetchAll();
        $biz['is_favorite'] = false;
        if ($uid) { $fav = $pdo->prepare('SELECT id FROM favorites WHERE user_id=? AND business_id=?'); $fav->execute([$uid, $bizId]); $biz['is_favorite'] = (bool)$fav->fetch(); }
        echo json_encode(['success' => true, 'data' => $biz]); exit;
    }
    // GET /businesses (list)
    if ($sub === '' && $method === 'GET') {
        $cat = $_GET['category'] ?? null;
        $city = $_GET['city'] ?? null;
        $search = $_GET['search'] ?? null;
        $min = (float)($_GET['minRating'] ?? 0);
        $lim = (int)($_GET['limit'] ?? 20);
        $off = (int)($_GET['offset'] ?? 0);
        $sql = 'SELECT * FROM businesses WHERE is_active=1';
        $params = [];
        if ($cat)    { $sql .= ' AND category=?'; $params[] = $cat; }
        if ($city)   { $sql .= ' AND city=?'; $params[] = $city; }
        if ($search) { $sql .= ' AND (name LIKE ? OR description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($min)    { $sql .= ' AND rating>=?'; $params[] = $min; }
        $sql .= ' ORDER BY rating DESC LIMIT ? OFFSET ?';
        $params[] = $lim; $params[] = $off;
        $s = $pdo->prepare($sql); $s->execute($params); $rows = $s->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]); exit;
    }
}

// ── USER ──────────────────────────────────────────────────────────────────────
if ($base === 'user') {
    $uid = require_auth($JWT_SECRET);

    if ($sub === 'profile' && $method === 'GET') {
        $user = find_user_by_id($pdo, $uid);
        $favs = $pdo->prepare('SELECT b.* FROM businesses b JOIN favorites f ON f.business_id=b.id WHERE f.user_id=? ORDER BY f.created_at DESC'); $favs->execute([$uid]);
        $revs = $pdo->prepare('SELECT r.*,b.name as business_name FROM reviews r JOIN businesses b ON r.business_id=b.id WHERE r.user_id=? ORDER BY r.created_at DESC'); $revs->execute([$uid]);
        $vis  = $pdo->prepare('SELECT b.* FROM businesses b JOIN visits v ON v.business_id=b.id WHERE v.user_id=? ORDER BY v.visited_at DESC'); $vis->execute([$uid]);
        $st   = $pdo->prepare('SELECT (SELECT COUNT(*) FROM reviews WHERE user_id=?) as reviews,(SELECT COUNT(*) FROM favorites WHERE user_id=?) as favorites,(SELECT COUNT(*) FROM visits WHERE user_id=?) as visits'); $st->execute([$uid,$uid,$uid]);
        echo json_encode(['success' => true, 'data' => ['user' => $user, 'stats' => $st->fetch(), 'favorites' => $favs->fetchAll(), 'reviews' => $revs->fetchAll(), 'visited' => $vis->fetchAll()]]); exit;
    }
    if ($sub === 'profile' && ($method === 'PUT' || $method === 'PATCH')) {
        $allowed = ['name','email','phone','bio','avatar_url','username','birth_date'];
        $sets = []; $vals = [];
        foreach ($allowed as $f) { if (isset($input[$f])) { $sets[] = "$f=?"; $vals[] = $input[$f]; } }
        if ($sets) { $vals[] = $uid; $pdo->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($vals); }
        echo json_encode(['success' => true, 'message' => 'Profile updated.', 'data' => find_user_by_id($pdo, $uid)]); exit;
    }
    if ($sub === 'favorites' && $method === 'GET') {
        $s = $pdo->prepare('SELECT b.* FROM businesses b JOIN favorites f ON f.business_id=b.id WHERE f.user_id=? ORDER BY f.created_at DESC'); $s->execute([$uid]);
        $rows = $s->fetchAll(); echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]); exit;
    }
    if ($sub === 'visited' && $method === 'GET') {
        $s = $pdo->prepare('SELECT b.* FROM businesses b JOIN visits v ON v.business_id=b.id WHERE v.user_id=? ORDER BY v.visited_at DESC'); $s->execute([$uid]);
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'visit' && $method === 'POST') {
        $bizId = (int)($input['businessId'] ?? 0);
        if (!$bizId) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'businessId required.']); exit; }
        try { $pdo->prepare('INSERT INTO visits (user_id,business_id) VALUES (?,?)')->execute([$uid, $bizId]); echo json_encode(['success' => true, 'message' => 'Check-in recorded!']); }
        catch (PDOException $e) { echo json_encode(['success' => true, 'message' => 'Already checked in.', 'already_visited' => true]); }
        exit;
    }
    if ($sub === 'reviews' && $method === 'GET') {
        $s = $pdo->prepare('SELECT r.*,b.name as business_name FROM reviews r JOIN businesses b ON r.business_id=b.id WHERE r.user_id=? ORDER BY r.created_at DESC'); $s->execute([$uid]);
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'stats' && $method === 'GET') {
        $s = $pdo->prepare('SELECT (SELECT COUNT(*) FROM reviews WHERE user_id=?) as reviews,(SELECT COUNT(*) FROM favorites WHERE user_id=?) as favorites,(SELECT COUNT(*) FROM visits WHERE user_id=?) as visits'); $s->execute([$uid,$uid,$uid]);
        echo json_encode(['success' => true, 'data' => $s->fetch()]); exit;
    }
}

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────
if ($base === 'notifications') {
    $uid = require_auth($JWT_SECRET);
    if ($sub === '' && $method === 'GET') {
        $lim = (int)($_GET['limit'] ?? 20); $off = (int)($_GET['offset'] ?? 0);
        $s = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?'); $s->execute([$uid,$lim,$off]);
        $u = $pdo->prepare('SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0'); $u->execute([$uid]);
        echo json_encode(['success' => true, 'data' => $s->fetchAll(), 'unreadCount' => $u->fetch()['c']]); exit;
    }
    if ($sub === 'read-all') {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$uid]);
        echo json_encode(['success' => true, 'message' => 'All marked as read.']); exit;
    }
    if (is_numeric($sub) && $subsub === 'read') {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$sub, $uid]);
        echo json_encode(['success' => true]); exit;
    }
}

// ── SEARCH ────────────────────────────────────────────────────────────────────
if ($base === 'search') {
    $q   = '%' . trim($_GET['q'] ?? '') . '%';
    $cat = $_GET['category'] ?? null;
    $city = $_GET['city'] ?? null;
    $min = (float)($_GET['minRating'] ?? 0);
    $lim = (int)($_GET['limit'] ?? 20);
    $off = (int)($_GET['offset'] ?? 0);
    $sql = 'SELECT * FROM businesses WHERE is_active=1 AND (name LIKE ? OR description LIKE ? OR category LIKE ?)';
    $params = [$q, $q, $q];
    if ($cat)  { $sql .= ' AND category=?'; $params[] = $cat; }
    if ($city) { $sql .= ' AND city=?'; $params[] = $city; }
    if ($min)  { $sql .= ' AND rating>=?'; $params[] = $min; }
    $sql .= ' ORDER BY rating DESC LIMIT ? OFFSET ?';
    $params[] = $lim; $params[] = $off;
    $s = $pdo->prepare($sql); $s->execute($params); $rows = $s->fetchAll();
    echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]); exit;
}

// ── SOCIAL ────────────────────────────────────────────────────────────────────
if ($base === 'social') {
    $uid = require_auth($JWT_SECRET);

    if ($sub === 'follow' && is_numeric($subsub) && $method === 'POST') {
        $tid = (int)$subsub;
        if ($tid === $uid) { http_response_code(400); echo json_encode(['success' => false, 'message' => "Can't follow yourself."]); exit; }
        $pdo->prepare('INSERT IGNORE INTO follows (follower_id,following_id) VALUES (?,?)')->execute([$uid, $tid]);
        echo json_encode(['success' => true, 'data' => ['is_following' => true]]); exit;
    }
    if ($sub === 'follow' && is_numeric($subsub) && $method === 'DELETE') {
        $pdo->prepare('DELETE FROM follows WHERE follower_id=? AND following_id=?')->execute([$uid, (int)$subsub]);
        echo json_encode(['success' => true, 'data' => ['is_following' => false]]); exit;
    }
    if ($sub === 'feed' && $method === 'GET') {
        $lim = (int)($_GET['limit'] ?? 20); $off = (int)($_GET['offset'] ?? 0);
        $s = $pdo->prepare('SELECT af.*,u.name as user_name,u.avatar_url,b.name as business_name,b.category,b.image_url FROM activity_feed af JOIN users u ON af.user_id=u.id JOIN businesses b ON af.business_id=b.id JOIN follows f ON f.follower_id=? AND f.following_id=af.user_id WHERE af.visibility="everyone" ORDER BY af.created_at DESC LIMIT ? OFFSET ?');
        $s->execute([$uid,$lim,$off]); echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'privacy' && $method === 'GET') {
        $s = $pdo->prepare('SELECT * FROM user_privacy WHERE user_id=?'); $s->execute([$uid]);
        $p = $s->fetch();
        if (!$p) { $pdo->prepare('INSERT IGNORE INTO user_privacy (user_id) VALUES (?)')->execute([$uid]); $p = ['user_id' => $uid]; }
        echo json_encode(['success' => true, 'data' => $p]); exit;
    }
    if ($sub === 'privacy' && ($method === 'PUT' || $method === 'PATCH')) {
        $allowed = ['activity_visibility','reviews_visibility','photos_visibility','visited_visibility','saved_visibility','followers_visibility'];
        $sets = []; $vals = [];
        foreach ($allowed as $f) { if (isset($input[$f])) { $sets[] = "$f=?"; $vals[] = $input[$f]; } }
        $pdo->prepare('INSERT IGNORE INTO user_privacy (user_id) VALUES (?)')->execute([$uid]);
        if ($sets) { $vals[] = $uid; $pdo->prepare('UPDATE user_privacy SET '.implode(',',$sets).' WHERE user_id=?')->execute($vals); }
        echo json_encode(['success' => true, 'message' => 'Privacy updated.']); exit;
    }
    if ($sub === 'search-users' && $method === 'GET') {
        $q = '%'.trim($_GET['q'] ?? '').'%';
        $s = $pdo->prepare('SELECT id,name,avatar_url,bio FROM users WHERE name LIKE ? AND id!=? ORDER BY name LIMIT 30'); $s->execute([$q,$uid]);
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'users' && is_numeric($subsub)) {
        $tid = (int)$subsub;
        $sub4 = $parts[3] ?? '';
        if ($sub4 === 'followers') {
            $s = $pdo->prepare('SELECT u.id,u.name,u.avatar_url FROM follows f JOIN users u ON f.follower_id=u.id WHERE f.following_id=?'); $s->execute([$tid]);
            echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
        }
        if ($sub4 === 'following') {
            $s = $pdo->prepare('SELECT u.id,u.name,u.avatar_url FROM follows f JOIN users u ON f.following_id=u.id WHERE f.follower_id=?'); $s->execute([$tid]);
            echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
        }
        $s = $pdo->prepare('SELECT id,name,avatar_url,bio,level,points,created_at FROM users WHERE id=?'); $s->execute([$tid]);
        $user = $s->fetch();
        if (!$user) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }
        $fc = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE following_id=?'); $fc->execute([$tid]);
        $ng = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=?');  $ng->execute([$tid]);
        $if = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=? AND following_id=?'); $if->execute([$uid,$tid]);
        $user['followers'] = $fc->fetch()['c']; $user['following'] = $ng->fetch()['c'];
        echo json_encode(['success' => true, 'data' => ['user' => $user, 'is_following' => (bool)$if->fetch()['c']]]); exit;
    }
}

// ── OWNER ─────────────────────────────────────────────────────────────────────
if ($base === 'owner') {
    $uid = require_auth($JWT_SECRET);

    if ($sub === 'businesses' && $subsub === '' && $method === 'GET') {
        $s = $pdo->prepare('SELECT * FROM businesses WHERE owner_id=? ORDER BY created_at DESC'); $s->execute([$uid]);
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    if ($sub === 'businesses' && $subsub === '' && $method === 'POST') {
        $b = $input;
        if (!($b['name']??'') || !($b['category']??'') || !($b['address']??'') || !($b['city']??'')) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Name, category, address and city required.']); exit; }
        $pdo->prepare('INSERT INTO businesses (name,category,description,address,city,phone,website,price_range,latitude,longitude,owner_id,is_active,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,"pending")')
            ->execute([$b['name'],$b['category'],$b['description']??null,$b['address'],$b['city'],$b['phone']??null,$b['website']??null,$b['price_range']??null,$b['latitude']??null,$b['longitude']??null,$uid]);
        http_response_code(201); echo json_encode(['success' => true, 'message' => 'Business submitted.', 'data' => ['id' => (int)$pdo->lastInsertId()]]); exit;
    }
    if ($sub === 'businesses' && is_numeric($subsub) && $method === 'GET') {
        $bizId = (int)$subsub;
        $s = $pdo->prepare('SELECT * FROM businesses WHERE id=? AND owner_id=?'); $s->execute([$bizId,$uid]);
        $biz = $s->fetch(); if (!$biz) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }
        $hrs = $pdo->prepare('SELECT * FROM business_hours WHERE business_id=?'); $hrs->execute([$bizId]);
        $phs = $pdo->prepare('SELECT * FROM photos WHERE business_id=?'); $phs->execute([$bizId]);
        echo json_encode(['success' => true, 'data' => array_merge($biz, ['hours' => $hrs->fetchAll(), 'photos' => $phs->fetchAll()])]); exit;
    }
    if ($sub === 'businesses' && is_numeric($subsub) && ($method === 'PUT' || $method === 'PATCH')) {
        $bizId = (int)$subsub; $b = $input;
        $chk = $pdo->prepare('SELECT id FROM businesses WHERE id=? AND owner_id=?'); $chk->execute([$bizId,$uid]);
        if (!$chk->fetch()) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Not authorized.']); exit; }
        $pdo->prepare('UPDATE businesses SET name=?,category=?,description=?,address=?,city=?,phone=?,website=?,price_range=?,latitude=?,longitude=? WHERE id=?')
            ->execute([$b['name'],$b['category'],$b['description']??null,$b['address'],$b['city'],$b['phone']??null,$b['website']??null,$b['price_range']??null,$b['latitude']??null,$b['longitude']??null,$bizId]);
        echo json_encode(['success' => true, 'message' => 'Updated.']); exit;
    }
}

// ── REVIEWS ───────────────────────────────────────────────────────────────────
if ($base === 'reviews' && is_numeric($sub)) {
    $uid = require_auth($JWT_SECRET);
    $rid = (int)$sub;
    if ($method === 'PUT' || $method === 'PATCH') {
        $ex = $pdo->prepare('SELECT * FROM reviews WHERE id=? AND user_id=?'); $ex->execute([$rid,$uid]); $rev = $ex->fetch();
        if (!$rev) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }
        $pdo->prepare('UPDATE reviews SET rating=?,title=?,content=? WHERE id=?')->execute([$input['rating']??$rev['rating'],$input['title']??$rev['title'],$input['content']??$rev['content'],$rid]);
        $avg = $pdo->prepare('SELECT AVG(rating) as a,COUNT(*) as c FROM reviews WHERE business_id=?'); $avg->execute([$rev['business_id']]);
        $r = $avg->fetch(); $pdo->prepare('UPDATE businesses SET rating=?,review_count=? WHERE id=?')->execute([round($r['a'],2),$r['c'],$rev['business_id']]);
        echo json_encode(['success' => true, 'message' => 'Updated.']); exit;
    }
    if ($method === 'DELETE') {
        $ex = $pdo->prepare('SELECT * FROM reviews WHERE id=? AND user_id=?'); $ex->execute([$rid,$uid]); $rev = $ex->fetch();
        if (!$rev) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }
        $pdo->prepare('DELETE FROM reviews WHERE id=?')->execute([$rid]);
        $avg = $pdo->prepare('SELECT AVG(rating) as a,COUNT(*) as c FROM reviews WHERE business_id=?'); $avg->execute([$rev['business_id']]);
        $r = $avg->fetch(); $pdo->prepare('UPDATE businesses SET rating=?,review_count=? WHERE id=?')->execute([round($r['a']??0,2),$r['c'],$rev['business_id']]);
        echo json_encode(['success' => true, 'message' => 'Deleted.']); exit;
    }
}

// ── 404 ───────────────────────────────────────────────────────────────────────
http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Route not found: /' . $base . '/' . $sub]);
