<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/registration.php';

$registrationMode = registration_mode($pdo);

// 强制使用东八区时间，保证 PHP 和 MySQL 锁定时间绝对对齐
date_default_timezone_set('Asia/Shanghai');
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Exception $e) {}

function getRealIP() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // NPM 等反代会把真实 IP 放在 X-Forwarded-For 中，如果有多个代理，取第一个逗号前的 IP
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    // 阿里云直接 IP 访问走这里
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
$ip = getRealIP();

// 检查是否处于锁定状态的函数
function checkIsLocked($pdo, $ip, $username) {
    $stmt = $pdo->prepare("SELECT type, lock_until FROM login_blocks WHERE (type = 'ip' AND identifier = ?) OR (type = 'username' AND identifier = ?)");
    $stmt->execute([$ip, $username]);
    $locks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($locks as $lock) {
        if ($lock['lock_until'] && strtotime($lock['lock_until']) > time()) {
            $rem = ceil((strtotime($lock['lock_until']) - time()) / 60);
            if ($lock['type'] === 'ip') return "🚫 您当前网络环境错误尝试过多，触发 IP 保护，请 $rem 分钟后再试。";
            if ($lock['type'] === 'username') return "🚫 该账号密码频繁输入错误，已被安全锁定，请 $rem 分钟后再试。";
        }
    }
    return false;
}

