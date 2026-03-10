<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_ADMIN', 1);
if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_ADMIN) {
    header("Location: ../login.php?session_expired=1", true, 303);
    exit();
}

$full_name = $_SESSION['full_name'] ?? 'Admin';
$email     = $_SESSION['email'] ?? '';
$db        = Database::getInstance()->getConnection();
$message   = '';
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $fn = trim($_POST['full_name']??''); $em = trim($_POST['email']??'');
        $ph = trim($_POST['phone']??'');    $pw = $_POST['password']??'';
        $st = trim($_POST['status']??'active');
        if ($fn && $em && $pw) {
            try {
                $s=$db->prepare("SELECT id FROM users WHERE email=?"); $s->execute([$em]);
                if ($s->fetch()) { $error="Email already exists."; }
                else {
                    $db->prepare("INSERT INTO users (full_name,email,phone,password,role_id,status,created_at) VALUES (?,?,?,?,2,?,NOW())")
                       ->execute([$fn,$em,$ph,password_hash($pw,PASSWORD_DEFAULT),$st]);
                    $message="Agent added successfully!";
                }
            } catch(PDOException $e){ error_log($e->getMessage()); $error="Failed to add agent."; }
        } else { $error="Please fill in all required fields."; }
    }
    elseif ($_POST['action']==='edit' && isset($_POST['agent_id'])) {
        $aid=$_POST['agent_id']; $fn=trim($_POST['full_name']??''); $em=trim($_POST['email']??'');
        $ph=trim($_POST['phone']??''); $st=trim($_POST['status']??'active');
        if ($fn && $em) {
            try {
                $s=$db->prepare("SELECT id FROM users WHERE email=? AND id!=?"); $s->execute([$em,$aid]);
                if ($s->fetch()) { $error="Email already exists."; }
                else {
                    if (!empty($_POST['password'])) {
                        $db->prepare("UPDATE users SET full_name=?,email=?,phone=?,password=?,status=? WHERE id=? AND role_id=2")
                           ->execute([$fn,$em,$ph,password_hash($_POST['password'],PASSWORD_DEFAULT),$st,$aid]);
                    } else {
                        $db->prepare("UPDATE users SET full_name=?,email=?,phone=?,status=? WHERE id=? AND role_id=2")
                           ->execute([$fn,$em,$ph,$st,$aid]);
                    }
                    $message="Agent updated successfully!";
                }
            } catch(PDOException $e){ error_log($e->getMessage()); $error="Failed to update agent."; }
        } else { $error="Please fill in all required fields."; }
    }
    elseif ($_POST['action']==='delete' && isset($_POST['agent_id'])) {
        $aid=$_POST['agent_id'];
        try {
            $s=$db->prepare("SELECT COUNT(*) FROM properties WHERE created_by=?"); $s->execute([$aid]);
            if ($s->fetchColumn()>0) { $error="Cannot delete agent with assigned properties."; }
            else { $db->prepare("DELETE FROM users WHERE id=? AND role_id=2")->execute([$aid]); $message="Agent deleted!"; }
        } catch(PDOException $e){ error_log($e->getMessage()); $error="Failed to delete agent."; }
    }
    elseif ($_POST['action']==='bulk' && isset($_POST['selected_ids'])) {
        $ids=json_decode($_POST['selected_ids'],true); $ba=$_POST['bulk_action']??'';
        if (!empty($ids) && $ba) {
            $ph=implode(',',array_fill(0,count($ids),'?'));
            try {
                if ($ba==='delete') {
                    $s=$db->prepare("SELECT created_by,COUNT(*) FROM properties WHERE created_by IN ($ph) GROUP BY created_by"); $s->execute($ids);
                    if ($s->fetchAll()) { $error="Some agents have properties and cannot be deleted."; }
                    else { $db->prepare("DELETE FROM users WHERE id IN ($ph) AND role_id=2")->execute($ids); $message="Selected agents deleted!"; }
                } elseif (in_array($ba,['active','inactive'])) {
                    $db->prepare("UPDATE users SET status=? WHERE id IN ($ph) AND role_id=2")->execute(array_merge([$ba],$ids));
                    $message="Updated to ".ucfirst($ba)."!";
                }
            } catch(PDOException $e){ error_log($e->getMessage()); $error="Failed to process bulk action."; }
        }
    }
}

