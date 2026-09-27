<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

header('Content-Type: application/json');

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
try {
    enforce_api_action_method($action, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require_csrf();
    }
} catch (HttpException $exception) {
    json_response(['status' => 'error', 'message' => $exception->getMessage()], $exception->statusCode());
}

$user_id = $_SESSION['user_id'] ?? 0;
if (!$user_id) { json_response(['status' => 'error', 'message' => '登录状态失效'], 401); }

$parent_id = $_SESSION['parent_id'] ?? 0;
$owner_id = ($parent_id > 0) ? $parent_id : $user_id;

// --- 【核心修复】：消除幽灵会话，每次请求实时查询数据库验证最新权限 ---
$stmtUser = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$user_id]);
$currUser = $stmtUser->fetch(PDO::FETCH_ASSOC);

if (!$currUser) { json_response(['status' => 'error', 'message' => '登录状态失效'], 401); }

$perm_finance = intval($currUser['perm_finance'] ?? 1);
$perm_edit = intval($currUser['perm_edit'] ?? 1);
$perm_delete = intval($currUser['perm_delete'] ?? 1);
$perm_download_tpl = intval($currUser['perm_download_tpl'] ?? 1);
$perm_import = intval($currUser['perm_import'] ?? 1);
$perm_add = intval($currUser['perm_add'] ?? 1);
$perm_export = intval($currUser['perm_export'] ?? 1);
$perm_history_view = intval($currUser['perm_history_view'] ?? 999);

// 提前提取主账号 (Owner) 数据，用于判断各个仓库的独立保险箱锁
$stmtOwner = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtOwner->execute([$owner_id]);
$ownerData = $stmtOwner->fetch(PDO::FETCH_ASSOC);
if (!$ownerData) { json_response(['status' => 'error', 'message' => '登录状态失效'], 401); }
// -----------------------------------------------------------

// 修复：全局定义状态锁映射，供所有 API (特别是 import) 共享，防止变量未定义被绕过
$statusLockMap = [
    'US' => 'lock_tab_us', 'TRANSIT' => 'lock_tab_transit', 'CN_WH' => 'lock_tab_cn', 
    'SOLD' => 'lock_tab_sold', 'PARTS' => 'lock_tab_parts', 'PARTS_SOLD' => 'lock_tab_parts_sold', 
    'REPAIR' => 'lock_tab_repair', 'REPAIR_DONE' => 'lock_tab_repair_done'
];

// === 🚨 后端绝对防御：防止 F12 绕过前端锁屏抓取 API 数据 ===
$timeout_minutes = intval($currUser['timeout_minutes'] ?? 20);
// ⚠️ 注意：必须放行 'verify_login'，否则锁屏后员工连密码都发不进来了
// ⚠️ 注意：必须放行 'verify_login' 和 'relock'，否则锁屏请求发来会直接销毁掉整个会话！
if ($timeout_minutes > 0 && !in_array($action, ['verify_login', 'relock'])) {
    $now = time();
    if (isset($_SESSION['last_active_time']) && ($now - $_SESSION['last_active_time'] > $timeout_minutes * 60)) {
        session_unset();
        session_destroy();
        echo json_encode(['status' => 'error', 'message' => '登录状态失效']);
        exit;
    }
    // 只要有合法的请求，统一刷新服务器端的活跃时间
    $_SESSION['last_active_time'] = $now;
}
// ====================================================================

// --- 接收前端保活心跳 ---
if ($action === 'heartbeat') {
    // 上面已经统一刷新过时间了，这里直接返回成功即可
    echo json_encode(['status' => 'success']);
    exit;
}

// --- ⚡ 性能提升：轻量级更新探针，拒绝无效的重型查询 ---
// --- ⚡ 性能提升：轻量级更新探针，拒绝无效的重型查询 ---
if ($action === 'check_update') {
    $stmt = $pdo->prepare("SELECT CONCAT(COUNT(id), '_', IFNULL(MAX(updated_at), '0')) FROM inventory_items WHERE user_id = ?");
    $stmt->execute([$owner_id]);
    echo json_encode(['status' => 'success', 'last_update' => $stmt->fetchColumn()]);
    exit;
}
// ---------------------------------