// 记录失败次数的函数（账号容错 5 次，IP 容错 20 次避免局域网误伤）
function recordFailedAttempt($pdo, $ip, $username) {
    $identifiers = [];
    if ($ip) $identifiers['ip'] = $ip;
    if ($username) $identifiers['username'] = $username;

    foreach ($identifiers as $type => $val) {
        $stmt = $pdo->prepare("SELECT failed_count, lock_until FROM login_blocks WHERE type = ? AND identifier = ?");
        $stmt->execute([$type, $val]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // --- 核心修复：IP 给 20 次机会，账号给 5 次机会 ---
        $threshold = ($type === 'ip') ? 20 : 5;

        if ($row) {
            // 如果存在历史锁定且已过期，重置为 1 次
            if ($row['lock_until'] && strtotime($row['lock_until']) <= time()) {
                $pdo->prepare("UPDATE login_blocks SET failed_count = 1, lock_until = NULL WHERE type = ? AND identifier = ?")->execute([$type, $val]);
            } else {
                // 累计错误次数
                $new_count = $row['failed_count'] + 1;
                $lock = ($new_count >= $threshold) ? date('Y-m-d H:i:s', time() + 15 * 60) : NULL;
                $pdo->prepare("UPDATE login_blocks SET failed_count = ?, lock_until = ? WHERE type = ? AND identifier = ?")->execute([$new_count, $lock, $type, $val]);
            }
        } else {
            // 第一次失败
            $pdo->prepare("INSERT INTO login_blocks (type, identifier, failed_count) VALUES (?, ?, 1)")->execute([$type, $val]);
        }
    }
}

// 成功后清除记录的函数
function clearAttempts($pdo, $ip, $username) {
    $stmt = $pdo->prepare("DELETE FROM login_blocks WHERE (type = 'ip' AND identifier = ?) OR (type = 'username' AND identifier = ?)");
    $stmt->execute([$ip, $username]);
}
// ===============================


// 拦截 AJAX 请求，处理密码找回 (同时接入防爆破)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_action'])) {
    header('Content-Type: application/json');
    $u = trim($_POST['username'] ?? '');

    // API 级别拦截锁
    if ($lockMsg = checkIsLocked($pdo, $ip, $u)) {
        echo json_encode(['status'=>'error', 'message'=>$lockMsg]);
        exit;
    }

    if ($_POST['api_action'] === 'get_q') {
        $stmt = $pdo->prepare("SELECT sec_q1, sec_q2, sec_q3 FROM users WHERE username = ? AND parent_id = 0");
        $stmt->execute([$u]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && !empty($user['sec_q1'])) {
            echo json_encode(['status'=>'success', 'data'=>$user]);
        } else {
            // --- 修复：找不到密保时，只封禁发起攻击的恶意 IP，绝不牵连目标账号 ---
            recordFailedAttempt($pdo, $ip, null);
            echo json_encode(['status'=>'error', 'message'=>'主账号不存在或早期账号未设置密保。员工请联系老板重置！']);
        }
        exit;
    }

    if ($_POST['api_action'] === 'reset_pwd') {
        $a1 = trim($_POST['a1']); $a2 = trim($_POST['a2']); $a3 = trim($_POST['a3']);
        $new_pwd = $_POST['new_pwd'];

        $stmt = $pdo->prepare("SELECT id, sec_a1, sec_a2, sec_a3 FROM users WHERE username = ? AND parent_id = 0");
        $stmt->execute([$u]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $a1_match = password_verify($a1, $user['sec_a1']) || ($user['sec_a1'] === $a1);
            $a2_match = password_verify($a2, $user['sec_a2']) || ($user['sec_a2'] === $a2);
            $a3_match = password_verify($a3, $user['sec_a3']) || ($user['sec_a3'] === $a3);

            if ($a1_match && $a2_match && $a3_match) {
                $hash = password_hash($new_pwd, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hash, $user['id']]);
                clearAttempts($pdo, $ip, $u); // 密保验证成功，清除错误状态
                echo json_encode(['status'=>'success']);
                exit;
            }
        }

        // 修复：密保错误只惩罚发起攻击的 IP（传 null），绝不能连坐牵连被试探的账号被锁死
        recordFailedAttempt($pdo, $ip, null);
        echo json_encode(['status'=>'error', 'message'=>'密保答案错误，验证失败！']);
        exit;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['api_action'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // 表单级别拦截锁
    if ($lockMsg = checkIsLocked($pdo, $ip, $username)) {
        $error = $lockMsg;
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password'])) {

                clearAttempts($pdo, $ip, $username); // 密码正确，解除警报

                // 🚀 核心绝杀 1：彻底清空前朝的幽灵数据（特别是旧的 last_active_time）
                session_unset();

                // 🛡️ 保持上次的修复：防高并发被覆盖
                session_regenerate_id();

                $_SESSION['is_logged_in'] = true; $_SESSION['user_id'] = $user['id']; $_SESSION['username'] = $user['username']; $_SESSION['role'] = $user['role'];
                $_SESSION['parent_id'] = $user['parent_id'] ?? 0; $_SESSION['perm_finance'] = $user['perm_finance'] ?? 1; $_SESSION['perm_edit'] = $user['perm_edit'] ?? 1; $_SESSION['perm_delete'] = $user['perm_delete'] ?? 1;
                $_SESSION['perm_tab_us'] = $user['perm_tab_us'] ?? 1; $_SESSION['perm_tab_transit'] = $user['perm_tab_transit'] ?? 1; $_SESSION['perm_tab_cn'] = $user['perm_tab_cn'] ?? 1; $_SESSION['perm_tab_sold'] = $user['perm_tab_sold'] ?? 1;

                // 🚀 核心绝杀 2：登录成功瞬间，立刻刷新活跃时间！防止带着旧时间去主页被秒踢
                $_SESSION['last_active_time'] = time();

                // 🛡️ 保持上次的修复：强制落盘
                session_write_close();

                header("Location: index.php"); exit;
            } else {
                recordFailedAttempt($pdo, $ip, $username); // 密码错误，记录一笔

                // 给用户动态提示还剩几次机会 (以账号错误次数为准展示)
                $stmtLock = $pdo->prepare("SELECT failed_count FROM login_blocks WHERE type='username' AND identifier=?");
                $stmtLock->execute([$username]);
                $curr_fails = $stmtLock->fetchColumn() ?: 1;
                $remains = 5 - $curr_fails;

                if ($remains > 0) {
                    $error = "密码不正确！该账号还有 $remains 次尝试机会。";
                } else {
                    $error = "🚫 错误次数超限，为保护账户安全，该账号已被锁定 15 分钟。";
                }
            }
        } catch (Exception $e) { $error = "系统未初始化，请先配置数据库环境"; }
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
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="flex flex-col gap-5" onsubmit="if(this.submitted) return false; this.submitted = true; document.getElementById('loginBtn').disabled = true; document.getElementById('loginBtn').innerText = '验证中...';">
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
        function toggleRecover(show) {
            document.getElementById('loginPanel').style.display = show ? 'none' : 'block';
            document.getElementById('recoverPanel').style.display = show ? 'flex' : 'none';
            document.getElementById('rec_step1').style.display = 'flex';
            document.getElementById('rec_step2').style.display = 'none';
        }

        async function fetchQuestions() {
            const u = document.getElementById('rec_user').value.trim();
            if(!u) return alert("请输入用户名");
            const fd = new FormData(); fd.append('api_action', 'get_q'); fd.append('username', u);
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
            const fd = new FormData(); fd.append('api_action', 'reset_pwd');
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