$agents = [];
try {
    $agents = $db->query("
        SELECT u.*, COUNT(DISTINCT p.id) as properties_count,
               COUNT(DISTINCT t.id) as tenants_count,
               COUNT(DISTINCT mr.id) as maintenance_count,
               COALESCE(SUM(py.amount),0) as total_collected
        FROM users u
        LEFT JOIN properties p ON p.created_by=u.id
        LEFT JOIN tenants t ON t.property_id=p.id
        LEFT JOIN maintenance_requests mr ON mr.property_id=p.id AND mr.status!='completed'
        LEFT JOIN leases l ON l.property_id=p.id
        LEFT JOIN payments py ON py.lease_id=l.id AND py.status='completed'
        WHERE u.role_id=2
        GROUP BY u.id ORDER BY u.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){ error_log($e->getMessage()); $error="Failed to load agents."; }

$edit_agent = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try { $s=$db->prepare("SELECT * FROM users WHERE id=? AND role_id=2"); $s->execute([$_GET['edit']]); $edit_agent=$s->fetch(PDO::FETCH_ASSOC); } catch(PDOException $e){}
}

$stats = ['total'=>count($agents),'active'=>0,'inactive'=>0,'with_properties'=>0,'total_properties'=>0,'total_tenants'=>0];
foreach ($agents as $a) {
    if (strtolower($a['status']??'active')==='active') $stats['active']++; else $stats['inactive']++;
    if ($a['properties_count']>0) $stats['with_properties']++;
    $stats['total_properties'] += $a['properties_count'];
    $stats['total_tenants']    += $a['tenants_count'];
}

