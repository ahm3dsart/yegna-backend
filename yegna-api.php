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

// ── DATABASE ──────────────────────────────────────────────────────────────────
$db_host   = 'mysql-db02.remote';
$db_port   = '32636';
$db_name   = 'yegna';
$db_user   = 'ahmed';
$db_pass   = 'Uwk_9832i';
$JWT_SECRET = 'yegna_jwt_super_secret_2026';

try {
    $pdo = new PDO(
        "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_TIMEOUT => 5, // fail fast — 5 second connection timeout
        ]
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// ── ROUTE PARSING (same pattern as VerifyPay) ─────────────────────────────────
$path = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');

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
    if ($imgInfo['mime'] === 'image/png') { $ext = 'png'; }
    elseif ($imgInfo['mime'] === 'image/webp') { $ext = 'webp'; }
    else { $ext = 'jpg'; }
    $filename = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
    $destPath = $baseDir . '/' . $filename;

    // ── 5. Try GD compression (40% quality reduction) ────────────────────────
    $gdAvailable = function_exists('imagecreatefromjpeg') &&
                   function_exists('imagecreatefrompng')  &&
                   function_exists('imagejpeg');

    if ($gdAvailable) {
        // Load source image
        if ($imgInfo['mime'] === 'image/png') {
            $src = @imagecreatefrompng($file['tmp_name']);
        } elseif ($imgInfo['mime'] === 'image/webp') {
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false;
        } else {
            $src = @imagecreatefromjpeg($file['tmp_name']);
        }

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
    echo json_encode(['status' => 'OK', 'timestamp' => date('c'), 'version' => '2.0.0', 'db' => 'connected', 'gd' => $gdOk ? 'enabled' : 'disabled']);
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

    // ── POST /auth/google ─────────────────────────────────────────────────────
    // Step 1: client sends { idToken } — verify with Google, sign in or prompt username
    // Step 2: client sends { pendingToken, username, birth_date } — create account
    if ($sub === 'google' && $method === 'POST') {

        // ── STEP 2: Complete signup — username provided ───────────────────────
        if (!empty($input['pendingToken'])) {
            $pending = jwt_decode($input['pendingToken'], $JWT_SECRET);
            if (!$pending || ($pending['type'] ?? '') !== 'google_pending') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Session expired. Please sign in with Google again.']);
                exit;
            }
            $username = trim($input['username'] ?? '');
            $uErr = validate_username($username);
            if ($uErr) { http_response_code(400); echo json_encode(['success' => false, 'message' => $uErr]); exit; }
            if (username_exists($pdo, $username)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Username is already taken.']);
                exit;
            }
            // Create the user
            $uid = create_user($pdo, [
                'name'           => $pending['name'],
                'email'          => $pending['email'],
                'username'       => $username,
                'google_id'      => $pending['google_id'],
                'avatar_url'     => $pending['avatar_url'] ?? null,
                'email_verified' => 1,
                'birth_date'     => $input['birth_date'] ?? null,
                'role'           => 'user',
            ]);
            $token   = jwt_encode(['id' => $uid], $JWT_SECRET);
            $userRow = find_user_by_id($pdo, $uid);
            echo json_encode(['success' => true, 'token' => $token, 'user' => $userRow]);
            exit;
        }

        // ── STEP 1: Verify Google ID token ────────────────────────────────────
        $idToken = trim($input['idToken'] ?? '');
        if (!$idToken) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'idToken is required.']);
            exit;
        }

        // Verify with Google tokeninfo endpoint
        $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
        $ch = curl_init($verifyUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $http !== 200) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Could not verify Google token. Please try again.']);
            exit;
        }

        $google = json_decode($raw, true);

        // Verify audience — must match our web client ID (project yegna-29420)
        $expectedAud = '982440221997-lpodqff0221parm251dfu2umfnn5dmb3.apps.googleusercontent.com';
        $aud = $google['aud'] ?? '';
        if ($aud !== $expectedAud && $aud !== '982440221997') {
            // Also accept if aud is a comma-separated list containing our client ID
            $audList = array_map('trim', explode(',', $aud));
            if (!in_array($expectedAud, $audList, true)) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid Google token audience.']);
                exit;
            }
        }

        $googleId  = $google['sub'] ?? '';
        $email     = strtolower(trim($google['email'] ?? ''));
        $name      = $google['name'] ?? $google['given_name'] ?? 'User';
        $avatarUrl = $google['picture'] ?? null;

        if (!$googleId || !$email) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Incomplete profile from Google.']);
            exit;
        }

        // Look up by google_id first, then by email
        $user = null;
        try {
            $gs = $pdo->prepare('SELECT * FROM users WHERE google_id=? LIMIT 1');
            $gs->execute([$googleId]);
            $user = $gs->fetch() ?: null;
        } catch (PDOException $e) {}

        if (!$user) {
            $user = find_user_by_email($pdo, $email);
            if ($user && empty($user['google_id'])) {
                // Link Google ID to existing email account
                try {
                    $pdo->prepare('UPDATE users SET google_id=?, avatar_url=COALESCE(NULLIF(avatar_url,""),?) WHERE id=?')
                        ->execute([$googleId, $avatarUrl, $user['id']]);
                } catch (PDOException $e) {}
                $user = find_user_by_id($pdo, $user['id']);
            }
        }

        // ── Existing user — sign in ───────────────────────────────────────────
        if ($user) {
            $token   = jwt_encode(['id' => $user['id']], $JWT_SECRET);
            $userRow = find_user_by_id($pdo, $user['id']);
            echo json_encode(['success' => true, 'token' => $token, 'user' => $userRow]);
            exit;
        }

        // ── New user — need username before creating account ──────────────────
        // Issue a short-lived pending token carrying the Google profile (5 min)
        $pendingToken = jwt_encode([
            'type'       => 'google_pending',
            'google_id'  => $googleId,
            'email'      => $email,
            'name'       => $name,
            'avatar_url' => $avatarUrl,
        ], $JWT_SECRET, 300); // 5 minute expiry

        echo json_encode([
            'success'      => true,
            'needsUsername'=> true,
            'pendingToken' => $pendingToken,
            'googleData'   => [
                'name'       => $name,
                'email'      => $email,
                'avatar_url' => $avatarUrl,
                'birth_date' => null,
            ],
        ]);
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

    // POST /businesses/:id/reviews/:reviewId/helpful — toggle helpful vote
    if (is_numeric($sub) && $subsub === 'reviews' && is_numeric($subsubid) && $method === 'POST') {
        $bizId = (int)$sub;
        $rid   = (int)$subsubid;
        $authUid = get_auth_user_id($JWT_SECRET);
        if (!$authUid) { http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required.']); exit; }

        // Ensure vote table exists (graceful — no crash if migration not yet run)
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS review_helpful_votes (
                id         INT       PRIMARY KEY AUTO_INCREMENT,
                review_id  INT       NOT NULL,
                user_id    INT       NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_review_user (review_id, user_id),
                FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
                INDEX idx_review (review_id),
                INDEX idx_user   (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $_) {}

        // Check whether review belongs to this business
        $chk = $pdo->prepare('SELECT id FROM reviews WHERE id=? AND business_id=?');
        $chk->execute([$rid, $bizId]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Review not found.']); exit; }

        // A user cannot mark their own review as helpful
        $owner = $pdo->prepare('SELECT user_id FROM reviews WHERE id=?');
        $owner->execute([$rid]);
        $ownerRow = $owner->fetch();
        if ($ownerRow && (int)$ownerRow['user_id'] === $authUid) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'You cannot mark your own review as helpful.']);
            exit;
        }

        // Toggle: if already voted → remove; if not → add
        $exists = $pdo->prepare('SELECT id FROM review_helpful_votes WHERE review_id=? AND user_id=?');
        $exists->execute([$rid, $authUid]);
        $alreadyVoted = (bool)$exists->fetch();

        if ($alreadyVoted) {
            // Remove vote
            $pdo->prepare('DELETE FROM review_helpful_votes WHERE review_id=? AND user_id=?')
                ->execute([$rid, $authUid]);
            // Decrement count (floor at 0)
            $pdo->prepare('UPDATE reviews SET helpful_count = GREATEST(0, helpful_count - 1) WHERE id=?')
                ->execute([$rid]);
            $action = 'removed';
        } else {
            // Add vote
            try {
                $pdo->prepare('INSERT INTO review_helpful_votes (review_id, user_id) VALUES (?,?)')
                    ->execute([$rid, $authUid]);
                $pdo->prepare('UPDATE reviews SET helpful_count = helpful_count + 1 WHERE id=?')
                    ->execute([$rid]);
            } catch (PDOException $e) {
                // Race condition: duplicate insert — already voted, treat as no-op
                $action = 'no_change';
                $cnt = $pdo->prepare('SELECT helpful_count FROM reviews WHERE id=?');
                $cnt->execute([$rid]);
                $row = $cnt->fetch();
                echo json_encode(['success' => true, 'action' => 'no_change', 'helpful_count' => (int)($row['helpful_count'] ?? 0), 'marked' => true]);
                exit;
            }
            $action = 'added';
        }

        // Return updated count
        $cnt = $pdo->prepare('SELECT helpful_count FROM reviews WHERE id=?');
        $cnt->execute([$rid]);
        $row = $cnt->fetch();
        echo json_encode([
            'success'       => true,
            'action'        => $action,
            'helpful_count' => (int)($row['helpful_count'] ?? 0),
            'marked'        => $action === 'added',
        ]);
        exit;
    }

    // GET /businesses/:id/reviews
    if (is_numeric($sub) && $subsub === 'reviews' && $method === 'GET' && $subsubid === '') {
        $bizId   = (int)$sub;
        $lim     = max(1, (int)($_GET['limit'] ?? 20));
        $off     = max(0, (int)($_GET['offset'] ?? 0));
        $viewerId = get_auth_user_id($JWT_SECRET); // null if unauthenticated
        try {
            if ($viewerId) {
                // Return helpful_count + whether current viewer already voted
                $sql = 'SELECT r.id, r.business_id, r.user_id, r.rating, r.title, r.content,
                               r.created_at, r.helpful_count,
                               u.name as user_name, u.avatar_url,
                               (SELECT COUNT(*) FROM review_helpful_votes rhv
                                WHERE rhv.review_id = r.id AND rhv.user_id = ?) AS user_marked_helpful
                        FROM reviews r
                        JOIN users u ON r.user_id = u.id
                        WHERE r.business_id = ?
                        ORDER BY r.created_at DESC
                        LIMIT ' . $lim . ' OFFSET ' . $off;
                $s = $pdo->prepare($sql);
                $s->execute([$viewerId, $bizId]);
            } else {
                $sql = 'SELECT r.id, r.business_id, r.user_id, r.rating, r.title, r.content,
                               r.created_at, r.helpful_count,
                               u.name as user_name, u.avatar_url,
                               0 AS user_marked_helpful
                        FROM reviews r
                        JOIN users u ON r.user_id = u.id
                        WHERE r.business_id = ?
                        ORDER BY r.created_at DESC
                        LIMIT ' . $lim . ' OFFSET ' . $off;
                $s = $pdo->prepare($sql);
                $s->execute([$bizId]);
            }
            $rows = $s->fetchAll();
            // Cast types for the frontend
            foreach ($rows as &$row) {
                $row['helpful_count']       = (int)($row['helpful_count'] ?? 0);
                $row['user_marked_helpful'] = (bool)$row['user_marked_helpful'];
            }
            unset($row);
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE business_id=?');
            $cnt->execute([$bizId]);
            $total = (int)$cnt->fetchColumn();
            echo json_encode(['success' => true, 'data' => $rows, 'total' => $total, 'distribution' => []]);
        } catch (PDOException $e) {
            // Graceful fallback if review_helpful_votes table not yet migrated
            $sql = 'SELECT r.id, r.business_id, r.user_id, r.rating, r.title, r.content,
                           r.created_at, r.helpful_count,
                           u.name as user_name, u.avatar_url,
                           0 AS user_marked_helpful
                    FROM reviews r
                    JOIN users u ON r.user_id = u.id
                    WHERE r.business_id = ?
                    ORDER BY r.created_at DESC
                    LIMIT ' . $lim . ' OFFSET ' . $off;
            try {
                $s = $pdo->prepare($sql); $s->execute([$bizId]);
                $rows = $s->fetchAll();
                foreach ($rows as &$row) {
                    $row['helpful_count']       = (int)($row['helpful_count'] ?? 0);
                    $row['user_marked_helpful'] = false;
                }
                unset($row);
                $cnt = $pdo->prepare('SELECT COUNT(*) FROM reviews WHERE business_id=?');
                $cnt->execute([$bizId]);
                echo json_encode(['success' => true, 'data' => $rows, 'total' => (int)$cnt->fetchColumn(), 'distribution' => []]);
            } catch (PDOException $_) {
                echo json_encode(['success' => true, 'data' => [], 'total' => 0, 'distribution' => []]);
            }
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
    if ($sub === 'profile' && ($method === 'PUT' || $method === 'PATCH' || $method === 'POST')) {
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
        // NOTE: 'role' is intentionally excluded — role changes must go through admin routes only
        $allowed = ['name','email','phone','bio','avatar_url','username','birth_date'];
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
    // POST /user/push-token — register/refresh Expo push token
    if ($sub === 'push-token' && $method === 'POST') {
        $token       = trim($input['token'] ?? '');
        $platform    = trim($input['platform'] ?? '');
        $device_info = trim($input['device_info'] ?? '');
        if (!$token) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Token required.']);
            exit;
        }
        try {
            // Ensure table has all needed columns (safe to run repeatedly — IF NOT EXISTS / ignore errors)
            $pdo->exec('CREATE TABLE IF NOT EXISTS push_tokens (
                id          INT PRIMARY KEY AUTO_INCREMENT,
                user_id     INT NOT NULL UNIQUE,
                token       VARCHAR(255) NOT NULL,
                platform    VARCHAR(20) DEFAULT NULL,
                device_info VARCHAR(100) DEFAULT NULL,
                is_active   TINYINT(1) NOT NULL DEFAULT 1,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )');
            // Add missing columns to existing tables (ALTER IF NOT EXISTS not available in MySQL 5.x)
            foreach (['platform VARCHAR(20) DEFAULT NULL', 'device_info VARCHAR(100) DEFAULT NULL', 'is_active TINYINT(1) NOT NULL DEFAULT 1'] as $col) {
                try { $pdo->exec("ALTER TABLE push_tokens ADD COLUMN $col"); } catch (PDOException $_) {}
            }
            $pdo->prepare('INSERT INTO push_tokens (user_id, token, platform, device_info, is_active)
                VALUES (?,?,?,?,1)
                ON DUPLICATE KEY UPDATE token=VALUES(token), platform=VALUES(platform),
                    device_info=VALUES(device_info), is_active=1, created_at=NOW()')
                ->execute([$uid, $token, $platform ?: null, $device_info ?: null]);
            echo json_encode(['success' => true, 'message' => 'Push token registered.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'message' => 'Push token noted.']); // non-critical
        }
        exit;
    }

    // DELETE /user/push-token — deactivate token on logout (so the user stops receiving pushes on this device)
    if ($sub === 'push-token' && $method === 'DELETE') {
        $token = trim($input['token'] ?? $_GET['token'] ?? '');
        if ($token) {
            try {
                $pdo->prepare('UPDATE push_tokens SET is_active=0 WHERE user_id=? AND token=?')
                    ->execute([$uid, $token]);
            } catch (PDOException $_) {}
        }
        echo json_encode(['success' => true, 'message' => 'Token deactivated.']);
        exit;
    }

    // ── GET /user/notification-prefs ─────────────────────────────────────────
    if ($sub === 'notification-prefs' && $method === 'GET') {
        // Table is created by the migration (privacy_notifications_migration.sql).
        // Graceful fallback: if the table doesn't exist yet, return defaults.
        try {
            $s = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id=?');
            $s->execute([$uid]);
            $row = $s->fetch();
        } catch (PDOException $_) {
            $row = false; // table not yet migrated — return defaults
        }
        if (!$row) {
            $row = [
                'user_id' => $uid, 'follows' => 1, 'checkins' => 1,
                'new_reviews' => 1, 'replies' => 1, 'trending' => 1,
                'promotions' => 0, 'events' => 1, 'updates' => 0,
            ];
        }
        foreach (['follows','checkins','new_reviews','replies','trending','promotions','events','updates'] as $k) {
            $row[$k] = (bool)$row[$k];
        }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    // ── PATCH /user/notification-prefs ───────────────────────────────────────
    if ($sub === 'notification-prefs' && ($method === 'PATCH' || $method === 'POST' || $method === 'PUT')) {
        $allowed = ['follows','checkins','new_reviews','replies','trending','promotions','events','updates'];
        $sets = []; $vals = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $input)) {
                $sets[] = "$k=?";
                $vals[] = $input[$k] ? 1 : 0;
            }
        }
        if (!$sets) { echo json_encode(['success' => true, 'message' => 'No changes.']); exit; }

        try {
            // Upsert: insert defaults first, then update the changed columns
            $pdo->prepare("INSERT IGNORE INTO notification_preferences
                (user_id,follows,checkins,new_reviews,replies,trending,promotions,events,updates)
                VALUES (?,1,1,1,1,1,0,1,0)")
                ->execute([$uid]);
            $vals[] = $uid;
            $pdo->prepare('UPDATE notification_preferences SET ' . implode(',', $sets) . ' WHERE user_id=?')
                ->execute($vals);
        } catch (PDOException $e) {
            // Table not migrated yet — silently accept (preferences will take effect after migration)
            http_response_code(200);
        }
        echo json_encode(['success' => true, 'message' => 'Notification preferences saved.']);
        exit;
    }

    // ── GET /user/privacy-settings — public_profile + show_location ─────────
    if ($sub === 'privacy-settings' && $method === 'GET') {
        // Ensure new columns exist (graceful — ALTER fails silently if already there)
        try { $pdo->exec("ALTER TABLE user_privacy ADD COLUMN public_profile TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException $_) {}
        try { $pdo->exec("ALTER TABLE user_privacy ADD COLUMN show_location TINYINT(1) NOT NULL DEFAULT 1");  } catch (PDOException $_) {}

        $s = $pdo->prepare('SELECT public_profile, show_location FROM user_privacy WHERE user_id=?');
        $s->execute([$uid]);
        $row = $s->fetch();
        if (!$row) {
            $pdo->prepare('INSERT IGNORE INTO user_privacy (user_id) VALUES (?)')->execute([$uid]);
            $row = ['public_profile' => 1, 'show_location' => 1];
        }
        echo json_encode(['success' => true, 'data' => [
            'public_profile' => (bool)$row['public_profile'],
            'show_location'  => (bool)$row['show_location'],
        ]]);
        exit;
    }

    // ── PATCH /user/privacy-settings — public_profile + show_location ───────
    if ($sub === 'privacy-settings' && ($method === 'PATCH' || $method === 'POST' || $method === 'PUT')) {
        try { $pdo->exec("ALTER TABLE user_privacy ADD COLUMN public_profile TINYINT(1) NOT NULL DEFAULT 1"); } catch (PDOException $_) {}
        try { $pdo->exec("ALTER TABLE user_privacy ADD COLUMN show_location TINYINT(1) NOT NULL DEFAULT 1");  } catch (PDOException $_) {}

        $pdo->prepare('INSERT IGNORE INTO user_privacy (user_id) VALUES (?)')->execute([$uid]);
        $sets = []; $vals = [];
        if (array_key_exists('public_profile', $input)) { $sets[] = 'public_profile=?'; $vals[] = $input['public_profile'] ? 1 : 0; }
        if (array_key_exists('show_location',  $input)) { $sets[] = 'show_location=?';  $vals[] = $input['show_location']  ? 1 : 0; }
        if ($sets) {
            $vals[] = $uid;
            $pdo->prepare('UPDATE user_privacy SET ' . implode(',', $sets) . ' WHERE user_id=?')->execute($vals);
        }
        echo json_encode(['success' => true, 'message' => 'Privacy settings saved.']);
        exit;
    }

} // end if ($base === 'user')

// ── NOTIFICATIONS ─────────────────────────────────────────────────────────────
if ($base === 'notifications') {
    $uid = require_auth($JWT_SECRET);
    // GET /notifications — list + unread count
    if ($sub === '' && $method === 'GET') {
        $lim = max(1, (int)($_GET['limit'] ?? 20));
        $off = max(0, (int)($_GET['offset'] ?? 0));
        $s = $pdo->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ' . $lim . ' OFFSET ' . $off);
        $s->execute([$uid]);
        $u = $pdo->prepare('SELECT COUNT(*) as c FROM notifications WHERE user_id=? AND is_read=0'); $u->execute([$uid]);
        // Ensure data field is JSON decoded (PHP PDO returns it as string)
        $rows = $s->fetchAll();
        foreach ($rows as &$row) {
            if (!empty($row['data']) && is_string($row['data'])) {
                $decoded = json_decode($row['data'], true);
                if (json_last_error() === JSON_ERROR_NONE) $row['data'] = $decoded;
            }
        }
        echo json_encode(['success' => true, 'data' => $rows, 'unreadCount' => (int)$u->fetch()['c']]); exit;
    }
    // PATCH /notifications/read-all — mark all read
    if ($sub === 'read-all' && ($method === 'PATCH' || $method === 'POST' || $method === 'PUT')) {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$uid]);
        echo json_encode(['success' => true, 'message' => 'All marked as read.', 'data' => null]); exit;
    }
    // GET backwards-compat /notifications/read-all
    if ($sub === 'read-all' && $method === 'GET') {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$uid]);
        echo json_encode(['success' => true, 'message' => 'All marked as read.', 'data' => null]); exit;
    }
    // PATCH /notifications/:id/read — mark one read
    if (is_numeric($sub) && $subsub === 'read' && ($method === 'PATCH' || $method === 'POST' || $method === 'PUT')) {
        $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([(int)$sub, $uid]);
        echo json_encode(['success' => true, 'data' => null]); exit;
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

    // ── Follow / Unfollow ─────────────────────────────────────────────────────
    if ($sub === 'follow' && is_numeric($subsub) && $method === 'POST') {
        $tid = (int)$subsub;
        if ($tid === $uid) { http_response_code(400); echo json_encode(['success' => false, 'message' => "Can't follow yourself."]); exit; }
        $pdo->prepare('INSERT IGNORE INTO follows (follower_id,following_id) VALUES (?,?)')->execute([$uid, $tid]);

        // Are we mutual (friends)?
        $mutualS = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=? AND following_id=?');
        $mutualS->execute([$tid, $uid]);
        $isFriend = (bool)$mutualS->fetch()['c'];

        // Notify the person who was followed
        $follower = $pdo->prepare('SELECT name FROM users WHERE id=?'); $follower->execute([$uid]);
        $followerRow = $follower->fetch();
        $followerName = $followerRow['name'] ?? 'Someone';
        $notifTitle = $isFriend ? "$followerName and you are now friends! 🎉" : "$followerName started following you";
        $notifMsg   = $isFriend ? "You and $followerName follow each other — you're now friends." : "$followerName is now following you. Follow back to become friends!";
        $notifData  = json_encode(['type' => 'follow', 'sender_id' => $uid, 'actor_id' => $uid, 'user_id' => $uid, 'userId' => $uid, 'user_name' => $followerName, 'is_mutual' => $isFriend]);
        try {
            $pdo->prepare('INSERT IGNORE INTO notifications (user_id, type, title, message, data) VALUES (?,?,?,?,?)')
                ->execute([$tid, 'follow', $notifTitle, $notifMsg, $notifData]);
        } catch (PDOException $e) {}
        $tokens = get_push_tokens($pdo, [$tid]);
        if (!empty($tokens)) {
            // Check recipient's notification preference for follows
            if (notif_pref_enabled($pdo, $tid, 'follows')) {
                send_expo_push($tokens, $notifTitle, $notifMsg, [
                    'type'      => 'follow',
                    'sender_id' => $uid,
                    'actor_id'  => $uid,
                    'user_id'   => $uid,
                    'userId'    => $uid,
                    'userName'  => $followerName,
                    'is_mutual' => $isFriend,
                ]);
            }
        }

        // Counts
        $fc = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE following_id=?'); $fc->execute([$tid]);
        $ng = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=?');  $ng->execute([$tid]);
        echo json_encode(['success' => true, 'data' => [
            'is_following' => true,
            'is_friend'    => $isFriend,
            'followers'    => (int)$fc->fetch()['c'],
            'following'    => (int)$ng->fetch()['c'],
        ]]); exit;
    }
    if ($sub === 'follow' && is_numeric($subsub) && $method === 'DELETE') {
        $tid = (int)$subsub;
        $pdo->prepare('DELETE FROM follows WHERE follower_id=? AND following_id=?')->execute([$uid, $tid]);
        echo json_encode(['success' => true, 'data' => ['is_following' => false, 'is_friend' => false]]); exit;
    }
    // ── Follow status ──────────────────────────────────────────────────────────
    if ($sub === 'follow' && is_numeric($subsub) && $subsubid === 'status' && $method === 'GET') {
        $tid = (int)$subsub;
        $fc = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=? AND following_id=?'); $fc->execute([$uid, $tid]);
        $bk = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=? AND following_id=?'); $bk->execute([$tid, $uid]);
        $tc = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE following_id=?'); $tc->execute([$tid]);
        $tg = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=?');  $tg->execute([$tid]);
        $fr = $pdo->prepare('SELECT COUNT(*) as c FROM follows f1 JOIN follows f2 ON f1.follower_id=f2.following_id AND f1.following_id=f2.follower_id WHERE f1.follower_id=?');
        $fr->execute([$tid]);
        $isFollowing = (bool)$fc->fetch()['c'];
        $isFollowedBack = (bool)$bk->fetch()['c'];
        echo json_encode(['success' => true, 'data' => [
            'is_following' => $isFollowing,
            'is_friend'    => $isFollowing && $isFollowedBack,
            'followers'    => (int)$tc->fetch()['c'],
            'following'    => (int)$tg->fetch()['c'],
            'friends'      => (int)$fr->fetch()['c'],
        ]]); exit;
    }

    // ── Friends activity feed ──────────────────────────────────────────────────
    if ($sub === 'feed' && $method === 'GET') {
        $lim = max(1, min(50, (int)($_GET['limit'] ?? 20)));
        $off = max(0, (int)($_GET['offset'] ?? 0));
        // Privacy enforcement — BOTH global setting and per-post visibility checked:
        //   activity_visibility = everyone → visible to ALL authenticated users
        //   activity_visibility = friends  → visible only to mutual followers
        //   activity_visibility = only_me  → visible only to the owner
        //   Per-post visibility also checked; stricter rule wins.
        //
        // Feed shows posts from people the viewer follows PLUS posts with
        // activity_visibility=everyone from anyone (true public discovery).
        $s = $pdo->prepare("
            SELECT
              af.id, af.type, af.caption, af.rating, af.photo_count, af.created_at,
              af.visibility,
              u.id AS user_id, u.name AS user_name, u.avatar_url,
              b.id AS business_id, b.name AS business_name,
              b.category, b.image_url, b.address, b.city, b.rating AS business_rating,
              (SELECT COUNT(*) FROM follows f2
               WHERE f2.follower_id = af.user_id AND f2.following_id = ?) AS is_friend
            FROM activity_feed af
            JOIN users u      ON af.user_id     = u.id
            JOIN businesses b ON af.business_id = b.id
            -- LEFT JOIN so we can include non-followed public posts
            LEFT JOIN follows f ON f.follower_id = ? AND f.following_id = af.user_id
            LEFT JOIN user_privacy up ON up.user_id = af.user_id
            WHERE
              -- Viewer cannot be the poster (own posts handled separately in profile)
              af.user_id != ?
              AND (
                -- CASE 1: global = everyone AND per-post = everyone → fully public
                (
                  COALESCE(up.activity_visibility, 'everyone') = 'everyone'
                  AND af.visibility = 'everyone'
                )
                -- CASE 2: global = everyone AND per-post = friends, viewer must follow
                OR (
                  COALESCE(up.activity_visibility, 'everyone') = 'everyone'
                  AND af.visibility = 'friends'
                  AND f.follower_id IS NOT NULL
                  AND (SELECT COUNT(*) FROM follows f3 WHERE f3.follower_id = af.user_id AND f3.following_id = ?) > 0
                )
                -- CASE 3: global = friends, viewer must be mutual follower
                OR (
                  COALESCE(up.activity_visibility, 'everyone') = 'friends'
                  AND f.follower_id IS NOT NULL
                  AND (SELECT COUNT(*) FROM follows f4 WHERE f4.follower_id = af.user_id AND f4.following_id = ?) > 0
                  AND (
                    af.visibility = 'everyone'
                    OR (af.visibility = 'friends' AND (SELECT COUNT(*) FROM follows f5 WHERE f5.follower_id = af.user_id AND f5.following_id = ?) > 0)
                  )
                )
                -- CASE 4: global = only_me → never shown to others (excluded by absence)
              )
            ORDER BY af.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $s->execute([$uid, $uid, $uid, $uid, $uid, $uid, $lim, $off]);
        $rows = $s->fetchAll();
        echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]); exit;
    }

    // ── Privacy ────────────────────────────────────────────────────────────────
    if ($sub === 'privacy' && $method === 'GET') {
        $s = $pdo->prepare('SELECT * FROM user_privacy WHERE user_id=?'); $s->execute([$uid]);
        $p = $s->fetch();
        if (!$p) {
            $pdo->prepare('INSERT IGNORE INTO user_privacy (user_id) VALUES (?)')->execute([$uid]);
            $p = ['user_id' => $uid, 'activity_visibility' => 'everyone', 'reviews_visibility' => 'everyone', 'photos_visibility' => 'everyone', 'visited_visibility' => 'everyone', 'saved_visibility' => 'friends', 'followers_visibility' => 'public'];
        }
        echo json_encode(['success' => true, 'data' => $p]); exit;
    }
    if ($sub === 'privacy' && ($method === 'PUT' || $method === 'PATCH')) {
        $allowed = ['activity_visibility','reviews_visibility','photos_visibility','visited_visibility','saved_visibility','followers_visibility','public_profile','show_location'];
        $sets = []; $vals = [];
        foreach ($allowed as $f) { if (isset($input[$f])) { $sets[] = "$f=?"; $vals[] = $input[$f]; } }
        $pdo->prepare('INSERT IGNORE INTO user_privacy (user_id) VALUES (?)')->execute([$uid]);
        if ($sets) { $vals[] = $uid; $pdo->prepare('UPDATE user_privacy SET '.implode(',',$sets).' WHERE user_id=?')->execute($vals); }
        echo json_encode(['success' => true, 'message' => 'Privacy updated.']); exit;
    }

    // ── Suggested people to follow (browse mode — empty search) ────────────────
    // Only returns users NOT already followed by me. Split into 2 sections.
    if ($sub === 'users' && $subsub === 'suggestions' && $method === 'GET') {
        // --- Section A: MUTUAL CONNECTIONS I DON'T YET FOLLOW ---
        $mutual = $pdo->prepare('
            SELECT
              target.id, target.name, target.avatar_url, target.bio,
              (SELECT COUNT(*) FROM follows WHERE following_id = target.id) AS followers,
              0 AS is_following,
              GROUP_CONCAT(DISTINCT connector.name ORDER BY connector.name SEPARATOR \'|||\') AS _mutual_names,
              COUNT(DISTINCT connector.id) AS _mutual_count
            FROM follows mine
            JOIN users connector   ON mine.follower_id  = ? AND mine.following_id = connector.id
            JOIN follows conn_fol  ON conn_fol.follower_id = connector.id
            JOIN users target      ON conn_fol.following_id = target.id
            LEFT JOIN follows me2  ON me2.follower_id = ? AND me2.following_id = target.id
            WHERE me2.id IS NULL AND target.id != ?
            GROUP BY target.id
            ORDER BY _mutual_count DESC, followers DESC
            LIMIT 30
        ');
        $mutual->execute([$uid, $uid, $uid]);
        $mutualRows = $mutual->fetchAll();
        foreach ($mutualRows as &$row) {
            $names = array_values(array_filter(explode('|||', $row['_mutual_names'] ?? '')));
            $row['mutual_follower_names'] = $names;
            $row['mutual_count'] = (int)$row['_mutual_count'];
            unset($row['_mutual_names'], $row['_mutual_count']);
        }
        unset($row);

        // --- Section B: PEOPLE I MIGHT KNOW ---
        // Exclude: me + people in mutual list + people I already follow.
        $mutualIds = array_map('intval', array_column($mutualRows, 'id'));
        $excludeIds = array_merge([(int)$uid], $mutualIds);
        $excludeList = implode(',', $excludeIds);

        $mightRows = [];
        $seenIds = [];
        if (empty($excludeIds)) { $excludeList = '0'; }

        // Query 1 – friends of friends (2nd degree connections)
        try {
            $s1 = $pdo->prepare("
                SELECT DISTINCT u.id, u.name, u.avatar_url, u.bio,
                       (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS followers,
                       0 AS is_following
                FROM follows f1
                JOIN follows f2 ON f2.follower_id = f1.following_id
                JOIN users u       ON u.id = f2.following_id
                LEFT JOIN follows mf ON mf.follower_id = ? AND mf.following_id = u.id
                WHERE mf.id IS NULL
                  AND u.id NOT IN ($excludeList)
                ORDER BY followers DESC
                LIMIT 30
            ");
            $s1->execute([$uid]);
            foreach ($s1->fetchAll() as $r) {
                $id = (int)$r['id'];
                if (isset($seenIds[$id])) continue;
                $seenIds[$id] = true;
                $mightRows[] = $r;
            }
        } catch (PDOException $e) { /* fallthrough */ }

        // Query 2 – popular users fallback
        try {
            if (count($mightRows) < 30) {
                $stillNeed = 30 - count($mightRows);
                $s2 = $pdo->prepare("
                    SELECT u.id, u.name, u.avatar_url, u.bio,
                           (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS followers,
                           0 AS is_following
                    FROM users u
                    LEFT JOIN follows mf ON mf.follower_id = ? AND mf.following_id = u.id
                    WHERE mf.id IS NULL
                      AND u.id NOT IN ($excludeList)
                    ORDER BY followers DESC
                    LIMIT $stillNeed
                ");
                $s2->execute([$uid]);
                foreach ($s2->fetchAll() as $r) {
                    $id = (int)$r['id'];
                    if (isset($seenIds[$id])) continue;
                    $seenIds[$id] = true;
                    $mightRows[] = $r;
                }
            }
        } catch (PDOException $e) { /* fallthrough */ }

        $mightRows = array_slice($mightRows, 0, 30);

        echo json_encode(['success' => true, 'data' => [
            'mutual'     => $mutualRows,
            'might_know' => $mightRows,
        ]]); exit;
    }

    // ── Search users (correct path: /social/users/search) ──────────────────────
    if ($sub === 'users' && $subsub === 'search' && $method === 'GET') {
        $rawQ = trim($_GET['q'] ?? '');
        $q = '%' . $rawQ . '%';
        // When user explicitly typed a search query → INCLUDE followed users (they asked for it).
        // On empty string → treat as "no filter" but still include everyone (client decides when to call this).
        $s = $pdo->prepare('
            SELECT u.id, u.name, u.avatar_url, u.bio,
                   (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS followers,
                   (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) AS is_following
            FROM users u
            WHERE u.name LIKE ? AND u.id != ?
            ORDER BY is_following DESC, followers DESC
            LIMIT 30
        ');
        $s->execute([$uid, $q, $uid]);
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }
    // Legacy path: also keep /social/search-users for backwards compatibility
    if ($sub === 'search-users' && $method === 'GET') {
        $rawQ = trim($_GET['q'] ?? '');
        $q = '%' . $rawQ . '%';
        $s = $pdo->prepare('
            SELECT u.id, u.name, u.avatar_url, u.bio,
                   (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS followers,
                   (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) AS is_following
            FROM users u
            WHERE u.name LIKE ? AND u.id != ?
            ORDER BY followers DESC
            LIMIT 30
        ');
        $s->execute([$uid, $q, $uid]);
        echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
    }

    // ── /social/users/:userId/* routes ─────────────────────────────────────────
    if ($sub === 'users' && is_numeric($subsub)) {
        $tid = (int)$subsub;
        $sub4 = $parts[3] ?? '';   // e.g. 'followers', 'following', 'friends', 'profile'

        // Followers list
        if ($sub4 === 'followers') {
            // Check target user's followers_visibility
            $fvRow = null;
            try { $fvQ = $pdo->prepare('SELECT followers_visibility FROM user_privacy WHERE user_id=?'); $fvQ->execute([$tid]); $fvRow = $fvQ->fetch(); } catch (PDOException $_) {}
            $fv = $fvRow['followers_visibility'] ?? 'public';
            // Check viewer relationship
            $viewerIsFriend = false; $viewerFollows = false;
            if ($uid && $uid !== $tid) {
                $chkF = $pdo->prepare('SELECT COUNT(*) c FROM follows WHERE follower_id=? AND following_id=?'); $chkF->execute([$uid, $tid]); $viewerFollows = (bool)$chkF->fetch()['c'];
                $chkB = $pdo->prepare('SELECT COUNT(*) c FROM follows WHERE follower_id=? AND following_id=?'); $chkB->execute([$tid, $uid]); $viewerIsFriend = $viewerFollows && (bool)$chkB->fetch()['c'];
            }
            $canSeeList = ($uid === $tid) || ($fv === 'public') || ($fv === 'friends' && $viewerIsFriend);
            if (!$canSeeList) { echo json_encode(['success' => true, 'data' => [], 'hidden' => true]); exit; }
            $s = $pdo->prepare('
                SELECT u.id, u.name, u.avatar_url, u.bio,
                       (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) AS is_following
                FROM follows f
                JOIN users u ON f.follower_id = u.id
                WHERE f.following_id = ?
                ORDER BY f.created_at DESC
            ');
            $s->execute([$uid ?: 0, $tid]);
            echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
        }
        // Following list
        if ($sub4 === 'following') {
            $fvRow = null;
            try { $fvQ = $pdo->prepare('SELECT followers_visibility FROM user_privacy WHERE user_id=?'); $fvQ->execute([$tid]); $fvRow = $fvQ->fetch(); } catch (PDOException $_) {}
            $fv = $fvRow['followers_visibility'] ?? 'public';
            $viewerIsFriend = false; $viewerFollows = false;
            if ($uid && $uid !== $tid) {
                $chkF = $pdo->prepare('SELECT COUNT(*) c FROM follows WHERE follower_id=? AND following_id=?'); $chkF->execute([$uid, $tid]); $viewerFollows = (bool)$chkF->fetch()['c'];
                $chkB = $pdo->prepare('SELECT COUNT(*) c FROM follows WHERE follower_id=? AND following_id=?'); $chkB->execute([$tid, $uid]); $viewerIsFriend = $viewerFollows && (bool)$chkB->fetch()['c'];
            }
            $canSeeList = ($uid === $tid) || ($fv === 'public') || ($fv === 'friends' && $viewerIsFriend);
            if (!$canSeeList) { echo json_encode(['success' => true, 'data' => [], 'hidden' => true]); exit; }
            $s = $pdo->prepare('
                SELECT u.id, u.name, u.avatar_url, u.bio,
                       (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) AS is_following
                FROM follows f
                JOIN users u ON f.following_id = u.id
                WHERE f.follower_id = ?
                ORDER BY f.created_at DESC
            ');
            $s->execute([$uid ?: 0, $tid]);
            echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
        }
        // Friends list (mutual follows)
        if ($sub4 === 'friends') {
            $fvRow = null;
            try { $fvQ = $pdo->prepare('SELECT followers_visibility FROM user_privacy WHERE user_id=?'); $fvQ->execute([$tid]); $fvRow = $fvQ->fetch(); } catch (PDOException $_) {}
            $fv = $fvRow['followers_visibility'] ?? 'public';
            $viewerIsFriend = false; $viewerFollows = false;
            if ($uid && $uid !== $tid) {
                $chkF = $pdo->prepare('SELECT COUNT(*) c FROM follows WHERE follower_id=? AND following_id=?'); $chkF->execute([$uid, $tid]); $viewerFollows = (bool)$chkF->fetch()['c'];
                $chkB = $pdo->prepare('SELECT COUNT(*) c FROM follows WHERE follower_id=? AND following_id=?'); $chkB->execute([$tid, $uid]); $viewerIsFriend = $viewerFollows && (bool)$chkB->fetch()['c'];
            }
            $canSeeList = ($uid === $tid) || ($fv === 'public') || ($fv === 'friends' && $viewerIsFriend);
            if (!$canSeeList) { echo json_encode(['success' => true, 'data' => [], 'hidden' => true]); exit; }
            $s = $pdo->prepare('
                SELECT u.id, u.name, u.avatar_url, u.bio
                FROM follows f1
                JOIN follows f2 ON f1.follower_id = f2.following_id AND f1.following_id = f2.follower_id
                JOIN users u ON f1.following_id = u.id
                WHERE f1.follower_id = ?
                ORDER BY f1.created_at DESC
            ');
            $s->execute([$tid]);
            echo json_encode(['success' => true, 'data' => $s->fetchAll()]); exit;
        }
        // Full public profile — /social/users/:userId/profile
        if ($sub4 === 'profile' || $sub4 === '') {
            $s = $pdo->prepare('SELECT id, name, avatar_url, bio, level, points, is_verified, created_at FROM users WHERE id=?');
            $s->execute([$tid]);
            $user = $s->fetch();
            if (!$user) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'User not found.']); exit; }

            // ── Load target user's full privacy settings ──────────────────────
            $privDefaults = [
                'reviews_visibility'   => 'everyone',
                'visited_visibility'   => 'everyone',
                'activity_visibility'  => 'everyone',
                'followers_visibility' => 'public',
                'saved_visibility'     => 'friends',
                'public_profile'       => 1,
                'show_location'        => 1,
            ];
            try {
                $ps = $pdo->prepare('SELECT reviews_visibility, visited_visibility, activity_visibility, followers_visibility, saved_visibility, public_profile, show_location FROM user_privacy WHERE user_id=?');
                $ps->execute([$tid]);
                $prow = $ps->fetch();
                if ($prow) $privDefaults = array_merge($privDefaults, $prow);
            } catch (PDOException $e) {}
            $priv = $privDefaults;

            // Follow relationship (viewer → target)
            $isFollowing = false; $isFriend = false;
            if ($uid) {
                $ifS = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=? AND following_id=?'); $ifS->execute([$uid, $tid]);
                $ibS = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=? AND following_id=?'); $ibS->execute([$tid, $uid]);
                $isFollowing = (bool)$ifS->fetch()['c'];
                $isFriend    = $isFollowing && (bool)$ibS->fetch()['c'];
            }

            $isMe = ($uid && $uid === $tid);

            // ── Privacy check helpers ─────────────────────────────────────────
            $canSee = function($visibility) use ($uid, $tid, $isFriend, $isFollowing, $isMe) {
                if ($isMe) return true; // owner always sees their own data
                if (!$visibility || $visibility === 'everyone' || $visibility === 'public') return true;
                if (!$uid) return false;
                if ($visibility === 'friends')   return $isFriend;
                if ($visibility === 'followers') return $isFollowing;
                if ($visibility === 'hidden' || $visibility === 'private' || $visibility === 'only_me') return false;
                return false;
            };

            // ── public_profile: if OFF, only return name + avatar ────────────
            $profileRestricted = !$isMe && !(bool)$priv['public_profile'];

            if ($profileRestricted) {
                // Return minimal profile — name + avatar only
                echo json_encode(['success' => true, 'data' => [
                    'user' => [
                        'id'           => (int)$user['id'],
                        'name'         => $user['name'],
                        'avatar_url'   => $user['avatar_url'],
                        'bio'          => null,
                        'level'        => null,
                        'points'       => null,
                        'is_verified'  => false,
                        'followers'    => null,
                        'following'    => null,
                        'friends'      => null,
                        'review_count' => null,
                    ],
                    'is_following'       => $isFollowing,
                    'is_friend'          => $isFriend,
                    'profile_restricted' => true,
                    'reviews'            => [],
                    'visited'            => [],
                ]]);
                exit;
            }

            // ── Count stats — apply followers_visibility ──────────────────────
            $showFollowerCounts = $canSee($priv['followers_visibility']);

            $fc = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE following_id=?'); $fc->execute([$tid]);
            $ng = $pdo->prepare('SELECT COUNT(*) as c FROM follows WHERE follower_id=?');  $ng->execute([$tid]);
            $fr = $pdo->prepare('SELECT COUNT(*) as c FROM follows f1 JOIN follows f2 ON f1.follower_id=f2.following_id AND f1.following_id=f2.follower_id WHERE f1.follower_id=?');
            $fr->execute([$tid]);
            $rc = $pdo->prepare('SELECT COUNT(*) as c FROM reviews WHERE user_id=?'); $rc->execute([$tid]);

            $followers   = $showFollowerCounts ? (int)$fc->fetch()['c'] : null;
            $following   = $showFollowerCounts ? (int)$ng->fetch()['c'] : null;
            $friends     = $showFollowerCounts ? (int)$fr->fetch()['c'] : null;
            // Reviews are always public — count is always returned
            $reviewCount = (int)$rc->fetch()['c'];

            // ── Activity data — reviews always public; visited applies visibility ──
            $rs = $pdo->prepare('SELECT r.*, b.name AS business_name FROM reviews r JOIN businesses b ON r.business_id = b.id WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 20');
            $rs->execute([$tid]);
            $reviews = $rs->fetchAll();

            $visited = [];
            if ($canSee($priv['visited_visibility'])) {
                $vs = $pdo->prepare('SELECT b.* FROM visits v JOIN businesses b ON v.business_id = b.id WHERE v.user_id = ? ORDER BY v.visited_at DESC LIMIT 20');
                $vs->execute([$tid]);
                $visited = $vs->fetchAll();
            }

            echo json_encode(['success' => true, 'data' => [
                'user' => array_merge($user, [
                    'followers'    => $followers,
                    'following'    => $following,
                    'friends'      => $friends,
                    'review_count' => $reviewCount,
                ]),
                'is_following'          => $isFollowing,
                'is_friend'             => $isFriend,
                'profile_restricted'    => false,
                'visited_hidden'        => !$canSee($priv['visited_visibility']),
                'followers_hidden'      => !$showFollowerCounts,
                'reviews'               => $reviews,
                'visited'               => $visited,
            ]]);
            exit;
        }
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

// ── TEMPORARY DEBUG — REMOVED (security: exposed schema without auth) ─────────
/**
 * Send Expo push notifications to one or more device tokens.
 *
 * Captures the Expo API response and automatically marks any
 * DeviceNotRegistered tokens as inactive so they are never used again.
 * Returns the number of messages that Expo accepted without error.
 */
function send_expo_push(array $tokens, string $title, string $body, array $data = []): int {
    global $pdo;
    if (empty($tokens)) return 0;

    // Build one message object per token, keeping track of which index maps to which token
    $messages   = [];
    $tokenIndex = []; // index → token string (for response matching)
    foreach (array_values($tokens) as $i => $t) {
        if (!$t || strncmp((string)$t, 'ExponentPushToken[', 18) !== 0) continue;
        $messages[]   = [
            'to'       => $t,
            'sound'    => 'default',
            'title'    => $title,
            'body'     => $body,
            'data'     => $data,
            'channelId'=> $data['type'] ?? 'default', // Android channel
        ];
        $tokenIndex[$i] = $t;
    }
    if (empty($messages)) return 0;

    $accepted = 0;

    // Expo push endpoint accepts up to 100 per batch
    foreach (array_chunk($messages, 100, true) as $batchOffset => $batch) {
        $ch = curl_init('https://exp.host/--/api/v2/push/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Accept-Encoding: gzip, deflate',
            ],
            CURLOPT_POSTFIELDS     => json_encode(array_values($batch)),
        ]);
        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$raw || $http < 200 || $http >= 300) continue;

        $resp = json_decode($raw, true);
        if (!isset($resp['data']) || !is_array($resp['data'])) {
            $accepted += count($batch);
            continue;
        }

        // Match each response ticket to its token and handle errors
        $batchTokens = array_values(array_intersect_key($tokenIndex, $batch));
        foreach ($resp['data'] as $idx => $ticket) {
            if (!isset($ticket['status'])) continue;
            if ($ticket['status'] === 'ok') {
                $accepted++;
                continue;
            }
            // status === 'error' — inspect the details code
            $errCode = $ticket['details']['error'] ?? '';
            if ($errCode === 'DeviceNotRegistered') {
                // Token is stale — mark inactive so it is never used again
                $deadToken = $batchTokens[$idx] ?? null;
                if ($deadToken && isset($pdo)) {
                    try {
                        $pdo->prepare("UPDATE push_tokens SET is_active=0 WHERE token=?")
                            ->execute([$deadToken]);
                    } catch (Throwable $_) {}
                }
            }
            // MessageRateExceeded, InvalidCredentials etc. — log but keep token
        }
    }

    return $accepted;
}

// ── HELPER: get active push tokens for a list of user IDs ────────────────────
// Only returns tokens that are is_active=1 (not pruned by DeviceNotRegistered).
function get_push_tokens(PDO $pdo, array $userIds): array {
    if (empty($userIds)) return [];
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $s  = $pdo->prepare("SELECT token FROM push_tokens WHERE user_id IN ($ph) AND is_active=1");
    $s->execute($userIds);
    return array_column($s->fetchAll(), 'token');
}

// ── HELPER: check a single notification preference for a user ─────────────────
// Returns true if the preference is enabled (or if the table/row doesn't exist
// yet — defaults to true so existing users are not silently broken).
// $prefKey MUST be one of the allowed keys below — never interpolate user input.
function notif_pref_enabled(PDO $pdo, int $userId, string $prefKey): bool {
    // Whitelist to prevent any possible SQL injection from caller mistakes
    $allowed = ['follows','checkins','new_reviews','replies','trending','promotions','events','updates'];
    if (!in_array($prefKey, $allowed, true)) return true; // unknown key → default on

    try {
        // Use a CASE expression to avoid dynamic column interpolation
        $sql = "SELECT CASE '$prefKey'
            WHEN 'follows'     THEN follows
            WHEN 'checkins'    THEN checkins
            WHEN 'new_reviews' THEN new_reviews
            WHEN 'replies'     THEN replies
            WHEN 'trending'    THEN trending
            WHEN 'promotions'  THEN promotions
            WHEN 'events'      THEN events
            WHEN 'updates'     THEN updates
            ELSE 1 END AS pref_value
            FROM notification_preferences WHERE user_id=?";
        $s = $pdo->prepare($sql);
        $s->execute([$userId]);
        $row = $s->fetch();
        if (!$row) return true; // no row = never configured = default on
        return (bool)$row['pref_value'];
    } catch (Throwable $_) {
        return true; // table doesn't exist yet — fail open
    }
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
        // Only send to followers who have the checkins preference enabled
        $followerIdsWithPref = array_filter($followerIds, function($fid) use ($pdo) {
            return notif_pref_enabled($pdo, (int)$fid, 'checkins');
        });
        $tokens = get_push_tokens($pdo, array_values($followerIdsWithPref));
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

        // Send push notifications — only to users who have promotions enabled
        $promoTokens = [];
        foreach ($selected as $sel) {
            if (notif_pref_enabled($pdo, (int)$sel['user_id'], 'promotions')) {
                $promoTokens[] = $sel['token'];
            }
        }
        if (!empty($promoTokens)) {
            send_expo_push($promoTokens, $title, $message, [
                'type' => 'promo',
                'campaignId' => (int)$campaignId,
                'businessId' => $bizId,
                'businessName' => $bizRow['name'],
            ]);
        }

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
        // Saved-place reminders are discovery nudges ("you saved this place, want to visit?").
        // They map to the 'trending' preference (Trending & Picks / discovery push),
        // NOT 'new_reviews' which is for review-related notifications.
        // In-app notification is always created above regardless of this preference.
        if (notif_pref_enabled($pdo, $uid, 'trending')) {
            $tk = $pdo->prepare('SELECT token FROM push_tokens WHERE user_id=? AND is_active=1');
            $tk->execute([$uid]);
            $tok = $tk->fetchColumn();
            if ($tok) {
                send_expo_push([$tok], $title, $body, $payload);
            }
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

// ── TEMPORARY DEBUG — REMOVED (security: exposed schema without auth) ─────────
// =============================================================================
// RECOMMENDATION SYSTEM  —  Smart Food & Drink Recommendations
// =============================================================================

// ── Helpers: auth + error used by recommendation routes ──────────────────────
// yegna_auth_user() mirrors require_auth() but is used in the recommendation
// section where the function name pattern differs. Returns the user ID or
// sends a 401 and exits.
function yegna_auth_user(): int {
    global $JWT_SECRET;
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    }
    if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    $token = (strncmp($auth, 'Bearer ', 7) === 0) ? trim(substr($auth, 7)) : null;
    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    $payload = jwt_decode($token, $JWT_SECRET);
    if (!$payload || empty($payload['id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token.']);
        exit;
    }
    return (int)$payload['id'];
}

// Convenience: send an error response and exit.
function yegna_fail(string $message, int $status = 400) {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ── Helpers: Calendar / holiday / fasting context ─────────────────────────────
function yegna_tz() { return new DateTimeZone('Africa/Addis_Ababa'); }

function yegna_ethiopian_shift(int $gregorianYear): int {
    // Enkutatash = Sept 11 OR Sept 12 in the year BEFORE a Gregorian leap year.
    // 1900-2099 safe rule: shift = 1 if (gregorianYear+1) mod 4 == 0 else 0.
    $next = $gregorianYear + 1;
    return (($next % 4 === 0 && $next % 100 !== 0) || ($next % 400 === 0)) ? 1 : 0;
}

function yegna_resolve_ethiopian_fixed(int $ecMonth, int $ecDay, int $gregorianYear): string {
    // Returns Y-m-d Gregorian date for an Ethiopian-calendar fixed mm/dd that
    // falls in the given Gregorian year (handles Enkutatash leap-shift).
    $shift = yegna_ethiopian_shift($gregorianYear);
    $enkutatash = new DateTime("{$gregorianYear}-09-" . (11 + $shift), yegna_tz());
    $daysFromNewYear = ($ecMonth - 1) * 30 + ($ecDay - 1);
    // Ethiopian months 1..12 map cleanly; Pagume (13) would be days 360-365.
    if ($ecMonth === 13) {
        $daysFromNewYear = 360 + ($ecDay - 1);
    }
    $date = clone $enkutatash;
    $date->modify("+{$daysFromNewYear} days");
    // If the result is >= Jan 1 of gregorianYear+1 AND target was before Meskerem,
    // we need the same ecMonth/ecDay but in the *previous* Ethiopian year that
    // still falls within $gregorianYear. This handles Jan–Sept portion correctly
    // because we check against the ENKUTATASH of the year we want to live in.
    if ($ecMonth >= 4 || ($ecMonth === 1 && $ecDay <= 30)) {
        // Tahsas or later in Ethiopian year lands in Jan+ of Gregorian year; but
        // Tahsas 29 (Jan 7) already lives in Gregorian year after Enkutatash's year.
        // For Tahsas..Nehase months: if date > end of gregorianYear, step back 1 Ethiopian year.
        $endOfYear = new DateTime("{$gregorianYear}-12-31", yegna_tz());
        if ($date > $endOfYear) {
            // Previous Ethiopian year: Enkutatash of previous Gregorian year
            $prevYear = $gregorianYear - 1;
            $shiftPrev = yegna_ethiopian_shift($prevYear);
            $enkPrev = new DateTime("{$prevYear}-09-" . (11 + $shiftPrev), yegna_tz());
            $date = clone $enkPrev;
            $date->modify("+{$daysFromNewYear} days");
        }
    }
    return $date->format('Y-m-d');
}

function yegna_context(DateTime $day): array {
    $ctx = [
        'date'       => $day->format('Y-m-d'),
        'dow'        => (int)$day->format('N'),   // 1=Mon..7=Sun
        'is_sunday'  => (int)$day->format('N') === 7,
        'holiday'    => null,                     // { name, importance, date_type }
        'holidays'   => [],                       // all holidays matching day
        'fasting_periods' => [],
        'is_fasting_day' => false,                // Wed/Fri OR inside major fast
        'fasting_context'  => null,
    ];
    global $pdo;
    $dateStr = $day->format('Y-m-d');
    $year = (int)$day->format('Y');
    $mmdd = $day->format('m-d');

    // FIXED_GREGORIAN holidays  (we store 2000 placeholder year in date_col)
    $s = $pdo->prepare("SELECT * FROM calendar_events
      WHERE category = 'holiday' AND date_type='FIXED_GREGORIAN'
        AND DATE_FORMAT(date_col,'%m-%d') = ?");
    $s->execute([$mmdd]);
    foreach ($s->fetchAll() as $r) { $ctx['holidays'][] = $r; }

    // YEAR_SPECIFIC holidays
    $s = $pdo->prepare("SELECT * FROM calendar_events
      WHERE category='holiday' AND date_type='YEAR_SPECIFIC' AND date_col = ?");
    $s->execute([$dateStr]);
    foreach ($s->fetchAll() as $r) { $ctx['holidays'][] = $r; }

    // ETHIOPIAN_FIXED holidays  — resolve each entry's mm-dd against $year
    $ethRows = $pdo->query("SELECT * FROM calendar_events
      WHERE category='holiday' AND date_type='ETHIOPIAN_FIXED'")->fetchAll();
    foreach ($ethRows as $r) {
        [$ecM, $ecD] = array_map('intval', explode('-', $r['ec_month_day']));
        $resolved = yegna_resolve_ethiopian_fixed($ecM, $ecD, $year);
        if ($resolved === $dateStr) { $ctx['holidays'][] = $r; }
    }

    // Pick dominant holiday  (highest importance, else first)
    $best = null;
    foreach ($ctx['holidays'] as $h) {
        if ($best === null || (int)$h['importance'] > (int)$best['importance']) $best = $h;
    }
    if ($best) { $ctx['holiday'] = ['name' => $best['name'], 'importance' => (int)$best['importance']]; }

    // Fasting periods: YEAR_SPECIFIC_RANGE
    $s = $pdo->prepare("SELECT * FROM calendar_events
      WHERE category='fasting_period' AND date_type='YEAR_SPECIFIC_RANGE'
        AND ? BETWEEN range_start AND range_end");
    $s->execute([$dateStr]);
    $ctx['fasting_periods'] = $s->fetchAll();

    // Weekly Wednesday/Friday rule + major fast periods = is_fasting_day
    $dow = $ctx['dow'];
    $inMajorFast = count($ctx['fasting_periods']) > 0;
    if ($inMajorFast) {
        $ctx['is_fasting_day'] = true;
        $ctx['fasting_context'] = $ctx['fasting_periods'][0]['fasting_type'] ?: 'major_fast';
    } elseif ($dow === 3 || $dow === 5) {
        $ctx['is_fasting_day'] = true;
        $ctx['fasting_context'] = ($dow === 3) ? 'wednesday' : 'friday';
    }
    return $ctx;
}

// ── Helpers: distance / open-now / fasting-compat / business scoring ─────────
function yegna_haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp/2)**2 + cos($p1)*cos($p2)*sin($dl/2)**2;
    $c = 2*atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

function yegna_open_now(int $bizId, DateTime $now): bool {
    global $pdo;
    $dow = (int)$now->format('N') % 7;   // SQL day: 0=Sun..6=Sat  (PHP N=7 Sun → 0)
    $hmm = (int)$now->format('Hi');
    $s = $pdo->prepare("SELECT open_time, close_time FROM business_hours
      WHERE business_id=? AND day_of_week=? LIMIT 1");
    $s->execute([$bizId, $dow]);
    $row = $s->fetch();
    if (!$row) return true;   // no hours data → assume open
    $open  = (int)str_replace(':', '', $row['open_time']);
    $close = (int)str_replace(':', '', $row['close_time']);
    if ($close <= $open) { $close += 2400; $cur = $hmm + ($hmm < $open ? 2400 : 0); return $cur >= $open && $cur <= $close; }
    return $hmm >= $open && $hmm <= $close;
}

function yegna_fasting_score(string $catName, string $bizName): float {
    // Businesses that almost always serve fasting-compatible (vegan) Ethiopian
    // food, beverages, juices get +0.9. Generic "Restaurant" gets +0.5 because
    // many have separate fasting menus. Meat-specialised names get 0 or negative.
    $cn = mb_strtolower($catName);
    $bn = mb_strtolower($bizName);
    $bev = ['coffee','cafe','café','tea','juice','smoothie','bar','bakery','pastry','drinks'];
    foreach ($bev as $w) { if (str_contains($cn,$w) || str_contains($bn,$w)) return 0.95; }
    $veg = ['vegetarian','vegan','fasting','ምግብ','ሽሮ','ምስር','ፋሶሊያ','ጎመን'];
    foreach ($veg as $w) { if (str_contains($cn,$w) || str_contains($bn,$w)) return 1.0; }
    if (str_contains($cn, 'restaurant') || str_contains($cn, 'ethiopian')) return 0.55;
    $meat = ['steak','butchery','bbq','barbecue','burger','chicken','meat','kebab','shawarma','fish','seafood','sushi','doro'];
    foreach ($meat as $w) { if (str_contains($cn,$w) || str_contains($bn,$w)) return -0.3; }
    return 0.5;
}

function yegna_cooldown_days(int $userId, int $bizId, DateTime $now): int {
    global $pdo;
    $s = $pdo->prepare("SELECT DATEDIFF(?, MAX(created_at)) AS d
      FROM recommendation_history WHERE user_id=? AND business_id=?");
    $s->execute([$now->format('Y-m-d H:i:s'), $userId, $bizId]);
    $d = $s->fetchColumn();
    return $d === null || $d === false ? 9999 : (int)$d;
}

function yegna_pick_business(int $userId, float $lat, float $lng, array $ctx, DateTime $now): ?array {
    global $pdo;
    $radii = [1000, 2000, 5000];       // metres
    $MIN_RESULTS = 4;                  // need at least this many to score from
    $bestBatch = null;
    foreach ($radii as $R) {
        // Rough bounding box + Haversine filter
        $latDelta = $R / 111320.0;
        $lngDelta = $R / (111320.0 * max(0.01, cos(deg2rad($lat))));
        $minLat = $lat - $latDelta; $maxLat = $lat + $latDelta;
        $minLng = $lng - $lngDelta; $maxLng = $lng + $lngDelta;
        $s = $pdo->prepare("SELECT id, name, category, rating, review_count,
          latitude, longitude, photos, is_verified, description
          FROM businesses
          WHERE status='approved'
            AND latitude BETWEEN ? AND ?
            AND longitude BETWEEN ? AND ?
          LIMIT 400");
        $s->execute([$minLat, $maxLat, $minLng, $maxLng]);
        $rows = $s->fetchAll();
        $candidates = [];
        foreach ($rows as $r) {
            $d = yegna_haversine_m($lat, $lng, (float)$r['latitude'], (float)$r['longitude']);
            if ($d <= $R) { $r['_distance_m'] = $d; $candidates[] = $r; }
        }
        if (count($candidates) >= $MIN_RESULTS) { $bestBatch = $candidates; break; }
        if ($bestBatch === null || count($candidates) > count($bestBatch)) $bestBatch = $candidates;
    }
    if (!$bestBatch) return null;

    // Score each candidate
    $scored = [];
    $isFast = !empty($ctx['is_fasting_day']);
    $isHoliday = $ctx['holiday'] !== null;
    foreach ($bestBatch as $b) {
        $distM = (float)$b['_distance_m'];
        // Distance score: 1.0 at 0m, ~0.55 at 1km, ~0.2 at 5km
        $distScore = exp(-$distM / 1800.0);
        $rating = (float)($b['rating'] ?? 0);
        $rc = (int)($b['review_count'] ?? 0);
        // Rating score — weighted with popularity (log of review count)
        $ratingScore = $rating >= 1 ? ($rating / 5.0) * 0.75 + (1 - exp(-$rc / 15.0)) * 0.25 : 0.3;
        $open = yegna_open_now((int)$b['id'], $now);
        $openScore = $open ? 1.0 : 0.35;
        $fastScore = $isFast ? yegna_fasting_score((string)$b['category'], (string)$b['name']) : 0.5;
        if ($isFast && $fastScore < 0) $fastScore = 0;
        // Holiday bonus for restaurant/cafe when it's a national holiday
        $catLow = mb_strtolower((string)$b['category']);
        $holidayBonus = ($isHoliday && (str_contains($catLow,'resta') || str_contains($catLow,'cafe') || str_contains($catLow,'coffee'))) ? 0.12 : 0.0;
        // Fasting-day strong-penalty for non-compatible
        $fastPenalty = ($isFast && $fastScore < 0.3) ? -0.35 : 0.0;
        // Cooldown: if recommended in last 21 days, strong penalty
        $cdDays = yegna_cooldown_days($userId, (int)$b['id'], $now);
        $cooldownPenalty = 0.0;
        if ($cdDays < 7)   $cooldownPenalty = -0.9;
        elseif ($cdDays < 21) $cooldownPenalty = -0.3 * (1 - ($cdDays - 7) / 14.0);

        $total = (0.28 * $distScore)
               + (0.20 * $ratingScore)
               + (0.18 * $openScore)
               + ($isFast ? 0.24 : 0.08) * max(0.0, $fastScore)
               + $holidayBonus
               + $fastPenalty
               + $cooldownPenalty
               + 0.02;  // tiny tie-break
        $b['_score'] = $total;
        $b['_breakdown'] = [
            'distance_score' => round($distScore,3),
            'rating_score'   => round($ratingScore,3),
            'open_score'     => $openScore,
            'fast_score'     => round($fastScore,3),
            'cooldown_days'  => $cdDays,
            'open_now'       => $open,
            'distance_m'     => round($distM),
        ];
        $scored[] = $b;
    }
    // Sort descending by score; pick the winner.
    usort($scored, fn($a,$b) => $b['_score'] <=> $a['_score']);
    // Top 3, pick randomly with 70/20/10 weights to add mild variety.
    $top = array_slice($scored, 0, 3);
    if (count($top) === 0) return null;
    $weights = [0 => 0.70, 1 => 0.20, 2 => 0.10];
    $rnd = mt_rand() / mt_getrandmax();
    $cum = 0.0; $pickIdx = 0;
    foreach ($top as $i => $_) {
        $cum += $weights[$i] ?? 0.10;
        if ($rnd <= $cum) { $pickIdx = $i; break; }
    }
    $winner = $top[$pickIdx];
    $winner['_distance_m'] = round((float)$winner['_distance_m']);
    return $winner;
}

// ── Helpers: Schedule computation ────────────────────────────────────────────
function yegna_next_due(DateTime $createdAt, ?DateTime $lastSent, DateTime $now): DateTime {
    // Next due = next Sunday at the same HH:MM as $createdAt (Addis timezone)
    // + jitter of ±900 s (15 min). If $lastSent is within 6 days of $now, push
    // to the Sunday AFTER next to guarantee the ~7-day cadence.
    $tz = yegna_tz();
    $due = (clone $createdAt)->setTimezone($tz);
    $hh = (int)$due->format('H'); $mm = (int)$due->format('i');
    // Anchor to "this week's Sunday" in Addis TZ, then step forward to first valid.
    $ref = (clone $now)->setTimezone($tz);
    $refDow = (int)$ref->format('N');  // 1=Mon..7=Sun
    $daysAhead = (7 - $refDow + 7) % 7; // days until Sunday (0 if today is Sunday)
    $candidate = (clone $ref)->modify("+{$daysAhead} days");
    $candidate->setTime($hh, $mm, 0);
    // Jitter ± 15 min (deterministic per user — skip for simplicity; use random)
    $jitterSec = random_int(-900, 900);
    $candidate->modify(($jitterSec >= 0 ? '+' : '') . "{$jitterSec} seconds");
    // Ensure minimum gap from last_sent
    if ($lastSent) {
        $diffDays = (int)$lastSent->diff($candidate)->days;
        $gapOK = $candidate >= $lastSent && $diffDays >= 6;
        if (!$gapOK) { $candidate->modify('+7 days'); }
    }
    // If candidate is BEFORE "now + 1 minute" (i.e., missed this past Sunday by a
    // hair), use this upcoming Sunday (already handled by daysAhead formula for
    // today-Sunday edge when HH:MM already past).
    if ($candidate <= (clone $now)->modify('+1 minute')) {
        $candidate->modify('+7 days');
    }
    return $candidate;
}

// ── Routes: POST /user/location  ─────────────────────────────────────────────
if ($base === 'user' && $sub === 'location' && $method === 'POST') {
    $uid = yegna_auth_user();
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $lat = $body['latitude'] ?? null; $lng = $body['longitude'] ?? null;
    if ($lat === null || $lng === null) yegna_fail('latitude/longitude required', 400);
    if (!is_numeric($lat) || !is_numeric($lng)) yegna_fail('invalid location', 400);
    $acc = $body['accuracy_m'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO user_locations (user_id,latitude,longitude,accuracy_m)
      VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE
        latitude=VALUES(latitude), longitude=VALUES(longitude),
        accuracy_m=VALUES(accuracy_m), updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$uid, (float)$lat, (float)$lng, $acc === null ? null : (float)$acc]);
    echo json_encode(['success' => true, 'stored_at' => date('c')]);
    exit;
}

// ── Routes: GET /recommendations  ────────────────────────────────────────────
if ($base === 'recommendations' && $method === 'GET') {
    $uid = yegna_auth_user();
    $limit = min(20, (int)($_GET['limit'] ?? 5));
    $s = $pdo->prepare("SELECT rh.id, rh.business_id, rh.rec_type, rh.context,
        rh.holiday_name, rh.fasting_context, rh.created_at,
        b.name AS business_name, b.category, b.rating, b.review_count,
        b.address, b.city, b.photos, b.latitude, b.longitude
      FROM recommendation_history rh
      JOIN businesses b ON b.id = rh.business_id
      WHERE rh.user_id = ? ORDER BY rh.created_at DESC LIMIT ?");
    $s->execute([$uid, $limit]);
    $rows = $s->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $ctx = $r['context'] ? json_decode($r['context'], true) : null;
        $out[] = [
            'id' => (int)$r['id'],
            'business_id' => (int)$r['business_id'],
            'business_name' => $r['business_name'],
            'business_category' => $r['category'],
            'rating' => $r['rating'] === null ? null : (float)$r['rating'],
            'review_count' => (int)$r['review_count'],
            'address' => $r['address'],
            'city' => $r['city'],
            'cover_photo' => yegna_first_photo($r['photos']),
            'latitude' => $r['latitude'] === null ? null : (float)$r['latitude'],
            'longitude' => $r['longitude'] === null ? null : (float)$r['longitude'],
            'rec_type' => $r['rec_type'],
            'holiday_name' => $r['holiday_name'],
            'fasting_context' => $r['fasting_context'],
            'score_breakdown' => $ctx['score_breakdown'] ?? null,
            'created_at' => $r['created_at'],
        ];
    }
    echo json_encode(['success' => true, 'data' => $out]);
    exit;
}

// ── Routes: POST /admin/scheduler/run-recommendations ────────────────────────
// One idempotent admin endpoint. Cron on Plesk calls this hourly with admin JWT.
// Processes up to 100 due users per run. Idempotent: writes history BEFORE push
// so retries don't double-notify; row-level lock column prevents concurrency.
if ($base === 'admin' && $sub === 'scheduler' && $subsub === 'run-recommendations' && $method === 'POST') {
    // Admin auth — reuse the existing JWT check + require role=admin
    $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $token = null;
    foreach ($headers as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0 && strncmp($v, 'Bearer ', 7) === 0) $token = trim(substr($v, 7));
    }
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION']) && strncmp($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ', 7) === 0)
        $token = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
    if (!$token) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Missing auth']); exit; }
    try { $jwt = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key(JWT_SECRET, 'HS256')); }
    catch (Exception $e) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }
    $callerId = (int)($jwt->sub ?? $jwt->user_id ?? 0);
    if ($callerId <= 0) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Invalid token subject']); exit; }
    // Accept admin users, OR any user when ADMIN_WHITELIST allows — for this cron
    // endpoint we simply accept any valid signed JWT (the URL itself is obscure,
    // and the processing_lock + idempotency prevent damage). Keep narrow: require role=admin.
    $roleQ = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
    $roleQ->execute([$callerId]);
    $callerRole = (string)($roleQ->fetchColumn() ?: 'user');
    if ($callerRole !== 'admin') {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Admin role required','caller_id'=>$callerId,'role'=>$callerRole]);
        exit;
    }

    ignore_user_abort(true);
    set_time_limit(120);
    $now = new DateTime('now', yegna_tz());
    $BATCH_SIZE = 100;

    // Stage 1: claim a batch. For users with NO schedule row yet (new users),
    // INSERT IGNORE a row with next_due_at initially NULL — the UPDATE then picks
    // them up via OR next_due_at IS NULL with LEFT JOIN.
    $pdo->exec("INSERT IGNORE INTO recommendation_schedule (user_id, next_due_at, processing_lock, lock_expires_at)
      SELECT id, NULL, 0, NULL FROM users");

    // Clear stale locks (older than 8 minutes) in case a previous run died mid-flight
    $pdo->prepare("UPDATE recommendation_schedule
      SET processing_lock=0, lock_expires_at=NULL
      WHERE processing_lock=1 AND lock_expires_at IS NOT NULL AND lock_expires_at < ?")
      ->execute([$now->format('Y-m-d H:i:s')]);

    // Find + claim due users (lock with UPDATE LIMIT first — no joins needed)
    $claimExpires = (clone $now)->modify('+8 minutes');
    $lockSql = "UPDATE recommendation_schedule
      SET processing_lock = 1, lock_expires_at = ?, updated_at = CURRENT_TIMESTAMP
      WHERE processing_lock = 0
        AND (next_due_at IS NULL OR next_due_at <= ?)
      ORDER BY COALESCE(next_due_at, '1970-01-01') ASC
      LIMIT {$BATCH_SIZE}";
    $pdo->prepare($lockSql)->execute([
        $claimExpires->format('Y-m-d H:i:s'),
        $now->format('Y-m-d H:i:s'),
    ]);

    // Pull our locked batch with user details
    $batch = $pdo->query("SELECT s.user_id, s.last_sent_at, s.next_due_at,
        u.created_at, u.role, u.status
      FROM recommendation_schedule s
      JOIN users u ON u.id = s.user_id
      WHERE s.processing_lock = 1 AND s.lock_expires_at = '".$claimExpires->format('Y-m-d H:i:s')."'")->fetchAll();

    $processed = 0; $sent = 0; $skippedNoLoc = 0; $skippedNoBiz = 0; $skippedNoToken = 0; $errors = [];

    foreach ($batch as $row) {
        $uid = (int)$row['user_id'];
        try {
            // Determine context (sunday + holiday + fasting) for DUE DATE (use today — scheduler runs hourly)
            $ctx = yegna_context($now);

            // Get user location
            $locRow = $pdo->prepare("SELECT latitude, longitude FROM user_locations WHERE user_id=? LIMIT 1");
            $locRow->execute([$uid]); $loc = $locRow->fetch();
            if (!$loc) {
                $skippedNoLoc++;
                yegna_sched_advance($pdo, $uid, $row, $now, null);
                continue;
            }
            $lat = (float)$loc['latitude']; $lng = (float)$loc['longitude'];

            // Pick business
            $biz = yegna_pick_business($uid, $lat, $lng, $ctx, $now);
            if (!$biz) {
                $skippedNoBiz++;
                yegna_sched_advance($pdo, $uid, $row, $now, null);
                continue;
            }

            // Decide rec_type & wording (holiday overrides sunday)
            $holidayName = $ctx['holiday']['name'] ?? null;
            $isFast = !empty($ctx['is_fasting_day']);
            $fastingCtx = $ctx['fasting_context'];
            if ($holidayName) $recType = 'holiday';
            elseif ($isFast) $recType = 'fasting';
            else             $recType = 'sunday';

            $bizId = (int)$biz['id'];
            $bizName = $biz['name'];
            $ctxJson = json_encode([
                'holiday' => $holidayName,
                'fasting' => $fastingCtx,
                'is_sunday' => $ctx['is_sunday'],
                'score_breakdown' => $biz['_breakdown'] ?? null,
                'search_centre' => ['lat'=>$lat,'lng'=>$lng],
            ]);

            // Idempotency: INSERT IGNORE into history. If a row already exists for
            // this user+biz+day → we already processed; skip sending.
            $ins = $pdo->prepare("INSERT IGNORE INTO recommendation_history
              (user_id, business_id, rec_type, context, holiday_name, fasting_context, notification_sent, created_date, created_at)
              VALUES (?,?,?,CAST(? AS JSON),?,?,1,?,?)");
            $createdAtStr = $now->format('Y-m-d H:i:s');
            $createdDate  = $now->format('Y-m-d');
            $ins->execute([$uid, $bizId, $recType, $ctxJson, $holidayName, $fastingCtx, $createdDate, $createdAtStr]);
            if ($ins->rowCount() === 0) {
                // Already sent; still advance schedule so we don't retry the same slot.
                yegna_sched_advance($pdo, $uid, $row, $now, $bizId);
                $processed++;
                continue;
            }
            $histId = (int)$pdo->lastInsertId();

            // Build notification text
            $distKm = round(($biz['_distance_m'] ?? 0) / 1000.0, 1);
            if ($recType === 'holiday') {
                $title = '🎉 Enjoy the Holiday';
                $body  = "It's {$holidayName}. We found {$bizName} near you to enjoy today.";
            } elseif ($recType === 'fasting') {
                $isCafe = stripos((string)$biz['category'], 'Coffee') !== false || stripos((string)$biz['category'], 'Cafe') !== false;
                if ($isCafe) {
                    $title = '☕ Fasting-Day Pick';
                    $body  = "Looking for somewhere with coffee or fasting-friendly drinks? Try {$bizName}.";
                } else {
                    $title = '🌱 Fasting-Friendly Pick';
                    $body  = "Looking for fasting-friendly food? We found {$bizName} near you.";
                }
            } else {
                $isCafe = stripos((string)$biz['category'], 'Coffee') !== false || stripos((string)$biz['category'], 'Cafe') !== false;
                if ($isCafe) {
                    $title = '☕ Sunday Coffee Idea';
                    $body  = "We found a café near you worth checking out: {$bizName}.";
                } else {
                    $title = '🍽️ This Week\u0027s Place';
                    $body  = "Looking for somewhere to eat? {$bizName} is {$distKm} km away.";
                }
            }

            // Insert into notifications table (so NotificationsContext renders it)
            $notifData = json_encode([
                'type' => 'recommendation',
                'business_id' => $bizId,
                'recommendation_id' => $histId,
                'screen' => 'BusinessDetail',
            ]);
            $nins = $pdo->prepare("INSERT INTO notifications
              (user_id, type, title, message, data, is_read, created_at)
              VALUES (?, 'recommendation', ?, ?, CAST(? AS JSON), 0, ?)");
            $nins->execute([$uid, $title, $body, $notifData, $createdAtStr]);

            // Expo push — only if user has trending/recommendation push enabled
            $hasPushTokens = yegna_user_has_push_tokens($pdo, $uid);
            if (!$hasPushTokens) {
                $skippedNoToken++;
            } elseif (!notif_pref_enabled($pdo, $uid, 'trending')) {
                $skippedNoToken++; // preference disabled — count as skipped
            } else {
                $extra = [
                    'business_id' => $bizId,
                    'screen'      => 'BusinessDetail',
                    'type'        => 'recommendation',
                ];
                send_expo_push_all_tokens($pdo, $uid, $title, $body, $extra);
            }

            // Advance schedule
            yegna_sched_advance($pdo, $uid, $row, $now, $bizId);
            $sent++;
            $processed++;

        } catch (Throwable $e) {
            $errors[] = ['uid' => $uid, 'err' => $e->getMessage()];
            // Unlock only (don't leave it locked for 8 min — keep idempotent advance
            // even on failure so a bad row doesn't permanently block).
            try {
                $pdo->prepare("UPDATE recommendation_schedule
                  SET processing_lock=0, lock_expires_at=NULL
                  WHERE user_id=? AND processing_lock=1 LIMIT 1")->execute([$uid]);
            } catch (Throwable $_) {}
        }
    }

    echo json_encode([
        'success' => true,
        'ran_at' => $now->format('c'),
        'batch_size' => count($batch),
        'processed' => $processed,
        'recommendations_sent' => $sent,
        'skipped_no_location' => $skippedNoLoc,
        'skipped_no_business' => $skippedNoBiz,
        'skipped_no_push_token' => $skippedNoToken,
        'errors' => $errors,
    ]);
    exit;
}

// Helper used by the scheduler: advance a user's next_due_at to the NEXT due slot,
// unlock, write last_sent_at if recommendation was produced.
function yegna_sched_advance($pdo, int $uid, array $row, DateTime $now, ?int $bizId): void {
    $created = new DateTime($row['created_at'], yegna_tz());
    $last    = $row['last_sent_at'] ? new DateTime($row['last_sent_at'], yegna_tz()) : null;
    $next    = yegna_next_due($created, $bizId ? $now : $last, $now);
    $upd = $pdo->prepare("UPDATE recommendation_schedule SET
      last_sent_at = COALESCE(?, last_sent_at),
      next_due_at   = ?,
      processing_lock = 0,
      lock_expires_at = NULL,
      updated_at = CURRENT_TIMESTAMP
      WHERE user_id=? LIMIT 1");
    $upd->execute([
        $bizId ? $now->format('Y-m-d H:i:s') : null,
        $next->format('Y-m-d H:i:s'),
        $uid,
    ]);
}

// Tiny helper: does a user have ANY active push token?
// Falls back to any token if is_active column doesn't exist yet (pre-migration safety).
function yegna_user_has_push_tokens($pdo, int $uid): bool {
    try {
        $s = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM push_tokens WHERE user_id=? AND is_active=1)");
        $s->execute([$uid]);
        return (bool)$s->fetchColumn();
    } catch (PDOException $_) {
        // Column may not exist yet — fall back to checking any token for this user
        try {
            $s = $pdo->prepare("SELECT EXISTS(SELECT 1 FROM push_tokens WHERE user_id=?)");
            $s->execute([$uid]);
            return (bool)$s->fetchColumn();
        } catch (PDOException $_) {
            return false;
        }
    }
}

// Send Expo push to all tokens for a user via the existing helpers.
function send_expo_push_all_tokens($pdo, int $uid, string $title, string $body, array $extra = []): void {
    $tokens = get_push_tokens($pdo, [$uid]);
    if ($tokens) send_expo_push($tokens, $title, $body, $extra);
}

// Extract first photo URL from a photos JSON column (matches JS shape).
function yegna_first_photo($photosJson): ?string {
    if (!$photosJson) return null;
    $decoded = json_decode($photosJson, true);
    if (!is_array($decoded) || empty($decoded)) return null;
    $first = reset($decoded);
    if (is_string($first)) return $first;
    if (is_array($first)) {
        return $first['url'] ?? $first['image_url'] ?? $first['photo'] ?? $first['src'] ?? null;
    }
    return null;
}

// ── Routes: GET /calendar/context  — debug / app can display fasting badge ───
if ($base === 'calendar' && $sub === 'context' && $method === 'GET') {
    $dStr = $_GET['date'] ?? 'now';
    $d = $dStr === 'now' ? new DateTime('now', yegna_tz()) : new DateTime($dStr, yegna_tz());
    $ctx = yegna_context($d);
    $ctx['date'] = $d->format('Y-m-d');
    $ctx['now_iso'] = $d->format('c');
    echo json_encode(['success' => true, 'data' => $ctx]);
    exit;
}

// =============================================================================
// FEATURED ADS SYSTEM
// =============================================================================

// ── Helper: ensure featured_ads table exists ─────────────────────────────────
function ensure_featured_ads_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS featured_ads (
        id               INT          PRIMARY KEY AUTO_INCREMENT,
        title            VARCHAR(255) NOT NULL COMMENT 'Internal campaign name',
        business_id      INT          DEFAULT NULL COMMENT 'Optional linked business',
        media_type       ENUM('image','video') NOT NULL DEFAULT 'image',
        media_url        VARCHAR(600) NOT NULL,
        destination_url  VARCHAR(600) DEFAULT NULL,
        cta_text         VARCHAR(100) DEFAULT NULL,
        start_at         DATETIME     NOT NULL COMMENT 'UTC — admin enters EAT, PHP converts to UTC before storing',
        end_at           DATETIME     NOT NULL COMMENT 'UTC — admin enters EAT, PHP converts to UTC before storing',
        display_duration SMALLINT     NOT NULL DEFAULT 8 COMMENT 'Seconds to show image ad',
        priority         SMALLINT     NOT NULL DEFAULT 10 COMMENT 'Lower = higher priority',
        weight           SMALLINT     NOT NULL DEFAULT 1  COMMENT 'Rotation weight',
        is_active        TINYINT(1)   NOT NULL DEFAULT 1,
        impressions      INT          NOT NULL DEFAULT 0,
        clicks           INT          NOT NULL DEFAULT 0,
        created_by       INT          NOT NULL,
        created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active_schedule (is_active, start_at, end_at),
        INDEX idx_priority        (priority, weight)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Timezone helper: parse an EAT datetime string and return a UTC DateTime ──
// Dashboard sends: 'YYYY-MM-DD HH:MM:SS' or 'YYYY-MM-DDTHH:MM' — treated as EAT.
// Returns UTC DateTime or false on failure.
function eat_to_utc(string $eatStr): DateTime|false {
    $eat_tz = new DateTimeZone('Africa/Addis_Ababa');
    $utc_tz = new DateTimeZone('UTC');
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $eatStr, $eat_tz)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $eatStr, $eat_tz)
       ?: DateTime::createFromFormat('Y-m-d\TH:i',   $eatStr, $eat_tz)
       ?: DateTime::createFromFormat('Y-m-d H:i',    $eatStr, $eat_tz);
    if (!$dt) return false;
    $dt->setTimezone($utc_tz);
    return $dt;
}

// ── Timezone helper: return current UTC time as Y-m-d H:i:s string ───────────
function now_utc(): string {
    return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

// ── GET /featured-ads — public: return currently active ads ─────────────────
if ($base === 'featured-ads' && ($sub === '' || $sub === null) && $method === 'GET') {
    ensure_featured_ads_table($pdo);

    // Use UTC NOW() — timestamps stored as UTC, so server TZ is irrelevant.
    $nowUtc = now_utc();

    $s = $pdo->prepare("
        SELECT
            fa.id, fa.title, fa.media_type, fa.media_url,
            fa.destination_url, fa.cta_text,
            fa.start_at, fa.end_at, fa.display_duration,
            fa.priority, fa.weight,
            fa.business_id,
            b.name  AS business_name,
            b.image_url AS business_image,
            b.category  AS business_category,
            b.address   AS business_address,
            b.city      AS business_city
        FROM featured_ads fa
        LEFT JOIN businesses b ON b.id = fa.business_id AND b.is_active = 1
        WHERE fa.is_active = 1
          AND fa.start_at <= ?
          AND fa.end_at   >= ?
        ORDER BY fa.priority ASC, fa.id ASC
    ");
    $s->execute([$nowUtc, $nowUtc]);
    $rows = $s->fetchAll();

    // Apply weighted shuffle within each priority group so higher-weight ads
    // appear proportionally more often while still being fair across campaigns.
    // Priority grouping: all priority=1 ads shuffle among themselves (weighted),
    // then priority=2, etc. This prevents a high-weight low-priority ad from
    // pushing out a higher-priority ad.
    $grouped = [];
    foreach ($rows as $row) {
        $grouped[(int)$row['priority']][] = $row;
    }
    ksort($grouped);
    $ordered = [];
    foreach ($grouped as $group) {
        // Build a weighted pool: each ad gets weight slots
        $pool = [];
        foreach ($group as $ad) {
            $w = max(1, (int)$ad['weight']);
            for ($i = 0; $i < $w; $i++) $pool[] = $ad['id'];
        }
        // Shuffle the pool (weighted Fisher-Yates effect)
        shuffle($pool);
        // De-duplicate while preserving weighted order
        $seen = [];
        foreach ($pool as $aid) {
            if (!isset($seen[$aid])) {
                $seen[$aid] = true;
                foreach ($group as $ad) {
                    if ((int)$ad['id'] === (int)$aid) {
                        $ordered[] = $ad;
                        break;
                    }
                }
            }
        }
    }

    // Cast numeric types
    foreach ($ordered as &$row) {
        $row['id']               = (int)$row['id'];
        $row['display_duration'] = (int)$row['display_duration'];
        $row['priority']         = (int)$row['priority'];
        $row['weight']           = (int)$row['weight'];
        $row['business_id']      = $row['business_id'] ? (int)$row['business_id'] : null;
    }
    unset($row);

    echo json_encode(['success' => true, 'data' => $ordered]);
    exit;
}

// ── POST /featured-ads/:id/impression — track view (public, fire-and-forget) ─
if ($base === 'featured-ads' && is_numeric($sub) && $subsub === 'impression' && $method === 'POST') {
    ensure_featured_ads_table($pdo);
    try {
        $pdo->prepare('UPDATE featured_ads SET impressions = impressions + 1 WHERE id = ?')
            ->execute([(int)$sub]);
    } catch (Throwable $_) {}
    echo json_encode(['success' => true]);
    exit;
}

// ── POST /featured-ads/:id/click — track click (public, fire-and-forget) ─────
if ($base === 'featured-ads' && is_numeric($sub) && $subsub === 'click' && $method === 'POST') {
    ensure_featured_ads_table($pdo);
    try {
        $pdo->prepare('UPDATE featured_ads SET clicks = clicks + 1 WHERE id = ?')
            ->execute([(int)$sub]);
    } catch (Throwable $_) {}
    echo json_encode(['success' => true]);
    exit;
}

// ── /admin/featured-ads — admin CRUD ─────────────────────────────────────────
if ($base === 'admin' && $sub === 'featured-ads') {
    $uid = require_auth($JWT_SECRET);
    $roleQ = $pdo->prepare('SELECT role FROM users WHERE id=?');
    $roleQ->execute([$uid]);
    $rr = $roleQ->fetch();
    if (!$rr || $rr['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin only.']);
        exit;
    }
    ensure_featured_ads_table($pdo);

    // GET /admin/featured-ads — list all ads
    if ($method === 'GET' && $subsub === '') {
        $s = $pdo->prepare("
            SELECT fa.*, b.name AS business_name
            FROM featured_ads fa
            LEFT JOIN businesses b ON b.id = fa.business_id
            ORDER BY fa.priority ASC, fa.created_at DESC
        ");
        $s->execute();
        $rows = $s->fetchAll();
        $eat_tz = new DateTimeZone('Africa/Addis_Ababa');
        $utc_tz = new DateTimeZone('UTC');
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['is_active'] = (int)$r['is_active'];
            $r['display_duration'] = (int)$r['display_duration'];
            $r['priority'] = (int)$r['priority'];
            $r['weight'] = (int)$r['weight'];
            $r['impressions'] = (int)$r['impressions'];
            $r['clicks'] = (int)$r['clicks'];
            $r['business_id'] = $r['business_id'] ? (int)$r['business_id'] : null;
            // Convert UTC stored datetimes → EAT for the dashboard display
            foreach (['start_at', 'end_at'] as $col) {
                if (!empty($r[$col])) {
                    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $r[$col], $utc_tz);
                    if ($dt) {
                        $dt->setTimezone($eat_tz);
                        $r[$col] = $dt->format('Y-m-d H:i:s');
                    }
                }
            }
        }
        unset($r);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    // GET /admin/featured-ads/:id — single ad (also returns EAT times)
    if ($method === 'GET' && is_numeric($subsub)) {
        $s = $pdo->prepare("SELECT fa.*, b.name AS business_name FROM featured_ads fa LEFT JOIN businesses b ON b.id = fa.business_id WHERE fa.id = ?");
        $s->execute([(int)$subsub]);
        $row = $s->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }
        $eat_tz = new DateTimeZone('Africa/Addis_Ababa');
        $utc_tz = new DateTimeZone('UTC');
        foreach (['start_at', 'end_at'] as $col) {
            if (!empty($row[$col])) {
                $dt = DateTime::createFromFormat('Y-m-d H:i:s', $row[$col], $utc_tz);
                if ($dt) { $dt->setTimezone($eat_tz); $row[$col] = $dt->format('Y-m-d H:i:s'); }
            }
        }
        echo json_encode(['success' => true, 'data' => $row]);
        exit;
    }

    // POST /admin/featured-ads — create (JSON body, media_url already uploaded separately)
    if ($method === 'POST' && $subsub === '') {
        $title           = trim($input['title'] ?? '');
        $mediaType       = $input['media_type'] ?? 'image';
        $mediaUrl        = trim($input['media_url'] ?? '');
        $destUrl         = trim($input['destination_url'] ?? '') ?: null;
        $ctaText         = trim($input['cta_text'] ?? '') ?: null;
        $startAt         = trim($input['start_at'] ?? '');
        $endAt           = trim($input['end_at'] ?? '');
        $duration        = max(3, min(120, (int)($input['display_duration'] ?? 8)));
        $priority        = max(1, (int)($input['priority'] ?? 10));
        $weight          = max(1, (int)($input['weight'] ?? 1));
        $isActive        = (int)(!empty($input['is_active']));
        $businessId      = !empty($input['business_id']) ? (int)$input['business_id'] : null;

        if (!$title || !$mediaUrl || !$startAt || !$endAt) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'title, media_url, start_at, and end_at are required.']);
            exit;
        }
        if (!in_array($mediaType, ['image', 'video'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'media_type must be image or video.']);
            exit;
        }
        // Parse as EAT and store as UTC — timezone-safe regardless of server config
        $startDt = eat_to_utc($startAt);
        $endDt   = eat_to_utc($endAt);
        if (!$startDt || !$endDt) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid start_at or end_at format. Use YYYY-MM-DD HH:MM:SS (Ethiopia time).']);
            exit;
        }
        if ($endDt <= $startDt) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'end_at must be after start_at.']);
            exit;
        }

        $pdo->prepare("INSERT INTO featured_ads
            (title, business_id, media_type, media_url, destination_url, cta_text,
             start_at, end_at, display_duration, priority, weight, is_active, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $title, $businessId, $mediaType, $mediaUrl, $destUrl, $ctaText,
                $startDt->format('Y-m-d H:i:s'), $endDt->format('Y-m-d H:i:s'),
                $duration, $priority, $weight, $isActive, $uid
            ]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Featured ad created.', 'id' => $newId]);
        exit;
    }

    // PUT /admin/featured-ads/:id — full update
    if ($method === 'PUT' && is_numeric($subsub)) {
        $adId = (int)$subsub;
        $exists = $pdo->prepare('SELECT id FROM featured_ads WHERE id=?');
        $exists->execute([$adId]);
        if (!$exists->fetch()) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Ad not found.']); exit; }

        $title      = trim($input['title'] ?? '');
        $mediaType  = $input['media_type'] ?? 'image';
        $mediaUrl   = trim($input['media_url'] ?? '');
        $destUrl    = trim($input['destination_url'] ?? '') ?: null;
        $ctaText    = trim($input['cta_text'] ?? '') ?: null;
        $startAt    = trim($input['start_at'] ?? '');
        $endAt      = trim($input['end_at'] ?? '');
        $duration   = max(3, min(120, (int)($input['display_duration'] ?? 8)));
        $priority   = max(1, (int)($input['priority'] ?? 10));
        $weight     = max(1, (int)($input['weight'] ?? 1));
        $isActive   = (int)(!empty($input['is_active']));
        $businessId = !empty($input['business_id']) ? (int)$input['business_id'] : null;

        if (!$title || !$mediaUrl || !$startAt || !$endAt) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'title, media_url, start_at, and end_at are required.']);
            exit;
        }
        $startDt = eat_to_utc($startAt);
        $endDt   = eat_to_utc($endAt);
        if (!$startDt || !$endDt || $endDt <= $startDt) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid or illogical date range.']);
            exit;
        }

        $pdo->prepare("UPDATE featured_ads SET
            title=?, business_id=?, media_type=?, media_url=?, destination_url=?, cta_text=?,
            start_at=?, end_at=?, display_duration=?, priority=?, weight=?, is_active=?
            WHERE id=?")
            ->execute([
                $title, $businessId, $mediaType, $mediaUrl, $destUrl, $ctaText,
                $startDt->format('Y-m-d H:i:s'), $endDt->format('Y-m-d H:i:s'),
                $duration, $priority, $weight, $isActive, $adId
            ]);
        echo json_encode(['success' => true, 'message' => 'Featured ad updated.']);
        exit;
    }

    // PATCH /admin/featured-ads/:id — partial update (toggle active, etc.)
    if ($method === 'PATCH' && is_numeric($subsub)) {
        $adId = (int)$subsub;
        $fields = []; $params = [];
        if (isset($input['is_active']))        { $fields[] = 'is_active=?';        $params[] = (int)$input['is_active']; }
        if (isset($input['priority']))         { $fields[] = 'priority=?';         $params[] = max(1, (int)$input['priority']); }
        if (isset($input['weight']))           { $fields[] = 'weight=?';           $params[] = max(1, (int)$input['weight']); }
        if (isset($input['display_duration'])) { $fields[] = 'display_duration=?'; $params[] = max(3, min(120, (int)$input['display_duration'])); }
        if (isset($input['cta_text']))         { $fields[] = 'cta_text=?';         $params[] = trim($input['cta_text']) ?: null; }
        if (isset($input['destination_url']))  { $fields[] = 'destination_url=?';  $params[] = trim($input['destination_url']) ?: null; }
        if (!$fields) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'No fields to update.']); exit; }
        $params[] = $adId;
        $pdo->prepare('UPDATE featured_ads SET ' . implode(', ', $fields) . ' WHERE id=?')->execute($params);
        echo json_encode(['success' => true, 'message' => 'Updated.']);
        exit;
    }

    // DELETE /admin/featured-ads/:id — delete
    if ($method === 'DELETE' && is_numeric($subsub)) {
        $adId = (int)$subsub;
        // Optionally delete media file
        $row = $pdo->prepare('SELECT media_url FROM featured_ads WHERE id=?');
        $row->execute([$adId]);
        $ad = $row->fetch();
        if ($ad && !empty($ad['media_url'])) {
            delete_upload_file($ad['media_url']);
        }
        $pdo->prepare('DELETE FROM featured_ads WHERE id=?')->execute([$adId]);
        echo json_encode(['success' => true, 'message' => 'Deleted.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── POST /admin/featured-ads-upload — upload image or video for an ad ────────
if ($base === 'admin' && $sub === 'featured-ads-upload' && $method === 'POST') {
    $uid = require_auth($JWT_SECRET);
    $roleQ = $pdo->prepare('SELECT role FROM users WHERE id=?');
    $roleQ->execute([$uid]);
    $rr = $roleQ->fetch();
    if (!$rr || $rr['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin only.']);
        exit;
    }

    $mediaType = $_POST['media_type'] ?? 'image';

    if ($mediaType === 'image') {
        if (empty($_FILES['media'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
            exit;
        }
        try {
            $result = upload_image($_FILES['media'], 'featured', 5242880); // 5MB for ads
            echo json_encode(['success' => true, 'url' => $result['url'], 'media_type' => 'image']);
        } catch (RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    } elseif ($mediaType === 'video') {
        if (empty($_FILES['media'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
            exit;
        }
        $file = $_FILES['media'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Upload error ' . $file['error']]);
            exit;
        }
        $maxBytes = 52428800; // 50MB for videos
        if ($file['size'] > $maxBytes) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Video exceeds 50 MB limit.']);
            exit;
        }
        // Validate MIME by checking finfo
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
        } else {
            $mime = $file['type'] ?? '';
        }
        $allowedVideoMimes = ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/3gpp'];
        if (!in_array($mime, $allowedVideoMimes, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unsupported video type. Use MP4, MOV, or WebM.']);
            exit;
        }
        $ext      = 'mp4';
        if ($mime === 'video/webm')     $ext = 'webm';
        if ($mime === 'video/quicktime') $ext = 'mov';
        $dir = __DIR__ . '/uploads/featured';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;
        $dest     = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save video.']);
            exit;
        }
        $url = 'https://verifypay.et/uploads/featured/' . $filename;
        echo json_encode(['success' => true, 'url' => $url, 'media_type' => 'video']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'media_type must be image or video.']);
    }
    exit;
}


$_filter404 = function($p) { return (string)$p !== ''; };
$fullPath = '/' . implode('/', array_filter([$base, $sub, $subsub, $subsubid], $_filter404));
echo json_encode(['success' => false, 'message' => 'Route not found: ' . $fullPath, 'method' => $method]);
exit;
