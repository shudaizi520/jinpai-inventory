<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    perform_logout();
    header('Location: login.php');
    exit;
}

// ==========================================
// 服务器端绝对时间强制校验
// ==========================================
$timeout_minutes = $currentUser['timeout_minutes'] ?? 20;
if ($timeout_minutes > 0) {
    $timeout_seconds = $timeout_minutes * 60;
    $now = time();
    if (isset($_SESSION['last_active_time']) && ($now - $_SESSION['last_active_time'] > $timeout_seconds)) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
    $_SESSION['last_active_time'] = $now;
}
// ==========================================
unset($_SESSION['finance_unlocked_' . $_SESSION['user_id']]);

$perm_finance = $currentUser['perm_finance'] ?? 1;
$perm_edit = $currentUser['perm_edit'] ?? 1;
$perm_delete = $currentUser['perm_delete'] ?? 1;
$perm_download_tpl = $currentUser['perm_download_tpl'] ?? 1;
$perm_import = $currentUser['perm_import'] ?? 1;
$perm_add = $currentUser['perm_add'] ?? 1;
$perm_export = $currentUser['perm_export'] ?? 1;
$is_sub_account = ($currentUser['parent_id'] > 0) ? true : false;
$owner_id = $is_sub_account ? $currentUser['parent_id'] : $_SESSION['user_id'];

$stmt_owner = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt_owner->execute([$owner_id]);
$ownerData = $stmt_owner->fetch(PDO::FETCH_ASSOC);

$p_us = $currentUser['perm_tab_us'] ?? 1;
$p_transit = $currentUser['perm_tab_transit'] ?? 1;
$p_cn = $currentUser['perm_tab_cn'] ?? 1;
$p_sold = $currentUser['perm_tab_sold'] ?? 1;
$p_repair = $currentUser['perm_tab_repair'] ?? 1;
$p_repair_done = $currentUser['perm_tab_repair_done'] ?? 1;
$p_parts = $currentUser['perm_tab_parts'] ?? 1;
$p_parts_sold = $currentUser['perm_tab_parts_sold'] ?? 1;

if ($is_sub_account) {
    if (($ownerData['perm_tab_us'] ?? 1) == 0) $p_us = 0;
    if (($ownerData['perm_tab_transit'] ?? 1) == 0) $p_transit = 0;
    if (($ownerData['perm_tab_cn'] ?? 1) == 0) $p_cn = 0;
    if (($ownerData['perm_tab_sold'] ?? 1) == 0) $p_sold = 0;
    if (($ownerData['perm_tab_repair'] ?? 1) == 0) $p_repair = 0;
    if (($ownerData['perm_tab_repair_done'] ?? 1) == 0) $p_repair_done = 0;
    if (($ownerData['perm_tab_parts'] ?? 1) == 0) $p_parts = 0;
    if (($ownerData['perm_tab_parts_sold'] ?? 1) == 0) $p_parts_sold = 0;
}

$l_us = $ownerData['lock_tab_us'] ?? 0;
$l_transit = $ownerData['lock_tab_transit'] ?? 0;
$l_cn = $ownerData['lock_tab_cn'] ?? 0;
$l_sold = $ownerData['lock_tab_sold'] ?? 1;
$l_repair = $ownerData['lock_tab_repair'] ?? 0;
$l_repair_done = $ownerData['lock_tab_repair_done'] ?? 0;
$l_parts = $ownerData['lock_tab_parts'] ?? 0;
$l_parts_sold = $ownerData['lock_tab_parts_sold'] ?? 1;

