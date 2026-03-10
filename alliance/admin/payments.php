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

$db = Database::getInstance()->getConnection();

$message = '';
$error   = '';

/* -------------------- HANDLE POST ACTIONS -------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_status') {

        $id     = (int)$_POST['payment_id'];
        $status = strtolower($_POST['status']);

        if (in_array($status, ['completed','pending','failed'])) {

            $stmt = $db->prepare("UPDATE payments SET status=? WHERE id=?");
            $stmt->execute([$status,$id]);

            $message = "Payment status updated.";
        }
    }

    elseif ($_POST['action'] === 'delete') {

        $id = (int)$_POST['payment_id'];

        $stmt = $db->prepare("DELETE FROM payments WHERE id=?");
        $stmt->execute([$id]);

        $message = "Payment deleted.";
    }

    elseif ($_POST['action'] === 'add') {

        $tenant_id    = (int)$_POST['tenant_id'];
        $lease_id     = (int)$_POST['lease_id'];
        $amount       = floatval($_POST['amount']);
        $payment_date = $_POST['payment_date'];
        $method       = strtolower($_POST['payment_method']);
        $status       = strtolower($_POST['status']);

        $transaction  = trim($_POST['transaction_code']);
        $receipt      = trim($_POST['receipt_number']);

        if ($tenant_id && $lease_id && $amount && $payment_date) {

            $stmt = $db->prepare("
                INSERT INTO payments
                (tenant_id, lease_id, amount, payment_date, payment_method, transaction_code, receipt_number, status)
                VALUES (?,?,?,?,?,?,?,?)
            ");

            $stmt->execute([
                $tenant_id,
                $lease_id,
                $amount,
                $payment_date,
                $method,
                $transaction ?: null,
                $receipt ?: null,
                $status
            ]);

            $message = "Payment recorded successfully.";
        }
    }
}


/* -------------------- FILTER TAB -------------------- */

$tab = $_GET['tab'] ?? 'all';

if(!in_array($tab,['all','completed','pending','failed'])){
    $tab='all';
}


/* -------------------- PAYMENT COUNTS -------------------- */

$counts = ['all'=>0,'completed'=>0,'pending'=>0,'failed'=>0];
$totals = ['completed'=>0,'pending'=>0];