try {
    if ($action === 'list') {
        $status = $_GET['status'] ?? 'US';
        
        // --- 【安全修复4】：强制后端鉴权，拦截无权限员工强行抓包读取不该看的仓库数据 ---
        $permMap = ['US'=>'perm_tab_us', 'TRANSIT'=>'perm_tab_transit', 'CN_WH'=>'perm_tab_cn', 'SOLD'=>'perm_tab_sold', 'PARTS'=>'perm_tab_parts', 'PARTS_SOLD'=>'perm_tab_parts_sold', 'REPAIR'=>'perm_tab_repair', 'REPAIR_DONE'=>'perm_tab_repair_done'];
        $permCol = $permMap[$status] ?? '';
        if ($parent_id > 0 && $permCol && empty($currUser[$permCol])) {
            echo json_encode(['status' => 'error', 'message' => '越权访问拦截：您没有权限读取该仓库数据！']);
            exit;
        }
        // --------------------------------------------------------

        $keyword = $_GET['keyword'] ?? '';
        $dateFilter = $_GET['dateFilter'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = max(1, intval($_GET['limit'] ?? 50));
        $offset = ($page - 1) * $limit;
        
        $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
        $date_condition = "";

        // 改为直接读取已提取好的主账号锁定状态
        $lock_tab_sold = $ownerData['lock_tab_sold'] ?? 0;
        $lock_tab_parts_sold = $ownerData['lock_tab_parts_sold'] ?? 0; // 【安全修复8】：新增单独读取已售配件仓的锁

        if ($status === 'SOLD' || $status === 'PARTS_SOLD') {
            // 【安全修复8】：精准匹配对应的锁，防止看配件仓的时候读错锁导致历史销量完全泄露
            $current_lock = ($status === 'SOLD') ? $lock_tab_sold : $lock_tab_parts_sold;
            
            if ($current_lock == 1 && !$unlocked) {
                $date_condition = " AND status_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)";
            } else {
                if ($parent_id > 0 && $perm_history_view != 999 && $perm_history_view > 0) {
                    $date_condition = " AND status_date >= DATE_SUB(CURRENT_DATE(), INTERVAL " . $perm_history_view . " MONTH)";
                }
            }
        }

        $summary = ['tc' => 0, 'tf' => 0, 'total_count' => 0, 'tp' => 0, 'years' => [], 'months' => []];
        $baseWhere = "WHERE status = ? AND user_id = ?" . $date_condition; 
        
        $stmtTotalStats = $pdo->prepare("SELECT SUM(quantity) as total_count, SUM(cost_rmb) as tc, SUM(freight) as tf, SUM(collected_amount - cost_rmb - freight) as tp FROM inventory_items $baseWhere");
        $stmtTotalStats->execute([$status, $owner_id]);
        $totalStats = $stmtTotalStats->fetch(PDO::FETCH_ASSOC);

        if ($totalStats && $totalStats['total_count'] > 0) {
            $summary['total_count'] = (int)$totalStats['total_count'];
            $summary['tc'] = (float)$totalStats['tc'];
            $summary['tf'] = (float)$totalStats['tf'];
            $summary['tp'] = (float)$totalStats['tp'];

            // ⚡ 性能提升：改用原生截取，避开日期格式化函数导致的全表扫索引失效
            $stmtGroup = $pdo->prepare("SELECT LEFT(status_date, 4) as y, LEFT(status_date, 7) as m, SUM(quantity) as c, SUM(collected_amount - cost_rmb - freight) as p FROM inventory_items $baseWhere GROUP BY m, y ORDER BY m DESC");
            $stmtGroup->execute([$status, $owner_id]);
            
            while ($row = $stmtGroup->fetch(PDO::FETCH_ASSOC)) {
                $yKey = $row['y'] ? $row['y'] . '年' : '历史未知年';
                $mKey = $row['m'] ? str_replace('-', '年', $row['m']) . '月' : '历史未知月';
                
                if (!isset($summary['years'][$yKey])) $summary['years'][$yKey] = ['count' => 0, 'profit' => 0];
                if (!isset($summary['months'][$mKey])) $summary['months'][$mKey] = ['count' => 0, 'profit' => 0];
                
                $summary['years'][$yKey]['count'] += (int)$row['c'];
                $summary['years'][$yKey]['profit'] += (float)$row['p'];
                $summary['months'][$mKey]['count'] += (int)$row['c'];
                $summary['months'][$mKey]['profit'] += (float)$row['p'];
            }
        }

        $where = $baseWhere;
        $params = [$status, $owner_id];

        if ($keyword !== '') {
            $where .= " AND (service_no LIKE ? OR receiver LIKE ? OR batch_no LIKE ? OR config_desc LIKE ? OR remarks LIKE ? OR status_date LIKE ?)";
            $kw = "%$keyword%";
            array_push($params, $kw, $kw, $kw, $kw, $kw, $kw);
        }

        if ($dateFilter !== '') {
            $df = str_replace(['年', '月'], ['-', ''], $dateFilter);
            $where .= " AND status_date LIKE ?";
            $params[] = "$df%";
        }

        $stmtTotal = $pdo->prepare("SELECT COUNT(id) FROM inventory_items $where");
        $stmtTotal->execute($params);
        $filteredTotal = $stmtTotal->fetchColumn();

        $orderBy = ($status === 'SOLD' || $status === 'REPAIR' || $status === 'REPAIR_DONE' || $status === 'PARTS_SOLD') ? "ORDER BY status_date DESC, status_timestamp DESC, id DESC" : "ORDER BY status_date ASC, status_timestamp ASC, id ASC";
        $stmt = $pdo->prepare("SELECT *, (collected_amount - cost_rmb - freight) as profit FROM inventory_items $where $orderBy LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- 【核心修复】：执行 API 后端强制脱敏，切断 F12 抓包泄露 ---
        $statusLockMap = [
            'US' => 'lock_tab_us', 'TRANSIT' => 'lock_tab_transit', 'CN_WH' => 'lock_tab_cn', 
            'SOLD' => 'lock_tab_sold', 'PARTS' => 'lock_tab_parts', 'PARTS_SOLD' => 'lock_tab_parts_sold', 
            'REPAIR' => 'lock_tab_repair', 'REPAIR_DONE' => 'lock_tab_repair_done'
        ];
        $lock_col = $statusLockMap[$status] ?? '';
        $is_tab_locked = $lock_col ? (($ownerData[$lock_col] ?? 0) == 1) : false;
        
        $can_view_finance = ($perm_finance == 1) && !($is_tab_locked && !$unlocked);

        if (!$can_view_finance) {
            foreach ($data as &$row) {
                $row['cost_rmb'] = '***';
                $row['freight'] = '***';
                $row['profit'] = '***';
            }
            unset($row);
            
            $summary['tc'] = '***';
            $summary['tf'] = '***';
            $summary['tp'] = '***';
            if (isset($summary['years'])) {
                foreach ($summary['years'] as &$y) { $y['profit'] = '***'; }
            }
            if (isset($summary['months'])) {
                foreach ($summary['months'] as &$m) { $m['profit'] = '***'; }
            }
        }
        // ----------------------------------------------------

        echo json_encode(['status' => 'success', 'data' => $data, 'total' => $filteredTotal, 'summary' => $summary]);
    } 
    elseif ($action === 'batch_update_status') {
        if ($perm_edit == 0) throw new Exception("权限不足");
        $ids = explode(',', $_POST['ids'] ?? '');
        $new_status = $_POST['status'] ?? '';
        if (!empty($ids) && $new_status) {
            $inQuery = implode(',', array_fill(0, count($ids), '?'));
            
            // --- 核心修复：批量流转锁校验 ---
            $stmtStatus = $pdo->prepare("SELECT DISTINCT status FROM inventory_items WHERE id IN ($inQuery) AND user_id = ?");
            $stmtStatus->execute(array_merge($ids, [$owner_id]));
            $batch_statuses = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);
            $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
            
            foreach ($batch_statuses as $batch_status) {
                $source_lock = $statusLockMap[$batch_status] ?? '';
                $target_lock = $statusLockMap[$new_status] ?? '';
                // 彻底放开：批量流转和退回不再受财务锁限制
//                if ((($ownerData[$source_lock] ?? 0) == 1 || ($ownerData[$target_lock] ?? 0) == 1) && !$unlocked) {
//                    throw new Exception("安全拦截：选定批次涉及已锁定财务的仓库，请先解锁后再流转！");
//                }
            }
            // ------------------------------------------------

            // 防绕过：强制拦截无资料设备进入已售仓
            if (in_array($new_status, ['SOLD', 'PARTS_SOLD'])) {
                $checkParams = array_merge($ids, [$owner_id]);
                $stmtCheck = $pdo->prepare("SELECT id FROM inventory_items WHERE id IN ($inQuery) AND user_id = ? AND (receiver IS NULL OR receiver = '' OR collected_amount <= 0)");
                $stmtCheck->execute($checkParams);
                if ($stmtCheck->fetchColumn()) {
                    throw new Exception("安全拦截：部分设备缺失收货人或收款金额，拒绝流转至已售！");
                }
            }

            $params = array_merge([$new_status], $ids, [$owner_id]);
            $stmt = $pdo->prepare("UPDATE inventory_items SET status = ?, status_date = CURRENT_DATE(), status_timestamp = CURRENT_TIMESTAMP() WHERE id IN ($inQuery) AND user_id = ?");
            $stmt->execute($params);
        }
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'batch_edit') {
        if ($perm_edit == 0) throw new Exception("权限不足");

        // 🚀 核心修复：安全拦截，防止越权抓包批量修改财务数据（增加保险箱锁校验）
        $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
        $ids = array_filter(explode(',', $_POST['ids'] ?? ''));

        // === 修复：收款金额是业务销售数据，不应受财务权限和仓库锁的拦截 ===
        if (isset($_POST['update_cost']) || isset($_POST['update_freight'])) {
            if ($perm_finance == 0) throw new Exception("安全拦截：您没有修改财务数据的权限！");
            
            // 校验当前这批设备所在的仓库是否被锁
            if (!empty($ids)) {
                $inQueryCheck = implode(',', array_fill(0, count($ids), '?'));
                // 修复：去除 LIMIT 1 抽样漏洞，检索该批次跨越的所有独立状态
                $stmtStatus = $pdo->prepare("SELECT DISTINCT status FROM inventory_items WHERE id IN ($inQueryCheck) AND user_id = ?");
                $stmtStatus->execute(array_merge($ids, [$owner_id]));
                $batch_statuses = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($batch_statuses as $batch_status) {
                    $lock_col = $statusLockMap[$batch_status] ?? '';
                    $is_tab_locked = $lock_col ? (($ownerData[$lock_col] ?? 0) == 1) : false;
                    
                    if ($is_tab_locked && !$unlocked) {
                        throw new Exception("安全拦截：选定批次包含已锁定财务的仓库，请先输入保险箱密码解锁后再批量修改！");
                    }
                }
            }
        } 
        if (empty($ids)) throw new Exception("未选择");
        $updates = []; $params = [];
        
        if (isset($_POST['update_batch_no'])) { $updates[] = "batch_no = ?"; $params[] = $_POST['batch_no'] ?? ''; }
        if (isset($_POST['update_config'])) { $updates[] = "config_desc = ?"; $params[] = $_POST['config_desc'] ?? ''; }
        // 核心修改：利用 SQL 语句在底层自动将输入的单价乘以设备本身的 quantity 数量
        if (isset($_POST['update_cost'])) { 
            $updates[] = "cost_rmb = quantity * ?"; 
            $params[] = ($_POST['unit_cost'] === '') ? 0 : $_POST['unit_cost']; 
        }
        if (isset($_POST['update_freight'])) { 
            $updates[] = "freight = quantity * ?"; 
            $params[] = ($_POST['unit_freight'] === '') ? 0 : $_POST['unit_freight']; 
        }
        if (isset($_POST['update_receiver'])) { $updates[] = "receiver = ?"; $params[] = $_POST['receiver'] ?? ''; }
        if (isset($_POST['update_remarks'])) { $updates[] = "remarks = ?"; $params[] = $_POST['remarks'] ?? ''; }
        if (isset($_POST['update_collected_amount'])) { 
            $updates[] = "collected_amount = quantity * ?"; 
            $params[] = ($_POST['unit_collected'] === '') ? 0 : $_POST['unit_collected']; 
        }

        if (!empty($updates)) {
            $setClause = implode(', ', $updates);
            $inQuery = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE inventory_items SET $setClause WHERE id IN ($inQuery) AND user_id = ?";
            $finalParams = array_merge($params, $ids, [$owner_id]);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($finalParams);
        }
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'batch_delete') {
        if ($perm_delete == 0) throw new Exception("无权删除");
        $ids = array_filter(explode(',', $_POST['ids'] ?? ''));
        if (!empty($ids)) {
            $inQuery = implode(',', array_fill(0, count($ids), '?'));
            
            // --- 核心修复：批量删除锁校验 ---
            $stmtStatus = $pdo->prepare("SELECT DISTINCT status FROM inventory_items WHERE id IN ($inQuery) AND user_id = ?");
            $stmtStatus->execute(array_merge($ids, [$owner_id]));
            $batch_statuses = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);
            $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
            
            foreach ($batch_statuses as $batch_status) {
                $lock_col = $statusLockMap[$batch_status] ?? '';
                if ($lock_col && ($ownerData[$lock_col] ?? 0) == 1 && !$unlocked) {
                    throw new Exception("安全拦截：选定批次包含已锁定财务的仓库，请先解锁后再删除！");
                }
            }
            // ------------------------------------------------
            
            $params = array_merge($ids, [$owner_id]);
            $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE id IN ($inQuery) AND user_id = ?");
            $stmt->execute($params);
        }
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'dispatch_part') {
        if ($perm_edit == 0) throw new Exception("无权操作");
        $id = intval($_POST['id'] ?? 0);
        $dispatch_qty = intval($_POST['dispatch_qty'] ?? 0);
        $receiver = trim($_POST['receiver'] ?? '');
        $unit_collected = floatval($_POST['unit_collected'] ?? 0);

        if (empty($receiver)) throw new Exception("收货人不能为空");

        $pdo->beginTransaction();
        try {
            // 加行锁，避免并发脏读
            $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ? AND user_id = ? AND status = 'PARTS' FOR UPDATE");
            $stmt->execute([$id, $owner_id]);
            $item = $stmt->fetch();
            
            if (!$item) throw new Exception("找不到该配件或配件已被转移");
            if ($dispatch_qty <= 0 || $dispatch_qty > $item['quantity']) throw new Exception("出库数量输入不合法 (必须大于0且不能超过实际库存)");

            $total_collected = $unit_collected * $dispatch_qty;
            $unit_cost = $item['quantity'] > 0 ? ($item['cost_rmb'] / $item['quantity']) : 0;
            $unit_freight = $item['quantity'] > 0 ? ($item['freight'] / $item['quantity']) : 0;
            
            $dispatch_cost = $unit_cost * $dispatch_qty;
            $dispatch_freight = $unit_freight * $dispatch_qty;

            if ($dispatch_qty == $item['quantity']) {
                $stmtUpdate = $pdo->prepare("UPDATE inventory_items SET status='PARTS_SOLD', receiver=?, collected_amount=?, status_date=CURRENT_DATE(), status_timestamp=CURRENT_TIMESTAMP() WHERE id=?");
                $stmtUpdate->execute([$receiver, $total_collected, $id]);
            } else {
                $new_qty = $item['quantity'] - $dispatch_qty;
                $new_cost = $item['cost_rmb'] - $dispatch_cost;
                $new_freight = $item['freight'] - $dispatch_freight;
                
                // --- 修复：同步按比例扣减留在仓库里的母体收款金额，防止被两头重复计算利润 ---
                $unit_collected_orig = $item['quantity'] > 0 ? ($item['collected_amount'] / $item['quantity']) : 0;
                $new_collected = $item['collected_amount'] - ($unit_collected_orig * $dispatch_qty);

                $stmt1 = $pdo->prepare("UPDATE inventory_items SET quantity=?, cost_rmb=?, freight=?, collected_amount=? WHERE id=?");
                $stmt1->execute([$new_qty, $new_cost, $new_freight, $new_collected, $id]);

                $stmt2 = $pdo->prepare("INSERT INTO inventory_items (user_id, service_no, batch_no, quantity, status, status_date, status_timestamp, config_desc, cost_rmb, freight, receiver, remarks, collected_amount) VALUES (?, ?, ?, ?, 'PARTS_SOLD', CURRENT_DATE(), CURRENT_TIMESTAMP(), ?, ?, ?, ?, ?, ?)");
                $stmt2->execute([$owner_id, $item['service_no'], $item['batch_no'], $dispatch_qty, $item['config_desc'], $dispatch_cost, $dispatch_freight, $receiver, $item['remarks'], $total_collected]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw new Exception($e->getMessage()); 
        }
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'save') {
        $id = $_POST['id'] ?? '';
        if ($id !== '') {
            if ($perm_edit == 0) throw new Exception("安全拦截：您没有编辑/流转设备的权限！");
        } else {
            if ($perm_add == 0) throw new Exception("安全拦截：您没有新增设备的权限！");
        }
        $new_status = $_POST['status'] ?? 'US';
        $collected = ($_POST['collected_amount'] === '' || $_POST['collected_amount'] === null) ? 0 : $_POST['collected_amount'];
        $qty = intval($_POST['quantity'] ?? 1);
        if ($qty < 1) $qty = 1;
        
        $service_no = trim($_POST['service_no'] ?? '');
        if (empty($service_no)) throw new Exception("编号不能为空");

        // --- 修复：拦截通过下拉菜单强行把空资料设备保存至已售 ---
        if (in_array($new_status, ['SOLD', 'PARTS_SOLD'])) {
            $rec = trim($_POST['receiver'] ?? '');
            if (empty($rec) || $collected <= 0) {
                throw new Exception("安全拦截：保存失败！设备流转至已售状态必须填写收货人和有效的收款金额！");
            }
        }

        // 🚀 终极防御：使用 MySQL 命名应用锁！
        // 仅锁定当前操作的“老板ID+设备编号”，彻底杜绝幽灵双胞胎，且绝不阻塞操作其他编号的同事！
        $lockName = 'inv_save_' . $owner_id . '_' . md5($service_no);
        $stmtLock = $pdo->prepare("SELECT GET_LOCK(?, 5)"); // 最多等待5秒
        $stmtLock->execute([$lockName]);
        $lockAcquired = $stmtLock->fetchColumn();
        
        if (!$lockAcquired) {
            throw new Exception("🛑 系统繁忙：有同事正在极速操作该设备，请稍后再试！");
        }

        try {
            // 必须在拿到 GET_LOCK 之后再开启事务，这样才能保证读取到最新鲜的数据，彻底打破快照隔离！
            $pdo->beginTransaction();

            if (!in_array($new_status, ['REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD'])) {
                if ($id) {
                    $stmtCheck = $pdo->prepare("SELECT id FROM inventory_items WHERE service_no = ? AND id != ? AND user_id = ? AND status NOT IN ('REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD')");
                    $stmtCheck->execute([$service_no, $id, $owner_id]);
                } else {
                    $stmtCheck = $pdo->prepare("SELECT id FROM inventory_items WHERE service_no = ? AND user_id = ? AND status NOT IN ('REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD')");
                    $stmtCheck->execute([$service_no, $owner_id]);
                }
                if ($stmtCheck->fetch()) {
                    throw new Exception("操作失败：服务编号【{$service_no}】在您的主流程电脑仓库中已存在！只有售后维修或零配件仓允许重复录入。");
                }
            }

            if ($id) {
                // 编辑时，使用 FOR UPDATE 锁住特定行记录，防止多人同时修改同一台设备导致数据覆盖
                $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $item = $stmt->fetch();
                if(!$item || $item['user_id'] != $owner_id) throw new Exception("无权操作或数据已被删除");
                
                $post_updated_at = $_POST['updated_at'] ?? '';
                if ($post_updated_at !== '' && $item['updated_at'] !== $post_updated_at) {
                    throw new Exception("🛑 保存失败：该设备刚刚已被其他同事修改，请刷新页面获取最新数据后再编辑，以免覆盖别人数据！");
                }
                
                // 重新校验财务权限
                $statusLockMap = ['US'=>'lock_tab_us', 'TRANSIT'=>'lock_tab_transit', 'CN_WH'=>'lock_tab_cn', 'SOLD'=>'lock_tab_sold', 'PARTS'=>'lock_tab_parts', 'PARTS_SOLD'=>'lock_tab_parts_sold', 'REPAIR'=>'lock_tab_repair', 'REPAIR_DONE'=>'lock_tab_repair_done'];
                $lock_col = $statusLockMap[$item['status']] ?? '';
                $is_tab_locked = $lock_col ? (($ownerData[$lock_col] ?? 0) == 1) : false;
                $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
                $can_edit_finance = ($perm_finance == 1) && !($is_tab_locked && !$unlocked);

                if (!$can_edit_finance) {
                    $old_qty = ($item['quantity'] > 0) ? $item['quantity'] : 1;
                    $cost_rmb = ($item['cost_rmb'] / $old_qty) * $qty;
                    $freight = ($item['freight'] / $old_qty) * $qty;
                } else {
                    $cost_rmb = isset($_POST['cost_rmb']) ? ($_POST['cost_rmb'] === '' ? 0 : $_POST['cost_rmb']) : $item['cost_rmb'];
                    $freight = isset($_POST['freight']) ? ($_POST['freight'] === '' ? 0 : $_POST['freight']) : $item['freight'];
                }

                if ($item['status'] !== $new_status) {
                    $stmt = $pdo->prepare("UPDATE inventory_items SET service_no=?, batch_no=?, quantity=?, status=?, status_date=CURRENT_DATE(), status_timestamp=CURRENT_TIMESTAMP(), config_desc=?, cost_rmb=?, freight=?, receiver=?, remarks=?, collected_amount=? WHERE id=? AND user_id=?");
                } else {
                    $stmt = $pdo->prepare("UPDATE inventory_items SET service_no=?, batch_no=?, quantity=?, status=?, config_desc=?, cost_rmb=?, freight=?, receiver=?, remarks=?, collected_amount=? WHERE id=? AND user_id=?");
                }
                $stmt->execute([$service_no, $_POST['batch_no'] ?? '', $qty, $new_status, $_POST['config_desc'] ?? '', $cost_rmb, $freight, $_POST['receiver'] ?? '', $_POST['remarks'] ?? '', $collected, $id, $owner_id]);
            } else {
                $statusLockMap = ['US'=>'lock_tab_us', 'TRANSIT'=>'lock_tab_transit', 'CN_WH'=>'lock_tab_cn', 'SOLD'=>'lock_tab_sold', 'PARTS'=>'lock_tab_parts', 'PARTS_SOLD'=>'lock_tab_parts_sold', 'REPAIR'=>'lock_tab_repair', 'REPAIR_DONE'=>'lock_tab_repair_done'];
                $lock_col = $statusLockMap[$new_status] ?? '';
                $is_tab_locked = $lock_col ? (($ownerData[$lock_col] ?? 0) == 1) : false;
                $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
                $can_edit_finance = ($perm_finance == 1) && !($is_tab_locked && !$unlocked);

                $cost_rmb = $can_edit_finance ? (isset($_POST['cost_rmb']) && $_POST['cost_rmb'] !== '' ? $_POST['cost_rmb'] : 0) : 0;
                $freight = $can_edit_finance ? (isset($_POST['freight']) && $_POST['freight'] !== '' ? $_POST['freight'] : 0) : 0;
                
                $stmt = $pdo->prepare("INSERT INTO inventory_items (user_id, service_no, batch_no, quantity, status, status_date, status_timestamp, config_desc, cost_rmb, freight, receiver, remarks, collected_amount) VALUES (?, ?, ?, ?, ?, CURRENT_DATE(), CURRENT_TIMESTAMP(), ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$owner_id, $service_no, $_POST['batch_no'] ?? '', $qty, $new_status, $_POST['config_desc'] ?? '', $cost_rmb, $freight, $_POST['receiver'] ?? '', $_POST['remarks'] ?? '', $collected]);
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success']);
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new Exception($e->getMessage());
        } finally {
            // 🛡️ 无论保存成功还是发生报错，务必释放应用锁，防止锁死后续正常操作
            $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
        }
    } 
    elseif ($action === 'update_status') {
        if ($perm_edit == 0) throw new Exception("无权操作");
        $new_status = $_POST['status'] ?? '';
        $id = $_POST['id'] ?? '';
        
        // --- 核心修复：流转时校验源仓库和目标仓库的保险箱锁 ---
        $stmtOld = $pdo->prepare("SELECT status FROM inventory_items WHERE id = ? AND user_id = ?");
        $stmtOld->execute([$id, $owner_id]);
        $old_status = $stmtOld->fetchColumn();
        if ($old_status) {
            $source_lock = $statusLockMap[$old_status] ?? '';
            $target_lock = $statusLockMap[$new_status] ?? '';
            $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
            // 彻底放开：单条流转和退回不再受财务锁限制
//            if ((($ownerData[$source_lock] ?? 0) == 1 || ($ownerData[$target_lock] ?? 0) == 1) && !$unlocked) {
//                throw new Exception("安全拦截：涉及锁定仓库的流转操作，必须先验证财务密码解锁！");
//            }
        }
        // ------------------------------------------------
        
        // --- 新增：安全拦截，防止无资料强制流转至已售 ---
        if (in_array($new_status, ['SOLD', 'PARTS_SOLD'])) {
            $stmtCheck = $pdo->prepare("SELECT id FROM inventory_items WHERE id = ? AND user_id = ? AND (receiver IS NULL OR receiver = '' OR collected_amount <= 0)");
            $stmtCheck->execute([$id, $owner_id]);
            if ($stmtCheck->fetchColumn()) {
                throw new Exception("安全拦截：该设备缺失收货人或收款金额，拒绝流转至已售！");
            }
        }
        // ------------------------------------------------
        
        $stmt = $pdo->prepare("UPDATE inventory_items SET status = ?, status_date = CURRENT_DATE(), status_timestamp = CURRENT_TIMESTAMP() WHERE id = ? AND user_id = ?");
        $stmt->execute([$new_status, $id, $owner_id]);
        echo json_encode(['status' => 'success']);
    } 
    elseif ($action === 'delete') {
        if ($perm_delete == 0) throw new Exception("无权操作");
        $id = $_POST['id'] ?? '';
        
        // --- 核心修复：删除时校验仓库的保险箱锁 ---
        $stmtOld = $pdo->prepare("SELECT status FROM inventory_items WHERE id = ? AND user_id = ?");
        $stmtOld->execute([$id, $owner_id]);
        $old_status = $stmtOld->fetchColumn();
        if ($old_status) {
            $source_lock = $statusLockMap[$old_status] ?? '';
            $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
            if ($source_lock && ($ownerData[$source_lock] ?? 0) == 1 && !$unlocked) {
                throw new Exception("安全拦截：该设备所在仓库的财务已锁定，请先解锁后再删除！");
            }
        }
        // ------------------------------------------------
        
        $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $owner_id]);
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'import') {
        if ($perm_import == 0) throw new Exception("安全拦截：您没有批量导入数据的权限！");
        $json = file_get_contents('php://input');
        $rows = json_decode($json, true);
        if (!$rows) throw new Exception("无数据");
        
        $pdo->beginTransaction();
        try {
            $statusMap = ['美国'=>'US', '国外途中'=>'TRANSIT', '国内仓'=>'CN_WH', '已售'=>'SOLD', '售后维修'=>'REPAIR', '维修完毕'=>'REPAIR_DONE', '零配件仓'=>'PARTS', '已售配件'=>'PARTS_SOLD'];
            $success = 0;
            
            foreach ($rows as $row) {
                if (empty($row['服务编号']) && empty($row['编号'])) continue;
                $service_no = trim($row['服务编号'] ?? $row['编号']);
                $status = $statusMap[$row['状态'] ?? '美国仓'] ?? 'US';
                $qty = (isset($row['数量']) && $row['数量'] !== '') ? intval($row['数量']) : 1;
                $qty = max(1, $qty); // 🛡️ 新增这行：强制兜底，数量最小必须为 1，防止 Excel 填入 0 或负数导致后续除零报错
                
                // --- 修复：双向流转必须合并查找，防止误判为新设备导致克隆双胞胎 ---
                if (in_array($status, ['REPAIR', 'REPAIR_DONE'])) {
                    $stmtCheck = $pdo->prepare("SELECT * FROM inventory_items WHERE service_no = ? AND user_id = ? AND status IN ('REPAIR', 'REPAIR_DONE') ORDER BY id DESC LIMIT 1");
                    $stmtCheck->execute([$service_no, $owner_id]);
                } elseif (in_array($status, ['PARTS', 'PARTS_SOLD'])) {
                    $stmtCheck = $pdo->prepare("SELECT * FROM inventory_items WHERE service_no = ? AND user_id = ? AND status IN ('PARTS', 'PARTS_SOLD') ORDER BY id DESC LIMIT 1");
                    $stmtCheck->execute([$service_no, $owner_id]);
                } else {
                    $stmtCheck = $pdo->prepare("SELECT * FROM inventory_items WHERE service_no = ? AND user_id = ? AND status NOT IN ('REPAIR', 'REPAIR_DONE', 'PARTS', 'PARTS_SOLD')");
                    $stmtCheck->execute([$service_no, $owner_id]);
                }
                $existsData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                // --- 修复：合并校验新旧状态的财务锁，只要有一端上锁即全盘拦截，防绕过 ---
                $target_lock = $statusLockMap[$status] ?? '';
                $source_lock = $existsData ? ($statusLockMap[$existsData['status']] ?? '') : '';
                $is_tab_locked = ($target_lock && ($ownerData[$target_lock] ?? 0) == 1) || ($source_lock && ($ownerData[$source_lock] ?? 0) == 1);
                
                $unlocked = isset($_SESSION['finance_unlocked_' . $user_id]) && $_SESSION['finance_unlocked_' . $user_id] === true;
                $can_edit_finance = ($perm_finance == 1) && !($is_tab_locked && !$unlocked);
                
                // --- 修复：提取旧数量用于等比缩放，防止历史账本被暴跌稀释 ---
                $old_qty = ($existsData && $existsData['quantity'] > 0) ? $existsData['quantity'] : 1;
                $fallback_collected = $existsData ? (($existsData['collected_amount'] / $old_qty) * $qty) : 0;
                
                $collected = (isset($row['单件收款金额']) && $row['单件收款金额'] !== '') ? (float)$row['单件收款金额'] * $qty : ((isset($row['收款金额']) && $row['收款金额'] !== '') ? (float)$row['收款金额'] : $fallback_collected);
                
                $rec = trim($row['收货人'] ?? ($existsData['receiver'] ?? ''));
                if (in_array($status, ['SOLD', 'PARTS_SOLD']) && (empty($rec) || $collected <= 0)) {
                    throw new Exception("安全拦截：导入失败！编号 [{$service_no}] 流转至已售必须填写收货人和有效收款金额！");
                }

                if ($existsData) {
                    if ($can_edit_finance) {
                        $fallback_cost = ($existsData['cost_rmb'] / $old_qty) * $qty;
                        $fallback_freight = ($existsData['freight'] / $old_qty) * $qty;
                        
                        $cost = (isset($row['单件成本']) && $row['单件成本'] !== '') ? (float)$row['单件成本'] * $qty : ((isset($row['成本']) && $row['成本'] !== '') ? (float)$row['成本'] : ((isset($row['人民币成本']) && $row['人民币成本'] !== '') ? (float)$row['人民币成本'] : $fallback_cost));
                        $freight = (isset($row['单件运费']) && $row['单件运费'] !== '') ? (float)$row['单件运费'] * $qty : ((isset($row['运费']) && $row['运费'] !== '') ? (float)$row['运费'] : $fallback_freight);
                    } else {
                        // --- 修复：无权限修改时，也必须跟随数量等比缩放 ---
                        $cost = ($existsData['cost_rmb'] / $old_qty) * $qty;
                        $freight = ($existsData['freight'] / $old_qty) * $qty;
                    }

                    // === 核心修复：只有当状态发生真正改变时，才更新流转时间。否则强制保留原本的历史时间，防止财务报表日期被摧毁 ===
                    // === 核心修复：只有当状态发生真正改变时，才更新流转时间。否则强制保留原本的历史时间，防止财务报表日期被摧毁 ===
                    
                    // 【安全修复】：安全提取 Excel 数据，若表格未填写这些列，强制保留数据库原有的历史数据，绝不强行清空
                    $batch_no = array_key_exists('批次', $row) ? $row['批次'] : ($existsData['batch_no'] ?? '');
                    $config_desc = array_key_exists('配置', $row) ? $row['配置'] : ($existsData['config_desc'] ?? '');
                    $remarks = array_key_exists('备注', $row) ? $row['备注'] : ($existsData['remarks'] ?? '');

                    if ($existsData['status'] !== $status) {
                        $stmtUpdate = $pdo->prepare("UPDATE inventory_items SET batch_no=?, quantity=?, config_desc=?, status=?, cost_rmb=?, freight=?, receiver=?, remarks=?, collected_amount=?, status_date=CURRENT_DATE(), status_timestamp=CURRENT_TIMESTAMP() WHERE id=?");
                        $stmtUpdate->execute([$batch_no, $qty, $config_desc, $status, $cost, $freight, $rec, $remarks, $collected, $existsData['id']]);
                    } else {
                        // 状态没变，单纯更新业务资料，禁止覆盖原本的 status_date
                        $stmtUpdate = $pdo->prepare("UPDATE inventory_items SET batch_no=?, quantity=?, config_desc=?, status=?, cost_rmb=?, freight=?, receiver=?, remarks=?, collected_amount=? WHERE id=?");
                        $stmtUpdate->execute([$batch_no, $qty, $config_desc, $status, $cost, $freight, $rec, $remarks, $collected, $existsData['id']]);
                    }
                } else {
                    // 1. 处理敏感的财务字段（受限）
                    if ($can_edit_finance) {
                        $cost = (isset($row['单件成本']) && $row['单件成本'] !== '') ? (float)$row['单件成本'] * $qty : ((isset($row['成本']) && $row['成本'] !== '') ? (float)$row['成本'] : ((isset($row['人民币成本']) && $row['人民币成本'] !== '') ? (float)$row['人民币成本'] : 0));
                        $freight = (isset($row['单件运费']) && $row['单件运费'] !== '') ? (float)$row['单件运费'] * $qty : ((isset($row['运费']) && $row['运费'] !== '') ? (float)$row['运费'] : 0);
                    } else {
                        $cost = 0; $freight = 0;
                    }
                    
                    // 2. 处理普通的销售收款字段（不受限）
                    $collected = (isset($row['单件收款金额']) && $row['单件收款金额'] !== '') ? (float)$row['单件收款金额'] * $qty : ((isset($row['收款金额']) && $row['收款金额'] !== '') ? (float)$row['收款金额'] : 0);

                    $stmtInsert = $pdo->prepare("INSERT INTO inventory_items (user_id, service_no, batch_no, quantity, config_desc, status, status_date, status_timestamp, cost_rmb, freight, receiver, remarks, collected_amount) VALUES (?, ?, ?, ?, ?, ?, CURRENT_DATE(), CURRENT_TIMESTAMP(), ?, ?, ?, ?, ?)");
                    $stmtInsert->execute([$owner_id, $service_no, $row['批次'] ?? '', $qty, $row['配置'] ?? '', $status, $cost, $freight, $row['收货人'] ?? '', $row['备注'] ?? '', $collected]);
                }
                $success++;
            }
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "成功导入并更新 {$success} 条数据！"]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw new Exception("导入过程出错已全部回滚撤销： " . $e->getMessage());
        }
    } 
    elseif ($action === 'verify_lock') {
        $pwd = $_POST['pwd'] ?? '';
        
        $stmtOwner = $pdo->prepare("SELECT lock_password FROM users WHERE id = ?");
        $stmtOwner->execute([$owner_id]);
        $owner_pwd = $stmtOwner->fetchColumn();

        $self_pwd = '';
        if ($parent_id > 0) {
            $stmtSelf = $pdo->prepare("SELECT lock_password FROM users WHERE id = ?");
            $stmtSelf->execute([$user_id]);
            $self_pwd = $stmtSelf->fetchColumn();
        }

        if (empty($owner_pwd) && empty($self_pwd)) { 
            $_SESSION['finance_unlocked_' . $user_id] = true;
            echo json_encode(['status' => 'success']); 
            exit;
        }

        // --- 修复：优先校验操作者自己设置的专属保险箱密码，如果没有设置，再用老板密码兜底 ---
        $target_pwd = ($parent_id > 0 && !empty($self_pwd)) ? $self_pwd : $owner_pwd;
        $target_id = ($parent_id > 0 && !empty($self_pwd)) ? $user_id : $owner_id;
        
        if (password_verify($pwd, $target_pwd) || ($target_pwd === $pwd && $pwd !== '')) { 
            if ($target_pwd === $pwd && $pwd !== '') { 
                $new_hash = password_hash($pwd, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET lock_password = ? WHERE id = ?")->execute([$new_hash, $target_id]);
            }
            $_SESSION['finance_unlocked_' . $user_id] = true;
            echo json_encode(['status' => 'success']); 
        } else {
            echo json_encode(['status' => 'error', 'message' => '验证失败：此区域受系统主账号保护，必须输入主账号的财务密码！']); 
        }
    }
    // === 🚀 新增：接收前端主动上锁的强制指令 ===
    elseif ($action === 'relock') {
        unset($_SESSION['finance_unlocked_' . $user_id]);
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'get_sec_questions') {
        if ($parent_id > 0) throw new Exception("子账号不支持找回功能");
        $stmt = $pdo->prepare("SELECT sec_q1, sec_q2, sec_q3 FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && !empty($user['sec_q1'])) { echo json_encode(['status' => 'success', 'data' => $user]); } 
        else { throw new Exception("您未绑定密保问题，请先在设置中绑定。"); }
    }
    elseif ($action === 'reset_lock_with_sec') {
        if ($parent_id > 0) throw new Exception("无权操作");
        
        $login_pwd = $_POST['login_pwd'] ?? '';
        $stmt_pwd = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt_pwd->execute([$user_id]);
        $hash = $stmt_pwd->fetchColumn();
        if (!password_verify($login_pwd, $hash)) throw new Exception("登录密码输入错误！拒绝重置！");

        $a1 = trim($_POST['a1'] ?? ''); $a2 = trim($_POST['a2'] ?? ''); $a3 = trim($_POST['a3'] ?? '');
        $new_lock = $_POST['new_lock'] ?? '';
        $stmt = $pdo->prepare("SELECT sec_a1, sec_a2, sec_a3 FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $a1_match = password_verify($a1, $user['sec_a1']) || ($user['sec_a1'] === $a1);
            $a2_match = password_verify($a2, $user['sec_a2']) || ($user['sec_a2'] === $a2);
            $a3_match = password_verify($a3, $user['sec_a3']) || ($user['sec_a3'] === $a3);

            if ($a1_match && $a2_match && $a3_match) {
                $hash_to_store = empty($new_lock) ? '' : password_hash($new_lock, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET lock_password = ? WHERE id = ?");
                $stmt->execute([$hash_to_store, $user_id]);
                echo json_encode(['status' => 'success']);
                exit;
            }
        }
        
        echo json_encode(['status' => 'error', 'message' => '密保答案验证失败！']);
    }
    elseif ($action === 'reset_sub_lock') {
        if ($parent_id == 0) throw new Exception("主账号请使用密保找回流程！");
        $login_pwd = $_POST['login_pwd'] ?? '';
        $new_lock = $_POST['new_lock'] ?? '';
        
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();
        
        if (!password_verify($login_pwd, $hash)) throw new Exception("子账号登录密码错误！");
        
        $hash_to_store = empty($new_lock) ? '' : password_hash($new_lock, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET lock_password = ? WHERE id = ?");
        $stmt->execute([$hash_to_store, $user_id]);
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'update_sec_questions') {
        if ($parent_id > 0) throw new Exception("子账号无此功能");
        $pwd = $_POST['pwd'] ?? '';
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($pwd, $hash)) throw new Exception("当前登录密码验证失败！");
        
        $q1 = $_POST['q1'] ?? ''; $a1 = trim($_POST['a1'] ?? '');
        $q2 = $_POST['q2'] ?? ''; $a2 = trim($_POST['a2'] ?? '');
        $q3 = $_POST['q3'] ?? ''; $a3 = trim($_POST['a3'] ?? '');
        
        if (!$a1 || !$a2 || !$a3) throw new Exception("三个密保答案都必须填写！");
        
        // --- 增加对密保答案的加密 ---
        $hash_a1 = password_hash($a1, PASSWORD_DEFAULT);
        $hash_a2 = password_hash($a2, PASSWORD_DEFAULT);
        $hash_a3 = password_hash($a3, PASSWORD_DEFAULT);

        // --- 注意：execute 里面存入的是加密后的 $hash_a1 等变量 ---
        $stmt = $pdo->prepare("UPDATE users SET sec_q1=?, sec_a1=?, sec_q2=?, sec_a2=?, sec_q3=?, sec_a3=? WHERE id=?");
        $stmt->execute([$q1, $hash_a1, $q2, $hash_a2, $q3, $hash_a3, $user_id]);
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'change_my_password') {
        $old_pwd = (string) ($_POST['old_pwd'] ?? '');
        $new_pwd = (string) ($_POST['new_pwd'] ?? '');
        $passwordCheck = validate_password($new_pwd);
        if (!$passwordCheck['valid']) throw new Exception(implode(' ', $passwordCheck['errors']));
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = (string) $stmt->fetchColumn();
        if (!verify_stored_secret($old_pwd, $hash)['valid']) throw new Exception("原密码错误！");
        $new_hash = password_hash($new_pwd, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$new_hash, $user_id]);
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'set_lock') {
        $old_lock = $_POST['old_lock'] ?? '';
        $new_pwd = $_POST['new_pwd'] ?? '';
        $stmt = $pdo->prepare("SELECT lock_password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $real_pwd = $stmt->fetchColumn();
        
        // 兼容原有的明文判断逻辑
        if (!empty($real_pwd)) {
            $is_match = password_verify($old_lock, $real_pwd) || ($real_pwd === $old_lock);
            if (!$is_match) throw new Exception("原保险箱密码验证失败！");
        }
        
        $hash_to_store = empty($new_pwd) ? '' : password_hash($new_pwd, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET lock_password = ? WHERE id = ?");
        $stmt->execute([$hash_to_store, $user_id]);
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'set_timeout') {
        $mins = intval($_POST['minutes'] ?? 20);
        $stmt = $pdo->prepare("UPDATE users SET timeout_minutes = ? WHERE id = ?");
        $stmt->execute([$mins, $user_id]);
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'update_master_tabs') {
        if ($parent_id > 0) throw new Exception("无权修改全局配置！");

        // --- 新增：强制校验保险箱密码 ---
        $stmtPwd = $pdo->prepare("SELECT lock_password FROM users WHERE id = ?");
        $stmtPwd->execute([$user_id]);
        $real_pwd = $stmtPwd->fetchColumn();
        
        if (!empty($real_pwd)) {
            $input_pwd = $_POST['lock_pwd'] ?? '';
            if (!password_verify($input_pwd, $real_pwd) && $real_pwd !== $input_pwd) {
                throw new Exception("保险箱密码验证失败，拒绝修改全局锁配置！");
            }
        }
        // ---------------------------------

        // 👇 这里是你刚刚不小心删掉的变量定义，我已经帮你补全了
        $p_us = isset($_POST['p_us']) ? 1 : 0; $p_transit = isset($_POST['p_transit']) ? 1 : 0; $p_cn = isset($_POST['p_cn']) ? 1 : 0; $p_sold = isset($_POST['p_sold']) ? 1 : 0; $p_repair = isset($_POST['p_repair']) ? 1 : 0; $p_repair_done = isset($_POST['p_repair_done']) ? 1 : 0;
        $p_parts = isset($_POST['p_parts']) ? 1 : 0; $p_parts_sold = isset($_POST['p_parts_sold']) ? 1 : 0;

        $l_us = isset($_POST['l_us']) ? 1 : 0; $l_transit = isset($_POST['l_transit']) ? 1 : 0; $l_cn = isset($_POST['l_cn']) ? 1 : 0; $l_sold = isset($_POST['l_sold']) ? 1 : 0; $l_repair = isset($_POST['l_repair']) ? 1 : 0; $l_repair_done = isset($_POST['l_repair_done']) ? 1 : 0;
        $l_parts = isset($_POST['l_parts']) ? 1 : 0; $l_parts_sold = isset($_POST['l_parts_sold']) ? 1 : 0;
        
        $stmt = $pdo->prepare("UPDATE users SET perm_tab_us=?, perm_tab_transit=?, perm_tab_cn=?, perm_tab_sold=?, perm_tab_repair=?, perm_tab_repair_done=?, perm_tab_parts=?, perm_tab_parts_sold=?, lock_tab_us=?, lock_tab_transit=?, lock_tab_cn=?, lock_tab_sold=?, lock_tab_repair=?, lock_tab_repair_done=?, lock_tab_parts=?, lock_tab_parts_sold=? WHERE id=?");
        $stmt->execute([$p_us, $p_transit, $p_cn, $p_sold, $p_repair, $p_repair_done, $p_parts, $p_parts_sold, $l_us, $l_transit, $l_cn, $l_sold, $l_repair, $l_repair_done, $l_parts, $l_parts_sold, $user_id]);
        echo json_encode(['status' => 'success']);
    }
    elseif ($action === 'delete_my_account') {
        if ($parent_id > 0) throw new Exception("无权");
        $pwd = $_POST['pwd'] ?? '';
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($pwd, $hash)) throw new Exception("验证失败！");
        
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM inventory_items WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM users WHERE parent_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
            $pdo->commit();
            session_destroy();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw new Exception("注销过程异常：" . $e->getMessage());
        }
    }
    elseif ($action === 'list_sub_accounts') {
        if ($parent_id > 0) throw new Exception("无权操作");
        $stmt = $pdo->prepare("SELECT id, username, perm_finance, perm_edit, perm_delete, perm_download_tpl, perm_import, perm_add, perm_export, perm_tab_us, perm_tab_transit, perm_tab_cn, perm_tab_sold, perm_tab_parts, perm_tab_parts_sold, perm_tab_repair, perm_tab_repair_done, perm_history_view, created_at FROM users WHERE parent_id = ? ORDER BY id DESC");
        $stmt->execute([$user_id]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    elseif ($action === 'save_sub_account') {
        if ($parent_id > 0) throw new Exception("无权操作");
        $sub_id = $_POST['sub_id'] ?? '';
        $sub_user = trim((string) ($_POST['sub_user'] ?? ''));
        $sub_pass = (string) ($_POST['sub_pass'] ?? '');
        if ($sub_user === '' || strlen($sub_user) > 50) throw new Exception("员工用户名格式不正确。");
        if ($sub_pass !== '') {
            $passwordCheck = validate_password($sub_pass);
            if (!$passwordCheck['valid']) throw new Exception(implode(' ', $passwordCheck['errors']));
        }
        
        $p_fin = isset($_POST['p_fin']) ? 1 : 0; $p_edt = isset($_POST['p_edt']) ? 1 : 0; $p_del = isset($_POST['p_del']) ? 1 : 0;
        $p_dl = isset($_POST['p_dl']) ? 1 : 0; $p_imp = isset($_POST['p_imp']) ? 1 : 0; $p_add = isset($_POST['p_add']) ? 1 : 0; $p_exp = isset($_POST['p_exp']) ? 1 : 0;
        
        // === 核心安全修复：如果开启或保留了财务查看权限，后端强制校验主账号财务锁，防止恶意添加后门账号 ===
        if ($p_fin === 1) {
            $stmtPwd = $pdo->prepare("SELECT lock_password FROM users WHERE id = ?");
            $stmtPwd->execute([$user_id]); // 主账号的 user_id
            $real_pwd = $stmtPwd->fetchColumn();
            
            if (!empty($real_pwd)) {
                $input_pwd = $_POST['lock_pwd'] ?? '';
                if (!password_verify($input_pwd, $real_pwd) && $real_pwd !== $input_pwd) {
                    throw new Exception("安全拦截：授权或保留员工的【财务查看权限】必须验证您的财务保险箱密码！");
                }
            }
        }
        // ==================================================================================

        $p_us = isset($_POST['p_us']) ? 1 : 0; $p_transit = isset($_POST['p_transit']) ? 1 : 0; $p_cn = isset($_POST['p_cn']) ? 1 : 0; $p_sold = isset($_POST['p_sold']) ? 1 : 0;
        $p_parts = isset($_POST['p_parts']) ? 1 : 0; $p_parts_sold = isset($_POST['p_parts_sold']) ? 1 : 0;
        $p_repair = isset($_POST['p_repair']) ? 1 : 0; $p_repair_done = isset($_POST['p_repair_done']) ? 1 : 0;
        $p_hist = isset($_POST['p_hist']) ? intval($_POST['p_hist']) : 999;
        
        if ($sub_id) {
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmtCheck->execute([$sub_user, $sub_id]);
            if($stmtCheck->fetchColumn()) throw new Exception("该用户名已被占用，请换一个！");

            if (!empty($sub_pass)) {
                $hash = password_hash($sub_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, perm_finance=?, perm_edit=?, perm_delete=?, perm_download_tpl=?, perm_import=?, perm_add=?, perm_export=?, perm_tab_us=?, perm_tab_transit=?, perm_tab_cn=?, perm_tab_sold=?, perm_tab_parts=?, perm_tab_parts_sold=?, perm_tab_repair=?, perm_tab_repair_done=?, perm_history_view=?, password=? WHERE id=? AND parent_id=?");
                $stmt->execute([$sub_user, $p_fin, $p_edt, $p_del, $p_dl, $p_imp, $p_add, $p_exp, $p_us, $p_transit, $p_cn, $p_sold, $p_parts, $p_parts_sold, $p_repair, $p_repair_done, $p_hist, $hash, $sub_id, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username=?, perm_finance=?, perm_edit=?, perm_delete=?, perm_download_tpl=?, perm_import=?, perm_add=?, perm_export=?, perm_tab_us=?, perm_tab_transit=?, perm_tab_cn=?, perm_tab_sold=?, perm_tab_parts=?, perm_tab_parts_sold=?, perm_tab_repair=?, perm_tab_repair_done=?, perm_history_view=? WHERE id=? AND parent_id=?");
                $stmt->execute([$sub_user, $p_fin, $p_edt, $p_del, $p_dl, $p_imp, $p_add, $p_exp, $p_us, $p_transit, $p_cn, $p_sold, $p_parts, $p_parts_sold, $p_repair, $p_repair_done, $p_hist, $sub_id, $user_id]);
            }
        } else {
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmtCheck->execute([$sub_user]);
            if($stmtCheck->fetchColumn()) throw new Exception("该用户名已被占用，请换一个！");

            if (empty($sub_pass)) throw new Exception("需设置密码");
        $hash = password_hash($sub_pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, role, parent_id, perm_finance, perm_edit, perm_delete, perm_download_tpl, perm_import, perm_add, perm_export, perm_tab_us, perm_tab_transit, perm_tab_cn, perm_tab_sold, perm_tab_parts, perm_tab_parts_sold, perm_tab_repair, perm_tab_repair_done, perm_history_view) VALUES (?, ?, 'user', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$sub_user, $hash, $user_id, $p_fin, $p_edt, $p_del, $p_dl, $p_imp, $p_add, $p_exp, $p_us, $p_transit, $p_cn, $p_sold, $p_parts, $p_parts_sold, $p_repair, $p_repair_done, $p_hist]);
    }
    echo json_encode(['status' => 'success']);
}
elseif ($action === 'verify_login') {
        $pwd = $_POST['pwd'] ?? '';
        $u = $_SESSION['username'] ?? '';

        $stmtLock = $pdo->prepare("SELECT lock_until FROM login_blocks WHERE type='username' AND identifier=?");
        $stmtLock->execute([$u]);
        $lock_until = $stmtLock->fetchColumn();
        if ($lock_until && strtotime($lock_until) > time()) {
            $rem = ceil((strtotime($lock_until) - time()) / 60);
            throw new Exception("🚫 账号已被安全锁定，请 $rem 分钟后再试。");
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();

        $verification = verify_stored_secret((string) $pwd, (string) $hash);
        if ($verification['valid']) {
            clear_auth_failures($pdo, 'username', (string) $u);
            if ($verification['upgrade_hash'] !== null) {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
                    ->execute([$verification['upgrade_hash'], $user_id]);
            }
            $_SESSION['last_active_time'] = time(); 
            echo json_encode(['status' => 'success']);
        } else {
            $new_count = record_auth_failure($pdo, 'username', (string) $u, 5, 15);
            if ($new_count >= 5) {
                perform_logout();
                throw new Exception("FORCE_LOGOUT");
            } else {
                $remains = 5 - $new_count;
                throw new Exception("密码错误！还剩 $remains 次尝试机会。");
            }
        }
    }
    elseif ($action === 'delete_sub_account') {
        if ($parent_id > 0) throw new Exception("无权");
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND parent_id = ?");
        $stmt->execute([$_POST['sub_id'], $user_id]);
        echo json_encode(['status' => 'success']);
    }
    else { echo json_encode(['status' => 'error', 'message' => '无效请求']); }
} catch (Exception $e) {
    $msg = $e->getMessage();
    echo json_encode(['status' => 'error', 'message' => $msg]);
}
?>
