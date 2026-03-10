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
    if ($_POST['action'] === 'update_status') {
        $id     = (int)$_POST['request_id'];
        $status = strtolower(trim($_POST['status']));
        if (in_array($status, ['pending','in_progress','completed','cancelled'])) {
            $db->prepare("UPDATE maintenance_requests SET status=? WHERE id=?")->execute([$status, $id]);
            $message = "Status updated successfully.";
        }
    }
    if ($_POST['action'] === 'update_priority') {
        $id       = (int)$_POST['request_id'];
        $priority = strtolower(trim($_POST['priority']));
        if (in_array($priority, ['low','medium','high','urgent'])) {
            $db->prepare("UPDATE maintenance_requests SET priority=? WHERE id=?")->execute([$priority, $id]);
            $message = "Priority updated successfully.";
        }
    }
    if ($_POST['action'] === 'delete') {
        $id = (int)$_POST['request_id'];
        $db->prepare("DELETE FROM maintenance_requests WHERE id=?")->execute([$id]);
        $message = "Request deleted successfully.";
    }
}

$tab          = $_GET['tab'] ?? 'all';
$allowed_tabs = ['all','pending','in_progress','completed','cancelled'];
if (!in_array($tab, $allowed_tabs)) $tab = 'all';

$counts = ['all'=>0,'pending'=>0,'in_progress'=>0,'completed'=>0,'cancelled'=>0];
foreach ($db->query("SELECT status, COUNT(*) cnt FROM maintenance_requests GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $counts[$row['status']] = (int)$row['cnt'];
    $counts['all'] += (int)$row['cnt'];
}

$where  = $tab !== 'all' ? 'WHERE mr.status=?' : '';
$params = $tab !== 'all' ? [$tab] : [];
$stmt   = $db->prepare("
    SELECT mr.*, t.name tenant_name, t.email tenant_email,
           p.title property_title, p.location property_location,
           u.unit_number
    FROM maintenance_requests mr
    LEFT JOIN tenants t    ON t.id = mr.tenant_id
    LEFT JOIN properties p ON p.id = mr.property_id
    LEFT JOIN units u      ON u.id = mr.unit_id
    $where
    ORDER BY mr.created_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance - Admin | Alliance Realtors</title>
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

        /* Welcome */
        .welcome-card { background:linear-gradient(135deg,#7c3aed,#3b82f6); color:white; padding:28px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; }
        .welcome-card h1 { font-size:22px; margin-bottom:6px; }
        .welcome-card p  { font-size:14px; opacity:0.85; }
        .admin-badge { background:rgba(255,255,255,0.15); padding:8px 16px; border-radius:20px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px; }

        /* Stat cards */
        .stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:18px; margin-bottom:25px; }
        .stat-card { background:white; border-radius:10px; padding:20px; display:flex; align-items:center; gap:15px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        .stat-icon { width:50px; height:50px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .stat-icon.yellow { background:#fef3c7; color:#f59e0b; }
        .stat-icon.blue   { background:#dbeafe; color:#3b82f6; }
        .stat-icon.green  { background:#d1fae5; color:#10b981; }
        .stat-icon.red    { background:#fee2e2; color:#ef4444; }
        .stat-label { font-size:12px; color:#64748b; margin-bottom:4px; }
        .stat-value { font-size:24px; font-weight:700; color:#0f172a; }

        /* Tabs */
        .tabs { display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap; }
        .tab-link { padding:8px 18px; border-radius:6px; text-decoration:none; color:#334155; font-size:13px; background:white; box-shadow:0 1px 3px rgba(0,0,0,0.08); font-weight:500; transition:all 0.2s; }
        .tab-link:hover { background:#f1f5f9; }
        .tab-link.active { background:#3b82f6; color:white; }
        .tab-count { background:rgba(255,255,255,0.25); border-radius:10px; padding:1px 7px; font-size:11px; margin-left:4px; }
        .tab-link:not(.active) .tab-count { background:#f1f5f9; color:#64748b; }

        /* Table */
        .table-card { background:white; border-radius:10px; padding:22px; box-shadow:0 2px 4px rgba(0,0,0,0.08); }
        .table-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
        .table-header h3 { font-size:15px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; }
        table { width:100%; border-collapse:collapse; }
        th { text-align:left; padding:10px 12px; background:#f8fafc; color:#475569; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; border-bottom:2px solid #e2e8f0; }
        td { padding:12px; border-bottom:1px solid #f8fafc; font-size:13px; color:#334155; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        tr:hover td { background:#f8fafc; }
        .empty-row td { text-align:center; color:#94a3b8; padding:40px; }

        /* Badges */
        .badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600; }
        .badge-pending     { background:#fef3c7; color:#92400e; }
        .badge-in_progress { background:#dbeafe; color:#1e40af; }
        .badge-completed   { background:#d1fae5; color:#065f46; }
        .badge-cancelled   { background:#f1f5f9; color:#64748b; }
        .badge-low         { background:#d1fae5; color:#065f46; }
        .badge-medium      { background:#fef3c7; color:#92400e; }
        .badge-high        { background:#fee2e2; color:#991b1b; }
        .badge-urgent      { background:#7f1d1d; color:white; }

        /* Inline dropdowns */
        select.inline { border:1px solid #e2e8f0; border-radius:6px; padding:4px 8px; font-size:12px; background:white; cursor:pointer; }
        select.inline:focus { outline:none; border-color:#3b82f6; }

        /* Action buttons */
        .btn-icon { background:none; border:none; cursor:pointer; padding:5px 8px; border-radius:6px; font-size:14px; transition:background 0.2s; }
        .btn-icon.view   { color:#3b82f6; } .btn-icon.view:hover   { background:#dbeafe; }
        .btn-icon.delete { color:#ef4444; } .btn-icon.delete:hover { background:#fee2e2; }

        /* Alerts */
        .alert { padding:13px 18px; border-radius:8px; margin-bottom:20px; font-size:13px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:#d4edda; color:#155724; border-left:3px solid #28a745; }

        /* Modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.open { display:flex; }
        .modal { background:white; border-radius:12px; padding:28px; width:520px; max-width:95vw; max-height:90vh; overflow-y:auto; }
        .modal h3 { font-size:17px; font-weight:700; margin-bottom:18px; color:#0f172a; }
        .modal-row { display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #f1f5f9; font-size:13px; }
        .modal-row:last-child { border-bottom:none; }
        .modal-label { color:#64748b; font-weight:600; }
        .modal-value { color:#0f172a; text-align:right; max-width:60%; }
        .modal-close { float:right; background:none; border:none; font-size:20px; cursor:pointer; color:#94a3b8; margin-top:-5px; }
        .modal-desc { background:#f8fafc; border-radius:8px; padding:14px; margin-top:12px; font-size:13px; color:#334155; line-height:1.6; }

        .footer { text-align:center; color:#94a3b8; font-size:12px; margin-top:30px; padding-bottom:20px; }
    </style>
</head>
<body>
<div class="dashboard">

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header"><h2>ALLIANCE</h2><p>Realtors</p></div>
        <div class="user-info">
            <div class="name"><?php echo htmlspecialchars($full_name); ?></div>
            <div class="email"><?php echo htmlspecialchars($email); ?></div>
        </div>
        <a href="dashboard.php"   class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php"  class="menu-item"><i class="fas fa-building"></i> Properties</a>
        <a href="tenants.php"     class="menu-item"><i class="fas fa-users"></i> Tenants</a>
        <a href="agents.php"      class="menu-item"><i class="fas fa-user-tie"></i> Agents</a>
        <a href="maintenance.php" class="menu-item active"><i class="fas fa-tools"></i> Maintenance</a>
        <a href="payments.php"    class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="reports.php"     class="menu-item"><i class="fas fa-chart-bar"></i> Reports</a>
        <div class="menu-divider"></div>
        <a href="settings.php"    class="menu-item"><i class="fas fa-cog"></i> Settings</a>
        <a href="profile.php"     class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php"   class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <!-- Main -->
    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Maintenance</span></div>
            <div><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Welcome -->
        <div class="welcome-card">
            <div>
                <h1>Maintenance Requests</h1>
                <p><i class="fas fa-tools"></i> Manage and track maintenance across all properties</p>
            </div>
            <div class="admin-badge"><i class="fas fa-crown"></i> Administrator</div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fas fa-clock"></i></div>
                <div><div class="stat-label">Pending</div><div class="stat-value"><?php echo $counts['pending']; ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-spinner"></i></div>
                <div><div class="stat-label">In Progress</div><div class="stat-value"><?php echo $counts['in_progress']; ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div><div class="stat-label">Completed</div><div class="stat-value"><?php echo $counts['completed']; ?></div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                <div><div class="stat-label">Cancelled</div><div class="stat-value"><?php echo $counts['cancelled']; ?></div></div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <?php foreach (['all'=>'All','pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'] as $key => $label): ?>
            <a href="?tab=<?php echo $key; ?>" class="tab-link <?php echo $tab===$key?'active':''; ?>">
                <?php echo $label; ?> <span class="tab-count"><?php echo $counts[$key]; ?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Table -->
        <div class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-list" style="color:#7c3aed"></i>
                    <?php echo $tab==='all' ? 'All Requests' : ucwords(str_replace('_',' ',$tab)).' Requests'; ?>
                </h3>
                <span style="font-size:13px;color:#64748b;"><?php echo count($requests); ?> record(s)</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Tenant</th>
                        <th>Property</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($requests)): ?>
                    <tr class="empty-row"><td colspan="8"><i class="fas fa-tools" style="font-size:32px;color:#e2e8f0;display:block;margin-bottom:10px;"></i>No requests found</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td style="color:#94a3b8;">#<?php echo $r['id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($r['title']); ?></strong></td>
                        <td>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($r['tenant_name'] ?? '—'); ?></div>
                            <div style="font-size:11px;color:#94a3b8;"><?php echo htmlspecialchars($r['tenant_email'] ?? ''); ?></div>
                        </td>
                        <td>
                            <div><?php echo htmlspecialchars($r['property_title'] ?? '—'); ?></div>
                            <?php if (!empty($r['unit_number'])): ?>
                            <div style="font-size:11px;color:#94a3b8;">Unit <?php echo htmlspecialchars($r['unit_number']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_priority">
                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                <select name="priority" class="inline" onchange="this.form.submit()">
                                    <?php foreach (['low','medium','high','urgent'] as $p): ?>
                                    <option value="<?php echo $p; ?>" <?php echo $r['priority']===$p?'selected':''; ?>><?php echo ucfirst($p); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                <select name="status" class="inline" onchange="this.form.submit()">
                                    <?php foreach (['pending','in_progress','completed','cancelled'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php echo $r['status']===$s?'selected':''; ?>><?php echo ucwords(str_replace('_',' ',$s)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td style="color:#64748b;"><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
                        <td>
                            <button class="btn-icon view" onclick='openModal(<?php echo json_encode($r); ?>)' title="View"><i class="fas fa-eye"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this request?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                <button type="submit" class="btn-icon delete" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="footer">© <?php echo date('Y'); ?> Alliance Realtors. All rights reserved.</div>
    </div>
</div>

<!-- View Modal -->
<div class="modal-overlay" id="modal">
    <div class="modal">
        <button class="modal-close" onclick="closeModal()">×</button>
        <h3><i class="fas fa-tools" style="color:#7c3aed;margin-right:8px;"></i> Request Details</h3>
        <div id="modal-body"></div>
    </div>
</div>

<script>
function openModal(r) {
    const priority_colors = {low:'#065f46',medium:'#92400e',high:'#991b1b',urgent:'white'};
    const priority_bg     = {low:'#d1fae5',medium:'#fef3c7',high:'#fee2e2',urgent:'#7f1d1d'};
    const status_colors   = {pending:'#92400e',in_progress:'#1e40af',completed:'#065f46',cancelled:'#64748b'};
    const status_bg       = {pending:'#fef3c7',in_progress:'#dbeafe',completed:'#d1fae5',cancelled:'#f1f5f9'};

    const p  = r.priority || 'low';
    const s  = r.status   || 'pending';

    document.getElementById('modal-body').innerHTML = `
        <div class="modal-row"><span class="modal-label">Title</span><span class="modal-value">${r.title}</span></div>
        <div class="modal-row"><span class="modal-label">Tenant</span><span class="modal-value">${r.tenant_name||'—'}</span></div>
        <div class="modal-row"><span class="modal-label">Property</span><span class="modal-value">${r.property_title||'—'}</span></div>
        <div class="modal-row"><span class="modal-label">Unit</span><span class="modal-value">${r.unit_number||'—'}</span></div>
        <div class="modal-row"><span class="modal-label">Priority</span><span class="modal-value"><span style="background:${priority_bg[p]};color:${priority_colors[p]};padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">${p.charAt(0).toUpperCase()+p.slice(1)}</span></span></div>
        <div class="modal-row"><span class="modal-label">Status</span><span class="modal-value"><span style="background:${status_bg[s]};color:${status_colors[s]};padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">${s.replace('_',' ').replace(/\b\w/g,c=>c.toUpperCase())}</span></span></div>
        <div class="modal-row"><span class="modal-label">Date</span><span class="modal-value">${r.created_at}</span></div>
        ${r.description ? `<div class="modal-desc"><strong>Description:</strong><br>${r.description}</div>` : ''}
    `;
    document.getElementById('modal').classList.add('open');
}
function closeModal() {
    document.getElementById('modal').classList.remove('open');
}
document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>