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

    // Send via Gmail SMTP (SSL port 465) — no external library needed
    smtp_send_email($email, $subject, $html);
}

function smtp_send_email(string $to, string $subject, string $html): void {
    $host = 'ssl://smtp.gmail.com';
    $port = 465;
    $user = 'yegnaapp@gmail.com';
    $pass = 'ubaj ojjz ysyq ephd';
    $from = 'yegnaapp@gmail.com';
    $name = 'Yegna App';

    $sock = @fsockopen($host, $port, $errno, $errstr, 15);
    if (!$sock) {
        error_log("Yegna SMTP connect failed: $errstr ($errno)");
        return;
    }

    $read = function() use ($sock) { return fgets($sock, 512); };
    $send = function(string $cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };

    $read(); // 220 greeting
    $send("EHLO verifypay.et"); while (strpos($r = $read(), '-') === 3) {} // read all EHLO lines
    $send("AUTH LOGIN");       $read();
    $send(base64_encode($user)); $read();
    $send(base64_encode($pass)); $r = $read();
    if (strpos($r, '235') === false) { fclose($sock); error_log("Yegna SMTP auth failed: $r"); return; }

    $send("MAIL FROM:<$from>");  $read();
    $send("RCPT TO:<$to>");      $read();
    $send("DATA");               $read();

    $body  = "Date: " . date('r') . "\r\n";
    $body .= "From: =?UTF-8?B?" . base64_encode($name) . "?= <$from>\r\n";
    $body .= "To: $to\r\n";
    $body .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $body .= "MIME-Version: 1.0\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($html));
    $body .= "\r\n.";
    $send($body); $read();

    $send("QUIT"); fclose($sock);
}

// ── USER HELPERS ──────────────────────────────────────────────────────────────
function find_user_by_email($pdo, $email) {
    $s = $pdo->prepare('SELECT * FROM users WHERE email=?'); $s->execute([$email]); return $s->fetch() ?: null;
}
function find_user_by_username($pdo, $u) {
    try { $s = $pdo->prepare('SELECT * FROM users WHERE username=?'); $s->execute([$u]); return $s->fetch() ?: null; }
    catch (PDOException $e) { return null; }
}
function find_user_by_id($pdo, $id) {
    // Try full column set first, fall back to basic columns if schema is older
    try {
        $s = $pdo->prepare('SELECT id,name,email,phone,bio,avatar_url,role,points,level,is_verified,email_verified,birth_date,google_id,created_at FROM users WHERE id=?');
        $s->execute([$id]); return $s->fetch() ?: null;
    } catch (PDOException $e) {
        // Fall back to guaranteed columns
        $s = $pdo->prepare('SELECT id,name,email,avatar_url,role,created_at FROM users WHERE id=?');
        $s->execute([$id]); return $s->fetch() ?: null;
    }
}
function username_exists($pdo, $u) {
    try { $s = $pdo->prepare('SELECT id FROM users WHERE username=?'); $s->execute([$u]); return (bool)$s->fetch(); }
    catch (PDOException $e) { return false; }
}
function validate_username($u) {
    if (strlen($u) < 3 || strlen($u) > 30) return 'Username must be 3-30 characters.';
    if (!preg_match('/^[a-zA-Z0-9_.]+$/', $u)) return 'Username can only contain letters, numbers, _ and .';
    return null;
}
function create_user($pdo, $data) {
    $hash = null;
    if (!empty($data['password'])) $hash = password_hash($data['password'], PASSWORD_BCRYPT);
    // Check if username column exists
    $has_username = false;
    try { $pdo->query('SELECT username FROM users LIMIT 1'); $has_username = true; } catch (PDOException $e) {}
    if ($has_username) {
        $s = $pdo->prepare('INSERT INTO users (name,username,email,password_hash,phone,birth_date,google_id,avatar_url,email_verified,role) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$data['name'], $data['username'] ?? null, $data['email'], $hash, $data['phone'] ?? null, $data['birth_date'] ?? null, $data['google_id'] ?? null, $data['avatar_url'] ?? null, $data['email_verified'] ?? 0, $data['role'] ?? 'user']);
    } else {
        $s = $pdo->prepare('INSERT INTO users (name,email,password_hash,phone,birth_date,google_id,avatar_url,email_verified,role) VALUES (?,?,?,?,?,?,?,?,?)');
        $s->execute([$data['name'], $data['email'], $hash, $data['phone'] ?? null, $data['birth_date'] ?? null, $data['google_id'] ?? null, $data['avatar_url'] ?? null, $data['email_verified'] ?? 0, $data['role'] ?? 'user']);
    }
    return (int)$pdo->lastInsertId();
}

// ══════════════════════════════════════════════════════════════════════════════
// IMAGE UPLOAD HELPER
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Process and save an uploaded image file.
 * - Validates MIME type using PHP image functions (not just extension)
 * - Compresses ~40% using GD if available
 * - Resizes iteratively until under $maxBytes
 * - Returns ['url' => string] on success or throws RuntimeException on failure
 */
function upload_image(array $file, string $subdir, int $maxBytes = 2097152): array {
    // ── 1. Basic upload error check ───────────────────────────────────────────
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errs = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write file.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
        ];
        throw new RuntimeException($errs[$file['error']] ?? 'Upload error ' . $file['error']);
    }

    // ── 2. Validate it is actually an image (not just by extension) ───────────
    $imgInfo = @getimagesize($file['tmp_name']);
    if (!$imgInfo) {
        throw new RuntimeException('Invalid image file. Only JPG, PNG, and WebP are accepted.');
    }
    $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    if (!in_array($imgInfo['mime'], $allowedMimes, true)) {
        throw new RuntimeException('Unsupported image type: ' . $imgInfo['mime'] . '. Use JPG, PNG, or WebP.');
    }

    // ── 3. Ensure upload directory exists ────────────────────────────────────
    $baseDir    = __DIR__ . '/uploads/' . $subdir;
    if (!is_dir($baseDir)) {
        if (!mkdir($baseDir, 0755, true)) {
            throw new RuntimeException('Could not create upload directory. Check server permissions.');
        }
    }

    // ── 4. Generate a safe unique filename ───────────────────────────────────
    $ext      = match ($imgInfo['mime']) {
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'jpg',
    };
    $filename = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
    $destPath = $baseDir . '/' . $filename;

    // ── 5. Try GD compression (40% quality reduction) ────────────────────────
    $gdAvailable = function_exists('imagecreatefromjpeg') &&
                   function_exists('imagecreatefrompng')  &&
                   function_exists('imagejpeg');

    if ($gdAvailable) {
        // Load source image
        $src = match ($imgInfo['mime']) {
            'image/png'  => @imagecreatefrompng($file['tmp_name']),
            'image/webp' => function_exists('imagecreatefromwebp')
                              ? @imagecreatefromwebp($file['tmp_name'])
                              : false,
            default      => @imagecreatefromjpeg($file['tmp_name']),
        };

        if ($src !== false) {
            $origW   = imagesx($src);
            $origH   = imagesy($src);
            $quality = 60; // ~40% reduction from typical 100% quality
            $scale   = 1.0;

            // Iteratively reduce until under maxBytes
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $newW = (int)round($origW * $scale);
                $newH = (int)round($origH * $scale);

                // Create resized canvas
                $dst = imagecreatetruecolor($newW, $newH);

                // Preserve transparency for PNG
                if ($imgInfo['mime'] === 'image/png') {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
                    imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
                }

                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

                // Save to temp buffer and check size
                ob_start();
                if ($imgInfo['mime'] === 'image/png') {
                    imagepng($dst, null, 6); // compression 6 = good balance
                } else {
                    imagejpeg($dst, null, $quality);
                }
                $buf  = ob_get_clean();
                $size = strlen($buf);
                imagedestroy($dst);

                if ($size <= $maxBytes) {
                    // Write to final destination
                    file_put_contents($destPath, $buf);
                    imagedestroy($src);
                    $publicUrl = 'https://verifypay.et/uploads/' . $subdir . '/' . $filename;
                    return ['url' => $publicUrl, 'filename' => $filename, 'size' => $size];
                }

                // Still too big — reduce quality and scale
                $quality = max(30, $quality - 10);
                $scale  *= 0.75;
            }
            imagedestroy($src);
            throw new RuntimeException('Image could not be compressed below 2 MB. Please use a smaller image.');
        }
    }

    // ── 6. GD not available — just validate size and move ────────────────────
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('Image exceeds 2 MB. GD extension is not enabled on this server so automatic compression is unavailable. Please compress the image before uploading.');
    }
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Failed to save uploaded file.');
    }
    $publicUrl = 'https://verifypay.et/uploads/' . $subdir . '/' . $filename;
    return ['url' => $publicUrl, 'filename' => $filename, 'size' => $file['size']];
}