$stmt = $db->query("
SELECT status, COUNT(*) c, COALESCE(SUM(amount),0) total
FROM payments
GROUP BY status
");

foreach($stmt as $r){

    $s = strtolower($r['status']);

    if(isset($counts[$s])){
        $counts[$s] = $r['c'];
        $totals[$s] = $r['total'];
    }

    $counts['all'] += $r['c'];
}


/* -------------------- FETCH PAYMENTS -------------------- */

$where = '';
$params = [];

if($tab !== 'all'){
    $where = "WHERE p.status=?";
    $params[] = $tab;
}

$stmt = $db->prepare("
SELECT
p.*,
t.name tenant_name,
t.email tenant_email,
l.monthly_rent,
pr.title property_title
FROM payments p
LEFT JOIN tenants t ON t.id=p.tenant_id
LEFT JOIN leases l ON l.id=p.lease_id
LEFT JOIN properties pr ON pr.id=l.property_id
$where
ORDER BY p.payment_date DESC
");

$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* -------------------- TENANTS -------------------- */

$tenants = $db->query("
SELECT id,name,email
FROM tenants
ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);


/* -------------------- LEASES -------------------- */

$leases = $db->query("
SELECT
l.id,
l.tenant_id,
l.monthly_rent,
t.name tenant_name,
pr.title property_title
FROM leases l
LEFT JOIN tenants t ON t.id=l.tenant_id
LEFT JOIN properties pr ON pr.id=l.property_id
ORDER BY t.name
")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html>
<head>

<title>Payments | Alliance Realtors</title>

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>

*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI'}

body{background:#f0f2f5}

.dashboard{display:flex}

/* SIDEBAR */

.sidebar{
width:260px;
background:#1e293b;
color:white;
position:fixed;
height:100vh
}

.sidebar-header{padding:14px 20px;border-bottom:1px solid #334155}

.sidebar-header h2{font-size:20px}

.sidebar-header p{font-size:12px;color:#94a3b8}

.user-info{padding:12px 20px;background:#0f1a24}

.user-info .name{font-weight:600}

.user-info .email{font-size:12px;color:#94a3b8}

.menu-item{
display:flex;
align-items:center;
padding:9px 20px;
text-decoration:none;
color:#cbd5e1;
font-size:13px
}

.menu-item:hover,
.menu-item.active{
background:#334155;
color:white;
border-left:3px solid #3b82f6
}

.menu-item i{width:24px;margin-right:12px}

.menu-divider{height:1px;background:#334155;margin:8px 20px}

/* MAIN */

.main-content{
margin-left:260px;
padding:24px 32px;
flex:1
}

.top-bar{
display:flex;
justify-content:space-between;
background:white;
padding:15px 25px;
border-radius:10px;
margin-bottom:25px
}

.table-container{
background:white;
border-radius:10px;
padding:20px
}

table{
width:100%;
border-collapse:collapse
}

th{
text-align:left;
padding:12px;
background:#f8fafc;
font-size:12px
}

td{
padding:12px;
border-bottom:1px solid #f1f5f9;
font-size:13px
}

.btn{
background:#3b82f6;
border:none;
color:white;
padding:8px 14px;
border-radius:6px;
cursor:pointer
}

.alert{
padding:12px;
margin-bottom:15px;
border-radius:6px
}

.alert-success{background:#d1fae5;color:#065f46}
.alert-error{background:#fee2e2;color:#991b1b}

</style>
</head>

<body>

<div class="dashboard">

<!-- SIDEBAR -->

<div class="sidebar">

<div class="sidebar-header">
<h2>ALLIANCE</h2>
<p>Realtors</p>
</div>

<div class="user-info">
<div class="name"><?=htmlspecialchars($full_name)?></div>
<div class="email"><?=htmlspecialchars($email)?></div>
</div>

<a href="dashboard.php" class="menu-item"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
<a href="properties.php" class="menu-item"><i class="fas fa-building"></i>Properties</a>
<a href="tenants.php" class="menu-item"><i class="fas fa-users"></i>Tenants</a>
<a href="agents.php" class="menu-item"><i class="fas fa-user-tie"></i>Agents</a>
<a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i>Maintenance</a>
<a href="payments.php" class="menu-item active"><i class="fas fa-credit-card"></i>Payments</a>
<a href="reports.php" class="menu-item"><i class="fas fa-chart-bar"></i>Reports</a>

<div class="menu-divider"></div>

<a href="settings.php" class="menu-item"><i class="fas fa-cog"></i>Settings</a>
<a href="profile.php" class="menu-item"><i class="fas fa-user-circle"></i>Profile</a>
<a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i>Logout</a>

</div>

<!-- MAIN -->

<div class="main-content">

<div class="top-bar">
<div><i class="fas fa-home"></i> Home / <strong>Payments</strong></div>
<div><?=date('l, F j, Y')?></div>
</div>


<?php if($message): ?>
<div class="alert alert-success"><?=$message?></div>
<?php endif ?>

<?php if($error): ?>
<div class="alert alert-error"><?=$error?></div>
<?php endif ?>


<div class="table-container">

<table>

<thead>
<tr>
<th>ID</th>
<th>Tenant</th>
<th>Property</th>
<th>Amount</th>
<th>Date</th>
<th>Status</th>
<th>Actions</th>
</tr>
</thead>

<tbody>

<?php foreach($payments as $p): ?>

<tr>

<td>#<?=$p['id']?></td>

<td>
<strong><?=htmlspecialchars($p['tenant_name'])?></strong>
<br>
<small><?=$p['tenant_email']?></small>
</td>

<td><?=htmlspecialchars($p['property_title'])?></td>

<td>KES <?=number_format($p['amount'])?></td>

<td><?=date('M j, Y',strtotime($p['payment_date']))?></td>

<td><?=ucfirst($p['status'])?></td>

<td>

<form method="POST" style="display:inline">

<input type="hidden" name="action" value="delete">
<input type="hidden" name="payment_id" value="<?=$p['id']?>">

<button class="btn" onclick="return confirm('Delete payment?')">
Delete
</button>

</form>

</td>

</tr>

<?php endforeach ?>

</tbody>

</table>

</div>

</div>

</div>

</body>
</html>