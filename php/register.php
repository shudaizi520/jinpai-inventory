<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/registration.php';

$error = '';
$success = '';
$registrationMode = registration_mode($pdo);
$registrationClosed = !registration_is_available($registrationMode);

if ($registrationClosed) {
    http_response_code(403);
    $error = '系统当前已关闭新账号注册，请联系管理员。';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        register_primary_account(
            $pdo,
            $_POST,
            client_ip($_SERVER, app_config_list('TRUSTED_PROXIES'))
        );
        $success = '注册成功！您的账号和密保信息已安全保存。';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $requestId = safe_log($exception);
        $error = '注册暂时无法完成，请稍后重试。请求编号：' . $requestId;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>注册 - 金牌卖家进销存</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238B0000' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22'></path></svg>">
    <script src="assets/tailwindcss.js"></script>
    <style>
        input::placeholder { color: #94a3b8; font-size: 14px; }
        /* 隐藏滚动条以保持页面整洁 */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="flex flex-col items-center justify-center min-h-screen relative overflow-x-hidden bg-slate-100 font-sans py-10">

    <div class="fixed inset-0 z-0">
        <img src="https://images.unsplash.com/photo-1497366216548-37526070297c?auto=format&fit=crop&q=80" class="w-full h-full object-cover filter blur-[6px] scale-105 opacity-50" alt="background">
        <div class="absolute inset-0 bg-slate-200/30 mix-blend-multiply"></div>
    </div>

    <div class="bg-white px-10 py-10 rounded-[28px] shadow-2xl w-[480px] z-10 relative">
        <div class="text-center mb-8">
            <div class="flex justify-center mb-3">
                <svg stroke-linejoin="round" stroke-linecap="round" stroke-width="2" stroke="currentColor" fill="none" viewBox="0 0 24 24" class="w-9 h-9 text-[#8B0000]">
                    <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"></path>
                </svg>
            </div>
            <h2 class="text-[20px] font-black text-[#8B0000] tracking-wider">创建独立主账户</h2>
        </div>

        <?php if($error): ?>
            <div class="bg-red-50 text-red-600 border border-red-100 p-3 rounded-xl mb-6 text-sm font-bold shadow-sm text-center">
                <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <?php if($registrationClosed): ?>
            <a href="login.php" class="block w-full text-center bg-[#8B0000] text-white font-bold py-3.5 rounded-xl hover:bg-[#600000] shadow-lg shadow-red-900/20 transition-all text-[15px] tracking-widest">返回登录</a>
        <?php elseif($success): ?>
            <div class="bg-emerald-50 text-emerald-700 border border-emerald-200 p-4 rounded-xl mb-6 text-center font-bold shadow-sm">🎉 <?php echo e($success); ?></div>
            <a href="login.php" class="block w-full text-center bg-[#8B0000] text-white font-bold py-3.5 rounded-xl hover:bg-[#600000] shadow-lg shadow-red-900/20 transition-all text-[15px] tracking-widest">立即前往登录</a>
        <?php else: ?>
            <form method="POST" class="flex flex-col gap-5">

                <div class="space-y-4">
                    <div>
                        <input type="text" name="username" required placeholder="请设置系统用户名" autocomplete="off" class="w-full bg-[#F8F9FA] border border-slate-200 rounded-xl px-4 py-3.5 focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none transition-all text-slate-800 font-medium text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <input type="password" name="password" minlength="12" maxlength="128" required autocomplete="new-password" placeholder="设置登录密码（至少12位）" class="w-full bg-[#F8F9FA] border border-slate-200 rounded-xl px-4 py-3.5 focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none transition-all text-slate-800 font-medium text-sm">
                        </div>
                        <div>
                            <input type="password" name="password_confirm" minlength="12" maxlength="128" required autocomplete="new-password" placeholder="再次确认密码" class="w-full bg-[#F8F9FA] border border-slate-200 rounded-xl px-4 py-3.5 focus:bg-white focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none transition-all text-slate-800 font-medium text-sm">
                        </div>
                    </div>
                    <?php if ($registrationMode === 'invite'): ?>
                        <div>
                            <input type="text" name="invite_code" required autocomplete="off" maxlength="64" placeholder="请输入管理员提供的邀请码" class="w-full bg-[#FFF7ED] border border-orange-200 rounded-xl px-4 py-3.5 focus:bg-white focus:border-orange-500 focus:ring-1 focus:ring-orange-500 outline-none transition-all text-slate-800 font-medium text-sm uppercase">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="bg-slate-50 border border-slate-200 p-5 rounded-2xl space-y-4 mt-2">
                    <h3 class="text-[13px] font-black text-[#8B0000] mb-3 flex items-center gap-1.5 tracking-wide">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        设置安全密保 (用于找回密码)
                    </h3>

                    <div>
                        <select name="q1" class="w-full text-xs font-bold text-slate-500 bg-[#F8F9FA] border border-slate-200 border-b-0 rounded-t-xl px-3 py-2 outline-none focus:border-[#8B0000] transition-all">
                            <option value="您出生的城市是哪里？">问题1：您出生的城市是哪里？</option>
                            <option value="您小学班主任的名字是？">问题1：您小学班主任的名字是？</option>
                            <option value="您的初恋名字是？">问题1：您的初恋名字是？</option>
                            <option value="您第一份工作的公司名是？">问题1：您第一份工作的公司名是？</option>
                            <option value="您最喜欢的明星/偶像名字是？">问题1：您最喜欢的明星/偶像名字是？</option>
                            <option value="您的家乡所在省份是？">问题1：您的家乡所在省份是？</option>
                        </select>
                        <input type="text" name="a1" required placeholder="输入答案" class="w-full text-sm bg-white border border-slate-200 rounded-b-xl px-4 py-2.5 focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none relative z-10 transition-all text-slate-800 font-medium">
                    </div>

                    <div>
                        <select name="q2" class="w-full text-xs font-bold text-slate-500 bg-[#F8F9FA] border border-slate-200 border-b-0 rounded-t-xl px-3 py-2 outline-none focus:border-[#8B0000] transition-all">
                            <option value="您父亲的姓名是？">问题2：您父亲的姓名是？</option>
                            <option value="您人生中第一辆车的品牌是？">问题2：您人生中第一辆车的品牌是？</option>
                            <option value="您母亲的姓名是？">问题2：您母亲的姓名是？</option>
                            <option value="您最喜欢的宠物名字是？">问题2：您最喜欢的宠物名字是？</option>
                            <option value="您的高中校名是？">问题2：您的高中校名是？</option>
                            <option value="您的学号/工号是？">问题2：您的学号/工号是？</option>
                        </select>
                        <input type="text" name="a2" required placeholder="输入答案" class="w-full text-sm bg-white border border-slate-200 rounded-b-xl px-4 py-2.5 focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none relative z-10 transition-all text-slate-800 font-medium">
                    </div>

                    <div>
                        <select name="q3" class="w-full text-xs font-bold text-slate-500 bg-[#F8F9FA] border border-slate-200 border-b-0 rounded-t-xl px-3 py-2 outline-none focus:border-[#8B0000] transition-all">
                            <option value="您最好朋友的名字是？">问题3：您最好朋友的名字是？</option>
                            <option value="您最喜欢的一部电影是？">问题3：您最喜欢的一部电影是？</option>
                            <option value="您最喜欢的食物是？">问题3：您最喜欢的食物是？</option>
                            <option value="您的大学室友名字是？">问题3：您的大学室友名字是？</option>
                            <option value="您的伴侣生日是？">问题3：您的伴侣生日是？</option>
                            <option value="您最想去的国家是？">问题3：您最想去的国家是？</option>
                        </select>
                        <input type="text" name="a3" required placeholder="输入答案" class="w-full text-sm bg-white border border-slate-200 rounded-b-xl px-4 py-2.5 focus:border-[#8B0000] focus:ring-1 focus:ring-[#8B0000] outline-none relative z-10 transition-all text-slate-800 font-medium">
                    </div>
                </div>

                <button type="submit" class="w-full bg-[#8B0000] text-white font-bold py-3.5 rounded-xl hover:bg-[#600000] shadow-lg shadow-red-900/20 transition-all mt-2 text-[15px] tracking-widest">
                    立即注册并保存
                </button>
            </form>

            <div class="mt-6 text-center text-xs font-medium text-slate-400">
                已有账户？ <a href="login.php" class="text-[#8B0000] font-bold hover:underline transition-colors">返回登录</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="relative mt-8 z-10 text-[13px] font-medium text-slate-500 drop-shadow-sm tracking-wide">
        © 2026 金牌卖家进销存系统
    </div>

</body>
</html>