/**
 * Delete an uploaded image file from disk safely.
 * Only deletes files inside the uploads/ directory.
 */
function delete_upload_file(string $url): void {
    // Extract relative path from URL
    $prefix = 'https://verifypay.et/uploads/';
    if (strpos($url, $prefix) !== 0) return; // not our file
    $rel  = substr($url, strlen($prefix));
    // Prevent path traversal
    $rel  = ltrim(str_replace(['..', '//'], '', $rel), '/');
    $path = __DIR__ . '/uploads/' . $rel;
    if (is_file($path)) {
        @unlink($path);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// ROUTES
// ══════════════════════════════════════════════════════════════════════════════

// ── HEALTH ────────────────────────────────────────────────────────────────────
if ($base === 'health' || $base === '') {
    $gdOk = function_exists('imagecreatefromjpeg') && function_exists('imagejpeg');
    echo json_encode(['status' => 'OK', 'timestamp' => date('c'), 'version' => '2.0.0', 'db' => 'connected', 'gd' => $gdOk ? 'enabled' : 'disabled', 'debug' => ['base' => $base, 'sub' => $sub, 'subsub' => $subsub, 'uri' => $_SERVER['REQUEST_URI']]]);
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
        $cat = $_GET['category'] ?? null;
        $lim = max(1, (int)($_GET['limit'] ?? 30));
        $off = max(0, (int)($_GET['offset'] ?? 0));
        if (!$lat || !$lng) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'lat and lng required.']); exit; }
        $catClause = $cat ? ' AND category=?' : '';
        $sql = 'SELECT *, (6371*acos(cos(radians(?))*cos(radians(latitude))*cos(radians(longitude)-radians(?))+sin(radians(?))*sin(radians(latitude)))) AS distance FROM businesses WHERE is_active=1' . $catClause . ' HAVING distance<? ORDER BY distance LIMIT ' . $lim . ' OFFSET ' . $off;
        $params = [$lat, $lng, $lat];
        if ($cat) $params[] = $cat;
        $params[] = $rad;
        $s = $pdo->prepare($sql); $s->execute($params);
        $rows = $s->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]); exit;
    }
    if ($sub === 'favorite' && $method === 'POST') {
        $uid   = require_auth($JWT_SECRET);
        $bizId = (int)($input['businessId'] ?? 0);
        if (!$bizId) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'businessId required.']); exit; }

        // Reject saves for missing / inactive businesses
        $bizChk = $pdo->prepare('SELECT id FROM businesses WHERE id=? AND is_active=1');
        $bizChk->execute([$bizId]);
        if (!$bizChk->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Business not available.']);
            exit;
        }

        $chk = $pdo->prepare('SELECT id FROM favorites WHERE user_id=? AND business_id=?');
        $chk->execute([$uid, $bizId]);
        if ($chk->fetch()) {
            $pdo->prepare('DELETE FROM favorites WHERE user_id=? AND business_id=?')->execute([$uid, $bizId]);
            // Clear reminder tracking so a future re-save can remind again later
            try {
                $pdo->prepare('DELETE FROM saved_reminders WHERE user_id=? AND business_id=?')->execute([$uid, $bizId]);
            } catch (PDOException $e) {}
            echo json_encode(['success' => true, 'data' => ['isFavorite' => false, 'action' => 'removed']]);
        } else {
            // UNIQUE(user_id, business_id) prevents duplicates
            try {
                $pdo->prepare('INSERT INTO favorites (user_id,business_id) VALUES (?,?)')->execute([$uid, $bizId]);
            } catch (PDOException $e) {
                // Concurrent duplicate insert — treat as already saved
            }
            echo json_encode(['success' => true, 'data' => ['isFavorite' => true, 'action' => 'added']]);
        }
        exit;
    }
    if ($sub === 'search' && $method === 'GET') {
        $q   = '%' . trim($_GET['q'] ?? '') . '%';
        $cat = $_GET['category'] ?? null;
        $city = $_GET['city'] ?? null;
        $min = (float)($_GET['minRating'] ?? 0);
        $lim = max(1, (int)($_GET['limit'] ?? 30));
        $off = max(0, (int)($_GET['offset'] ?? 0));
        $sql = 'SELECT * FROM businesses WHERE is_active=1 AND (name LIKE ? OR description LIKE ? OR category LIKE ?)';
        $params = [$q, $q, $q];
        if ($cat)  { $sql .= ' AND category=?'; $params[] = $cat; }
        if ($city) { $sql .= ' AND city=?'; $params[] = $city; }
        if ($min)  { $sql .= ' AND rating>=?'; $params[] = $min; }
        $sql .= ' ORDER BY rating DESC LIMIT ' . $lim . ' OFFSET ' . $off;
        $s = $pdo->prepare($sql); $s->execute($params); $rows = $s->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]); exit;
    }
    // DELETE /businesses/:id/reviews/:reviewId
    if (is_numeric($sub) && $subsub === 'reviews' && is_numeric($subsubid) && $method === 'DELETE') {
        $bizId  = (int)$sub;
        $rid    = (int)$subsubid;
        $authUid = get_auth_user_id($JWT_SECRET);
        if (!$authUid) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required.']); exit; }
        $ex = $pdo->prepare('SELECT * FROM reviews WHERE id=? AND business_id=? AND user_id=?');
        $ex->execute([$rid, $bizId, $authUid]);
        $rev = $ex->fetch();
        if (!$rev) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Review not found or not yours.']); exit; }
        $pdo->prepare('DELETE FROM reviews WHERE id=?')->execute([$rid]);
        $avg = $pdo->prepare('SELECT AVG(rating) as a, COUNT(*) as c FROM reviews WHERE business_id=?');
        $avg->execute([$bizId]);
        $r = $avg->fetch();
        $pdo->prepare('UPDATE businesses SET rating=?, review_count=? WHERE id=?')
            ->execute([round($r['a'] ?? 0, 2), $r['c'], $bizId]);
        echo json_encode(['success' => true, 'message' => 'Review deleted.']); exit;
    }

    // PUT/PATCH /businesses/:id/reviews/:reviewId
    if (is_numeric($sub) && $subsub === 'reviews' && is_numeric($subsubid) && ($method === 'PUT' || $method === 'PATCH')) {
        $bizId  = (int)$sub;
        $rid    = (int)$subsubid;
        $authUid = get_auth_user_id($JWT_SECRET);
        if (!$authUid) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required.']); exit; }
        $ex = $pdo->prepare('SELECT * FROM reviews WHERE id=? AND business_id=? AND user_id=?');
        $ex->execute([$rid, $bizId, $authUid]);
        $rev = $ex->fetch();
        if (!$rev) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Review not found or not yours.']); exit; }
        $newRating  = isset($input['rating'])  ? (int)$input['rating']         : $rev['rating'];
        $newTitle   = isset($input['title'])   ? trim($input['title'])          : $rev['title'];
        $newContent = isset($input['content']) ? trim($input['content'])        : $rev['content'];
        $pdo->prepare('UPDATE reviews SET rating=?, title=?, content=? WHERE id=?')
            ->execute([$newRating, $newTitle ?: null, $newContent, $rid]);
        echo json_encode(['success' => true, 'message' => 'Review updated.']); exit;
    }

    // POST /businesses/:id/reviews/:reviewId/helpful
    if (is_numeric($sub) && $subsub === 'reviews' && is_numeric($subsubid) && $method === 'POST') {
        echo json_encode(['success' => true]); exit;
    }

    // POST /businesses/:id/reviews/:reviewId/report  
    // (caught by the helpful handler above for now)

    // GET /businesses/:id/reviews
    if (is_numeric($sub) && $subsub === 'reviews' && $method === 'GET' && $subsubid === '') {
        $bizId = (int)$sub;
        $lim = max(1, (int)($_GET['limit'] ?? 20));
        $off = max(0, (int)($_GET['offset'] ?? 0));
        try {
            $s = $pdo->prepare('SELECT r.id, r.business_id, r.user_id, r.rating, r.title, r.content, r.created_at, u.name as user_name, u.avatar_url FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.business_id=? ORDER BY r.created_at DESC LIMIT ? OFFSET ?');
            $s->execute([$bizId, $lim, $off]);
            $rows = $s->fetchAll();
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE business_id=?');
            $cnt->execute([$bizId]);
            $total = (int)$cnt->fetchColumn();
            echo json_encode(['success' => true, 'data' => $rows, 'total' => $total, 'distribution' => []]);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'data' => [], 'total' => 0, 'distribution' => [], '_err' => $e->getMessage()]);
        }
        exit;
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
        $lim = max(1, (int)($_GET['limit'] ?? 20));
        $off = max(0, (int)($_GET['offset'] ?? 0));
        $sql = 'SELECT * FROM businesses WHERE is_active=1';
        $params = [];
        if ($cat)    { $sql .= ' AND category=?'; $params[] = $cat; }
        if ($city)   { $sql .= ' AND city=?'; $params[] = $city; }
        if ($search) { $sql .= ' AND (name LIKE ? OR description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($min)    { $sql .= ' AND rating>=?'; $params[] = $min; }
        $sql .= ' ORDER BY rating DESC LIMIT ' . $lim . ' OFFSET ' . $off;
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
        // ── Handle avatar file upload ─────────────────────────────────────────
        if (!empty($_FILES['avatar'])) {
            try {
                $result = upload_image($_FILES['avatar'], 'profile');
                // Delete old avatar from disk
                $oldUser = find_user_by_id($pdo, $uid);
                if ($oldUser && !empty($oldUser['avatar_url'])) {
                    delete_upload_file($oldUser['avatar_url']);
                }
                $pdo->prepare('UPDATE users SET avatar_url=? WHERE id=?')->execute([$result['url'], $uid]);
                // Also apply any other text fields sent alongside
                $textFields = ['name','phone','bio','username','birth_date'];
                $sets = []; $vals = [];
                foreach ($textFields as $f) {
                    if (isset($_POST[$f])) { $sets[] = "$f=?"; $vals[] = $_POST[$f]; }
                }
                if ($sets) { $vals[] = $uid; $pdo->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($vals); }
                echo json_encode(['success' => true, 'message' => 'Profile updated.', 'avatar_url' => $result['url'], 'data' => find_user_by_id($pdo, $uid)]);
            } catch (RuntimeException $e) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }
        // ── JSON field update (no file) ───────────────────────────────────────
        $allowed = ['name','email','phone','bio','avatar_url','username','birth_date','role'];
        $sets = []; $vals = [];
        foreach ($allowed as $f) { if (isset($input[$f])) { $sets[] = "$f=?"; $vals[] = $input[$f]; } }
        if ($sets) { $vals[] = $uid; $pdo->prepare('UPDATE users SET '.implode(',',$sets).' WHERE id=?')->execute($vals); }
        echo json_encode(['success' => true, 'message' => 'Profile updated.', 'data' => find_user_by_id($pdo, $uid)]); exit;
    }
    if ($sub === 'favorites' && $method === 'GET') {
        // Active businesses only; include hours + cover photo for Saved list UI.
        // Inactive/deleted businesses are dropped (CASCADE removes favorites for hard-deletes).
        $s = $pdo->prepare(
            'SELECT b.*
             FROM businesses b
             JOIN favorites f ON f.business_id = b.id
             WHERE f.user_id = ? AND b.is_active = 1
             ORDER BY f.created_at DESC'
        );
        $s->execute([$uid]);
        $rows = $s->fetchAll();

        // Attach today's hours + primary photo for each saved business
        $hrsStmt = $pdo->prepare(
            'SELECT * FROM business_hours WHERE business_id = ?
             ORDER BY FIELD(day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday")'
        );
        $photoStmt = $pdo->prepare(
            'SELECT image_url FROM photos WHERE business_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1'
        );

        foreach ($rows as &$biz) {
            $hrsStmt->execute([(int)$biz['id']]);
            $biz['hours'] = $hrsStmt->fetchAll();

            if (empty($biz['cover_image_url']) && empty($biz['image_url'])) {
                $photoStmt->execute([(int)$biz['id']]);
                $ph = $photoStmt->fetchColumn();
                if ($ph) {
                    $biz['cover_image_url'] = $ph;
                    $biz['image_url'] = $ph;
                }
            }

            $biz['unavailable'] = false;
        }
        unset($biz);

        echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
        exit;
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
    // POST /user/push-token — register Expo push token
    if ($sub === 'push-token' && $method === 'POST') {
        $token = trim($input['token'] ?? '');
        if (!$token) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Token required.']); exit; }
        // Store in a simple table (create if not exists)
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS push_tokens (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL UNIQUE, token VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)');
            $pdo->prepare('INSERT INTO push_tokens (user_id, token) VALUES (?,?) ON DUPLICATE KEY UPDATE token=?, created_at=NOW()')->execute([$uid, $token, $token]);
            echo json_encode(['success' => true, 'message' => 'Push token registered.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'message' => 'Push token noted.']); // non-critical
        }
        exit;
    }
}

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────
if ($base === 'notifications') {
    $uid = require_auth($JWT_SECRET);
    if ($sub === '' && $method === 'GET') {
        $lim = max(1, (int)($_GET['limit'] ?? 20));
        $off = max(0, (int)($_GET['offset'] ?? 0));
        $s = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ' . $lim . ' OFFSET ' . $off);
        $s->execute([$uid]);
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
    $lim = max(1, (int)($_GET['limit'] ?? 20));
    $off = max(0, (int)($_GET['offset'] ?? 0));
    $sql = 'SELECT * FROM businesses WHERE is_active=1 AND (name LIKE ? OR description LIKE ? OR category LIKE ?)';
    $params = [$q, $q, $q];
    if ($cat)  { $sql .= ' AND category=?'; $params[] = $cat; }
    if ($city) { $sql .= ' AND city=?'; $params[] = $city; }
    if ($min)  { $sql .= ' AND rating>=?'; $params[] = $min; }
    $sql .= ' ORDER BY rating DESC LIMIT ' . $lim . ' OFFSET ' . $off;
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

        // Notify the person who was followed
        $follower = $pdo->prepare('SELECT name FROM users WHERE id=?'); $follower->execute([$uid]);
        $followerRow = $follower->fetch();
        $followerName = $followerRow['name'] ?? 'Someone';
        $notifTitle = "$followerName started following you";
        $notifData  = json_encode(['type' => 'follow', 'user_id' => $uid, 'userId' => $uid, 'user_name' => $followerName]);
        try {
            $pdo->prepare('INSERT IGNORE INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)')
                ->execute([$tid, 'follow', $notifTitle, $notifTitle, $notifData]);
        } catch (PDOException $e) {}
        $tokens = get_push_tokens($pdo, [$tid]);
        if (!empty($tokens)) {
            send_expo_push($tokens, $notifTitle, 'Tap to see their profile.', [
                'type'     => 'follow',
                'user_id'  => $uid,
                'userId'   => $uid,
                'userName' => $followerName,
            ]);
        }

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
    // Role guard — only business_owner or admin can use owner routes
    try {
        $roleRow = $pdo->prepare('SELECT role FROM users WHERE id=?');
        $roleRow->execute([$uid]);
        $userRole = ($roleRow->fetch())['role'] ?? 'user';
    } catch (PDOException $e) {
        $userRole = 'user';
    }
    if (!in_array($userRole, ['business_owner', 'admin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Business owner account required. Upgrade your account in Settings.']);
        exit;
    }

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

    // POST /owner/businesses/:id/photos — upload one or more photos
    if ($sub === 'businesses' && is_numeric($subsub) && $subsub !== '' && $subsubid === 'photos' && $method === 'POST') {
        $bizId = (int)$subsub;
        // Verify ownership
        $chk = $pdo->prepare('SELECT id FROM businesses WHERE id=? AND owner_id=?'); $chk->execute([$bizId,$uid]);
        if (!$chk->fetch()) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Not authorized to upload photos for this business.']); exit; }

        // Check existing photo count
        $cntS = $pdo->prepare('SELECT COUNT(*) as c FROM photos WHERE business_id=?'); $cntS->execute([$bizId]);
        $existingCount = (int)$cntS->fetch()['c'];

        if (empty($_FILES)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'No files uploaded.']); exit; }

        $uploaded = [];
        $errors   = [];

        // Handle both single file (photos) and multiple files (photos[])
        $files = [];
        if (isset($_FILES['photos'])) {
            $f = $_FILES['photos'];
            if (is_array($f['name'])) {
                // Multiple files
                for ($i = 0; $i < count($f['name']); $i++) {
                    $files[] = ['name' => $f['name'][$i], 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
                }
            } else {
                $files[] = $f;
            }
        } elseif (isset($_FILES['photo'])) {
            $files[] = $_FILES['photo'];
        }

        if (empty($files)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'No image files found in request. Use field name "photos" or "photo".']); exit; }

        if ($existingCount + count($files) > 10) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Maximum 10 photos per business. Currently have ' . $existingCount . '.']); exit; }

        foreach ($files as $i => $file) {
            try {
                $result    = upload_image($file, 'business');
                $isPrimary = ($existingCount === 0 && $i === 0) ? 1 : 0;
                $pdo->prepare('INSERT INTO photos (business_id, user_id, image_url, is_primary, uploaded_by) VALUES (?,?,?,?,?)')
                    ->execute([$bizId, $uid, $result['url'], $isPrimary, $uid]);
                $photoId    = (int)$pdo->lastInsertId();
                // Update business image_url if this is the primary
                if ($isPrimary) {
                    $pdo->prepare('UPDATE businesses SET image_url=? WHERE id=?')->execute([$result['url'], $bizId]);
                }
                $uploaded[] = ['id' => $photoId, 'url' => $result['url'], 'is_primary' => $isPrimary];
            } catch (RuntimeException $e) {
                $errors[] = 'Photo ' . ($i + 1) . ': ' . $e->getMessage();
            }
        }

        if (empty($uploaded)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => implode(' | ', $errors)]);
        } else {
            echo json_encode(['success' => true, 'message' => count($uploaded) . ' photo(s) uploaded.', 'data' => $uploaded, 'errors' => $errors]);
        }
        exit;
    }

    // PATCH /owner/businesses/:bizId/photos/:photoId/cover — set as cover
    if ($sub === 'businesses' && is_numeric($subsub) && $subsub !== '' && $subsubid === 'photos' && isset($parts[4]) && is_numeric($parts[4]) && isset($parts[5]) && $parts[5] === 'cover' && $method === 'PATCH') {
        $bizId   = (int)$subsub;
        $photoId = (int)$parts[4];
        $chk = $pdo->prepare('SELECT id FROM businesses WHERE id=? AND owner_id=?'); $chk->execute([$bizId,$uid]);
        if (!$chk->fetch()) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Not authorized.']); exit; }
        // Unset all primary, then set this one
        $pdo->prepare('UPDATE photos SET is_primary=0 WHERE business_id=?')->execute([$bizId]);
        $pdo->prepare('UPDATE photos SET is_primary=1 WHERE id=? AND business_id=?')->execute([$photoId, $bizId]);
        // Update business cover image
        $ph = $pdo->prepare('SELECT image_url FROM photos WHERE id=?'); $ph->execute([$photoId]);
        $phRow = $ph->fetch();
        if ($phRow) { $pdo->prepare('UPDATE businesses SET image_url=? WHERE id=?')->execute([$phRow['image_url'], $bizId]); }
        echo json_encode(['success' => true, 'message' => 'Cover photo updated.']);
        exit;
    }
    if ($sub === 'businesses' && is_numeric($subsub) && $subsub !== '' && $subsubid === 'photos' && isset($parts[4]) && is_numeric($parts[4]) && $method === 'DELETE') {
        $bizId   = (int)$subsub;
        $photoId = (int)$parts[4];
        // Verify ownership
        $chk = $pdo->prepare('SELECT id FROM businesses WHERE id=? AND owner_id=?'); $chk->execute([$bizId,$uid]);
        if (!$chk->fetch()) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Not authorized.']); exit; }

        $ps = $pdo->prepare('SELECT * FROM photos WHERE id=? AND business_id=?'); $ps->execute([$photoId,$bizId]);
        $photo = $ps->fetch();
        if (!$photo) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Photo not found.']); exit; }

        // Delete file from disk
        delete_upload_file($photo['image_url']);
        $pdo->prepare('DELETE FROM photos WHERE id=?')->execute([$photoId]);

        // If deleted photo was primary, promote next photo
        if ($photo['is_primary']) {
            $next = $pdo->prepare('SELECT id, image_url FROM photos WHERE business_id=? ORDER BY id ASC LIMIT 1'); $next->execute([$bizId]);
            $nextPhoto = $next->fetch();
            if ($nextPhoto) {
                $pdo->prepare('UPDATE photos SET is_primary=1 WHERE id=?')->execute([$nextPhoto['id']]);
                $pdo->prepare('UPDATE businesses SET image_url=? WHERE id=?')->execute([$nextPhoto['image_url'], $bizId]);
            } else {
                $pdo->prepare('UPDATE businesses SET image_url=NULL WHERE id=?')->execute([$bizId]);
            }
        }
        echo json_encode(['success' => true, 'message' => 'Photo deleted.']);
        exit;
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

// ── ADMIN ──────────────────────────────────────────────────────────────────────
if ($base === 'admin') {
    $uid = require_auth($JWT_SECRET);
    try {
        $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id=?');
        $roleStmt->execute([$uid]);
        $roleRow = $roleStmt->fetch();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Role check failed: ' . $e->getMessage()]);
        exit;
    }
    if (!$roleRow || $roleRow['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required. Role: ' . ($roleRow['role'] ?? 'none')]);
        exit;
    }

    // GET /admin/businesses?status=pending|approved|rejected
    if ($sub === 'businesses' && $method === 'GET') {
        $status = $_GET['status'] ?? 'pending';
        $lim    = max(1, (int)($_GET['limit'] ?? 50));
        $off    = max(0, (int)($_GET['offset'] ?? 0));
        try {
            // Check if status column exists
            $hasStatus = false;
            try { $pdo->query('SELECT status FROM businesses LIMIT 1'); $hasStatus = true; } catch (PDOException $e) {}

            if ($hasStatus) {
                $s = $pdo->prepare('SELECT id,name,category,address,city,owner_id,status,created_at FROM businesses WHERE status=? ORDER BY created_at DESC LIMIT ' . $lim . ' OFFSET ' . $off);
                $s->execute([$status]);
            } else {
                // status column missing — show all as pending
                $s = $pdo->prepare('SELECT id,name,category,address,city,owner_id,created_at FROM businesses ORDER BY created_at DESC LIMIT ' . $lim . ' OFFSET ' . $off);
                $s->execute();
            }
            echo json_encode(['success' => true, 'data' => $s->fetchAll(), 'status_column' => $hasStatus]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Query failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // PATCH /admin/businesses/:id — approve or reject
    if ($sub === 'businesses' && is_numeric($subsub) && $method === 'PATCH') {
        $bizId  = (int)$subsub;
        $status = $input['status'] ?? '';
        $reason = trim($input['rejection_reason'] ?? '');
        if (!in_array($status, ['approved', 'rejected'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'status must be approved or rejected.']);
            exit;
        }
        $isActive = $status === 'approved' ? 1 : 0;
        try {
            // Try with status column first
            $pdo->prepare('UPDATE businesses SET status=?, is_active=? WHERE id=?')->execute([$status, $isActive, $bizId]);
            // Save rejection reason if provided
            if ($status === 'rejected' && $reason) {
                try {
                    $pdo->prepare('UPDATE businesses SET rejection_reason=? WHERE id=?')->execute([$reason, $bizId]);
                } catch (PDOException $e) {
                    // rejection_reason column may not exist yet — ignore
                }
            }
        } catch (PDOException $e) {
            // Fallback if status column missing
            $pdo->prepare('UPDATE businesses SET is_active=? WHERE id=?')->execute([$isActive, $bizId]);
        }
        echo json_encode(['success' => true, 'message' => 'Business ' . $status . '.']);
        exit;
    }
}

// ── TEMPORARY DEBUG — remove after diagnosis ──────────────────────────────────
if ($base === 'debug-reviews') {
    header('Content-Type: application/json');
    $bizId = (int)($_GET['biz'] ?? 9);
    $results = [];
    // Test 1: does the reviews table exist?
    try {
        $t = $pdo->query('SHOW TABLES LIKE "reviews"');
        $results['table_exists'] = (bool)$t->fetch();
    } catch (Exception $e) { $results['table_exists'] = 'error: ' . $e->getMessage(); }
    // Test 2: what columns does it have?
    try {
        $c = $pdo->query('SHOW COLUMNS FROM reviews');
        $results['columns'] = array_column($c->fetchAll(), 'Field');
    } catch (Exception $e) { $results['columns'] = 'error: ' . $e->getMessage(); }
    // Test 3: simple count
    try {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE business_id=?');
        $cnt->execute([$bizId]);
        $results['count'] = (int)$cnt->fetchColumn();
    } catch (Exception $e) { $results['count'] = 'error: ' . $e->getMessage(); }
    // Test 4: the actual query we use
    try {
        $s = $pdo->prepare('SELECT r.id, r.business_id, r.user_id, r.rating, r.title, r.content, r.created_at, u.name as user_name, u.avatar_url FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.business_id=? ORDER BY r.created_at DESC LIMIT 5 OFFSET 0');
        $s->execute([$bizId]);
        $results['query_ok'] = true;
        $results['rows'] = $s->fetchAll();
    } catch (Exception $e) { $results['query_ok'] = false; $results['query_error'] = $e->getMessage(); }
    echo json_encode($results);
    exit;
}
function send_expo_push(array $tokens, string $title, string $body, array $data = []): void {
    if (empty($tokens)) return;
    $messages = array_map(fn($t) => [
        'to'    => $t,
        'sound' => 'default',
        'title' => $title,
        'body'  => $body,
        'data'  => $data,
    ], array_values($tokens));
    // Expo push endpoint accepts up to 100 per batch
    foreach (array_chunk($messages, 100) as $batch) {
        $ch = curl_init('https://exp.host/--/api/v2/push/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($batch),
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}

// ── HELPER: get push tokens for a list of user IDs ───────────────────────────
function get_push_tokens(PDO $pdo, array $userIds): array {
    if (empty($userIds)) return [];
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $s  = $pdo->prepare("SELECT token FROM push_tokens WHERE user_id IN ($ph)");
    $s->execute($userIds);
    return array_column($s->fetchAll(), 'token');
}

// ── POST /user/checkin-notify — notify followers when user checks in ──────────
if ($base === 'user' && $sub === 'checkin-notify' && $method === 'POST') {
    $uid   = require_auth($JWT_SECRET);
    $bizId = (int)($input['businessId'] ?? 0);
    if (!$bizId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'businessId required.']);
        exit;
    }

    // Fetch full business details for the rich notification payload
    $biz = $pdo->prepare('SELECT id, name, image_url, category, address, city FROM businesses WHERE id=? AND is_active=1');
    $biz->execute([$bizId]);
    $bizRow = $biz->fetch();
    if (!$bizRow) {
        echo json_encode(['success' => false, 'message' => 'Business not found.']);
        exit;
    }

    // Fetch the checking-in user's name
    $usr = $pdo->prepare('SELECT name, avatar_url FROM users WHERE id=?');
    $usr->execute([$uid]);
    $usrRow   = $usr->fetch();
    $userName = $usrRow['name'] ?? 'Someone';

    // Find all followers of this user
    $fol = $pdo->prepare('SELECT follower_id FROM follows WHERE following_id=?');
    $fol->execute([$uid]);
    $followerIds = array_column($fol->fetchAll(), 'follower_id');

    $notified = 0;
    if (!empty($followerIds)) {
        $notifTitle = "$userName is at {$bizRow['name']}";
        $notifMsg   = "{$bizRow['category']} · {$bizRow['city']}";

        // Rich data — both snake_case (in-app deep-link) and camelCase (push payload)
        $notifData = json_encode([
            'type'           => 'checkin',
            'business_id'    => $bizId,
            'businessId'     => $bizId,
            'user_id'        => $uid,
            'user_name'      => $userName,
            'business_name'  => $bizRow['name'],
            'business_image' => $bizRow['image_url'],
            'category'       => $bizRow['category'],
            'address'        => $bizRow['address'],
            'city'           => $bizRow['city'],
        ]);

        $ins = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($followerIds as $fid) {
            try {
                $ins->execute([$fid, 'checkin', $notifTitle, $notifMsg, $notifData]);
                $notified++;
            } catch (PDOException $e) { /* skip duplicate */ }
        }

        // Push notifications to followers who have tokens
        $tokens = get_push_tokens($pdo, $followerIds);
        if (!empty($tokens)) {
            send_expo_push($tokens, $notifTitle, $notifMsg, [
                'type'         => 'checkin',
                'business_id'  => $bizId,
                'businessId'   => $bizId,
                'businessName' => $bizRow['name'],
                'userId'       => $uid,
                'userName'     => $userName,
            ]);
        }
    }

    echo json_encode(['success' => true, 'notified' => $notified]);
    exit;
}

// ── CAMPAIGNS (admin) ─────────────────────────────────────────────────────────
if ($base === 'admin' && $sub === 'campaigns') {
    $uid = require_auth($JWT_SECRET);
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id=?'); $roleStmt->execute([$uid]);
    $rr = $roleStmt->fetch();
    if (!$rr || $rr['role'] !== 'admin') { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin only.']); exit; }

    // Ensure table exists
    $pdo->exec('CREATE TABLE IF NOT EXISTS campaigns (
        id INT PRIMARY KEY AUTO_INCREMENT,
        business_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        image_url VARCHAR(500) DEFAULT NULL,
        target_percent TINYINT NOT NULL DEFAULT 100,
        recipients_count INT DEFAULT 0,
        status ENUM("draft","sent","failed") DEFAULT "draft",
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    )');

    // GET /admin/campaigns — list all campaigns
    if ($method === 'GET') {
        $s = $pdo->prepare('SELECT c.*, b.name as business_name, b.image_url as business_image FROM campaigns c JOIN businesses b ON c.business_id=b.id ORDER BY c.created_at DESC LIMIT 50');
        $s->execute();
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]);
        exit;
    }

    // POST /admin/campaigns — create + send campaign
    if ($method === 'POST') {
        $bizId   = (int)($input['business_id'] ?? 0);
        $title   = trim($input['title'] ?? '');
        $message = trim($input['message'] ?? '');
        $percent = max(1, min(100, (int)($input['target_percent'] ?? 100)));
        $imgUrl  = trim($input['image_url'] ?? '');

        if (!$bizId || !$title || !$message) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'business_id, title, and message are required.']);
            exit;
        }

        // Verify business exists
        $biz = $pdo->prepare('SELECT name FROM businesses WHERE id=? AND is_active=1'); $biz->execute([$bizId]);
        $bizRow = $biz->fetch();
        if (!$bizRow) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Business not found.']); exit; }

        // Select random eligible users (all users with push tokens)
        $allUsers = $pdo->query('SELECT pt.user_id, pt.token FROM push_tokens pt JOIN users u ON pt.user_id=u.id WHERE u.role="user"');
        $eligible = $allUsers->fetchAll();

        // Randomise and pick target percent
        shuffle($eligible);
        $count     = max(1, (int)ceil(count($eligible) * $percent / 100));
        $selected  = array_slice($eligible, 0, $count);
        $tokens    = array_column($selected, 'token');
        $userIds   = array_column($selected, 'user_id');

        // Record campaign
        $pdo->prepare('INSERT INTO campaigns (business_id, title, message, image_url, target_percent, recipients_count, status, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$bizId, $title, $message, $imgUrl ?: null, $percent, count($tokens), 'sent', $uid]);
        $campaignId = $pdo->lastInsertId();

        // Insert in-app notifications for selected users
        $ins = $pdo->prepare('INSERT IGNORE INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)');
        $notifData = json_encode(['type' => 'promo', 'campaign_id' => (int)$campaignId, 'business_id' => $bizId]);
        foreach ($userIds as $fid) {
            try { $ins->execute([$fid, 'promo', $title, $message, $notifData]); } catch (PDOException $e) {}
        }

        // Send push notifications
        send_expo_push($tokens, $title, $message, [
            'type' => 'promo',
            'campaignId' => (int)$campaignId,
            'businessId' => $bizId,
            'businessName' => $bizRow['name'],
        ]);

        $pdo->prepare('UPDATE campaigns SET sent_at=NOW() WHERE id=?')->execute([$campaignId]);
        echo json_encode(['success' => true, 'message' => 'Campaign sent.', 'recipients' => count($tokens), 'campaignId' => (int)$campaignId]);
        exit;
    }
}