function getStatusBadge($status) {
    $b = strtolower($status)==='active'
        ? ['bg'=>'#d1fae5','color'=>'#065f46','icon'=>'fa-check-circle']
        : ['bg'=>'#fee2e2','color'=>'#991b1b','icon'=>'fa-times-circle'];
    return "<span style='background:{$b['bg']};color:{$b['color']};padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;'><i class='fas {$b['icon']}'></i> ".ucfirst(strtolower($status))."</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Agents - Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',sans-serif; }
        body { background:#f0f2f5; }
        .dashboard { display:flex; min-height:100vh; }

        /* Sidebar */
        .sidebar { width:260px; background:#1e293b; color:white; position:fixed; height:100vh; overflow-y:auto; }
        .sidebar-header { padding:14px 20px; border-bottom:1px solid #334155; }
        .sidebar-header h2 { font-size:20px; font-weight:600; color:white; }
        .sidebar-header p  { font-size:12px; color:#94a3b8; }
        .user-info { padding:12px 20px; background:#0f1a24; }
        .user-info .name  { font-weight:600; font-size:16px; }
        .user-info .email { color:#94a3b8; font-size:12px; margin-top:2px; }
        .menu-item { padding:9px 20px; display:flex; align-items:center; color:#cbd5e1; text-decoration:none; font-size:13px; transition:background 0.2s; }
        .menu-item:hover, .menu-item.active { background:#334155; color:white; border-left:3px solid #3b82f6; }
        .menu-item i { width:24px; margin-right:12px; }
        .menu-divider { height:1px; background:#334155; margin:8px 20px; }

        /* Main */
        .main-content { margin-left:260px; flex:1; padding:24px 32px; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; background:white; padding:15px 25px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
        .breadcrumb { color:#64748b; font-size:14px; }
        .breadcrumb span { color:#0f172a; font-weight:600; }
        .welcome-card { background:linear-gradient(135deg,#7c3aed,#3b82f6); color:white; padding:28px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .welcome-card h1 { font-size:22px; margin-bottom:6px; }
        .welcome-card p  { font-size:14px; opacity:0.85; }
        .admin-badge { background:rgba(255,255,255,0.15); padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px; }

        /* Stats */
        .stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:20px; margin-bottom:25px; }
        .stat-card { background:white; padding:20px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,0.08); display:flex; align-items:center; gap:15px; }
        .stat-icon { width:50px; height:50px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; }
        .stat-icon.blue   { background:#dbeafe; color:#3b82f6; }
        .stat-icon.green  { background:#d1fae5; color:#10b981; }
        .stat-icon.orange { background:#fed7aa; color:#f97316; }
        .stat-icon.purple { background:#ede9fe; color:#7c3aed; }
        .stat-content .stat-label { font-size:13px; color:#64748b; margin-bottom:4px; }
        .stat-content .stat-value { font-size:24px; font-weight:700; color:#0f172a; }
        .stat-content .stat-sub   { font-size:12px; color:#64748b; margin-top:4px; }

        /* Action bar */
        .action-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px; }
        .action-bar h2 { font-size:18px; color:#0f172a; display:flex; align-items:center; gap:8px; }
        .btn-primary   { background:#3b82f6; color:white; border:none; padding:10px 20px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
        .btn-primary:hover { background:#2563eb; }
        .btn-secondary { background:white; border:1px solid #e2e8f0; padding:8px 16px; border-radius:6px; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; gap:6px; }
        .btn-secondary:hover { background:#f8fafc; }
        .btn-danger { background:#ef4444; color:white; border:none; padding:6px 12px; border-radius:6px; font-size:12px; cursor:pointer; }
        .btns-row { display:flex; gap:10px; }

        /* Filters */
        .filters { display:none; gap:15px; flex-wrap:wrap; background:white; padding:15px 20px; border-radius:10px; margin-bottom:20px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        .filter-group { display:flex; align-items:center; gap:8px; }
        .filter-group label { font-size:13px; font-weight:600; color:#475569; }
        .filter-group select, .filter-group input { padding:8px 12px; border:1px solid #e2e8f0; border-radius:6px; font-size:13px; min-width:150px; }

        /* Agent cards */
        .agents-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:20px; }
        .agent-card { background:white; border-radius:10px; padding:20px; box-shadow:0 2px 4px rgba(0,0,0,0.08); display:flex; gap:15px; transition:transform 0.2s; }
        .agent-card:hover { transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,0.12); }
        .agent-avatar { width:64px; height:64px; background:linear-gradient(135deg,#3b82f6,#7c3aed); border-radius:50%; display:flex; align-items:center; justify-content:center; color:white; font-size:26px; font-weight:700; flex-shrink:0; }
        .agent-info { flex:1; }
        .agent-name  { font-size:17px; font-weight:700; color:#0f172a; margin-bottom:2px; }
        .agent-email { font-size:12px; color:#64748b; margin-bottom:10px; }
        .agent-stats { display:flex; gap:18px; margin:12px 0; }
        .stat-item .value { font-size:18px; font-weight:700; color:#0f172a; }
        .stat-item .label { font-size:11px; color:#94a3b8; text-transform:uppercase; }
        .agent-footer { display:flex; justify-content:space-between; align-items:center; margin-top:10px; padding-top:10px; border-top:1px solid #f1f5f9; }
        .agent-actions a, .agent-actions button { background:none; border:none; color:#64748b; cursor:pointer; font-size:15px; padding:5px 7px; border-radius:5px; text-decoration:none; transition:all 0.2s; }
        .agent-actions a:hover { color:#3b82f6; background:#dbeafe; }
        .agent-actions .delete-btn:hover { color:#ef4444; background:#fee2e2; }

        /* Alert */
        .alert { padding:13px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:13px; }
        .alert-success { background:#d4edda; color:#155724; }
        .alert-error   { background:#fee2e2; color:#991b1b; }

        /* Empty */
        .empty-state { text-align:center; padding:60px; background:white; border-radius:10px; }
        .empty-state i { font-size:48px; color:#94a3b8; display:block; margin-bottom:15px; }

        /* Modal */
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; }
        .modal-content { background:white; width:90%; max-width:500px; margin:50px auto; border-radius:10px; padding:25px; max-height:85vh; overflow-y:auto; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #e2e8f0; }
        .modal-header h3 { font-size:18px; color:#0f172a; }
        .close-modal { background:none; border:none; font-size:22px; cursor:pointer; color:#94a3b8; }
        .form-group { margin-bottom:15px; }
        .form-group label { display:block; margin-bottom:5px; font-size:13px; font-weight:600; color:#475569; }
        .form-group input, .form-group select { width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:6px; font-size:14px; }
        .form-group input:focus, .form-group select:focus { outline:none; border-color:#3b82f6; }

        .footer { text-align:center; color:#94a3b8; font-size:12px; margin-top:30px; padding-bottom:20px; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="sidebar">
        <div class="sidebar-header"><h2>ALLIANCE</h2><p>Realtors</p></div>
        <div class="user-info">
            <div class="name"><?= htmlspecialchars($full_name) ?></div>
            <div class="email"><?= htmlspecialchars($email) ?></div>
        </div>
        <a href="dashboard.php"   class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php"  class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php"     class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="agents.php"      class="menu-item active"><i class="fas fa-user-tie"></i> Agents</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php"    class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="reports.php"     class="menu-item"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="menu-divider"></div>
        <a href="settings.php"    class="menu-item"><i class="fas fa-cog"></i> Settings</a>
        <a href="profile.php"     class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php"   class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Agents</span></div>
            <div><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
        </div>

        <div class="welcome-card">
            <div>
                <h1>Agent Management</h1>
                <p><i class="fas fa-user-tie"></i> Manage all agents and their performance</p>
            </div>
            <div class="admin-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-user-tie"></i></div>
                <div class="stat-content">
                    <div class="stat-label">Total Agents</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-sub"><?= $stats['with_properties'] ?> with properties</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-content"><div class="stat-label">Active</div><div class="stat-value"><?= $stats['active'] ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-building"></i></div>
                <div class="stat-content"><div class="stat-label">Properties</div><div class="stat-value"><?= $stats['total_properties'] ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-users"></i></div>
                <div class="stat-content"><div class="stat-label">Tenants</div><div class="stat-value"><?= $stats['total_tenants'] ?></div></div>
            </div>
        </div>

        <div class="action-bar">
            <h2><i class="fas fa-list"></i> All Agents</h2>
            <div class="btns-row">
                <button class="btn-secondary" onclick="toggleFilters()"><i class="fas fa-filter"></i> Filters</button>
                <button class="btn-primary" onclick="document.getElementById('agentModal').style.display='block'"><i class="fas fa-plus"></i> Add Agent</button>
            </div>
        </div>

        <div id="filters" class="filters">
            <div class="filter-group">
                <label>Status:</label>
                <select id="statusFilter" onchange="filterAgents()">
                    <option value="all">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Search:</label>
                <input type="text" id="searchInput" placeholder="Name or email..." onkeyup="filterAgents()">
            </div>
        </div>

        <?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (count($agents) > 0): ?>
        <div class="agents-grid" id="agentsGrid">
            <?php foreach ($agents as $agent): ?>
            <div class="agent-card" data-status="<?= strtolower($agent['status']??'active') ?>" data-properties="<?= $agent['properties_count'] ?>">
                <div class="agent-avatar"><?= strtoupper(substr($agent['full_name'],0,1)) ?></div>
                <div class="agent-info">
                    <div class="agent-name"><?= htmlspecialchars($agent['full_name']) ?></div>
                    <div class="agent-email"><?= htmlspecialchars($agent['email']) ?></div>
                    <?php if (!empty($agent['phone'])): ?>
                    <div style="font-size:12px;color:#64748b;margin-bottom:8px;"><?= htmlspecialchars($agent['phone']) ?></div>
                    <?php endif; ?>
                    <div class="agent-stats">
                        <div class="stat-item"><div class="value"><?= $agent['properties_count'] ?></div><div class="label">Properties</div></div>
                        <div class="stat-item"><div class="value"><?= $agent['tenants_count'] ?></div><div class="label">Tenants</div></div>
                        <div class="stat-item"><div class="value" style="font-size:14px;">KES <?= number_format($agent['total_collected'],0) ?></div><div class="label">Collected</div></div>
                    </div>
                    <div class="agent-footer">
                        <div>
                            <?= getStatusBadge($agent['status']??'active') ?>
                            <?php if ($agent['maintenance_count']>0): ?>
                            <span style="background:#fed7aa;color:#c2410c;padding:2px 8px;border-radius:12px;font-size:11px;margin-left:5px;"><i class="fas fa-tools"></i> <?= $agent['maintenance_count'] ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="agent-actions" style="display:flex;gap:5px;">
                            <a href="?edit=<?= $agent['id'] ?>" title="Edit"><i class="fas fa-edit"></i></a>
                            <button onclick="deleteAgent(<?= $agent['id'] ?>)" class="delete-btn" title="Delete"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-user-tie"></i><h3>No Agents Found</h3><p>Click "Add Agent" to get started.</p></div>
        <?php endif; ?>

        <div class="footer">© <?= date('Y') ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="agentModal" class="modal" style="display:<?= $edit_agent ? 'block' : 'none' ?>;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><?= $edit_agent ? 'Edit Agent' : 'Add New Agent' ?></h3>
            <button class="close-modal" onclick="closeAgentModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="<?= $edit_agent ? 'edit' : 'add' ?>">
            <?php if ($edit_agent): ?><input type="hidden" name="agent_id" value="<?= $edit_agent['id'] ?>"><?php endif; ?>
            <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" value="<?= htmlspecialchars($edit_agent['full_name']??'') ?>" required></div>
            <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?= htmlspecialchars($edit_agent['email']??'') ?>" required></div>
            <div class="form-group"><label>Phone</label><input type="text" name="phone" value="<?= htmlspecialchars($edit_agent['phone']??'') ?>"></div>
            <div class="form-group">
                <label><?= $edit_agent ? 'New Password (leave blank to keep)' : 'Password *' ?></label>
                <input type="password" name="password" <?= $edit_agent ? '' : 'required' ?>>
            </div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="active"   <?= (($edit_agent['status']??'')==='active')  ?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= (($edit_agent['status']??'')==='inactive')?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn-secondary" onclick="closeAgentModal()">Cancel</button>
                <button type="submit" class="btn-primary"><?= $edit_agent ? 'Update Agent' : 'Add Agent' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header"><h3>Confirm Delete</h3><button class="close-modal" onclick="closeDeleteModal()">&times;</button></div>
        <p style="margin:20px 0;color:#334155;">Delete this agent? This cannot be undone.</p>
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="agent_id" id="deleteAgentId">
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function closeAgentModal()  { window.location.href='agents.php'; }
function deleteAgent(id)    { document.getElementById('deleteAgentId').value=id; document.getElementById('deleteModal').style.display='block'; }
function closeDeleteModal() { document.getElementById('deleteModal').style.display='none'; }
function toggleFilters()    { const f=document.getElementById('filters'); f.style.display=f.style.display==='flex'?'none':'flex'; }
function filterAgents() {
    const sf=document.getElementById('statusFilter').value;
    const si=document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.agent-card').forEach(card=>{
        const statusOk = sf==='all' || card.getAttribute('data-status')===sf;
        const searchOk = si==='' || card.textContent.toLowerCase().includes(si);
        card.style.display = statusOk && searchOk ? 'flex' : 'none';
    });
}
window.onclick = e => { if (e.target.classList.contains('modal')) e.target.style.display='none'; }
</script>
</body>
</html>