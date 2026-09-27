<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/registration.php';

$registrationMode = registration_mode($pdo);
$ip = client_ip($_SERVER, app_config_list('TRUSTED_PROXIES'));

function login_lock_message(PDO $pdo, string $ip, string $username): ?string
{
    $ipLock = auth_lock_until($pdo, 'ip', $ip);
    $usernameLock = $username !== '' ? auth_lock_until($pdo, 'username', $username) : null;
    $lock = $ipLock ?? $usernameLock;
    if ($lock === null) {
        return null;
    }
    $minutes = max(1, (int) ceil(($lock->getTimestamp() - time()) / 60));
    return "登录尝试过多，请 {$minutes} 分钟后再试。";
}

function record_login_failure(PDO $pdo, string $ip, string $username, bool $includeUsername = true): int
{
    record_auth_failure($pdo, 'ip', $ip, 20, 15);
    return $includeUsername && $username !== ''
        ? record_auth_failure($pdo, 'username', $username, 5, 15)
        : 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    header('Content-Type: application/json');
    try {
        require_csrf();
        $u = trim((string) ($_POST['username'] ?? ''));
        if ($u === '' || strlen($u) > 50) {
            throw new HttpException('无法验证该账号的找回信息。', 400);
        }
        if ($lockMsg = login_lock_message($pdo, $ip, $u)) {
            throw new HttpException($lockMsg, 429);
        }

        if ($_POST['api_action'] === 'get_q') {
            $stmt = $pdo->prepare("SELECT sec_q1, sec_q2, sec_q3 FROM users WHERE username = ? AND parent_id = 0");
            $stmt->execute([$u]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || empty($user['sec_q1'])) {
                record_login_failure($pdo, $ip, $u, false);
                throw new HttpException('无法验证该账号的找回信息。', 400);
            }
            echo json_encode(['status' => 'success', 'data' => $user], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($_POST['api_action'] === 'reset_pwd') {
            $answers = [
                trim((string) ($_POST['a1'] ?? '')),
                trim((string) ($_POST['a2'] ?? '')),
                trim((string) ($_POST['a3'] ?? '')),
            ];
            $newPassword = (string) ($_POST['new_pwd'] ?? '');
            $passwordCheck = validate_password($newPassword);
            if (!$passwordCheck['valid']) {
                throw new HttpException(implode(' ', $passwordCheck['errors']), 400);
            }

            $stmt = $pdo->prepare("SELECT id, sec_a1, sec_a2, sec_a3 FROM users WHERE username = ? AND parent_id = 0");
            $stmt->execute([$u]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $checks = $user ? [
                verify_stored_secret($answers[0], (string) $user['sec_a1']),
                verify_stored_secret($answers[1], (string) $user['sec_a2']),
                verify_stored_secret($answers[2], (string) $user['sec_a3']),
            ] : [];

            if ($user && array_reduce($checks, fn (bool $valid, array $check): bool => $valid && $check['valid'], true)) {
                $stmt = $pdo->prepare("UPDATE users SET password = ?, sec_a1 = ?, sec_a2 = ?, sec_a3 = ? WHERE id = ?");
                $stmt->execute([
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $checks[0]['upgrade_hash'] ?? $user['sec_a1'],
                    $checks[1]['upgrade_hash'] ?? $user['sec_a2'],
                    $checks[2]['upgrade_hash'] ?? $user['sec_a3'],
                    $user['id'],
                ]);
                clear_auth_failures($pdo, 'ip', $ip);
                clear_auth_failures($pdo, 'username', $u);
                echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            record_login_failure($pdo, $ip, $u, false);
            throw new HttpException('无法验证该账号的找回信息。', 400);
        }
        throw new HttpException('未知操作。', 400);
    } catch (HttpException $exception) {
        http_response_code($exception->statusCode());
        echo json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        $requestId = safe_log($exception);
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '请求暂时无法完成，请稍后重试。请求编号：' . $requestId], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['api_action'])) {
    try {
        require_csrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || strlen($username) > 50) {
            throw new HttpException('用户名或密码不正确。', 400);
        }
        if ($lockMsg = login_lock_message($pdo, $ip, $username)) {
            throw new HttpException($lockMsg, 429);
        }

            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            $verification = $user
                ? verify_stored_secret($password, (string) $user['password'])
                : ['valid' => false, 'upgrade_hash' => null];
            if ($user && $verification['valid']) {
                clear_auth_failures($pdo, 'ip', $ip);
                clear_auth_failures($pdo, 'username', $username);
                if ($verification['upgrade_hash'] !== null) {
                    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                        ->execute([$verification['upgrade_hash'], $user['id']]);
                }
                establish_authenticated_session($user);
                session_write_close();
                header('Location: index.php');
                exit;
            }
            $failures = record_login_failure($pdo, $ip, $username);
            $remaining = max(0, 5 - $failures);
            throw new HttpException($remaining > 0
                ? "用户名或密码不正确，还可尝试 {$remaining} 次。"
                : '登录尝试过多，账号已暂时锁定。', 401);
    } catch (HttpException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $requestId = safe_log($exception);
        $error = '登录暂时无法完成，请稍后重试。请求编号：' . $requestId;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>登录 - 金牌卖家进销存</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238B0000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22'></path></svg>">
    <script src="assets/tailwindcss.js"></script>
    <style>
        input::placeholder { color: #94a3b8; font-size: 14px; }
    </style>

    <script>
        sessionStorage.removeItem('sys_tab_locked');
        localStorage.removeItem('sys_global_locked');
        localStorage.removeItem('sys_shared_active_time');
    </script>
    </head>
<body class="flex items-center justify-center h-screen relative overflow-hidden bg-slate-100 font-sans">

    <div class="absolute inset-0 z-0">
        <img src="https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&q=80" class="w-full h-full object-cover filter blur-[6px] scale-105 opacity-50" alt="background">
        <div class="absolute inset-0 bg-slate-200/30 mix-blend-multiply"></div>
    </div>

    <div id="loginPanel" class="bg-white px-10 py-12 rounded-[28px] shadow-2xl w-[420px] z-10 transition-opacity duration-300 relative">
        <div class="text-center mb-10">
            <div class="flex justify-center mb-4">
                <svg stroke-linejoin="round" stroke-linecap="round" stroke-width="2" stroke="currentColor" fill="none" viewBox="0 0 24 24" class="w-10 h-10 text-[#8B0000] hover:scale-110 duration-200 cursor-pointer">
                    <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"></path>
                </svg>
            </div>
            <h2 class="text-[22px] font-black text-[#8B0000] tracking-wider">金牌卖家 Workstation</h2>
        </div>

        <?php if($error): ?>
            <div class="bg-red-50 text-red-600 border border-red-100 p-3 rounded-xl mb-6 text-sm font-bold shadow-sm text-center">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="flex flex-col gap-5" onsubmit="if(this.submitted) return false; this.submitted = true; document.getElementById('loginBtn').disabled = true; document.getElementById('loginBtn').innerText = '验证中...';">
            <input type="hidden" name="_csrf_token" value="<?php echo e(csrf_token()); ?>">
            <div>
                <input type="text" name="username" required placeholder="请输入系统用户名" autocomplete="off" class="w-full bg-[#F8F9FA] border border-slate-200 rounded-xl px-4 py-3.5 focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none transition-all text-slate-800 font-medium">
            </div>
            <div>
                <input type="password" name="password" required placeholder="请输入密码" class="w-full bg-[#F8F9FA] border border-slate-200 rounded-xl px-4 py-3.5 focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none transition-all text-slate-800 font-medium">
            </div>

            <button type="submit" id="loginBtn" class="w-full bg-[#8B0000] text-white font-bold py-3.5 rounded-xl hover:bg-[#600000] shadow-lg shadow-red-900/20 transition-all mt-4 text-[15px] tracking-widest disabled:opacity-50 disabled:cursor-not-allowed">
                立即登录
            </button>
        </form>

        <div class="mt-8 flex justify-between items-center text-xs font-medium text-slate-400 px-1">
            <button type="button" onclick="toggleRecover(true)" class="hover:text-[#8B0000] transition-colors">忘记密码？</button>
            <?php if (registration_is_available($registrationMode)): ?>
                <a href="register.php" class="hover:text-[#8B0000] transition-colors"><?php echo $registrationMode === 'invite' ? '使用邀请码注册' : '创建独立新账户'; ?></a>
            <?php endif; ?>
        </div>
    </div>

    <div id="recoverPanel" class="bg-white px-10 py-12 rounded-[28px] shadow-2xl w-[420px] z-20 absolute hidden flex-col transition-opacity duration-300">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-xl font-black text-[#8B0000]">找回主账号密码</h2>
            <button onclick="toggleRecover(false)" class="text-sm font-bold text-slate-400 hover:text-slate-600">返回登录</button>
        </div>

        <div id="rec_step1" class="flex flex-col gap-5">
            <p class="text-sm text-slate-500 mb-1 leading-relaxed">请输入您的主账号用户名，系统将提取您的密保问题进行验证。</p>
            <input type="text" id="rec_user" placeholder="请输入系统用户名" class="w-full bg-[#F8F9FA] border border-slate-200 rounded-xl px-4 py-3.5 focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none transition-all text-slate-800 font-medium">
            <button onclick="fetchQuestions()" class="w-full bg-[#8B0000] text-white font-bold py-3.5 rounded-xl hover:bg-[#600000] shadow-lg shadow-red-900/20 transition-all mt-2 text-[15px] tracking-wider">下一步验证</button>
        </div>

        <div id="rec_step2" class="hidden flex flex-col gap-4">
            <div class="bg-orange-50/50 p-4 rounded-xl border border-orange-100 space-y-4 mb-2">
                <div>
                    <div class="text-xs font-bold text-orange-800 mb-1.5 pl-1" id="lbl_q1"></div>
                    <input type="text" id="rec_a1" placeholder="输入答案" class="w-full text-sm bg-white border border-slate-200 rounded-lg px-3 py-2.5 outline-none focus:border-orange-500 transition-all">
                </div>
                <div>
                    <div class="text-xs font-bold text-orange-800 mb-1.5 pl-1" id="lbl_q2"></div>
                    <input type="text" id="rec_a2" placeholder="输入答案" class="w-full text-sm bg-white border border-slate-200 rounded-lg px-3 py-2.5 outline-none focus:border-orange-500 transition-all">
                </div>
                <div>
                    <div class="text-xs font-bold text-orange-800 mb-1.5 pl-1" id="lbl_q3"></div>
                    <input type="text" id="rec_a3" placeholder="输入答案" class="w-full text-sm bg-white border border-slate-200 rounded-lg px-3 py-2.5 outline-none focus:border-orange-500 transition-all">
                </div>
            </div>
            <div>
                <input type="password" id="rec_new_pwd" placeholder="设置新登录密码" class="w-full bg-[#F8F9FA] border border-slate-200 rounded-xl px-4 py-3.5 focus:bg-white focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-none transition-all text-slate-800 font-medium">
            </div>
            <button onclick="submitRecover()" class="w-full bg-emerald-700 text-white font-bold py-3.5 rounded-xl hover:bg-emerald-800 shadow-lg shadow-emerald-900/20 transition-all mt-2 text-[15px] tracking-wider">验证并重置密码</button>
        </div>
    </div>

    <div class="absolute bottom-6 z-10 text-[13px] font-medium text-slate-500 drop-shadow-sm tracking-wide">
        © 2026 金牌卖家进销存系统
    </div>

    <script>
        const CSRF_TOKEN = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;

        function toggleRecover(show) {
            document.getElementById('loginPanel').style.display = show ? 'none' : 'block';
            document.getElementById('recoverPanel').style.display = show ? 'flex' : 'none';
            document.getElementById('rec_step1').style.display = 'flex';
            document.getElementById('rec_step2').style.display = 'none';
        }

        async function fetchQuestions() {
            const u = document.getElementById('rec_user').value.trim();
            if(!u) return alert("请输入用户名");
            const fd = new FormData(); fd.append('_csrf_token', CSRF_TOKEN); fd.append('api_action', 'get_q'); fd.append('username', u);
            const res = await fetch('', {method:'POST', body:fd}); const result = await res.json();
            if(result.status === 'success') {
                document.getElementById('lbl_q1').innerText = result.data.sec_q1;
                document.getElementById('lbl_q2').innerText = result.data.sec_q2;
                document.getElementById('lbl_q3').innerText = result.data.sec_q3;
                document.getElementById('rec_step1').style.display = 'none';
                document.getElementById('rec_step2').style.display = 'flex';
            } else { alert(result.message); }
        }

        async function submitRecover() {
            const fd = new FormData(); fd.append('_csrf_token', CSRF_TOKEN); fd.append('api_action', 'reset_pwd');
            fd.append('username', document.getElementById('rec_user').value.trim());
            fd.append('a1', document.getElementById('rec_a1').value.trim());
            fd.append('a2', document.getElementById('rec_a2').value.trim());
            fd.append('a3', document.getElementById('rec_a3').value.trim());
            const newPwd = document.getElementById('rec_new_pwd').value;
            if(!newPwd) return alert('新密码不能为空');
            fd.append('new_pwd', newPwd);

            const res = await fetch('', {method:'POST', body:fd}); const result = await res.json();
            if(result.status === 'success') {
                alert('密码重置成功！请使用新密码登录。'); window.location.reload();
            } else { alert(result.message); }
        }
    </script>
</body>
</html>