// --- 修改：只有初始主账号 (role 为 admin) 才能看到系统人数统计 ---
$user_count = 0;
$is_initial_admin = (isset($currentUser['role']) && $currentUser['role'] === 'admin');
if ($is_initial_admin) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $user_count = $stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>金牌卖家 Workstation</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238B0000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22'></path></svg>">
    <script src="assets/tailwindcss.js"></script>
    <script src="assets/xlsx.full.min.js"></script>
    <style>
        html, body { -webkit-user-select: auto !important; -moz-user-select: auto !important; -ms-user-select: auto !important; user-select: auto !important; }
        table, th, td, tr, tbody, thead, span, p, div { -webkit-user-select: text !important; -moz-user-select: text !important; -ms-user-select: text !important; user-select: text !important; cursor: text; }
        button, a, input[type="checkbox"], label, .tab-btn, .cursor-pointer, [onclick], button *, a *, label *, .tab-btn *, .cursor-pointer *, [onclick] * { -webkit-user-select: none !important; -moz-user-select: none !important; -ms-user-select: none !important; user-select: none !important; cursor: pointer !important; }
        body { background-color: #F4F7F9; overflow: hidden; height: 100vh; display: flex; flex-direction: column; font-family: system-ui, -apple-system, sans-serif; }
        .tab-btn { 
            padding: 8px 20px; 
            color: #64748b; 
            font-weight: 600; 
            font-size: 14px; 
            border-radius: 12px; 
            transition: all 0.15s ease; /* 动画改成了极短时间的无感淡入淡出 */
            border: 1px solid transparent; 
            background: transparent; 
            outline: none; 
        }
        .tab-btn:hover:not(.active) { 
            color: #1e293b; 
            background-color: #ffffff; 
            border-color: rgba(0,0,0,0.05); 
            box-shadow: 0 2px 6px rgba(0,0,0,0.04); /* 阴影调得更贴地，不那么飘了 */
            z-index: 10;
        }
        .tab-btn.active { 
            color: #8B0000; 
            font-weight: 800; 
            background-color: #ffffff; 
            border-color: #8B0000; /* 依然保留绝对清晰的品牌红边框 */
            box-shadow: 0 2px 8px rgba(139, 0, 0, 0.12); /* 依然保留微红的高级光晕，但更贴地 */
            z-index: 10;
        }
        .modal-enter { opacity: 0; transform: scale(0.97); }
        .modal-enter-active { opacity: 1; transform: scale(1); transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
        input::placeholder { color: #94a3b8; }
        .custom-checkbox { width: 1rem; height: 1rem; border-radius: 0.2rem; border: 1.5px solid #cbd5e1; cursor: pointer; accent-color: #8B0000; transition: all 0.2s; }
        .custom-checkbox:hover { border-color: #8B0000; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="text-slate-800 antialiased selection:bg-[#8B0000] selection:text-white">
    <script>
        const CSRF_TOKEN = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;
        async function apiFetch(input, init = {}) {
            const options = {...init};
            const method = String(options.method || 'GET').toUpperCase();
            if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
                const headers = new Headers(options.headers || {});
                headers.set('X-CSRF-Token', CSRF_TOKEN);
                options.headers = headers;
            }
            return window.fetch(input, options);
        }

        function relockFinance() {
            const body = new FormData();
            body.append('action', 'relock');
            return apiFetch('api_inventory.php', {method: 'POST', body});
        }

        const phpTag = '<' + '?php';
        const parsePHP = (str, def) => { 
            if (str.trim().startsWith(phpTag) || str.includes(phpTag)) return def; 
            const num = parseInt(str, 10); 
            return isNaN(num) ? def : num; 
        };

        const PERM_FINANCE = parsePHP("<?php echo $perm_finance; ?>", 1);
        const PERM_EDIT = parsePHP("<?php echo $perm_edit; ?>", 1);
        const PERM_DELETE = parsePHP("<?php echo $perm_delete; ?>", 1);
        const PERM_DOWNLOAD_TPL = parsePHP("<?php echo $perm_download_tpl; ?>", 1);
        const PERM_IMPORT = parsePHP("<?php echo $perm_import; ?>", 1);
        const PERM_ADD = parsePHP("<?php echo $perm_add; ?>", 1);
        const PERM_EXPORT = parsePHP("<?php echo $perm_export; ?>", 1);
        const rawSubAcc = "<?php echo $is_sub_account ? 'true' : 'false'; ?>";
        const IS_SUB_ACCOUNT = (rawSubAcc.trim().startsWith(phpTag) || rawSubAcc.includes(phpTag)) ? false : (rawSubAcc === 'true');
        
        let TIMEOUT_MINUTES = parsePHP("<?php echo $timeout_minutes; ?>", 20);
        const LOCK_TABS = { 
            'US': parsePHP("<?php echo $l_us; ?>", 0), 
            'TRANSIT': parsePHP("<?php echo $l_transit; ?>", 0), 
            'CN_WH': parsePHP("<?php echo $l_cn; ?>", 0), 
            'SOLD': parsePHP("<?php echo $l_sold; ?>", 1), 
            'REPAIR': parsePHP("<?php echo $l_repair; ?>", 0), 
            'REPAIR_DONE': parsePHP("<?php echo $l_repair_done; ?>", 0),
            'PARTS': parsePHP("<?php echo $l_parts; ?>", 0), 
            'PARTS_SOLD': parsePHP("<?php echo $l_parts_sold; ?>", 1)
        };
        
        let isFinanceUnlocked = false;
        let currentTab = '';
        
        const p_cn = parsePHP("<?php echo $p_cn; ?>", 1); 
        const p_us = parsePHP("<?php echo $p_us; ?>", 1); 
        const p_transit = parsePHP("<?php echo $p_transit; ?>", 1);
        const p_sold = parsePHP("<?php echo $p_sold; ?>", 1); 
        const p_repair = parsePHP("<?php echo $p_repair; ?>", 1); 
        const p_repair_done = parsePHP("<?php echo $p_repair_done; ?>", 1);
        const p_parts = parsePHP("<?php echo $p_parts; ?>", 1); 
        const p_parts_sold = parsePHP("<?php echo $p_parts_sold; ?>", 1);

        if(p_cn === 1) currentTab = 'CN_WH';
        if (!currentTab) {
            const availableTabs = [];
            if(p_us === 1) availableTabs.push('US'); 
            if(p_transit === 1) availableTabs.push('TRANSIT'); 
            if(p_sold === 1) availableTabs.push('SOLD');
            if(p_parts === 1) availableTabs.push('PARTS'); 
            if(p_parts_sold === 1) availableTabs.push('PARTS_SOLD');
            if(p_repair === 1) availableTabs.push('REPAIR'); 
            if(p_repair_done === 1) availableTabs.push('REPAIR_DONE');
            if(availableTabs.length > 0) currentTab = availableTabs[0];
        }
        if (!currentTab) currentTab = 'NONE';
        
        // === 🌟 1. 全局活跃度共享（带防卡顿优化） ===
        let tabLastActive = Date.now();
        function updateTabActivity() { 
            let now = Date.now();
            if (now - tabLastActive > 1000) { // 每秒最多记录一次，防止鼠标滑动导致浏览器卡顿
                tabLastActive = now; 
                localStorage.setItem('sys_shared_active_time', tabLastActive.toString());
            }
        }
        ['mousemove', 'mousedown', 'keypress', 'touchmove', 'scroll', 'click'].forEach(evt => {
            document.addEventListener(evt, updateTabActivity, { passive: true });
        });

        // === 🎨 渲染锁屏画面 ===
        function renderTabLockScreen() {
            document.body.innerHTML = `
                <div style="display:flex; height:100vh; width:100vw; background:#f8fafc; align-items:center; justify-content:center; flex-direction:column; font-family:sans-serif;">
                    <svg style="width:64px; height:64px; color:#cbd5e1; margin-bottom:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <h2 style="color:#334155; margin-bottom:10px; font-size:20px;">此标签页已安全锁定</h2>
                    <p style="color:#94a3b8; font-size:14px; margin-bottom:24px;">长时间未操作已自动隐藏，请验证登录密码以恢复工作状态。</p>
                    <input type="password" id="unlock_pwd" onkeypress="if(event.keyCode===13) unlockTab()" placeholder="请输入当前系统登录密码解锁" style="padding:12px 16px; border:1px solid #cbd5e1; border-radius:8px; width:280px; outline:none; text-align:center; margin-bottom:16px; font-size:14px;">
                    <button onclick="unlockTab()" style="background:#8B0000; color:white; border:none; padding:12px 24px; border-radius:8px; cursor:pointer; font-weight:bold; font-size:14px; width:280px; box-shadow:0 4px 6px -1px rgba(139,0,0,0.2);">验证密码并解锁</button>
                </div>
            `;
            setTimeout(() => { document.getElementById('unlock_pwd').focus(); }, 100);
        }

        // === 🔑 解锁请求函数 ===
        window.unlockTab = async function() {
            const pwd = document.getElementById('unlock_pwd').value;
            if(!pwd) return alert('密码不能为空！');
            const fd = new FormData();
            fd.append('action', 'verify_login');
            fd.append('pwd', pwd);
            try {
                const r = await apiFetch('api_inventory.php', {method: 'POST', body: fd});
                const j = await r.json();
                
                if(j.status === 'success') {
                    sessionStorage.removeItem('sys_tab_locked'); 
                    localStorage.removeItem('sys_global_locked'); 
                    window.location.reload(); 
                } else if (j.message === '登录状态失效' || j.message === 'FORCE_LOGOUT') {
                    sessionStorage.removeItem('sys_tab_locked');
                    localStorage.removeItem('sys_global_locked');
                    if (j.message === 'FORCE_LOGOUT') {
                        alert('🚨 警告：连续 5 次输入错误，系统已强制保护并注销！');
                    } else {
                        alert('主账号安全会话已过期，请重新登录系统！');
                    }
                    window.location.href = 'login.php';
                } else {
                    alert(j.message || '密码错误！');
                    document.getElementById('unlock_pwd').value = '';
                    document.getElementById('unlock_pwd').focus();
                }
            } catch(e) { alert('网络验证失败'); }
        };

        // === 🚨 2. 跨标签页状态同步（双向联动） ===
        window.addEventListener('storage', (e) => {
            if (e.key === 'sys_global_locked') {
                if (e.newValue === '1') {
                    sessionStorage.setItem('sys_tab_locked', '1');
                    renderTabLockScreen();
                } else if (e.newValue === null) {
                    // 如果别的页面解开了密码，当前页面也自动解开并刷新
                    if (sessionStorage.getItem('sys_tab_locked') === '1') {
                        sessionStorage.removeItem('sys_tab_locked');
                        window.location.reload();
                    }
                }
            }
        });

        // === 🛡️ 3. 初始加载防御 ===
        if (sessionStorage.getItem('sys_tab_locked') === '1' || localStorage.getItem('sys_global_locked') === '1') {
            document.documentElement.style.visibility = 'hidden'; 
            window.addEventListener('DOMContentLoaded', () => {
                document.documentElement.style.visibility = 'visible';
                renderTabLockScreen(); 
            });
        } else {
            // === ⏱️ 4. 核心心跳与智能超时控制 ===
            setInterval(() => {
                let isLocked = sessionStorage.getItem('sys_tab_locked') === '1' || localStorage.getItem('sys_global_locked') === '1';
                let now = Date.now();
                
                // 读取全局最高活跃时间，防止后台页面被误杀
                let sharedActive = parseInt(localStorage.getItem('sys_shared_active_time') || '0');
                if (sharedActive > tabLastActive) {
                    tabLastActive = sharedActive;
                }

                if (TIMEOUT_MINUTES > 0) {
                    let elapsed = now - tabLastActive;
                    if (elapsed > TIMEOUT_MINUTES * 60 * 1000) {
                        if (!isLocked) {
                            sessionStorage.setItem('sys_tab_locked', '1');
                            localStorage.setItem('sys_global_locked', '1'); // 触发全网锁定
                            isLocked = true;
                            relockFinance().catch(()=>'');
                            renderTabLockScreen();
                        }
                    } 
                }
                
                // 阻断幽灵心跳：离开座位 35 秒后彻底闭嘴，让后端的 PHP 会话自然死亡
                if (!isLocked && (now - tabLastActive < 35000)) {
                    apiFetch('api_inventory.php?action=heartbeat').catch(()=>'');
                }
            }, 30000);
        }
        // ----------------------------------------------------
    </script>

    <header class="bg-[#8B0000] text-white flex-none flex justify-between items-center px-4 py-2 shadow-md z-20">
        <div class="flex items-center gap-3">
            <svg stroke-linejoin="round" stroke-linecap="round" stroke-width="2" stroke="currentColor" fill="none" viewBox="0 0 24 24" class="w-6 h-6"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"></path></svg>
            <h1 class="text-[15px] font-bold tracking-widest uppercase">金牌卖家 Workstation</h1>
        </div>
        <div class="flex items-center gap-4 text-sm font-medium">
            <div id="widget_datetime" class="hidden md:block text-[14px] font-medium tracking-wide opacity-95">--月--日 周- --:--</div>
            <div class="relative" id="userMenuContainer">
                <div class="flex items-center gap-2 cursor-pointer hover:bg-white/10 px-2 py-1.5 rounded-lg transition-colors" onclick="document.getElementById('userMenu').classList.toggle('hidden')">
                    <?php if($is_sub_account): ?>
                        <div class="w-7 h-7 rounded-full bg-orange-500 text-white flex items-center justify-center text-xs font-bold shadow-inner">员工</div>
                    <?php else: ?>
                        <div class="w-7 h-7 rounded-full bg-white text-[#8B0000] flex items-center justify-center text-[13px] font-black shadow-inner"><?php echo e(first_character((string) ($_SESSION['username'] ?? '用'))); ?></div>
                    <?php endif; ?>
                    <span class="text-[14px] font-bold tracking-wide"><?php echo htmlspecialchars($_SESSION['username'] ?? '预览用户'); ?></span>
                    <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div id="userMenu" class="absolute right-0 top-full mt-1.5 w-48 bg-white rounded-xl shadow-xl border border-slate-100 hidden flex-col py-1.5 text-slate-700 z-50">
                    <?php if(!empty($is_initial_admin) && $is_initial_admin): ?>
                    <div class="px-4 py-2 bg-slate-50 text-[11px] font-bold text-slate-400 border-b border-slate-100 mb-1">
                        系统当前有 <?php echo $user_count; ?> 位用户注册
                    </div>
                    <?php endif; ?>
                    <div class="px-4 py-2.5 hover:bg-slate-50 cursor-pointer flex items-center gap-2 font-bold text-[13px] transition-colors" onclick="document.getElementById('userMenu').classList.add('hidden'); openSettingsModal();">⚙️ 系统设置</div>
                    <div class="border-t border-slate-100 my-1"></div>
                    <form method="POST" action="logout.php">
                        <input type="hidden" name="_csrf_token" value="<?php echo e(csrf_token()); ?>">
                        <button type="submit" class="w-full px-4 py-2.5 hover:bg-red-50 hover:text-red-600 cursor-pointer flex items-center gap-2 font-bold text-[13px] transition-colors">🚪 退出登录</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <div class="bg-white flex-none border-b border-slate-200 px-4 py-2 flex flex-wrap gap-4 items-center justify-between shadow-sm z-10 relative">
        <div class="flex flex-1 items-center gap-3 min-w-[400px]">
            <div class="relative w-full max-w-sm group">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none"><svg class="w-4 h-4 text-slate-400 group-focus-within:text-[#8B0000] transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg></div>
                <input type="text" id="searchInput" onkeypress="if(event.keyCode===13) handleSearch()" placeholder="全局检索 日期/编号/收货人/备注... (按回车)" class="w-full bg-slate-50 border border-slate-200 text-slate-800 rounded pl-9 pr-3 py-1.5 text-sm outline-none focus:ring-1 focus:ring-[#8B0000] focus:border-[#8B0000] transition-all font-medium">
            </div>
            <button onclick="handleSearch()" class="bg-slate-800 text-white px-4 py-1.5 rounded text-sm font-bold shadow-sm hover:bg-slate-700 transition-all">搜索</button>
            <?php if($perm_edit == 1 || $perm_delete == 1): ?>
            <div id="batchActionPanel" class="hidden flex items-center gap-2 bg-[#FDE8E8] px-3 py-1 rounded border border-[#F8B4B4] shadow-sm transition-all text-sm">
                <span class="font-bold text-[#8B0000]">已选 <span id="selectedCount">0</span> 项</span>
                <div class="w-[1px] h-3 bg-[#F8B4B4] mx-1"></div>
                <?php if($perm_edit == 1): ?>
                <button onclick="openBatchEditModal()" class="text-slate-600 hover:text-[#8B0000] font-bold transition-colors">✏️ 批量编辑</button>
                <span class="text-slate-300">|</span>
                <button onclick="executeBatchMove()" id="batchMoveBtn" class="text-slate-600 hover:text-[#8B0000] font-bold transition-colors">⚡ 批量流转</button>
                <span class="text-slate-300">|</span>
                <?php endif; ?>
                <?php if($perm_delete == 1): ?>
                <button onclick="executeBatchDelete()" class="text-red-500 hover:text-red-700 font-bold transition-colors">🗑️ 删除</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="flex items-center gap-2 text-sm">
            <?php if($perm_download_tpl == 1): ?>
            <button onclick="downloadTemplate()" class="text-slate-600 hover:bg-slate-100 border border-transparent hover:border-slate-200 px-3 py-1.5 rounded font-medium transition-all">下载模板</button>
            <?php endif; ?>
            <?php if($perm_import == 1): ?>
            <label class="text-slate-600 hover:bg-slate-100 border border-transparent hover:border-slate-200 px-3 py-1.5 rounded font-medium cursor-pointer transition-all">
                批量导入 <input type="file" id="excelUpload" accept=".xlsx, .xls" class="hidden" onchange="handleExcelUpload(event)">
            </label>
            <?php endif; ?>
            <?php if($perm_add == 1): ?>
            <button onclick="openModal()" class="bg-[#8B0000] hover:bg-[#600000] text-white px-4 py-1.5 rounded font-bold shadow-sm transition-all flex items-center gap-1">➕ 新增记录</button>
            <?php endif; ?>
            <?php if($perm_export == 1): ?>
            <button onclick="exportToExcel()" class="bg-white text-slate-600 border border-slate-200 hover:bg-slate-50 px-3 py-1.5 rounded font-medium shadow-sm transition-all">导出当前页</button>
            <?php endif; ?>
        </div>
    </div>

    <main class="flex-1 w-full px-4 py-3 flex flex-col min-h-0">
        <div class="flex-1 flex flex-col min-h-0 bg-white rounded-lg border border-slate-200 shadow-sm overflow-hidden">
            <div class="flex-none flex items-center px-4 py-2.5 bg-slate-50 border-b border-slate-200 gap-1 overflow-x-auto hide-scrollbar">
                <?php if($p_us == 1): ?><button onclick="switchTab('US')" id="tab_US" class="tab-btn whitespace-nowrap">🇺🇸 美国仓</button><?php endif; ?>
                <?php if($p_transit == 1): ?><button onclick="switchTab('TRANSIT')" id="tab_TRANSIT" class="tab-btn whitespace-nowrap">🚢 国外途中</button><?php endif; ?>
                <?php if($p_cn == 1): ?><button onclick="switchTab('CN_WH')" id="tab_CN_WH" class="tab-btn whitespace-nowrap">🇨🇳 国内仓</button><?php endif; ?>
                <?php if($p_sold == 1): ?><button onclick="switchTab('SOLD')" id="tab_SOLD" class="tab-btn whitespace-nowrap">✅ 已售出</button><?php endif; ?>
                
                <div class="w-[1px] h-5 bg-slate-200 mx-2 shrink-0"></div>
                
                <?php if($p_parts == 1): ?><button onclick="switchTab('PARTS')" id="tab_PARTS" class="tab-btn whitespace-nowrap">🔌 零配件仓</button><?php endif; ?>
                <?php if($p_parts_sold == 1): ?><button onclick="switchTab('PARTS_SOLD')" id="tab_PARTS_SOLD" class="tab-btn whitespace-nowrap">🛒 已售配件</button><?php endif; ?>
                
                <div class="flex-grow"></div>
                
                <?php if($p_repair == 1): ?><button onclick="switchTab('REPAIR')" id="tab_REPAIR" class="tab-btn whitespace-nowrap">🛠️ 售后维修</button><?php endif; ?>
                <?php if($p_repair_done == 1): ?><button onclick="switchTab('REPAIR_DONE')" id="tab_REPAIR_DONE" class="tab-btn whitespace-nowrap">📦 维修完毕</button><?php endif; ?>
            </div>
            
            <div id="statsSummaryPanel" class="hidden flex-none bg-white border-b border-slate-100 p-3 overflow-y-auto"></div>
            
            <div class="flex-1 overflow-y-auto">
                <table class="w-full text-left text-sm border-collapse select-text">
                    <thead id="tableHead" class="bg-[#F8F9FA] text-slate-500 sticky top-0 z-10 shadow-sm"></thead>
                    <tbody id="tableBody" class="divide-y divide-slate-100 bg-white select-text"><tr><td colspan="14" class="text-center py-20 text-slate-400">数据加载中...</td></tr></tbody>
                </table>
            </div>
            
            <div id="paginationPanel" class="flex-none p-2.5 bg-[#F8F9FA] border-t border-slate-200 flex justify-between items-center hidden text-sm">
                <div class="text-slate-500 font-medium ml-2" id="pageInfo"></div>
                <div class="flex gap-2 mr-2" id="pageControls"></div>
            </div>
        </div>
    </main>

    <div id="dispatchModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[70] hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-sm rounded-xl shadow-2xl overflow-hidden modal-enter border border-slate-100" id="dispatchContent">
            <div class="px-6 py-4 bg-indigo-50 border-b border-indigo-100 flex justify-between items-center">
                <h3 class="font-black text-base text-indigo-900" id="dispatchTitle">🛒 拆分出库</h3>
                <button onclick="closeDispatchModal()" class="text-indigo-400 hover:text-indigo-600 transition-all duration-200"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="p-6">
                <form id="dispatchForm" onsubmit="submitDispatch(event)" class="space-y-4">
                    <input type="hidden" id="dispatch_id">
                    
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">出库数量 <span class="text-xs text-indigo-600 font-normal ml-1" id="dispatch_max_label"></span></label>
                        <input type="number" id="dispatch_qty" min="1" required class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">单件收款 (¥)</label>
                        <input type="number" step="0.01" id="dispatch_unit_collected" placeholder="卖了多少钱/件" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-1">收货人*</label>
                        <input type="text" id="dispatch_receiver" required autocomplete="off" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition-all">
                    </div>
                    
                    <div class="mt-6 pt-4 border-t border-slate-100 flex justify-end gap-2">
                        <button type="button" onclick="closeDispatchModal()" class="px-5 py-2 text-sm bg-slate-100 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition-all">取消</button>
                        <button type="submit" class="px-5 py-2 text-sm font-bold text-white bg-indigo-600 rounded-lg shadow-sm hover:bg-indigo-700 transition-all">确认出库</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="batchEditModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[60] hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-lg rounded-xl shadow-2xl overflow-hidden modal-enter border border-slate-100" id="batchEditContent">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                <h3 class="font-black text-base text-slate-800">✏️ 批量编辑数据</h3>
                <button onclick="closeBatchEditModal()" class="text-slate-400 hover:text-slate-600 transition-all duration-200"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="p-6">
                <form id="batchEditForm" onsubmit="submitBatchEdit(event)" class="space-y-4">
                    <div class="flex items-center gap-3">
                        <input type="checkbox" name="update_batch_no" id="chk_batch_no" onchange="document.getElementById('batch_batch_no').disabled = !this.checked" class="custom-checkbox flex-none">
                        <label for="chk_batch_no" class="text-sm font-bold w-16 text-slate-700 cursor-pointer">批次</label>
                        <input type="text" name="batch_no" id="batch_batch_no" disabled class="flex-1 text-sm border border-slate-200 rounded-lg px-3 py-2 disabled:bg-slate-50 outline-none focus:ring-1 focus:ring-[#8B0000] focus:border-[#8B0000] transition-all">
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="checkbox" name="update_config" id="chk_batch_config" onchange="document.getElementById('batch_config').disabled = !this.checked" class="custom-checkbox flex-none">
                        <label for="chk_batch_config" class="text-sm font-bold w-16 text-slate-700 cursor-pointer">配置</label>
                        <input type="text" name="config_desc" id="batch_config" disabled class="flex-1 text-sm border border-slate-200 rounded-lg px-3 py-2 disabled:bg-slate-50 outline-none focus:ring-1 focus:ring-[#8B0000] focus:border-[#8B0000] transition-all">
                    </div>
                    <?php if($perm_finance == 1): ?>
                    <div id="batch_cost_container" class="flex items-center gap-3">
                        <input type="checkbox" name="update_cost" id="chk_batch_cost" onchange="document.getElementById('batch_cost').disabled = !this.checked" class="custom-checkbox flex-none">
                        <label for="chk_batch_cost" class="text-sm font-bold w-16 text-slate-700 cursor-pointer">单件成本(¥)</label>
                        <input type="number" step="0.01" name="unit_cost" id="batch_cost" disabled placeholder="0" class="flex-1 text-sm border border-slate-200 rounded-lg px-3 py-2 disabled:bg-slate-50 text-right outline-none focus:ring-1 focus:ring-[#8B0000] focus:border-[#8B0000] transition-all">
                    </div>
                    <div id="batch_freight_container" class="flex items-center gap-3">
                        <input type="checkbox" name="update_freight" id="chk_batch_freight" onchange="document.getElementById('batch_freight').disabled = !this.checked" class="custom-checkbox flex-none">
                        <label for="chk_batch_freight" class="text-sm font-bold w-16 text-slate-700 cursor-pointer">单件运费(¥)</label>
                        <input type="number" step="0.01" name="unit_freight" id="batch_freight" disabled placeholder="0" class="flex-1 text-sm border border-slate-200 rounded-lg px-3 py-2 disabled:bg-slate-50 text-right outline-none focus:ring-1 focus:ring-[#8B0000] focus:border-[#8B0000] transition-all">
                    </div>
                    <?php endif; ?>
                    <div class="flex items-center gap-3">
                        <input type="checkbox" name="update_receiver" id="chk_batch_receiver" onchange="document.getElementById('batch_receiver').disabled = !this.checked" class="custom-checkbox flex-none">
                        <label for="chk_batch_receiver" class="text-sm font-bold w-16 text-slate-700 cursor-pointer">收货人</label>
                        <input type="text" name="receiver" id="batch_receiver" autocomplete="off" disabled class="flex-1 text-sm border border-slate-200 rounded-lg px-3 py-2 disabled:bg-slate-50 outline-none focus:ring-1 focus:ring-[#8B0000] focus:border-[#8B0000] transition-all">
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="checkbox" name="update_remarks" id="chk_batch_remarks" onchange="document.getElementById('batch_remarks').disabled = !this.checked" class="custom-checkbox flex-none">
                        <label for="chk_batch_remarks" class="text-sm font-bold w-16 text-slate-700 cursor-pointer">备注</label>
                        <input type="text" name="remarks" id="batch_remarks" autocomplete="off" disabled class="flex-1 text-sm border border-slate-200 rounded-lg px-3 py-2 disabled:bg-slate-50 outline-none focus:ring-1 focus:ring-[#8B0000] focus:border-[#8B0000] transition-all">
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="checkbox" name="update_collected_amount" id="chk_batch_collected_amount" onchange="document.getElementById('batch_collected_amount').disabled = !this.checked" class="custom-checkbox flex-none">
                        <label for="chk_batch_collected_amount" class="text-sm font-bold w-16 text-slate-700 cursor-pointer">单件收款(¥)</label>
                        <input type="number" step="0.01" name="unit_collected" id="batch_collected_amount" disabled placeholder="0" class="flex-1 text-sm border border-slate-200 rounded-lg px-3 py-2 disabled:bg-slate-50 text-right outline-none focus:ring-1 focus:ring-[#8B0000] focus:border-[#8B0000] transition-all">
                    </div>
                    <div class="mt-6 pt-4 border-t border-slate-100 flex justify-end gap-3">
                        <button type="button" onclick="closeBatchEditModal()" class="px-5 py-2 text-sm bg-slate-100 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition-all">取消</button>
                        <button type="submit" class="px-5 py-2 text-sm font-bold text-white bg-[#8B0000] rounded-lg shadow-sm hover:bg-[#600000] transition-all">确认覆盖</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="settingsModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[110] hidden flex items-center justify-center p-4">
        <div class="bg-slate-50 w-full max-w-4xl h-[650px] rounded-xl shadow-2xl overflow-hidden modal-enter flex flex-col border border-slate-200" id="settingsContent">
            <div class="px-6 py-4 bg-white border-b border-slate-200 flex justify-between items-center shadow-sm z-10">
                <h3 class="font-black text-base text-slate-800 flex items-center gap-2">⚙️ 系统偏好设置</h3>
                <button onclick="closeSettingsModal()" class="text-slate-400 hover:text-slate-600 transition-all duration-200"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="flex flex-1 overflow-hidden">
                <div class="w-[200px] bg-white border-r border-slate-200 flex flex-col p-4 gap-2">
                    <button onclick="switchSetTab('pwd')" id="setTab_pwd" class="set-tab-btn w-full px-4 py-3 text-left text-[14px] font-bold rounded-xl transition-all bg-slate-800 text-white shadow-md">🛡️ 账号安全</button>
                    <?php if(!$is_sub_account): ?>
                    <button onclick="switchSetTab('tabs')" id="setTab_tabs" class="set-tab-btn w-full px-4 py-3 text-left text-[14px] font-bold rounded-xl transition-all text-slate-500 hover:bg-slate-100">🖥️ 仓库配置</button>
                    <button onclick="switchSetTab('sub')" id="setTab_sub" class="set-tab-btn w-full px-4 py-3 text-left text-[14px] font-bold rounded-xl transition-all text-slate-500 hover:bg-slate-100">👥 员工管理</button>
                    <?php endif; ?>
                    <?php if($is_initial_admin): ?>
                    <button onclick="switchSetTab('registration')" id="setTab_registration" class="set-tab-btn w-full px-4 py-3 text-left text-[14px] font-bold rounded-xl transition-all text-slate-500 hover:bg-slate-100">🌐 注册管理</button>
                    <?php endif; ?>
                </div>
                
                <div class="flex-1 p-6 overflow-y-auto">
                    <div id="setPanel_pwd" class="space-y-6">
                        
                        <?php if(!$is_sub_account && empty($currentUser['sec_q1'])): ?>
                        <div class="bg-white border border-slate-200 p-6 rounded-xl shadow-sm">
                            <h4 class="font-black text-[15px] text-slate-800 mb-4 pb-3 border-b border-slate-100">🛡️ 绑定密保 (老账号必填)</h4>
                            <div class="space-y-3 mb-4">
                                <div>
                                    <select id="my_q1" class="w-full text-sm border border-slate-200 rounded-t-lg px-4 py-2.5 bg-slate-50 outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                        <option value="您出生的城市是哪里？">问题1：您出生的城市是哪里？</option>
                                        <option value="您小学班主任的名字是？">问题1：您小学班主任的名字是？</option>
                                        <option value="您的初恋名字是？">问题1：您的初恋名字是？</option>
                                        <option value="您第一份工作的公司名是？">问题1：您第一份工作的公司名是？</option>
                                    </select>
                                    <input type="text" id="my_a1" placeholder="输入答案" class="w-full text-sm border-x border-b border-slate-200 rounded-b-lg px-4 py-2.5 outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                </div>
                                <div>
                                    <select id="my_q2" class="w-full text-sm border border-slate-200 rounded-t-lg px-4 py-2.5 bg-slate-50 outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                        <option value="您父亲的姓名是？">问题2：您父亲的姓名是？</option>
                                        <option value="您人生中第一辆车的品牌是？">问题2：您人生中第一辆车的品牌是？</option>
                                        <option value="您母亲的姓名是？">问题2：您母亲的姓名是？</option>
                                        <option value="您最喜欢的宠物名字是？">问题2：您最喜欢的宠物名字是？</option>
                                    </select>
                                    <input type="text" id="my_a2" placeholder="输入答案" class="w-full text-sm border-x border-b border-slate-200 rounded-b-lg px-4 py-2.5 outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                </div>
                                <div>
                                    <select id="my_q3" class="w-full text-sm border border-slate-200 rounded-t-lg px-4 py-2.5 bg-slate-50 outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                        <option value="您最好朋友的名字是？">问题3：您最好朋友的名字是？</option>
                                        <option value="您最喜欢的一部电影是？">问题3：您最喜欢的一部电影是？</option>
                                        <option value="您最喜欢的食物是？">问题3：您最喜欢的食物是？</option>
                                        <option value="您的大学室友名字是？">问题3：您的大学室友名字是？</option>
                                    </select>
                                    <input type="text" id="my_a3" placeholder="输入答案" class="w-full text-sm border-x border-b border-slate-200 rounded-b-lg px-4 py-2.5 outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <input type="password" id="my_sec_pwd" placeholder="输入当前登录密码验证身份" class="flex-1 bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                <button onclick="saveSecQuestions()" class="bg-slate-800 hover:bg-slate-700 text-white font-bold px-5 py-2.5 rounded-lg text-sm shadow-sm transition-all">绑定密保</button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="bg-white border border-slate-200 p-6 rounded-xl shadow-sm">
                            <h4 class="font-black text-[15px] text-slate-800 mb-4 pb-3 border-b border-slate-100 flex justify-between items-center">
                                <span>🔒 财务保险箱密码</span>
                                <?php if(!$is_sub_account): ?>
                                    <button onclick="openLockRecModal()" class="text-xs font-bold text-slate-500 hover:text-red-600 bg-slate-50 px-3 py-1 rounded border border-slate-200 transition-all">找回密码</button>
                                <?php else: ?>
                                    <button onclick="openSubLockRecModal()" class="text-xs font-bold text-slate-500 hover:text-red-600 bg-slate-50 px-3 py-1 rounded border border-slate-200 transition-all">重置密码</button>
                                <?php endif; ?>
                            </h4>
                            <div class="flex flex-col gap-3">
                                <input type="password" id="my_old_lock" placeholder="原保险箱密码 (如未设置请留空)" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                <input type="password" id="my_lock_pwd" placeholder="新保险箱密码 (留空即关闭保险箱)" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                <button onclick="saveLockPwd()" class="w-full bg-[#8B0000] hover:bg-[#600000] text-white font-bold px-4 py-2.5 rounded-lg text-sm shadow-sm transition-all mt-1">验证并保存</button>
                            </div>
                        </div>

                        <div class="bg-white border border-slate-200 p-6 rounded-xl shadow-sm">
                            <h4 class="font-black text-[15px] text-slate-800 mb-4 pb-3 border-b border-slate-100">🔑 修改登录密码</h4>
                            <div class="flex flex-col gap-3">
                                <input type="password" id="my_old_pwd" placeholder="输入【原登录密码】" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm outline-none focus:bg-white focus:ring-1 focus:ring-slate-400 transition-all">
                                <input type="password" id="my_new_pwd" placeholder="输入【新登录密码】" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm outline-none focus:bg-white focus:ring-1 focus:ring-slate-400 transition-all">
                                <input type="password" id="my_new_pwd_confirm" placeholder="再次输入【新登录密码】确认" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 text-sm outline-none focus:bg-white focus:ring-1 focus:ring-slate-400 transition-all">
                                <button onclick="changeMyPwd()" class="w-full bg-slate-800 hover:bg-slate-700 text-white font-bold px-4 py-2.5 rounded-lg text-sm shadow-sm transition-all mt-1">验证并修改</button>
                            </div>
                        </div>

                        <div class="bg-white border border-slate-200 p-6 rounded-xl shadow-sm">
                            <h4 class="font-black text-[15px] text-slate-800 mb-4 pb-3 border-b border-slate-100">⏱️ 自动退出保护</h4>
                            <div class="flex gap-3">
                                <select id="my_timeout" class="flex-1 border border-slate-200 bg-slate-50 rounded-lg px-4 py-2.5 text-sm font-bold text-slate-700 outline-none focus:ring-1 focus:ring-slate-400 focus:bg-white transition-all">
                                    <option value="1" <?php if($timeout_minutes==1) echo 'selected'; ?>>1 分钟无操作退出</option>
                                    <option value="3" <?php if($timeout_minutes==3) echo 'selected'; ?>>3 分钟无操作退出</option>
                                    <option value="5" <?php if($timeout_minutes==5) echo 'selected'; ?>>5 分钟无操作退出</option>
                                    <option value="10" <?php if($timeout_minutes==10) echo 'selected'; ?>>10 分钟无操作退出</option>
                                    <option value="20" <?php if($timeout_minutes==20) echo 'selected'; ?>>20 分钟无操作退出</option>
                                </select>
                                <button onclick="saveTimeout()" class="bg-slate-800 hover:bg-slate-700 text-white font-bold px-6 py-2.5 rounded-lg text-sm shadow-sm transition-all">保存</button>
                            </div>
                        </div>

                        <?php if(!$is_sub_account): ?>
                        <div class="bg-white border border-red-200 p-6 rounded-xl shadow-sm">
                            <h4 class="font-black text-[15px] text-red-600 mb-4 pb-3 border-b border-red-100">⚠️ 危险注销</h4>
                            <div class="flex gap-3">
                                <input type="password" id="my_delete_pwd" placeholder="输入登录密码验证身份" class="flex-1 bg-red-50/50 border border-red-200 rounded-lg px-4 py-2.5 text-sm outline-none focus:bg-white focus:ring-1 focus:ring-red-400 transition-all">
                                <button onclick="deleteMyAccount()" class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-lg text-sm shadow-sm transition-all">永久注销账号</button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if(!$is_sub_account): ?>
                    <div id="setPanel_tabs" class="hidden space-y-6">
                        <div class="bg-white border border-slate-200 p-6 rounded-xl shadow-sm">
                            <h4 class="font-black text-[15px] text-slate-800 mb-4 pb-3 border-b border-slate-100">🖥️ 标签页显示配置</h4>
                            <div class="grid grid-cols-2 gap-4 mb-8">
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_tab_us" class="custom-checkbox" <?php if($p_us==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🇺🇸 美国仓可见</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_tab_transit" class="custom-checkbox" <?php if($p_transit==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🚢 国外途中可见</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_tab_cn" class="custom-checkbox" <?php if($p_cn==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🇨🇳 国内仓可见</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_tab_sold" class="custom-checkbox" <?php if($p_sold==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">✅ 已售仓可见</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_tab_parts" class="custom-checkbox" <?php if($p_parts==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🔌 零配件仓可见</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_tab_parts_sold" class="custom-checkbox" <?php if($p_parts_sold==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🛒 已售配件可见</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_tab_repair" class="custom-checkbox" <?php if($p_repair==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🛠️ 售后维修可见</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_tab_repair_done" class="custom-checkbox" <?php if($p_repair_done==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">📦 维修完毕可见</span></label>
                            </div>
                            
                            <h4 class="font-black text-[15px] text-slate-800 mb-4 pb-3 border-b border-slate-100">🔒 仓库保险箱锁设置</h4>
                            <div class="grid grid-cols-2 gap-4 mb-6">
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_lock_us" class="custom-checkbox" <?php if($l_us==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🇺🇸 锁定美国仓财务</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_lock_transit" class="custom-checkbox" <?php if($l_transit==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🚢 锁定途中仓财务</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_lock_cn" class="custom-checkbox" <?php if($l_cn==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🇨🇳 锁定国内仓财务</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_lock_sold" class="custom-checkbox" <?php if($l_sold==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">✅ 锁定已售仓财务</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_lock_parts" class="custom-checkbox" <?php if($l_parts==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🔌 锁定配件仓财务</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_lock_parts_sold" class="custom-checkbox" <?php if($l_parts_sold==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🛒 锁定已售配件财务</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_lock_repair" class="custom-checkbox" <?php if($l_repair==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">🛠️ 锁定售后维修财务</span></label>
                                <label class="flex items-center gap-3"><input type="checkbox" id="master_lock_repair_done" class="custom-checkbox" <?php if($l_repair_done==1) echo 'checked'; ?>><span class="text-sm font-bold text-slate-700">📦 锁定维修完毕财务</span></label>
                            </div>
                            <button onclick="saveMasterTabs()" class="w-full bg-[#8B0000] hover:bg-[#600000] text-white font-bold px-4 py-3 rounded-lg text-sm shadow-sm transition-all mt-4">保存配置并生效</button>
                        </div>
                    </div>

                    <div id="setPanel_sub" class="hidden">
                        <div class="bg-white border border-slate-200 p-6 rounded-xl shadow-sm">
                            <div class="flex justify-between items-center mb-4 pb-3 border-b border-slate-100">
                                <h4 class="font-black text-[15px] text-slate-800">👥 员工账号列表</h4>
                                <button onclick="openSubModal()" class="bg-slate-800 hover:bg-slate-700 text-white font-bold px-4 py-2 rounded-lg text-sm shadow-sm transition-all">➕ 添加员工</button>
                            </div>
                            <div class="border border-slate-200 rounded-lg overflow-hidden">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-slate-50 text-slate-500 font-bold border-b border-slate-200">
                                        <tr>
                                            <th class="p-3">用户名</th>
                                            <th class="p-3 text-center">财务</th>
                                            <th class="p-3 text-center">历史销量</th>
                                            <th class="p-3 text-center">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody id="subAccTable" class="divide-y divide-slate-100 bg-white"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php if($is_initial_admin): ?>
                    <div id="setPanel_registration" class="hidden space-y-6">
                        <div class="bg-white border border-slate-200 p-6 rounded-xl shadow-sm">
                            <h4 class="font-black text-[15px] text-slate-800 mb-4 pb-3 border-b border-slate-100">🌐 新主账号注册方式</h4>
                            <div class="flex gap-3">
                                <select id="registrationMode" class="flex-1 border border-slate-200 bg-slate-50 rounded-lg px-4 py-2.5 text-sm font-bold text-slate-700 outline-none focus:ring-1 focus:ring-slate-400">
                                    <option value="closed">关闭注册（仅管理员添加）</option>
                                    <option value="invite">邀请码注册（推荐）</option>
                                    <option value="open">开放注册</option>
                                </select>
                                <button onclick="saveRegistrationMode()" class="bg-slate-800 hover:bg-slate-700 text-white font-bold px-5 py-2.5 rounded-lg text-sm">保存模式</button>
                            </div>
                            <p class="mt-3 text-xs text-slate-500 leading-relaxed">此设置只控制新的独立主账号。老板添加的员工账号不受影响，各主账号库存仍完全分开。</p>
                        </div>
                        <div class="bg-white border border-slate-200 p-6 rounded-xl shadow-sm">
                            <div class="flex justify-between items-center mb-4 pb-3 border-b border-slate-100">
                                <h4 class="font-black text-[15px] text-slate-800">🎟️ 邀请码</h4>
                                <div class="flex gap-2">
                                    <select id="inviteExpiresDays" class="border border-slate-200 rounded-lg px-3 py-2 text-sm">
                                        <option value="1">1 天有效</option>
                                        <option value="7" selected>7 天有效</option>
                                        <option value="30">30 天有效</option>
                                        <option value="90">90 天有效</option>
                                    </select>
                                    <button onclick="createRegistrationInvite()" class="bg-[#8B0000] hover:bg-[#600000] text-white font-bold px-4 py-2 rounded-lg text-sm">生成邀请码</button>
                                </div>
                            </div>
                            <div id="newInviteCode" class="hidden mb-4 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 font-mono text-sm break-all"></div>
                            <div class="border border-slate-200 rounded-lg overflow-hidden">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-slate-50 text-slate-500 font-bold"><tr><th class="p-3">编号</th><th class="p-3">状态</th><th class="p-3">到期时间</th><th class="p-3 text-center">操作</th></tr></thead>
                                    <tbody id="registrationInviteTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div id="lockRecModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[150] hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-md rounded-xl shadow-2xl overflow-hidden modal-enter border border-slate-100" id="lockRecContent">
            <div class="px-6 py-4 bg-orange-50 border-b border-orange-100 flex justify-between items-center">
                <h3 class="font-black text-base text-orange-900">重置保险箱密码</h3>
                <button onclick="closeLockRecModal()" class="text-orange-500 hover:text-orange-700 transition-all duration-200"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="p-6">
                <div class="space-y-3 mb-5 bg-slate-50 p-4 rounded-lg border border-slate-200">
                    <div><div class="text-xs font-bold text-slate-600 mb-1" id="lr_q1"></div><input type="text" id="lr_a1" class="w-full text-sm bg-white border border-slate-200 rounded px-3 py-1.5 outline-none focus:ring-1 focus:ring-orange-500 focus:border-orange-500 transition-all"></div>
                    <div><div class="text-xs font-bold text-slate-600 mb-1" id="lr_q2"></div><input type="text" id="lr_a2" class="w-full text-sm bg-white border border-slate-200 rounded px-3 py-1.5 outline-none focus:ring-1 focus:ring-orange-500 focus:border-orange-500 transition-all"></div>
                    <div><div class="text-xs font-bold text-slate-600 mb-1" id="lr_q3"></div><input type="text" id="lr_a3" class="w-full text-sm bg-white border border-slate-200 rounded px-3 py-1.5 outline-none focus:ring-1 focus:ring-orange-500 focus:border-orange-500 transition-all"></div>
                </div>
                <div class="space-y-3">
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">主账号登录密码验证 <span class="text-red-500">*</span></label><input type="password" id="lr_login_pwd" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:ring-1 focus:ring-orange-500 focus:border-orange-500 transition-all"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">新保险箱密码 <span class="text-xs text-slate-400 font-normal">(留空即关闭)</span></label><input type="password" id="lr_new_lock" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:ring-1 focus:ring-orange-500 focus:border-orange-500 transition-all"></div>
                </div>
                <div class="mt-6 pt-4 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="closeLockRecModal()" class="px-5 py-2 text-sm bg-slate-100 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition-all">取消</button>
                    <button onclick="submitLockRec()" class="px-5 py-2 text-sm font-bold text-white bg-orange-600 rounded-lg shadow-sm hover:bg-orange-700 transition-all">确认重置</button>
                </div>
            </div>
        </div>
    </div>

    <div id="subLockRecModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[150] hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-sm rounded-xl shadow-2xl overflow-hidden modal-enter border border-slate-100" id="subLockRecContent">
            <div class="px-6 py-4 bg-orange-50 border-b border-orange-100 flex justify-between items-center">
                <h3 class="font-black text-base text-orange-900">重置保险箱密码</h3>
                <button onclick="closeSubLockRecModal()" class="text-orange-500 hover:text-orange-700 transition-all duration-200"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="p-6">
                <div class="space-y-3">
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">登录密码验证 <span class="text-red-500">*</span></label><input type="password" id="sub_lr_login_pwd" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:ring-1 focus:ring-orange-500 focus:border-orange-500 transition-all"></div>
                    <div><label class="block text-sm font-bold text-slate-700 mb-1">新保险箱密码 <span class="text-xs text-slate-400 font-normal">(留空即关闭)</span></label><input type="password" id="sub_lr_new_lock" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:ring-1 focus:ring-orange-500 focus:border-orange-500 transition-all"></div>
                </div>
                <div class="mt-6 pt-4 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" onclick="closeSubLockRecModal()" class="px-5 py-2 text-sm bg-slate-100 text-slate-600 font-bold rounded-lg hover:bg-slate-200 transition-all">取消</button>
                    <button onclick="submitSubLockRec()" class="px-5 py-2 text-sm font-bold text-white bg-orange-600 rounded-lg shadow-sm hover:bg-orange-700 transition-all">确认重置</button>
                </div>
            </div>
        </div>
    </div>

    <div id="verifyLockModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[200] hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-sm rounded-xl shadow-2xl overflow-hidden modal-enter" id="verifyLockContent">
            <div class="px-6 py-4 bg-slate-800 flex justify-between items-center">
                <h3 class="font-black text-base text-white flex items-center gap-2">🔒 验证保险箱密码</h3>
                <button onclick="closeVerifyLockModal()" class="text-slate-400 hover:text-white transition-all duration-200"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="p-6">
                <form id="verifyLockForm" onsubmit="submitVerifyLock(event)">
                    <p class="text-sm font-bold text-slate-700 mb-1">此区域受保护，请输入您的保险箱密码：</p>
                    <input type="password" id="verify_lock_input" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:border-[#8B0000] focus:ring-1 transition-all mb-5" placeholder="未设置请直接回车">
                    <div class="flex justify-end gap-2">
                        <button type="submit" class="px-5 py-2 text-sm font-bold text-white bg-[#8B0000] rounded-lg shadow-sm hover:bg-[#600000] transition-all">确定</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="subModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[120] hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-sm rounded-xl shadow-2xl p-6 modal-enter border border-slate-100" id="subContent">
            <h3 class="font-black text-base text-slate-800 mb-4" id="subModalTitle">配置员工账户</h3>
            <form onsubmit="saveSubAcc(event)" class="flex flex-col gap-3">
                <input type="hidden" id="sub_id">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">用户名</label>
                    <input type="text" id="sub_user" required class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all text-sm">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">登录密码 <span class="text-xs text-slate-400 font-normal">(设空则不修改)</span></label>
                    <input type="password" id="sub_pass" placeholder="输入即可强行重置" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all text-sm">
                </div>
                <div class="bg-slate-50 p-4 rounded-lg border border-slate-200 mt-1">
                    <div class="text-sm font-black text-slate-800 mb-2 border-b border-slate-200 pb-1">操作权限</div>
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_fin" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">查看财务数据</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_edt" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">编辑/流转设备</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_dl" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">下载导入模版</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_imp" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">批量导入记录</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_add" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">单条新增记录</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_exp" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">导出当前页数据</span></label>
                        <label class="flex items-center gap-2 cursor-pointer col-span-2 mt-1"><input type="checkbox" id="sub_perm_del" class="custom-checkbox"><span class="text-sm font-bold text-red-600">彻底删除设备 (高危)</span></label>
                    </div>
                    
                    <div class="text-sm font-black text-slate-800 mb-2 border-b border-slate-200 pb-1 mt-3">已售仓历史查询限制</div>
                    <div class="mb-4">
                        <select id="sub_perm_hist" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2 outline-none focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all text-sm">
                            <option value="999">允许查看全部历史记录 (默认)</option>
                            <option value="6">仅允许查看最近 6 个月记录</option>
                            <option value="3">仅允许查看最近 3 个月记录</option>
                        </select>
                    </div>

                    <div class="text-sm font-black text-slate-800 mb-2 border-b border-slate-200 pb-1 mt-3">可见仓库</div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_us" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">🇺🇸 美国仓</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_transit" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">🚢 途中仓</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_cn" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">🇨🇳 国内仓</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_sold" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">✅ 已售仓</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_parts" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">🔌 零配件仓</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_parts_sold" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">🛒 已售配件</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_repair" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">🛠️ 售后维修</span></label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" id="sub_perm_repair_done" class="custom-checkbox"><span class="text-sm font-bold text-slate-700">📦 维修完毕</span></label>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-3">
                    <button type="button" onclick="closeSubModal()" class="px-5 py-2 text-sm font-bold bg-slate-100 text-slate-600 rounded-lg hover:bg-slate-200 transition-all">取消</button>
                    <button type="submit" class="px-5 py-2 text-sm font-bold text-white bg-[#8B0000] rounded-lg shadow-sm hover:bg-[#600000] transition-all">保存配置</button>
                </div>
            </form>
        </div>
    </div>

    <div id="itemModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white w-full max-w-2xl rounded-xl shadow-2xl overflow-hidden modal-enter max-h-[95vh] flex flex-col border border-slate-100" id="modalContent">
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-100 flex justify-between items-center">
                <h3 class="font-black text-base text-slate-800" id="modalTitle">新增记录</h3>
                <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600 transition-all duration-200"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="overflow-y-auto p-6">
                <form id="itemForm" onsubmit="saveItem(event)">
                    <input type="hidden" id="form_id" name="id">
                    <input type="hidden" id="form_updated_at" name="updated_at">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="block text-sm font-bold text-slate-700 mb-1">服务编号/配件型号*</label>
                            <input type="text" id="form_service_no" name="service_no" required class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all">
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="block text-sm font-bold text-slate-700 mb-1">状态</label>
                            <select id="form_status" name="status" onchange="toggleModalFields()" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all">
                                <?php if($p_us == 1): ?><option value="US">美国仓</option><?php endif; ?>
                                <?php if($p_transit == 1): ?><option value="TRANSIT">国外途中</option><?php endif; ?>
                                <?php if($p_cn == 1): ?><option value="CN_WH">国内仓</option><?php endif; ?>
                                <?php if($p_sold == 1): ?><option value="SOLD">已售</option><?php endif; ?>
                                <?php if($p_parts == 1): ?><option value="PARTS">零配件仓</option><?php endif; ?>
                                <?php if($p_parts_sold == 1): ?><option value="PARTS_SOLD">已售配件</option><?php endif; ?>
                                <?php if($p_repair == 1): ?><option value="REPAIR">售后维修</option><?php endif; ?>
                                <?php if($p_repair_done == 1): ?><option value="REPAIR_DONE">维修完毕</option><?php endif; ?>
                            </select>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="block text-sm font-bold text-slate-700 mb-1">批次</label>
                            <input type="text" id="form_batch_no" name="batch_no" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all">
                        </div>
                        <div class="col-span-2 sm:col-span-1" id="modal_qty_container" style="display:none;">
                            <label class="block text-sm font-bold text-slate-700 mb-1">数量</label>
                            <input type="number" id="form_quantity" name="quantity" value="1" min="1" oninput="updateModalTotals()" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all font-bold text-indigo-700">
                        </div>
                        
                        <div class="col-span-2">
                            <label class="block text-sm font-bold text-slate-700 mb-1">配置/规格说明</label>
                            <input type="text" id="form_config_desc" name="config_desc" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all">
                        </div>
                        <div class="col-span-2">
                            <label class="block text-sm font-bold text-slate-700 mb-1">备注信息</label>
                            <input type="text" id="form_remarks" name="remarks" autocomplete="off" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all">
                        </div>
                        <div class="col-span-2"><hr class="border-slate-100"></div>
                        <?php if($perm_finance == 1): ?>
                        <div id="modal_cost_container" class="col-span-2 sm:col-span-1">
                            <label class="block text-sm font-bold text-slate-700 mb-1" id="lbl_cost">成本 (¥)</label>
                            <input type="number" step="0.01" id="form_unit_cost" name="unit_cost" oninput="updateModalTotals()" placeholder="0" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all text-right">
                            <div class="text-[11px] text-slate-400 mt-1 font-medium text-right pr-1" id="modal_cost_total" style="display:none;">总成本计: ¥0.00</div>
                        </div>
                        <div id="modal_freight_container" class="col-span-2 sm:col-span-1">
                            <label class="block text-sm font-bold text-slate-700 mb-1" id="lbl_freight">运费 (¥)</label>
                            <input type="number" step="0.01" id="form_unit_freight" name="unit_freight" oninput="updateModalTotals()" placeholder="0" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all text-right">
                            <div class="text-[11px] text-slate-400 mt-1 font-medium text-right pr-1" id="modal_freight_total" style="display:none;">总运费计: ¥0.00</div>
                        </div>
                        <?php endif; ?>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="block text-sm font-bold text-slate-700 mb-1">收货人</label>
                            <input type="text" id="form_receiver" name="receiver" autocomplete="off" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all">
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="block text-sm font-bold text-slate-700 mb-1" id="lbl_collected">收款金额 (¥)</label>
                            <input type="number" step="0.01" id="form_unit_collected" name="unit_collected" oninput="updateModalTotals()" placeholder="0" class="w-full text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 outline-none focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] transition-all text-right">
                            <div class="text-[11px] text-slate-400 mt-1 font-medium text-right pr-1" id="modal_collected_total" style="display:none;">总收款计: ¥0.00</div>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-slate-100 flex justify-end gap-2">
                        <button type="button" onclick="closeModal()" class="px-5 py-2 text-sm font-bold text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition-all duration-200">取消</button>
                        <button type="submit" class="px-5 py-2 text-sm font-bold text-white bg-[#8B0000] rounded-lg shadow-sm hover:bg-[#600000] transition-all duration-200">保存记录</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<script>
    function toggleModalFields() {
        const status = document.getElementById('form_status').value;
        const isParts = ['PARTS', 'PARTS_SOLD'].includes(status);
        
        const qtyContainer = document.getElementById('modal_qty_container');
        if(qtyContainer) qtyContainer.style.display = isParts ? 'block' : 'none';
        
        if (!isParts) {
            document.getElementById('form_quantity').value = 1;
        }

        const lblCost = document.getElementById('lbl_cost');
        if(lblCost) lblCost.innerText = isParts ? '单件成本 (¥)' : '成本 (¥)';
        
        const lblFreight = document.getElementById('lbl_freight');
        if(lblFreight) lblFreight.innerText = isParts ? '单件运费 (¥)' : '运费 (¥)';
        
        const lblCollected = document.getElementById('lbl_collected');
        if(lblCollected) lblCollected.innerText = isParts ? '单件收款 (¥)' : '收款金额 (¥)';
        
        const totCost = document.getElementById('modal_cost_total');
        if(totCost) totCost.style.display = isParts ? 'block' : 'none';
        
        const totFreight = document.getElementById('modal_freight_total');
        if(totFreight) totFreight.style.display = isParts ? 'block' : 'none';
        
        const totCol = document.getElementById('modal_collected_total');
        if(totCol) totCol.style.display = isParts ? 'block' : 'none';
        
        updateModalTotals();
    }

    document.addEventListener('click', function(e) {
        const container = document.getElementById('userMenuContainer');
        if(container && !container.contains(e.target)) {
            const menu = document.getElementById('userMenu');
            if(menu && !menu.classList.contains('hidden')) {
                menu.classList.add('hidden');
            }
        }
    });

    const escapeHTML = (str) => {
        if (str === null || str === undefined) return '';
        return String(str).replace(/[&<>'"]/g, tag => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
        }[tag]));
    };

    const INVENTORY_API_URL = 'api_inventory.php';
    let currentData = []; 
    let totalFilteredItems = 0; 
    let summaryData = null; 
    let currentSearchTerm = ''; 
    let currentDateFilter = null; 
    let userSelectedAll = false; 
    let currentPage = 1; 
    const ITEMS_PER_PAGE = 50;
    
    let selectedIdsToRestore = []; 
    let subAccountsData = []; // 全局存储员工数据，防止 HTML 解析错误
    
    // ⚡ 性能提升：新增全局变量，记录最后一次数据库的数据时间戳
    let lastKnownUpdate = null;

    window.onload = () => { 
        if(currentTab !== 'NONE') switchTab(currentTab); 
    };

    const fmtMoney = (num) => { 
        return (parseFloat(num) || 0).toString(); 
    };

    const statusFlow = { 
        'US': { next: 'TRANSIT', nextLabel: '发往国外', icon: '🚢', color: 'blue' }, 
        'TRANSIT': { next: 'CN_WH', nextLabel: '到达国内仓', icon: '🏭', color: 'indigo' }, 
        'CN_WH': { next: 'SOLD', nextLabel: '标记已售', icon: '✅', color: 'emerald' }, 
        'SOLD': { next: 'CN_WH', nextLabel: '退回国内仓', icon: '🔙', color: 'orange' },
        'PARTS': { next: 'PARTS_SOLD', nextLabel: '配件已售', icon: '🛒', color: 'indigo' },
        'PARTS_SOLD': { next: 'PARTS', nextLabel: '退回配件仓', icon: '🔙', color: 'orange' },
        'REPAIR': { next: 'REPAIR_DONE', nextLabel: '修好完毕', icon: '📦', color: 'emerald' },
        'REPAIR_DONE': { next: 'REPAIR', nextLabel: '重新维修', icon: '🛠️', color: 'red' }
    };

    function switchTab(status) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        const activeBtn = document.getElementById('tab_' + status);
        if(activeBtn) activeBtn.classList.add('active');
        
        currentTab = status; 
        if (isFinanceUnlocked) {
            isFinanceUnlocked = false;
            relockFinance().catch(()=>'');
        }
        currentDateFilter = null; 
        userSelectedAll = false; 
        currentSearchTerm = ''; 
        currentPage = 1;
        document.getElementById('searchInput').value = '';
        
        if(document.getElementById('tableHead')) document.getElementById('tableHead').innerHTML = '';
        if(document.getElementById('batchActionPanel')) document.getElementById('batchActionPanel').classList.add('hidden');
        
        loadData();
    }

    function handleSearch() {
        currentSearchTerm = document.getElementById('searchInput').value.trim().toLowerCase();
        currentPage = 1;
        loadData();
    }

    function setDateFilter(val) {
        currentDateFilter = val;
        userSelectedAll = (val === null);
        currentPage = 1;
        loadData();
    }

    function changePage(p) {
        currentPage = p;
        loadData();
        document.getElementById('tableHead') && document.getElementById('tableHead').scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    let currentLockCallback = null;
    let currentLockFailCallback = null; // --- 新增：记录用户取消或验证失败的操作 ---

    function verifyLock(callback, failCallback = null) {
        if(PERM_FINANCE === 0 && !['SOLD', 'REPAIR', 'REPAIR_DONE', 'PARTS_SOLD'].includes(currentTab)) {
            return alert('您没有查看财务数据的权限！');
        }
        currentLockCallback = callback;
        currentLockFailCallback = failCallback; 
        document.getElementById('verify_lock_input').value = '';
        document.getElementById('verifyLockModal').classList.remove('hidden');
        setTimeout(() => { 
            document.getElementById('verifyLockContent').classList.add('modal-enter-active'); 
            document.getElementById('verify_lock_input').focus(); 
        }, 10);
    }

    function closeVerifyLockModal(isCancel = true) {
        document.getElementById('verifyLockContent').classList.remove('modal-enter-active');
        setTimeout(() => document.getElementById('verifyLockModal').classList.add('hidden'), 200);
        
        // --- 新增：如果用户主动点击关闭（取消），触发失败回调恢复 UI ---
        if (isCancel === true && currentLockFailCallback) currentLockFailCallback();
        
        currentLockCallback = null;
        currentLockFailCallback = null;
    }

    async function submitVerifyLock(e) {
        e.preventDefault();
        if (!currentLockCallback) return;
        const p = document.getElementById('verify_lock_input').value;
        const callback = currentLockCallback;
        const failCallback = currentLockFailCallback;
        
        // 提交密码时关闭弹窗，传 false 表示这不是"取消关闭"
        closeVerifyLockModal(false); 
        
        const fd = new FormData(); 
        fd.append('action', 'verify_lock'); 
        fd.append('pwd', p);
        try {
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd});
            const j = await r.json();
            if(j.status === 'success') {
                callback();
            } else {
                alert(j.message || "保险箱密码错误！");
                if (failCallback) failCallback(); // --- 新增：密码错误时也触发 UI 恢复 ---
            }
        } catch (e) { 
            alert("网络异常验证失败"); 
            if (failCallback) failCallback();
        }
    }

    function toggleFinanceLock() {
    if(isFinanceUnlocked) { 
        isFinanceUnlocked = false; 
        relockFinance().catch(()=>'');
        loadData(); // 修复：立即重新拉取带星号掩码的数据
    } else { 
        verifyLock(() => { 
            isFinanceUnlocked = true; 
            loadData(); // 修复：立即向服务器拉取真实的财务明细，实现“秒出”
        }); 
    }
}

    function renderSummaryPanels() {
        const panel = document.getElementById('statsSummaryPanel');
        let isTabLocked = (LOCK_TABS[currentTab] === 1 && !isFinanceUnlocked);
        let showF = (PERM_FINANCE === 1) && !isTabLocked;

        if (['SOLD', 'REPAIR', 'REPAIR_DONE', 'PARTS_SOLD'].includes(currentTab)) {
            if (isTabLocked) {
                panel.classList.remove('hidden');
                panel.innerHTML = `
                    <div class="flex justify-end items-center bg-[#FDE8E8] border border-[#F8B4B4] p-2 rounded w-full">
                        <button onclick="toggleFinanceLock()" class="bg-[#8B0000] hover:bg-[#600000] text-white font-bold py-1 px-4 text-[13px] rounded transition-all shadow-sm flex items-center gap-2">🔒 验证密码解锁历史月份看板</button>
                    </div>`;
            } else {
                if (!summaryData || summaryData.total_count === 0) { 
                    panel.classList.add('hidden'); 
                    return; 
                }
                panel.classList.remove('hidden');
                
                const bC = (l, c, p, iT=false) => {
                    const isA = (currentDateFilter === l) || (iT && !currentDateFilter);
                    const baseClass = "min-w-[150px] flex-none p-3 rounded-xl border transition-all duration-300 cursor-pointer relative overflow-hidden group ";
                    let activeClass = iT ? (isA ? "bg-slate-800 border-slate-800 text-white shadow-md ring-2 ring-slate-800/20 ring-offset-1" : "bg-white border-slate-200 hover:border-slate-400 hover:shadow-sm") 
                                         : (isA ? "bg-red-50 border-red-200 shadow-sm ring-1 ring-red-500/30" : "bg-white border-slate-100 hover:border-red-200 hover:shadow-sm hover:bg-red-50/30");
                    
                    if (PERM_FINANCE === 0) {
                        const titleCol = iT ? (isA ? 'text-slate-300' : 'text-slate-500') : (isA ? 'text-red-800' : 'text-slate-500');
                        const badgeCol = isA ? (iT ? 'bg-white/20 text-white' : 'bg-red-100 text-red-800') : 'bg-slate-100 text-slate-600';
                        return `
                        <div onclick="setDateFilter(${iT ? 'null' : `'${l}'`})" class="${baseClass} ${activeClass}">
                            <div class="flex justify-between items-center mb-1.5">
                                <span class="text-xs font-bold ${titleCol}">${iT ? '📊 历史总计' : '📅 ' + l}</span>
                                <span class="text-[10px] font-black px-1.5 py-0.5 rounded-md ${badgeCol}">📦 ${c} 项</span>
                            </div>
                            <div class="text-sm font-black opacity-80 mt-2">${iT ? '点击查看全部' : '点击过滤本月'}</div>
                        </div>`;
                    } else {
                        const pC = p > 0 ? (isA && iT ? 'text-red-300' : 'text-red-600') : (p < 0 ? (isA && iT ? 'text-emerald-300' : 'text-emerald-500') : (isA && iT ? 'text-slate-300' : 'text-slate-400'));
                        const titleCol = iT ? (isA ? 'text-slate-300' : 'text-slate-500') : (isA ? 'text-red-800' : 'text-slate-500');
                        const badgeCol = isA ? (iT ? 'bg-white/20 text-white' : 'bg-red-100 text-red-800') : 'bg-slate-100 text-slate-600 group-hover:bg-red-100 group-hover:text-red-800 transition-colors';
                        return `
                        <div onclick="setDateFilter(${iT ? 'null' : `'${l}'`})" class="${baseClass} ${activeClass}">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-[11px] font-bold ${titleCol} tracking-wider">${iT ? 'TOTAL' : l}</span>
                                <span class="text-[10px] font-black px-1.5 py-0.5 rounded flex items-center gap-1 ${badgeCol}"><span>📦</span>${c}</span>
                            </div>
                            <div class="text-[17px] font-black ${pC} truncate mt-1 tracking-tight">${fmtMoney(p)}</div>
                        </div>`;
                    }
                };
                
                let yH = '', mH = ''; 
                let hasYears = false, hasMonths = false;
                Object.keys(summaryData.years).sort().reverse().forEach(y => { 
                    if (summaryData.years[y].count === 0) return; 
                    yH += bC(y, summaryData.years[y].count, summaryData.years[y].profit); 
                    hasYears = true; 
                });
                Object.keys(summaryData.months).sort().reverse().forEach(m => { 
                    if (summaryData.months[m].count === 0) return; 
                    mH += bC(m, summaryData.months[m].count, summaryData.months[m].profit); 
                    hasMonths = true; 
                });
                
                let title = PERM_FINANCE === 0 ? "历史销量看板" : "历史销量与利润看板";
                let lockBtnHtml = LOCK_TABS[currentTab] === 1 ? `<button onclick="toggleFinanceLock()" class="text-[11px] font-bold text-slate-500 hover:text-red-600 bg-white px-2 py-1 rounded-md border border-slate-200 shadow-sm transition-all flex items-center gap-1">🔒 重新锁定</button>` : '';
                let divider = `<div class="w-[1px] bg-slate-200 mx-1 shrink-0 rounded-full my-2"></div>`;
                
                panel.innerHTML = `
                    <div class="flex justify-between items-center mb-2.5 px-1">
                        <h2 class="text-[13px] font-black text-slate-800 flex items-center gap-2">
                            📈 ${title} <span class="text-[11px] font-medium text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full border border-slate-200/50">横向滑动 • 点击卡片过滤</span>
                        </h2>
                        ${lockBtnHtml}
                    </div>
                    <div class="flex gap-3 overflow-x-auto hide-scrollbar pb-1.5 pt-0.5 px-1 -mx-1 snap-x">
                        ${bC('全部', summaryData.total_count, summaryData.tp, true)}
                        ${hasYears ? divider + yH : ''}
                        ${hasMonths ? divider + mH : ''}
                    </div>`;
            }
        } else {
            if (!summaryData || summaryData.total_count === 0) { 
                panel.classList.add('hidden'); 
                return; 
            }
            if (PERM_FINANCE === 0) { 
                panel.classList.add('hidden'); 
            } else {
                panel.classList.remove('hidden');
                if (isTabLocked) {
                    panel.innerHTML = `
                        <div class="flex justify-end items-center bg-[#FDE8E8] border border-[#F8B4B4] p-2 rounded w-full">
                            <button onclick="toggleFinanceLock()" class="bg-[#8B0000] hover:bg-[#600000] text-white font-bold py-1 px-4 text-[13px] rounded transition-all shadow-sm flex items-center gap-2">🔒 验证密码解锁【成本统计】</button>
                        </div>`;
                } else {
                    const tco = summaryData.tc + summaryData.tf;
                    let lockBtnHtml = LOCK_TABS[currentTab] === 1 ? `<button onclick="toggleFinanceLock()" class="text-[11px] font-bold text-slate-500 hover:text-red-600 bg-white px-2 py-1 rounded-md border border-slate-200 shadow-sm transition-all flex items-center gap-1">🔒 重新锁定</button>` : '';
                    
                    panel.innerHTML = `
                    <div class="flex justify-between items-center mb-2.5 px-1">
                        <h2 class="text-[13px] font-black text-slate-800 flex items-center gap-2">
                            📊 全库成本统计 <span class="text-[11px] font-medium text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full border border-slate-200/50">当前页面实时汇总</span>
                        </h2>
                        ${lockBtnHtml}
                    </div>
                    <div class="flex gap-3 overflow-x-auto hide-scrollbar pb-1.5 pt-0.5 px-1">
                        <div class="min-w-[150px] flex-none p-3 rounded-xl border border-slate-200 bg-white shadow-sm hover:border-slate-300 transition-all group">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-[11px] font-bold text-slate-500 tracking-wider">总采购成本</span>
                                <span class="text-[12px] opacity-80">📦</span>
                            </div>
                            <div class="text-[17px] font-black text-slate-800 tracking-tight mt-1">${fmtMoney(summaryData.tc)}</div>
                        </div>
                        
                        <div class="min-w-[150px] flex-none p-3 rounded-xl border border-slate-200 bg-white shadow-sm hover:border-slate-300 transition-all group">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-[11px] font-bold text-slate-500 tracking-wider">总运费</span>
                                <span class="text-[12px] opacity-80">✈️</span>
                            </div>
                            <div class="text-[17px] font-black text-slate-800 tracking-tight mt-1">${fmtMoney(summaryData.tf)}</div>
                        </div>
                        
                        <div class="min-w-[150px] flex-none p-3 rounded-xl border border-slate-800 bg-slate-800 shadow-md ring-2 ring-slate-800/20 ring-offset-1">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-[11px] font-bold text-slate-300 tracking-wider">沉没总金额</span>
                                <span class="text-[12px] opacity-90">💰</span>
                            </div>
                            <div class="text-[17px] font-black text-white tracking-tight mt-1">${fmtMoney(tco)}</div>
                        </div>
                    </div>`;
                }
            }
        }
    }

    async function loadData() {
        if(currentTab === 'NONE') return;
        const tb = document.getElementById('tableBody');
        tb.innerHTML = '<tr><td colspan="14" class="text-center py-16 text-slate-400 font-medium">正在与服务器同步检索...</td></tr>';
        
        try {
            const params = new URLSearchParams({ 
                action: 'list', 
                status: currentTab, 
                keyword: currentSearchTerm, 
                dateFilter: currentDateFilter || '', 
                page: currentPage, 
                limit: ITEMS_PER_PAGE, 
                unlocked: isFinanceUnlocked ? '1' : '0' 
            });
            const r = await apiFetch(`${INVENTORY_API_URL}?${params.toString()}`);
            const j = await r.json();
            
            if (j.status === 'success') { 
                currentData = j.data; 
                totalFilteredItems = parseInt(j.total); 
                summaryData = j.summary; 
                renderSummaryPanels(); 
                renderTable(); 
            } else { 
                tb.innerHTML = `<tr><td colspan="14" class="text-center py-16 text-red-500">${escapeHTML(j.message || '请求失败')}</td></tr>`;
            }
        } catch (e) { 
            tb.innerHTML = `<tr><td colspan="14" class="text-center py-16 text-red-500">网络异常或环境配置错误，请求未能成功</td></tr>`; 
        }
    }

    function renderTable() {
        const hd = document.getElementById('tableHead');
        const tb = document.getElementById('tableBody');
        const pg = document.getElementById('paginationPanel');
        
        let isTabLocked = (LOCK_TABS[currentTab] === 1 && !isFinanceUnlocked);
        let showF = (PERM_FINANCE === 1) && !isTabLocked;
        let isSoldView = ['SOLD', 'PARTS_SOLD', 'REPAIR_DONE'].includes(currentTab);
        let isPartsView = ['PARTS', 'PARTS_SOLD'].includes(currentTab);
        
        let ht = `<tr>`;
        if(PERM_EDIT === 1 || PERM_DELETE === 1) {
            ht += `<th class="px-3 py-2.5 w-10 text-center"><input type="checkbox" id="selectAllCheckbox" onchange="toggleAllCheckboxes()" class="custom-checkbox"></th>`;
        }
        
        ht += `<th class="px-3 py-2.5 text-center whitespace-nowrap font-bold">序号</th>
               <th class="px-3 py-2.5 text-center whitespace-nowrap font-bold">入库日期</th>
               <th class="px-3 py-2.5 text-center whitespace-nowrap font-bold">批次</th>
               <th class="px-3 py-2.5 whitespace-nowrap font-bold">编号/型号</th>
               <th class="px-3 py-2.5 whitespace-nowrap font-bold">配置/规格</th>`;
               
        if(isPartsView) {
            ht += `<th class="px-3 py-2.5 text-center whitespace-nowrap font-bold text-indigo-700">数量</th>`;
        }
        
        if(showF) {
            let costLabel = isPartsView ? "单件成本(¥)" : "成本(¥)";
            let freightLabel = isPartsView ? "单件运费(¥)" : "运费(¥)";
            ht += `<th class="px-5 py-2.5 text-right whitespace-nowrap font-bold">${costLabel}</th>
                   <th class="px-5 py-2.5 text-right whitespace-nowrap font-bold pr-6">${freightLabel}</th>`;
        }
        
        let collectedLabel = isPartsView ? "单件收款(¥)" : "收款(¥)";
        ht += `<th class="px-3 py-2.5 whitespace-nowrap font-bold">收货人</th>
               <th class="px-3 py-2.5 whitespace-nowrap font-bold">备注</th>
               <th class="px-5 py-2.5 text-right whitespace-nowrap font-bold">${collectedLabel}</th>`;
        
        if(showF) {
            ht += isSoldView 
                ? `<th class="px-5 py-2.5 text-right whitespace-nowrap font-bold text-emerald-700">总利润 (¥)</th>` 
                : `<th class="px-5 py-2.5 text-right whitespace-nowrap font-bold text-slate-500">沉没总成本(¥)</th>`;
        }
        if(PERM_EDIT === 1 || PERM_DELETE === 1) {
            ht += `<th class="px-3 py-2.5 text-center whitespace-nowrap font-bold">操作</th>`;
        }
        ht += `</tr>`;
        hd.innerHTML = ht;

        const ti = totalFilteredItems;
        if (ti === 0) { 
            tb.innerHTML = '<tr><td colspan="14" class="text-center py-16 text-slate-400 font-medium">没有找到匹配的记录</td></tr>'; 
            pg.classList.add('hidden'); 
            return; 
        }
        
        const tp = Math.ceil(ti / ITEMS_PER_PAGE);
        if (currentPage > tp && tp > 0) { 
            currentPage = tp; 
            loadData(); 
            return; 
        }

        let h = '';
        currentData.forEach((r, i) => {
            const sl = (currentPage - 1) * ITEMS_PER_PAGE + i + 1;
            const fi = statusFlow[r.status];
            const qty = parseInt(r.quantity) || 1;
            const rowBg = i % 2 === 0 ? 'bg-white' : 'even:bg-slate-50/40';
            
            let fb = '';
            if (fi && PERM_EDIT === 1) {
                if (r.status === 'PARTS') {
                    fb = `<button onclick="openDispatchModalById(${r.id})" class="text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 px-2 py-0.5 rounded hover:bg-indigo-600 hover:text-white transition-all whitespace-nowrap shadow-sm">🛒 拆分出库</button>`;
                } else if (r.status === 'CN_WH' && fi.next === 'SOLD') {
                    const hr = r.receiver && String(r.receiver).trim() !== ''; 
                    const hc = parseFloat(r.collected_amount) > 0;
                    if (!hr || !hc) {
                        fb = `<button onclick="alert('拦截提示：发往已售必须填写收货人和收款金额！')" class="text-xs font-bold bg-slate-100 text-slate-400 border border-slate-200 px-1.5 py-0.5 rounded whitespace-nowrap">缺资料</button>`;
                    } else {
                        fb = `<button onclick="updateStatus(${r.id}, '${fi.next}')" class="text-xs font-bold bg-${fi.color}-50 text-${fi.color}-700 border border-${fi.color}-200 px-1.5 py-0.5 rounded hover:bg-${fi.color}-600 hover:text-white transition-all whitespace-nowrap shadow-sm">${fi.icon} ${fi.nextLabel}</button>`;
                    }
                } else if ((r.status === 'SOLD' && fi.next === 'CN_WH') || (r.status === 'PARTS_SOLD' && fi.next === 'PARTS')) {
                    fb = `<button onclick="sysConfirm('确定整体退回吗？').then(res => { if(res) updateStatus(${r.id}, '${fi.next}') })" class="text-xs font-bold bg-${fi.color}-50 text-${fi.color}-700 border border-${fi.color}-200 px-1.5 py-0.5 rounded hover:bg-${fi.color}-600 hover:text-white transition-all whitespace-nowrap shadow-sm">${fi.icon} 退回</button>`;
                } else {
                    fb = `<button onclick="updateStatus(${r.id}, '${fi.next}')" class="text-xs font-bold bg-${fi.color}-50 text-${fi.color}-700 border border-${fi.color}-200 px-1.5 py-0.5 rounded hover:bg-${fi.color}-600 hover:text-white transition-all whitespace-nowrap shadow-sm">${fi.icon} ${fi.nextLabel}</button>`;
                }
            }

            let qtyHtml = isPartsView ? `<td class="px-3 py-2 text-center font-black text-indigo-700 whitespace-nowrap">${qty}</td>` : '';

            let uc = isPartsView ? (qty > 0 ? (parseFloat(r.cost_rmb) / qty) : 0) : parseFloat(r.cost_rmb);
            let uf = isPartsView ? (qty > 0 ? (parseFloat(r.freight) / qty) : 0) : parseFloat(r.freight);
            let ucol = isPartsView ? (qty > 0 ? (parseFloat(r.collected_amount) / qty) : 0) : parseFloat(r.collected_amount);

            let fc = '';
            if (showF) {
                fc = `<td class="px-5 py-2 text-right font-medium text-slate-600 whitespace-nowrap">${fmtMoney(uc.toFixed(2))}</td>
                      <td class="px-5 py-2 text-right font-medium text-slate-500 whitespace-nowrap pr-6">${fmtMoney(uf.toFixed(2))}</td>`;
            }
            let ca = `<td class="px-5 py-2 text-right font-bold text-slate-800 whitespace-nowrap">${fmtMoney(ucol.toFixed(2))}</td>`;
            
            let pco = '';
            if (showF) {
                if (isSoldView) {
                    let pv = parseFloat(r.profit) || 0;
                    let pc = pv > 0 ? 'text-red-600' : (pv < 0 ? 'text-emerald-500' : 'text-slate-500');
                    pco = `<td class="px-5 py-2 text-right font-bold ${pc} whitespace-nowrap">${fmtMoney(pv)}</td>`;
                } else {
                    let tot = parseFloat(r.cost_rmb) + parseFloat(r.freight);
                    pco = `<td class="px-5 py-2 text-right font-bold text-slate-500 whitespace-nowrap">${fmtMoney(tot)}</td>`;
                }
            }
            
            let ac = '';
            if (PERM_EDIT === 1 || PERM_DELETE === 1) {
                let eb = PERM_EDIT === 1 ? `<button onclick="editItemById(${r.id})" class="text-xs font-bold text-[#8B0000] hover:underline px-1 transition-colors">编辑</button>` : '';
                let db = PERM_DELETE === 1 ? `<button onclick="deleteItem(${r.id})" class="text-xs font-bold text-slate-400 hover:text-red-600 hover:underline px-1 transition-colors">删除</button>` : '';
                ac = `<td class="px-3 py-2 align-middle text-center"><div class="flex flex-row items-center justify-center gap-1.5">${fb}${eb}${db}</div></td>`;
            }
            
            let cb = ''; 
            if (PERM_EDIT === 1 || PERM_DELETE === 1) {
                cb = `<td class="px-3 py-2 text-center"><input type="checkbox" class="row-checkbox custom-checkbox" value="${r.id}" onchange="checkSelection()"></td>`;
            }

            h += `<tr class="${rowBg} hover:bg-[#FDE8E8] transition-colors duration-200 border-b border-slate-100 select-text">
                ${cb}
                <td class="px-3 py-2 text-center text-slate-400 whitespace-nowrap font-medium">${sl}</td>
                <td class="px-3 py-2 text-center text-slate-600 whitespace-nowrap">${escapeHTML(r.status_date)||'-'}</td>
                <td class="px-3 py-2 text-center text-slate-600 whitespace-nowrap">${escapeHTML(r.batch_no)||'-'}</td>
                <td class="px-3 py-2 text-slate-800 font-bold whitespace-nowrap">${escapeHTML(r.service_no)}</td>
                <td class="px-3 py-2 text-slate-600 break-words max-w-[180px]">${escapeHTML(r.config_desc)||'-'}</td>
                ${qtyHtml}
                ${fc}
                <td class="px-3 py-2 text-slate-700 font-medium whitespace-nowrap">${escapeHTML(r.receiver)||'-'}</td>
                <td class="px-3 py-2 text-slate-500 break-words max-w-[140px]">${escapeHTML(r.remarks)||'-'}</td>
                ${ca}
                ${pco}
                ${ac}
            </tr>`;
        });
        
        tb.innerHTML = h;

        if (selectedIdsToRestore.length > 0) { 
            document.querySelectorAll('.row-checkbox').forEach(cb => { 
                if (selectedIdsToRestore.includes(cb.value)) cb.checked = true; 
            }); 
            selectedIdsToRestore = []; 
        }
        
        if (ti <= ITEMS_PER_PAGE) { 
            pg.classList.add('hidden'); 
        } else {
            pg.classList.remove('hidden'); 
            let sc = (currentPage - 1) * ITEMS_PER_PAGE + 1; 
            let ec = Math.min(currentPage * ITEMS_PER_PAGE, ti);
            document.getElementById('pageInfo').innerHTML = `显示 ${sc} 到 ${ec}，共 <span class="font-bold text-[#8B0000]">${ti}</span> 条`;
            document.getElementById('pageControls').innerHTML = `
                <button onclick="changePage(${currentPage - 1})" class="px-3 py-1.5 bg-white border border-slate-200 rounded text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-50 transition-all shadow-sm" ${currentPage === 1 ? 'disabled' : ''}>上一页</button>
                <span class="text-xs text-slate-500 font-bold px-3 flex items-center">${currentPage} / ${tp}</span>
                <button onclick="changePage(${currentPage + 1})" class="px-3 py-1.5 bg-white border border-slate-200 rounded text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-50 transition-all shadow-sm" ${currentPage === tp ? 'disabled' : ''}>下一页</button>`;
        }
        
        if(PERM_EDIT === 1 || PERM_DELETE === 1) checkSelection();
    }

    function toggleAllCheckboxes() { 
        const a = document.getElementById('selectAllCheckbox'); 
        if(!a) return; 
        const c = a.checked; 
        document.querySelectorAll('.row-checkbox').forEach(b => b.checked = c); 
        checkSelection(); 
    }
    
    function checkSelection() {
        if(PERM_EDIT === 0 && PERM_DELETE === 0) return; 
        const b = document.querySelectorAll('.row-checkbox'); 
        let c = 0; 
        b.forEach(x => { if(x.checked) c++; });
        
        const sa = document.getElementById('selectAllCheckbox'); 
        if(sa) sa.checked = (c > 0 && c === b.length);
        
        const p = document.getElementById('batchActionPanel');
        if (c > 0) { 
            p.classList.remove('hidden'); 
            document.getElementById('selectedCount').innerText = c; 
            const m = document.getElementById('batchMoveBtn'); 
            if (m) { 
                if (statusFlow[currentTab] && currentTab !== 'PARTS') { 
                    m.innerHTML = `⚡ 批量移至 [${statusFlow[currentTab].nextLabel}]`; 
                    m.classList.remove('hidden'); 
                } else { 
                    m.classList.add('hidden'); 
                } 
            } 
        } else { 
            p.classList.add('hidden'); 
        }
    }

    function openBatchEditModal() {
        const b = document.querySelectorAll('.row-checkbox'); 
        let c = 0; 
        b.forEach(x => { if(x.checked) c++; });
        if(c === 0) return alert('请先勾选需要批量编辑的设备！');
        
        document.getElementById('batchEditForm').reset();
        document.getElementById('batch_batch_no').disabled = true; 
        document.getElementById('batch_config').disabled = true;
        
        let isTabLocked = (LOCK_TABS[currentTab] === 1 && !isFinanceUnlocked); 
        let sF = (PERM_FINANCE === 1) && !isTabLocked;
        
        if(document.getElementById('batch_cost_container')) { 
            document.getElementById('batch_cost_container').classList.toggle('hidden', !sF); 
            document.getElementById('chk_batch_cost').disabled = !sF; 
            document.getElementById('batch_cost').disabled = true; 
            document.getElementById('batch_freight_container').classList.toggle('hidden', !sF); 
            document.getElementById('chk_batch_freight').disabled = !sF; 
            document.getElementById('batch_freight').disabled = true; 
        }
        
        document.getElementById('batch_receiver').disabled = true; 
        document.getElementById('batch_remarks').disabled = true; 
        document.getElementById('batch_collected_amount').disabled = true;
        document.getElementById('batchEditModal').classList.remove('hidden'); 
        setTimeout(() => document.getElementById('batchEditContent').classList.add('modal-enter-active'), 10);
    }
    
    function closeBatchEditModal() { 
        document.getElementById('batchEditContent').classList.remove('modal-enter-active'); 
        setTimeout(() => document.getElementById('batchEditModal').classList.add('hidden'), 200); 
    }

    async function submitBatchEdit(e) {
        e.preventDefault(); 
        const b = document.querySelectorAll('.row-checkbox'); 
        let ids = []; 
        b.forEach(x => { if(x.checked) ids.push(x.value); });
        
        const fd = new FormData(document.getElementById('batchEditForm')); 
        fd.append('action', 'batch_edit'); 
        fd.append('ids', ids.join(','));
        fd.append('unlocked', isFinanceUnlocked ? '1' : '0');
        
        let hu = false; 
        ['chk_batch_no', 'chk_batch_config', 'chk_batch_receiver', 'chk_batch_remarks', 'chk_batch_cost', 'chk_batch_freight', 'chk_batch_collected_amount'].forEach(id => { 
            const el = document.getElementById(id); 
            if (el && el.checked) hu = true; 
        });
        
        if(!hu) return alert("您没有勾选任何需要覆盖的字段！");
        if(!(await sysConfirm(`即将覆盖到选中的 ${ids.length} 个记录上。确认执行吗？`))) return;
        
        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if(j.status === 'success') { 
                closeBatchEditModal(); 
                selectedIdsToRestore = ids; 
                loadData(); 
            } else { 
                alert(j.message); 
            } 
        } catch (er) { 
            alert("批量编辑失败，网络异常"); 
        }
    }

    async function executeBatchMove() {
        const b = document.querySelectorAll('.row-checkbox'); 
        let ids = []; 
        b.forEach(x => { if(x.checked) ids.push(x.value); }); 
        if(ids.length === 0) return;
        
        if ((currentTab === 'CN_WH' && statusFlow[currentTab].next === 'SOLD') || (currentTab === 'PARTS' && statusFlow[currentTab].next === 'PARTS_SOLD')) {
            let hm = false; 
            ids.forEach(id => { 
                let r = currentData.find(x => x.id == id); 
                if (r) { 
                    const hr = r.receiver && String(r.receiver).trim() !== ''; 
                    const hc = parseFloat(r.collected_amount) > 0; 
                    if (!hr || !hc) hm = true; 
                } 
            });
            if (hm) return alert('拦截提示：\n选中包含【缺资料】的条目！\n请先补充【收货人】和【收款金额】！');
        }
        
        const ns = statusFlow[currentTab].next;
        if (!(await sysConfirm(`确定将选中的 ${ids.length} 项整体批量流转吗？`))) return;
        
        const fd = new FormData(); 
        fd.append('action', 'batch_update_status'); 
        fd.append('ids', ids.join(',')); 
        fd.append('status', ns);
        
        await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
        const sa = document.getElementById('selectAllCheckbox'); 
        if(sa) sa.checked = false; 
        loadData();
    }

    async function executeBatchDelete(autoReload = true, skipConfirm = false) {
        const b = document.querySelectorAll('.row-checkbox'); 
        let ids = []; 
        b.forEach(x => { if(x.checked) ids.push(x.value); }); 
        if(ids.length === 0) return;
        
        if (!skipConfirm) {
            if (!(await sysConfirm(`⚠️ 危险警告：确定要彻底删除选中的 ${ids.length} 项吗？不可恢复！`))) return;
        }

        const fd = new FormData(); 
        fd.append('action', 'batch_delete'); 
        fd.append('ids', ids.join(','));
        
        try {
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json();
            
            if (j.status === 'success') {
                const sa = document.getElementById('selectAllCheckbox'); 
                if(sa) sa.checked = false; 
                if (autoReload) loadData(); 
            } else {
                if (j.message && j.message.includes('锁定')) {
                    verifyLock(async () => {
                        await executeBatchDelete(false, true);     
                        await relockFinance(); 
                        isFinanceUnlocked = false; 
                        loadData(); 
                    });
                } else {
                    alert(j.message); 
                }
            }
        } catch (e) {
            alert("批量删除失败，网络异常");
        }
    }

    async function updateStatus(id, ns) { 
        const fd = new FormData(); 
        fd.append('action', 'update_status'); 
        fd.append('id', id); 
        fd.append('status', ns); 
        await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
        loadData(); 
    }
    
    async function deleteItem(id, autoReload = true, skipConfirm = false) { 
        if (!skipConfirm) {
            if(!(await sysConfirm("确定要彻底删除这条记录吗？该操作不可恢复！"))) return; 
        }

        const fd = new FormData(); 
        fd.append('action', 'delete'); 
        fd.append('id', id); 
        try {
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json();
            
            if (j.status === 'success') {
                if (autoReload) loadData(); 
            } else {
                if (j.message && j.message.includes('锁定')) {
                    verifyLock(async () => {
                        await deleteItem(id, false, true);           
                        await relockFinance();
                        isFinanceUnlocked = false;
                        loadData(); 
                    });
                } else {
                    alert(j.message); 
                }
            }
        } catch (e) {
            alert("删除失败，网络异常");
        }
    }

    function updateModalTotals() {
        let qty = parseInt(document.getElementById('form_quantity').value) || 1;
        let c = parseFloat(document.getElementById('form_unit_cost') ? document.getElementById('form_unit_cost').value : 0) || 0;
        let f = parseFloat(document.getElementById('form_unit_freight') ? document.getElementById('form_unit_freight').value : 0) || 0;
        let a = parseFloat(document.getElementById('form_unit_collected').value) || 0;
        
        if(document.getElementById('modal_cost_total')) document.getElementById('modal_cost_total').innerText = '总成本计: ¥' + (c*qty).toFixed(2);
        if(document.getElementById('modal_freight_total')) document.getElementById('modal_freight_total').innerText = '总运费计: ¥' + (f*qty).toFixed(2);
        if(document.getElementById('modal_collected_total')) document.getElementById('modal_collected_total').innerText = '总收款计: ¥' + (a*qty).toFixed(2);
    }

    function openModal() {
        if (PERM_ADD === 0) return alert('安全拦截：您没有新增记录的权限！');
        document.getElementById('itemForm').reset();
        document.getElementById('form_id').value = ''; 
        document.getElementById('form_updated_at').value = ''; 
        document.getElementById('form_batch_no').value = '';
        document.getElementById('form_quantity').value = 1; 
        document.getElementById('form_status').value = currentTab === 'NONE' ? 'US' : currentTab; 
        document.getElementById('form_remarks').value = '';
        
        let isTabLocked = (LOCK_TABS[currentTab] === 1 && !isFinanceUnlocked); 
        let sF = (PERM_FINANCE === 1) && !isTabLocked;
        
        if (document.getElementById('modal_cost_container')) { 
            document.getElementById('modal_cost_container').classList.toggle('hidden', !sF); 
            document.getElementById('form_unit_cost').disabled = !sF; 
            document.getElementById('modal_freight_container').classList.toggle('hidden', !sF); 
            document.getElementById('form_unit_freight').disabled = !sF; 
        }
        
        toggleModalFields();
        document.getElementById('modalTitle').innerText = '新增记录'; 
        document.getElementById('itemModal').classList.remove('hidden'); 
        setTimeout(() => { 
            document.getElementById('modalContent').classList.add('modal-enter-active');
            document.getElementById('form_service_no').focus();
        }, 10);
    }
    function editItemById(id) { 
        const row = currentData.find(x => x.id == id); 
        if (row) editItem(row); 
    }

    function openDispatchModalById(id) {
        const row = currentData.find(x => x.id == id);
        if (row) {
            const qty = parseInt(row.quantity) || 1;
            openDispatchModal(row.id, qty, row.service_no);
        }
    }
    function editItem(row) {
        document.getElementById('modalTitle').innerText = '编辑记录 - ' + row.service_no;
        document.getElementById('form_id').value = row.id; 
        // 修复：提取真实的 updated_at 传递给后端，而非业务流转时间 status_timestamp
        document.getElementById('form_updated_at').value = row.updated_at || ''; 
        document.getElementById('form_service_no').value = row.service_no; 
        document.getElementById('form_batch_no').value = row.batch_no || '';
        document.getElementById('form_status').value = row.status; 
        document.getElementById('form_quantity').value = row.quantity || 1;
        document.getElementById('form_config_desc').value = row.config_desc; 
        document.getElementById('form_remarks').value = row.remarks || '';
        
        let isTabLocked = (LOCK_TABS[currentTab] === 1 && !isFinanceUnlocked); 
        let sF = (PERM_FINANCE === 1) && !isTabLocked;
        const qty = row.quantity > 0 ? row.quantity : 1;
        
        if (document.getElementById('modal_cost_container')) {
            document.getElementById('modal_cost_container').classList.toggle('hidden', !sF); 
            document.getElementById('form_unit_cost').disabled = !sF;
            document.getElementById('modal_freight_container').classList.toggle('hidden', !sF); 
            document.getElementById('form_unit_freight').disabled = !sF;
            
            if (sF) { 
                let c_val = (row.cost_rmb / qty).toFixed(2).replace(/\.00$/, '');
                let f_val = (row.freight / qty).toFixed(2).replace(/\.00$/, '');
                document.getElementById('form_unit_cost').value = (c_val === '0') ? '' : c_val; 
                document.getElementById('form_unit_freight').value = (f_val === '0') ? '' : f_val; 
            }
        }
        
        document.getElementById('form_receiver').value = row.receiver; 
        let a_val = (row.collected_amount / qty).toFixed(2).replace(/\.00$/, '');
        document.getElementById('form_unit_collected').value = (a_val === '0') ? '' : a_val;
        
        toggleModalFields();
        document.getElementById('itemModal').classList.remove('hidden'); 
        setTimeout(() => { 
            document.getElementById('modalContent').classList.add('modal-enter-active');
            document.getElementById('form_service_no').focus();
        }, 10);
    }
    
    function closeModal() { 
        document.getElementById('modalContent').classList.remove('modal-enter-active'); 
        setTimeout(() => document.getElementById('itemModal').classList.add('hidden'), 200); 
    }
    
    async function saveItem(e) {
        e.preventDefault();
        
        const btn = e.target.querySelector('button[type="submit"]');
        if(btn) { btn.disabled = true; btn.innerText = '保存中...'; btn.classList.add('opacity-50', 'cursor-not-allowed'); }
        
        try {
            const fd = new FormData(document.getElementById('itemForm'));
            const qty = parseInt(fd.get('quantity')) || 1;
            const uc = parseFloat(fd.get('unit_cost')) || 0;
            const uf = parseFloat(fd.get('unit_freight')) || 0;
            const ucol = parseFloat(fd.get('unit_collected')) || 0;
            
            fd.append('cost_rmb', (uc * qty));
            fd.append('freight', (uf * qty));
            fd.append('collected_amount', (ucol * qty));
            fd.append('action', 'save');
            fd.append('unlocked', isFinanceUnlocked ? '1' : '0'); 
           
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json();
            
            if (j.status === 'success') { 
                closeModal(); 
                loadData(); 
            } else { 
                alert(j.message); 
            }
        } catch (error) {
            alert('网络请求异常，保存失败');
        } finally {
            if(btn) { btn.disabled = false; btn.innerText = '保存记录'; btn.classList.remove('opacity-50', 'cursor-not-allowed'); }
        }
    }

    // --- 拆分出库模态框控制 ---
    function openDispatchModal(id, maxQty, name) {
        document.getElementById('dispatch_id').value = id;
        document.getElementById('dispatch_qty').value = 1;
        document.getElementById('dispatch_qty').max = maxQty;
        document.getElementById('dispatch_max_label').innerText = `(最多可出库: ${maxQty})`;
        document.getElementById('dispatch_unit_collected').value = '';
        document.getElementById('dispatch_receiver').value = '';
        document.getElementById('dispatchTitle').innerText = '🛒 配件出库 - ' + name;
        
        document.getElementById('dispatchModal').classList.remove('hidden');
        setTimeout(() => { 
            document.getElementById('dispatchContent').classList.add('modal-enter-active');
            document.getElementById('dispatch_qty').focus(); 
        }, 10);
    }
    
    function closeDispatchModal() { 
        document.getElementById('dispatchContent').classList.remove('modal-enter-active'); 
        setTimeout(() => document.getElementById('dispatchModal').classList.add('hidden'), 200); 
    }

    async function submitDispatch(e) {
        e.preventDefault();
        
        const btn = e.target.querySelector('button[type="submit"]');
        if(btn) { btn.disabled = true; btn.innerText = '出库中...'; btn.classList.add('opacity-50', 'cursor-not-allowed'); }
        
        try {
            const fd = new FormData();
            fd.append('action', 'dispatch_part');
            fd.append('id', document.getElementById('dispatch_id').value);
            fd.append('dispatch_qty', document.getElementById('dispatch_qty').value);
            fd.append('unit_collected', document.getElementById('dispatch_unit_collected').value);
            fd.append('receiver', document.getElementById('dispatch_receiver').value);

            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd});
            const j = await r.json();
            if(j.status === 'success') { 
                closeDispatchModal(); 
                loadData(); 
            } else { 
                alert(j.message); 
            }
        } catch (error) {
            alert('网络请求异常，出库失败');
        } finally {
            if(btn) { btn.disabled = false; btn.innerText = '确认出库'; btn.classList.remove('opacity-50', 'cursor-not-allowed'); }
        }
    }

    function openSettingsModal() { 
        // --- 新增：每次打开设置面板时，强制把复选框恢复到真实的全局变量状态 ---
        if (!IS_SUB_ACCOUNT) {
            // 1. 恢复可见性配置
            if(document.getElementById('master_tab_us')) document.getElementById('master_tab_us').checked = (p_us === 1);
            if(document.getElementById('master_tab_transit')) document.getElementById('master_tab_transit').checked = (p_transit === 1);
            if(document.getElementById('master_tab_cn')) document.getElementById('master_tab_cn').checked = (p_cn === 1);
            if(document.getElementById('master_tab_sold')) document.getElementById('master_tab_sold').checked = (p_sold === 1);
            if(document.getElementById('master_tab_parts')) document.getElementById('master_tab_parts').checked = (p_parts === 1);
            if(document.getElementById('master_tab_parts_sold')) document.getElementById('master_tab_parts_sold').checked = (p_parts_sold === 1);
            if(document.getElementById('master_tab_repair')) document.getElementById('master_tab_repair').checked = (p_repair === 1);
            if(document.getElementById('master_tab_repair_done')) document.getElementById('master_tab_repair_done').checked = (p_repair_done === 1);

            // 2. 恢复保险箱锁配置
            if(document.getElementById('master_lock_us')) document.getElementById('master_lock_us').checked = (LOCK_TABS['US'] === 1);
            if(document.getElementById('master_lock_transit')) document.getElementById('master_lock_transit').checked = (LOCK_TABS['TRANSIT'] === 1);
            if(document.getElementById('master_lock_cn')) document.getElementById('master_lock_cn').checked = (LOCK_TABS['CN_WH'] === 1);
            if(document.getElementById('master_lock_sold')) document.getElementById('master_lock_sold').checked = (LOCK_TABS['SOLD'] === 1);
            if(document.getElementById('master_lock_parts')) document.getElementById('master_lock_parts').checked = (LOCK_TABS['PARTS'] === 1);
            if(document.getElementById('master_lock_parts_sold')) document.getElementById('master_lock_parts_sold').checked = (LOCK_TABS['PARTS_SOLD'] === 1);
            if(document.getElementById('master_lock_repair')) document.getElementById('master_lock_repair').checked = (LOCK_TABS['REPAIR'] === 1);
            if(document.getElementById('master_lock_repair_done')) document.getElementById('master_lock_repair_done').checked = (LOCK_TABS['REPAIR_DONE'] === 1);
        }
        // -------------------------------------------------------------------

        document.getElementById('settingsModal').classList.remove('hidden'); 
        setTimeout(() => document.getElementById('settingsContent').classList.add('modal-enter-active'), 10); 
        switchSetTab('pwd'); 
    }
    
    function closeSettingsModal() { 
        document.getElementById('settingsContent').classList.remove('modal-enter-active'); 
        setTimeout(() => document.getElementById('settingsModal').classList.add('hidden'), 200); 
    }
    
    function switchSetTab(t) { 
        document.querySelectorAll('.set-tab-btn').forEach(b => { 
            b.classList.remove('bg-slate-800', 'text-white', 'shadow-md'); 
            b.classList.add('text-slate-500', 'hover:bg-slate-100'); 
        }); 
        const activeTab = document.getElementById('setTab_' + t); 
        if(activeTab) { 
            activeTab.classList.remove('text-slate-500', 'hover:bg-slate-100'); 
            activeTab.classList.add('bg-slate-800', 'text-white', 'shadow-md'); 
        } 
        document.getElementById('setPanel_pwd').classList.add('hidden'); 
        if (document.getElementById('setPanel_tabs')) document.getElementById('setPanel_tabs').classList.add('hidden'); 
        if (document.getElementById('setPanel_sub')) document.getElementById('setPanel_sub').classList.add('hidden'); 
        if (document.getElementById('setPanel_registration')) document.getElementById('setPanel_registration').classList.add('hidden');
        document.getElementById('setPanel_' + t).classList.remove('hidden'); 
        if (t === 'sub') loadSubAccounts(); 
        if (t === 'registration') loadRegistrationSettings();
    }

    async function loadRegistrationSettings() {
        const table = document.getElementById('registrationInviteTable');
        if (!table) return;
        const response = await apiFetch(INVENTORY_API_URL + '?action=get_registration_settings');
        const result = await response.json();
        if (result.status !== 'success') return alert(result.message || '无法读取注册设置');
        document.getElementById('registrationMode').value = result.data.mode;
        table.replaceChildren();
        if (!result.data.invitations.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 4;
            cell.className = 'p-5 text-center text-slate-400';
            cell.textContent = '暂时没有邀请码';
            row.appendChild(cell);
            table.appendChild(row);
            return;
        }
        const statusLabels = {available: '可使用', used: '已使用', revoked: '已撤销', expired: '已过期'};
        result.data.invitations.forEach(invite => {
            const row = document.createElement('tr');
            row.className = 'border-t border-slate-100';
            const values = [String(invite.id), statusLabels[invite.status] || '未知', invite.expires_at || '永不过期'];
            values.forEach(value => {
                const cell = document.createElement('td');
                cell.className = 'p-3 text-slate-600';
                cell.textContent = value;
                row.appendChild(cell);
            });
            const actionCell = document.createElement('td');
            actionCell.className = 'p-3 text-center';
            if (invite.status === 'available') {
                const button = document.createElement('button');
                button.className = 'text-red-600 font-bold hover:underline';
                button.textContent = '撤销';
                button.addEventListener('click', () => revokeRegistrationInvite(Number(invite.id)));
                actionCell.appendChild(button);
            } else {
                actionCell.textContent = '-';
            }
            row.appendChild(actionCell);
            table.appendChild(row);
        });
    }

    async function saveRegistrationMode() {
        const body = new FormData();
        body.append('action', 'set_registration_mode');
        body.append('mode', document.getElementById('registrationMode').value);
        const response = await apiFetch(INVENTORY_API_URL, {method: 'POST', body});
        const result = await response.json();
        if (result.status === 'success') alert('注册模式已保存并立即生效');
        else alert(result.message || '保存失败');
    }

    async function createRegistrationInvite() {
        const body = new FormData();
        body.append('action', 'create_invitation');
        body.append('expires_days', document.getElementById('inviteExpiresDays').value);
        const response = await apiFetch(INVENTORY_API_URL, {method: 'POST', body});
        const result = await response.json();
        if (result.status !== 'success') return alert(result.message || '生成失败');
        const codeBox = document.getElementById('newInviteCode');
        codeBox.textContent = '请立即复制（系统不会再次显示）：' + result.data.code;
        codeBox.classList.remove('hidden');
        await loadRegistrationSettings();
    }

    async function revokeRegistrationInvite(inviteId) {
        if (!(await sysConfirm('确定撤销这个尚未使用的邀请码吗？'))) return;
        const body = new FormData();
        body.append('action', 'revoke_invitation');
        body.append('invite_id', String(inviteId));
        const response = await apiFetch(INVENTORY_API_URL, {method: 'POST', body});
        const result = await response.json();
        if (result.status === 'success') await loadRegistrationSettings();
        else alert(result.message || '撤销失败');
    }
    
    async function saveSecQuestions() { 
        const fd = new FormData(); 
        fd.append('action', 'update_sec_questions'); 
        fd.append('pwd', document.getElementById('my_sec_pwd').value); 
        fd.append('q1', document.getElementById('my_q1').value); 
        fd.append('a1', document.getElementById('my_a1').value); 
        fd.append('q2', document.getElementById('my_q2').value); 
        fd.append('a2', document.getElementById('my_a2').value); 
        fd.append('q3', document.getElementById('my_q3').value); 
        fd.append('a3', document.getElementById('my_a3').value); 
        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if (j.status === 'success') { 
                alert('密保问题绑定成功！页面将刷新。'); 
                window.location.reload(); 
            } else { 
                alert(j.message); 
            } 
        } catch (e) { 
            alert("提交绑定失败，网络异常"); 
        } 
    }
    
    function saveMasterTabs() { 
    verifyLock(async () => {
        const fd = new FormData(); 
        fd.append('action', 'update_master_tabs'); 
        ['us', 'transit', 'cn', 'sold', 'repair', 'repair_done', 'parts', 'parts_sold'].forEach(x => { 
            if (document.getElementById('master_tab_' + x) && document.getElementById('master_tab_' + x).checked) fd.append('p_' + x, '1'); 
            if (document.getElementById('master_lock_' + x) && document.getElementById('master_lock_' + x).checked) fd.append('l_' + x, '1'); 
        }); 
        
        // 把输入的解锁密码传给后端
        const pwd = document.getElementById('verify_lock_input').value;
        fd.append('lock_pwd', pwd);

        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if (j.status === 'success') { 
                alert('系统全局配置已保存！将立即刷新生效。'); 
                window.location.reload(); 
            } else { 
                alert(j.message); 
                window.location.reload(); 
            } 
        } catch (e) { 
            alert("保存失败"); 
            window.location.reload(); 
        } 
    }, () => {
        // --- 核心修复：当用户取消输入或密码错误时，立即刷新页面，把刚才乱改的复选框强行恢复到数据库里的真实状态！ ---
        window.location.reload();
    });
}
    
    async function saveTimeout() { 
        const m = document.getElementById('my_timeout').value; 
        const fd = new FormData(); 
        fd.append('action', 'set_timeout'); 
        fd.append('minutes', m); 
        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json();
            if (j.status === 'success') {
                alert('自动锁定时间设置成功！'); 
                TIMEOUT_MINUTES = parseInt(m); 
                // 👇 核心修复：调用新的标签页活跃度函数，删掉旧的错误函数
                updateTabActivity(); 
            } else {
                alert(j.message || '设置失败');
            }
        } catch (e) { 
            alert("网络异常，设置失败"); 
        } 
    }
    
    async function changeMyPwd() { 
        const o = document.getElementById('my_old_pwd').value; 
        const n = document.getElementById('my_new_pwd').value; 
        const c = document.getElementById('my_new_pwd_confirm').value; 
        if (!o || !n || !c) return alert("密码框都不能为空！"); 
        if (n !== c) return alert("两次输入的新密码不一致，请重新输入！"); 
        const fd = new FormData(); 
        fd.append('action', 'change_my_password'); 
        fd.append('old_pwd', o); 
        fd.append('new_pwd', n); 
        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if (j.status === 'success') { 
                alert('登录密码修改成功！下次请使用新密码登录。'); 
                document.getElementById('my_old_pwd').value = ''; 
                document.getElementById('my_new_pwd').value = ''; 
                document.getElementById('my_new_pwd_confirm').value = ''; 
            } else { 
                alert(j.message); 
            } 
        } catch (e) { 
            alert("修改失败"); 
        } 
    }
    
    async function saveLockPwd() { 
        const o = document.getElementById('my_old_lock').value; 
        const n = document.getElementById('my_lock_pwd').value; 
        const fd = new FormData(); 
        fd.append('action', 'set_lock'); 
        fd.append('old_lock', o); 
        fd.append('new_pwd', n); 
        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if (j.status === 'success') { 
                alert('保险箱密码更新成功！'); 
                document.getElementById('my_old_lock').value = ''; 
                document.getElementById('my_lock_pwd').value = ''; 
                isFinanceUnlocked = false; 
                renderSummaryPanels(); 
                renderTable(); 
            } else { 
                alert(j.message); 
            } 
        } catch (e) { 
            alert("保存失败"); 
        } 
    }
    
    async function deleteMyAccount() { 
        const p = document.getElementById('my_delete_pwd').value; 
        if (!p) return alert("必须输入登录密码确认身份！"); 
        if (!confirm("⚠️ 危险警告：确认要永久注销并清空所有数据吗？此操作无法撤销！")) return; 
        if (!confirm("再次最后确认：数据一旦删除将永远丢失！真的要注销吗？")) return; 
        const fd = new FormData(); 
        fd.append('action', 'delete_my_account'); 
        fd.append('pwd', p); 
        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if (j.status === 'success') { 
                alert('账号已成功注销。再见。'); 
                window.location.href = 'login.php'; 
            } else { 
                alert(j.message); 
            } 
        } catch (e) { 
            alert("注销失败"); 
        } 
    }
    
    async function openLockRecModal() { 
        try { 
            const fd = new FormData(); 
            fd.append('action', 'get_sec_questions'); 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if (j.status === 'success') { 
                document.getElementById('lr_q1').innerText = j.data.sec_q1; 
                document.getElementById('lr_q2').innerText = j.data.sec_q2; 
                document.getElementById('lr_q3').innerText = j.data.sec_q3; 
                document.getElementById('lr_a1').value = ''; 
                document.getElementById('lr_a2').value = ''; 
                document.getElementById('lr_a3').value = ''; 
                document.getElementById('lr_login_pwd').value = ''; 
                document.getElementById('lr_new_lock').value = ''; 
                document.getElementById('lockRecModal').classList.remove('hidden'); 
                setTimeout(() => document.getElementById('lockRecContent').classList.add('modal-enter-active'), 10); 
            } else { 
                alert(j.message); 
            } 
        } catch (e) { 
            alert("网络异常，无法获取密保问题"); 
        } 
    }
    
    function closeLockRecModal() { 
        document.getElementById('lockRecContent').classList.remove('modal-enter-active'); 
        setTimeout(() => document.getElementById('lockRecModal').classList.add('hidden'), 200); 
    }
    
    async function submitLockRec() { 
        const a1 = document.getElementById('lr_a1').value.trim(); 
        const a2 = document.getElementById('lr_a2').value.trim(); 
        const a3 = document.getElementById('lr_a3').value.trim(); 
        if (!a1 || !a2 || !a3) return alert("必须回答全部三个密保问题！"); 
        const lp = document.getElementById('lr_login_pwd').value; 
        if (!lp) return alert("必须验证您的登录密码！"); 
        const fd = new FormData(); 
        fd.append('action', 'reset_lock_with_sec'); 
        fd.append('a1', a1); 
        fd.append('a2', a2); 
        fd.append('a3', a3); 
        fd.append('login_pwd', lp); 
        fd.append('new_lock', document.getElementById('lr_new_lock').value); 
        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if (j.status === 'success') { 
                alert('保险箱密码已强制重置成功！'); 
                closeLockRecModal(); 
                isFinanceUnlocked = false; 
                renderSummaryPanels(); 
                renderTable(); 
            } else { 
                alert(j.message); 
            } 
        } catch (e) { 
            alert("重置失败，网络异常"); 
        } 
    }
    
    function openSubLockRecModal() { 
        document.getElementById('sub_lr_login_pwd').value = ''; 
        document.getElementById('sub_lr_new_lock').value = ''; 
        document.getElementById('subLockRecModal').classList.remove('hidden'); 
        setTimeout(() => document.getElementById('subLockRecContent').classList.add('modal-enter-active'), 10); 
    }
    
    function closeSubLockRecModal() { 
        document.getElementById('subLockRecContent').classList.remove('modal-enter-active'); 
        setTimeout(() => document.getElementById('subLockRecModal').classList.add('hidden'), 200); 
    }
    
    async function submitSubLockRec() { 
        const lp = document.getElementById('sub_lr_login_pwd').value; 
        if (!lp) return alert("必须输入您的登录密码！"); 
        const fd = new FormData(); 
        fd.append('action', 'reset_sub_lock'); 
        fd.append('login_pwd', lp); 
        fd.append('new_lock', document.getElementById('sub_lr_new_lock').value); 
        try { 
            const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
            const j = await r.json(); 
            if (j.status === 'success') { 
                alert('您的保险箱密码已重置成功！'); 
                closeSubLockRecModal(); 
                isFinanceUnlocked = false; 
                renderSummaryPanels(); 
                renderTable(); 
            } else { 
                alert(j.message); 
            } 
        } catch (e) { 
            alert("重置失败，网络异常"); 
        } 
    }
    
    async function loadSubAccounts() { 
        const r = await apiFetch(INVENTORY_API_URL + '?action=list_sub_accounts'); 
        const j = await r.json(); 
        let h = ''; 
        if (j.data.length === 0) { 
            h = '<tr><td colspan="4" class="p-6 text-center text-slate-400 font-bold">暂无员工账户</td></tr>'; 
        } else { 
            subAccountsData = j.data; // <--- 关键修复：把返回的员工数据存到全局，防止 HTML 解析时卡死
            j.data.forEach(u => { 
                let histLabel = u.perm_history_view == 999 ? '全部' : (u.perm_history_view == 6 ? '半年' : '3个月'); 
                h += `<tr class="hover:bg-slate-50 transition-all">
                        <td class="p-3 border-b font-bold text-slate-700">${escapeHTML(u.username)}</td>
                        <td class="p-3 border-b text-center">${u.perm_finance == 1 ? '✅' : '❌'}</td>
                        <td class="p-3 border-b text-center text-sm text-slate-500">${histLabel}</td>
                        <td class="p-3 border-b text-center">
                            <button onclick="editSubAcc(${u.id})" class="text-[#8B0000] hover:underline px-2 text-sm font-bold transition-all">设置</button>
                            <button onclick="delSubAcc(${u.id})" class="text-red-500 hover:text-red-700 hover:underline px-2 text-sm font-bold transition-all">删除</button>
                        </td>
                      </tr>`; 
            }); 
        } 
        document.getElementById('subAccTable').innerHTML = h; 
    }
    
    function openSubModal() { 
        document.getElementById('sub_id').value = ''; 
        document.getElementById('sub_user').value = ''; 
        document.getElementById('sub_pass').value = ''; 
        ['fin', 'edt', 'dl', 'imp', 'add', 'exp', 'us', 'transit', 'cn', 'sold', 'parts', 'parts_sold', 'repair', 'repair_done'].forEach(x => {
            const cb = document.getElementById('sub_perm_' + x);
            if(cb) cb.checked = true;
        }); 
        document.getElementById('sub_perm_del').checked = false; 
        document.getElementById('sub_perm_hist').value = "999"; 
        document.getElementById('subModalTitle').innerText = '新增员工账户'; 
        document.getElementById('subModal').classList.remove('hidden'); 
        setTimeout(() => document.getElementById('subContent').classList.add('modal-enter-active'), 10); 
    }
    
    function closeSubModal() { 
        document.getElementById('subContent').classList.remove('modal-enter-active'); 
        setTimeout(() => document.getElementById('subModal').classList.add('hidden'), 200); 
    }
    
    function editSubAcc(id) { 
        const u = subAccountsData.find(x => x.id == id);
        if(!u) return;

        document.getElementById('sub_id').value = u.id; 
        document.getElementById('sub_user').value = u.username; 
        document.getElementById('sub_pass').value = ''; 
        document.getElementById('sub_perm_fin').checked = (u.perm_finance == 1); 
        document.getElementById('sub_perm_edt').checked = (u.perm_edit == 1); 
        document.getElementById('sub_perm_del').checked = (u.perm_delete == 1); 
        if(document.getElementById('sub_perm_dl')) document.getElementById('sub_perm_dl').checked = (u.perm_download_tpl == 1);
        if(document.getElementById('sub_perm_imp')) document.getElementById('sub_perm_imp').checked = (u.perm_import == 1);
        if(document.getElementById('sub_perm_add')) document.getElementById('sub_perm_add').checked = (u.perm_add == 1);
        if(document.getElementById('sub_perm_exp')) document.getElementById('sub_perm_exp').checked = (u.perm_export == 1);
        document.getElementById('sub_perm_us').checked = (u.perm_tab_us == 1); 
        document.getElementById('sub_perm_transit').checked = (u.perm_tab_transit == 1); 
        document.getElementById('sub_perm_cn').checked = (u.perm_tab_cn == 1); 
        document.getElementById('sub_perm_sold').checked = (u.perm_tab_sold == 1); 
        
        if(document.getElementById('sub_perm_parts')) document.getElementById('sub_perm_parts').checked = (u.perm_tab_parts == 1);
        if(document.getElementById('sub_perm_parts_sold')) document.getElementById('sub_perm_parts_sold').checked = (u.perm_tab_parts_sold == 1);
        if(document.getElementById('sub_perm_repair')) document.getElementById('sub_perm_repair').checked = (u.perm_tab_repair == 1);
        if(document.getElementById('sub_perm_repair_done')) document.getElementById('sub_perm_repair_done').checked = (u.perm_tab_repair_done == 1);
        
        document.getElementById('sub_perm_hist').value = u.perm_history_view || "999"; 
        document.getElementById('subModalTitle').innerText = '编辑员工配置'; 
        document.getElementById('subModal').classList.remove('hidden'); 
        setTimeout(() => document.getElementById('subContent').classList.add('modal-enter-active'), 10); 
    }
    
    async function saveSubAcc(e) { 
        e.preventDefault(); 
        
        // 将实际的保存动作封装起来
        const doSave = async (lockPwd = '') => {
            const fd = new FormData(); 
            fd.append('action', 'save_sub_account'); 
            fd.append('sub_id', document.getElementById('sub_id').value); 
            fd.append('sub_user', document.getElementById('sub_user').value); 
            fd.append('sub_pass', document.getElementById('sub_pass').value); 
            fd.append('p_hist', document.getElementById('sub_perm_hist').value); 
            fd.append('lock_pwd', lockPwd); // 将输入的保险箱密码传给后端
            
            ['fin', 'edt', 'del', 'dl', 'imp', 'add', 'exp', 'us', 'transit', 'cn', 'sold', 'parts', 'parts_sold', 'repair', 'repair_done'].forEach(x => { 
                const el = document.getElementById('sub_perm_' + x);
                if (el && el.checked) fd.append('p_' + x, '1'); 
            }); 
            try { 
                const r = await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
                const j = await r.json(); 
                if (j.status === 'success') { 
                    closeSubModal(); 
                    loadSubAccounts(); 
                } else { 
                    alert(j.message); 
                } 
            } catch (er) { 
                alert("保存失败"); 
            }
        };

        // === 新增：检查是否勾选/保留了“查看财务”权限 ===
        const isFinChecked = document.getElementById('sub_perm_fin') && document.getElementById('sub_perm_fin').checked;
        if (isFinChecked) {
            // 如果要授权财务权限，必须强制调用系统的 verifyLock 弹窗进行密码验证
            verifyLock(() => {
                const pwd = document.getElementById('verify_lock_input').value;
                doSave(pwd);
            });
        } else {
            // 如果不涉及财务权限，则可以直接正常保存，不弹窗打扰
            doSave();
        }
    }
    
    async function delSubAcc(id) { 
        if (!confirm("确定要删除该员工吗？")) return; 
        const fd = new FormData(); 
        fd.append('action', 'delete_sub_account'); 
        fd.append('sub_id', id); 
        await apiFetch(INVENTORY_API_URL, {method: 'POST', body: fd}); 
        loadSubAccounts(); 
    }
    
    function downloadTemplate() { 
        if (PERM_DOWNLOAD_TPL === 0) return alert('安全拦截：您没有下载模板的权限！');
        const hd = ["入库日期", "批次", "服务编号", "配置", "数量", "状态", "单件成本", "单件运费", "收货人", "备注", "单件收款金额"]; 
        const r = ["2026-04-23", "第一批", "SV2026-001", "配置信息", "1", "美国仓", "20000", "500", "张三", "顺丰包邮", "0"]; 
        const ws = XLSX.utils.aoa_to_sheet([hd, r]); 
        const wb = XLSX.utils.book_new(); 
        XLSX.utils.book_append_sheet(wb, ws, "模板"); 
        XLSX.writeFile(wb, "进销存批量导入模板.xlsx"); 
    }
    
    function handleExcelUpload(e) { 
        const f = e.target.files[0]; 
        if (!f) return; 
        const rd = new FileReader(); 
        rd.onload = async function(ev) { 
            try { 
                const dt = new Uint8Array(ev.target.result); 
                const wb = XLSX.read(dt, {type: 'array'}); 
                const ws = wb.Sheets[wb.SheetNames[0]]; 
                const j = XLSX.utils.sheet_to_json(ws, {defval: ""}); 
                if (j.length === 0) return; 
                const url = `${INVENTORY_API_URL}?action=import&unlocked=${isFinanceUnlocked ? '1' : '0'}`;
                const r = await apiFetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(j)
        });
        const res = await r.json(); 
                alert(res.message); 
                loadData(); 
            } catch (er) { 
                alert("文件解析失败！"); 
            } 
            e.target.value = ''; 
        }; 
        rd.readAsArrayBuffer(f); 
    }
    
    function exportToExcel() { 
        if (PERM_EXPORT === 0) return alert('安全拦截：您没有导出数据的权限！');
        if (currentData.length === 0) return; 
        const sm = {'US': '美国仓', 'TRANSIT': '国外途中', 'CN_WH': '国内仓', 'SOLD': '已售', 'PARTS': '零配件仓', 'PARTS_SOLD': '已售配件', 'REPAIR': '售后维修', 'REPAIR_DONE': '维修完毕'}; 
        let isTabLocked = (LOCK_TABS[currentTab] === 1 && !isFinanceUnlocked); 
        let sF = (PERM_FINANCE === 1) && !isTabLocked; 
        let isPartsView = ['PARTS', 'PARTS_SOLD'].includes(currentTab);
        
        const ed = currentData.map(r => { 
            let qty = parseInt(r.quantity) || 1; 
            let o = { 
                "入库日期": r.status_date || '', 
                "批次": r.batch_no || '', 
                "编号": r.service_no, 
                "配置": r.config_desc
            }; 
            
            if (isPartsView) {
                o["数量"] = qty; 
            }
            o["状态"] = sm[r.status] || r.status;
            
            if (sF) { 
                o[isPartsView ? "单件成本" : "成本"] = isPartsView ? (parseFloat(r.cost_rmb) / qty).toFixed(2) : parseFloat(r.cost_rmb); 
                o[isPartsView ? "单件运费" : "运费"] = isPartsView ? (parseFloat(r.freight) / qty).toFixed(2) : parseFloat(r.freight); 
            } 
            o["收货人"] = r.receiver; 
            o["备注"] = r.remarks; 
            o[isPartsView ? "单件收款金额" : "收款金额"] = isPartsView ? (parseFloat(r.collected_amount) / qty).toFixed(2) : parseFloat(r.collected_amount); 
            
            if (sF) { 
                let isSoldView = ['SOLD', 'PARTS_SOLD', 'REPAIR_DONE'].includes(currentTab);
                o[isSoldView ? "总利润" : "沉没总成本"] = isSoldView ? parseFloat(r.profit) || 0 : parseFloat(r.cost_rmb) + parseFloat(r.freight); 
            } 
            return o; 
        }); 
        
        const ws = XLSX.utils.json_to_sheet(ed); 
        const wb = XLSX.utils.book_new(); 
        XLSX.utils.book_append_sheet(wb, ws, "数据"); 
        XLSX.writeFile(wb, `数据导出_${new Date().getTime()}.xlsx`); 
    }
    
    function updateDateTime() { 
        const now = new Date(); 
        const days = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']; 
        const month = now.getMonth() + 1; 
        const date = String(now.getDate()).padStart(2, '0'); 
        const day = days[now.getDay()]; 
        const hours = String(now.getHours()).padStart(2, '0'); 
        const minutes = String(now.getMinutes()).padStart(2, '0'); 
        const elTime = document.getElementById('widget_datetime'); 
        if(elTime) { 
            elTime.innerText = `${month}月${date}日 ${day} ${hours}:${minutes}`; 
        } 
    }
    
    setInterval(updateDateTime, 1000); 
    if(document.readyState === 'complete') { 
        updateDateTime(); 
    } else { 
        window.addEventListener('DOMContentLoaded', updateDateTime); 
    }
    
    // === ⚡ 性能版静默刷新 (极低服务器开销) ===
    async function silentRefresh() { 
        if(currentTab === 'NONE') return; 
        
        // 🛡️ 核心防御：离开座位超过 60 秒，或屏幕已锁定，立刻停止偷偷刷新
        let isLocked = sessionStorage.getItem('sys_tab_locked') === '1' || localStorage.getItem('sys_global_locked') === '1';
        if (isLocked || (Date.now() - tabLastActive > 60000)) return;
        
        const itemModal = document.getElementById('itemModal');
        if (!itemModal) return;

        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length; 
        const isItemModalOpen = !itemModal.classList.contains('hidden'); 
        const isBatchModalOpen = !document.getElementById('batchEditModal').classList.contains('hidden'); 
        const isSetModalOpen = !document.getElementById('settingsModal').classList.contains('hidden'); 
        const dispatchModal = document.getElementById('dispatchModal');
        const isDispatchModalOpen = dispatchModal && !dispatchModal.classList.contains('hidden');
        
        if (checkedCount > 0 || isItemModalOpen || isBatchModalOpen || isSetModalOpen || isDispatchModalOpen) return; 
        
        try { 
            // ⚡ 性能核心：先用轻量级探针查询数据库有没有发生变动
            const checkRes = await apiFetch(`${INVENTORY_API_URL}?action=check_update`);
            const checkJson = await checkRes.json();
            
            if (checkJson.status === 'success') {
                // 如果是第一次运行，或者别人操作导致时间戳变了，才去拉取全量数据
                if (lastKnownUpdate === null || checkJson.last_update !== lastKnownUpdate) {
                    lastKnownUpdate = checkJson.last_update;
                    
                    const params = new URLSearchParams({ 
                        action: 'list', 
                        status: currentTab, 
                        keyword: currentSearchTerm, 
                        dateFilter: currentDateFilter || '', 
                        page: currentPage, 
                        limit: ITEMS_PER_PAGE, 
                        unlocked: isFinanceUnlocked ? '1' : '0' 
                    }); 
                    
                    const r = await apiFetch(`${INVENTORY_API_URL}?${params.toString()}`); 
                    const j = await r.json(); 
                    
                    if (j.status === 'success') { 
                        currentData = j.data; 
                        totalFilteredItems = parseInt(j.total); 
                        summaryData = j.summary; 
                        renderSummaryPanels(); 
                        renderTable(); 
                    } 
                }
            }
        } catch (e) {} 
    }
    
    // 配合探针机制，在手动加载数据后同步更新一下时间戳
    const originalLoadData = loadData;
    loadData = async function() {
        await originalLoadData();
        try {
            const r = await apiFetch(`${INVENTORY_API_URL}?action=check_update`);
            const j = await r.json();
            if (j.status === 'success') lastKnownUpdate = j.last_update;
        } catch(e) {}
    }

    setInterval(silentRefresh, 10000); // 这是原有的代码

    // === 🎨 注入高级 UI：Toast 弹窗、Confirm 模态框、数字框全局一键全选 ===
    document.body.insertAdjacentHTML('beforeend', `
        <div id="sysToast" class="fixed top-5 left-1/2 -translate-x-1/2 z-[9999] transition-all duration-300 opacity-0 -translate-y-full pointer-events-none flex items-center gap-2 px-5 py-3 rounded-2xl shadow-2xl font-bold text-sm tracking-wide backdrop-blur-md"></div>
        <div id="sysConfirm" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[9999] hidden flex items-center justify-center p-4 opacity-0 transition-opacity duration-200">
            <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden transform scale-95 transition-transform duration-200" id="sysConfirmBox">
                <div class="p-6 text-center">
                    <div class="w-14 h-14 rounded-full bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-4"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg></div>
                    <h3 class="font-black text-lg text-slate-800 mb-2">操作确认</h3>
                    <p class="text-sm text-slate-500 font-medium leading-relaxed" id="sysConfirmMsg"></p>
                </div>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                    <button id="sysConfirmCancel" class="px-5 py-2.5 text-sm bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:bg-slate-50 shadow-sm transition-all">取消</button>
                    <button id="sysConfirmOk" class="px-5 py-2.5 text-sm font-bold text-white bg-red-600 rounded-xl shadow-sm hover:bg-red-700 hover:shadow-md transition-all tracking-wider">确认执行</button>
                </div>
            </div>
        </div>
    `);

    // 核心 Toast 函数
    window.sysToast = function(msg, type = 'error') {
        const t = document.getElementById('sysToast');
        if(type === 'success') {
            t.className = 'fixed top-5 left-1/2 -translate-x-1/2 z-[9999] transition-all duration-300 flex items-center gap-2 px-5 py-3 rounded-full shadow-lg font-bold text-sm tracking-wide bg-emerald-50 text-emerald-700 border border-emerald-200 translate-y-0 opacity-100';
            t.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
        } else {
            t.className = 'fixed top-5 left-1/2 -translate-x-1/2 z-[9999] transition-all duration-300 flex items-center gap-2 px-5 py-3 rounded-full shadow-lg font-bold text-sm tracking-wide bg-red-50 text-red-600 border border-red-200 translate-y-0 opacity-100';
            t.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
        }
        const message = document.createElement('span');
        message.textContent = String(msg);
        t.appendChild(message);
        setTimeout(() => { t.classList.add('opacity-0', '-translate-y-full', 'pointer-events-none'); t.classList.remove('translate-y-0', 'opacity-100'); }, 3000);
    };

    // 劫持系统的 alert，让全站所有原生警告秒变 Toast！
    window.alert = function(msg) {
        if(msg.includes('成功') || msg.includes('生效')) sysToast(msg, 'success');
        else sysToast(msg, 'error');
    };

    // 异步高颜值 Confirm 框
    window.sysConfirm = function(msg) {
        return new Promise(resolve => {
            const modal = document.getElementById('sysConfirm');
            const box = document.getElementById('sysConfirmBox');
            document.getElementById('sysConfirmMsg').innerText = msg;
            modal.classList.remove('hidden');
            setTimeout(() => { modal.classList.add('opacity-100'); box.classList.remove('scale-95'); }, 10);

            const close = (val) => {
                modal.classList.remove('opacity-100'); box.classList.add('scale-95');
                setTimeout(() => modal.classList.add('hidden'), 200);
                btnOk.onclick = null; btnCancel.onclick = null;
                resolve(val);
            };
            const btnOk = document.getElementById('sysConfirmOk');
            const btnCancel = document.getElementById('sysConfirmCancel');
            btnOk.onclick = () => close(true);
            btnCancel.onclick = () => close(false);
        });
    };

    // 🔥 微交互：全局接管所有数字输入框，点击瞬间一键全选
    document.addEventListener('focusin', (e) => {
        if (e.target && e.target.type === 'number') e.target.select();
    });
</script>
</body>
</html>