// ── POST /user/saved-reminder-check ───────────────────────────────────────────
// Lightweight "you saved this" nudge — NOT a visit appointment.
// Rules:
//  • Only after ~5 days since save (within the 3–7 day window)
//  • At most ONE reminder per user+business (ever), recorded in saved_reminders
//  • Skip if the user already checked in / visited after saving
//  • Max 1 reminder per API call (no spam bursts)
//  • Push is best-effort; in-app notification always recorded when reminding
if ($base === 'user' && $sub === 'saved-reminder-check' && $method === 'POST') {
    $uid = require_auth($JWT_SECRET);

    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS saved_reminders (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            business_id INT NOT NULL,
            reminded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_biz (user_id, business_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
        )');
    } catch (PDOException $e) {
        // Table may already exist without FK on some hosts — continue
    }

    // One eligible save: old enough, never reminded, still favorited, still active,
    // and user has not already visited that place after saving it.
    $s = $pdo->prepare('
        SELECT f.business_id, b.name AS biz_name
        FROM favorites f
        JOIN businesses b ON b.id = f.business_id AND b.is_active = 1
        LEFT JOIN saved_reminders sr
          ON sr.user_id = f.user_id AND sr.business_id = f.business_id
        LEFT JOIN visits v
          ON v.user_id = f.user_id AND v.business_id = f.business_id
         AND v.visited_at >= f.created_at
        WHERE f.user_id = ?
          AND f.created_at <= DATE_SUB(NOW(), INTERVAL 5 DAY)
          AND sr.id IS NULL
          AND v.id IS NULL
        ORDER BY f.created_at ASC
        LIMIT 1
    ');
    $s->execute([$uid]);
    $row = $s->fetch();

    if (!$row) {
        echo json_encode(['success' => true, 'reminders_sent' => 0]);
        exit;
    }

    $bizId   = (int)$row['business_id'];
    $bizName = $row['biz_name'];
    $title   = "You saved {$bizName} a few days ago";
    $body    = 'Want to check it out?';
    $payload = [
        'type'         => 'saved_reminder',
        'business_id'  => $bizId,
        'businessId'   => $bizId,
        'businessName' => $bizName,
    ];

    // Record FIRST so concurrent checks cannot double-send
    try {
        $pdo->prepare('INSERT INTO saved_reminders (user_id, business_id) VALUES (?,?)')
            ->execute([$uid, $bizId]);
    } catch (PDOException $e) {
        // Already reminded (race) — stop
        echo json_encode(['success' => true, 'reminders_sent' => 0]);
        exit;
    }

    // In-app notification (always — works without push permission)
    try {
        $pdo->prepare(
            'INSERT INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)'
        )->execute([
            $uid,
            'reminder',
            $title,
            $body,
            json_encode($payload),
        ]);
    } catch (PDOException $e) {}

    // Push — best effort; never fail the request if Expo/push is unavailable
    try {
        $tk = $pdo->prepare('SELECT token FROM push_tokens WHERE user_id=?');
        $tk->execute([$uid]);
        $tok = $tk->fetchColumn();
        if ($tok) {
            send_expo_push([$tok], $title, $body, $payload);
        }
    } catch (Throwable $e) {}

    echo json_encode(['success' => true, 'reminders_sent' => 1]);
    exit;
}

// ── GET /admin/users — list all users (admin only, for campaign targeting info) ──
if ($base === 'admin' && $sub === 'users' && $method === 'GET') {
    $uid = require_auth($JWT_SECRET);
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id=?'); $roleStmt->execute([$uid]);
    $rr = $roleStmt->fetch();
    if (!$rr || $rr['role'] !== 'admin') { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin only.']); exit; }
    $s = $pdo->query('SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 200');
    echo json_encode(['success' => true, 'data' => $s->fetchAll()]);
    exit;
}

// ── GET /admin/stats — dashboard stats ──
if ($base === 'admin' && $sub === 'stats' && $method === 'GET') {
    $uid = require_auth($JWT_SECRET);
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id=?'); $roleStmt->execute([$uid]);
    $rr = $roleStmt->fetch();
    if (!$rr || $rr['role'] !== 'admin') { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Admin only.']); exit; }
    $totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalBiz   = $pdo->query('SELECT COUNT(*) FROM businesses WHERE is_active=1')->fetchColumn();
    $pendingBiz = 0;
    try { $pendingBiz = $pdo->query("SELECT COUNT(*) FROM businesses WHERE status='pending'")->fetchColumn(); } catch (PDOException $e) {}
    $totalReviews = $pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
    $tokenCount = 0;
    try { $tokenCount = $pdo->query('SELECT COUNT(*) FROM push_tokens')->fetchColumn(); } catch (PDOException $e) {}
    echo json_encode(['success' => true, 'data' => [
        'total_users' => (int)$totalUsers,
        'active_businesses' => (int)$totalBiz,
        'pending_businesses' => (int)$pendingBiz,
        'total_reviews' => (int)$totalReviews,
        'push_token_users' => (int)$tokenCount,
    ]]);
    exit;
}

// ── TEMPORARY DEBUG — diagnose reviews 500 ───────────────────────────────────
if ($base === 'debug-reviews') {
    $bizId = (int)($_GET['biz'] ?? 9);
    $out = [];
    try { $t = $pdo->query('SHOW TABLES LIKE "reviews"'); $out['table'] = (bool)$t->fetch(); } catch(Exception $e) { $out['table'] = $e->getMessage(); }
    try { $c = $pdo->query('SHOW COLUMNS FROM reviews'); $out['cols'] = array_column($c->fetchAll(), 'Field'); } catch(Exception $e) { $out['cols'] = $e->getMessage(); }
    try { $cnt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE business_id=?'); $cnt->execute([$bizId]); $out['count'] = (int)$cnt->fetchColumn(); } catch(Exception $e) { $out['count'] = $e->getMessage(); }
    try {
        $s = $pdo->prepare('SELECT r.id, r.rating, r.content, u.name as user_name FROM reviews r JOIN users u ON r.user_id=u.id WHERE r.business_id=? LIMIT 3');
        $s->execute([$bizId]);
        $out['rows'] = $s->fetchAll();
    } catch(Exception $e) { $out['rows'] = $e->getMessage(); }
    echo json_encode($out);
    exit;
}

// ── 404 ───────────────────────────────────────────────────────────────────────
http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Route not found: /' . $base . '/' . $sub]);
