<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

define('ROLE_TENANT', 3);

if (!isset($_SESSION['user_id']) || (int)($_SESSION['role_id'] ?? 0) !== ROLE_TENANT) {
    header("Location: login.php", true, 303);
    exit();
}

try {
    $db = Database::getInstance()->getConnection();
    $search = trim($_GET['search'] ?? '');

    if (!empty($search)) {
        $stmt = $db->prepare("SELECT * FROM properties WHERE title LIKE ? OR description LIKE ? OR location LIKE ? OR type LIKE ? ORDER BY id DESC");
        $like = "%$search%";
        $stmt->execute([$like, $like, $like, $like]);
    } else {
        $stmt = $db->prepare("SELECT * FROM properties ORDER BY id DESC");
        $stmt->execute();
    }

    $properties       = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_properties = count($properties);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $properties = []; $total_properties = 0;
    $db_error   = "Unable to load properties.";
}

$base = '/Alliance/assets/Images/';
$three_images = [
    'alejandra-cifre-gonzalez-ylyn5r4vxcA-unsplash.jpg',
    'charles-sukarsa-47AoUoaOv08-unsplash.jpg',
    'stephan-bechert-yFV39g6AZ5o-unsplash.jpg',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Properties - Alliance Realtors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',sans-serif; }
        body { background:#f0f2f5; }
        .dashboard { display:flex; min-height:100vh; }
        .sidebar { width:260px; background:#1e293b; color:white; position:fixed; height:100vh; overflow-y:auto; }
        .sidebar-header { padding:24px 20px; border-bottom:1px solid #334155; }
        .sidebar-header h2 { font-size:20px; font-weight:600; }
        .sidebar-header p  { font-size:12px; color:#94a3b8; }
        .user-info { padding:20px; background:#0f1a24; }
        .user-info .name  { font-weight:600; font-size:16px; }
        .user-info .email { color:#94a3b8; font-size:12px; }
        .menu-item { padding:12px 20px; display:flex; align-items:center; color:#cbd5e1; text-decoration:none; font-size:14px; transition:background 0.2s; }
        .menu-item:hover,.menu-item.active { background:#334155; color:white; border-left:3px solid #3b82f6; }
        .menu-item i { width:24px; margin-right:12px; }
        .menu-divider { height:1px; background:#334155; margin:15px 20px; }
        .main-content { flex:1; margin-left:260px; padding:24px 32px; }
        .top-bar { background:white; padding:15px 25px; border-radius:10px; box-shadow:0 2px 4px rgba(0,0,0,0.1); margin-bottom:25px; display:flex; justify-content:space-between; align-items:center; }
        .breadcrumb { color:#64748b; font-size:14px; }
        .breadcrumb span { color:#0f172a; font-weight:600; }
        h1 { font-size:24px; margin-bottom:20px; color:#0f172a; }
        .search-box { display:flex; gap:10px; margin-bottom:20px; }
        .search-box input { flex:1; padding:12px; border:1px solid #ddd; border-radius:5px; font-size:14px; }
        .search-box input:focus { outline:none; border-color:#3b82f6; }
        .search-box button { padding:12px 24px; background:#3b82f6; color:white; border:none; border-radius:5px; cursor:pointer; }
        .search-info { background:white; padding:15px; border-radius:5px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; font-size:14px; color:#64748b; }
        .search-info a { color:#3b82f6; text-decoration:none; }
        .properties-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:20px; }
        .property-card { background:white; border-radius:10px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.1); transition:transform 0.2s,box-shadow 0.2s; }
        .property-card:hover { transform:translateY(-3px); box-shadow:0 6px 16px rgba(0,0,0,0.12); }
        .card-img { height:200px; overflow:hidden; position:relative; background:#1e293b; }
        .card-img img { width:100%; height:100%; object-fit:cover; display:block; transition:transform 0.35s; }
        .property-card:hover .card-img img { transform:scale(1.05); }
        .card-img-placeholder { width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#334155,#1e293b); color:white; font-size:52px; }
        .card-status { position:absolute; top:12px; right:12px; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; text-transform:uppercase; }
        .card-status.available   { background:#d1fae5; color:#065f46; }
        .card-status.occupied    { background:#fee2e2; color:#991b1b; }
        .card-status.maintenance { background:#fef3c7; color:#92400e; }
        .card-content { padding:20px; }
        .card-content h4 { font-size:17px; font-weight:700; margin-bottom:5px; color:#0f172a; }
        .card-location { color:#64748b; font-size:13px; margin-bottom:8px; display:flex; align-items:center; gap:5px; }
        .card-price { font-size:20px; font-weight:800; color:#3b82f6; margin-bottom:10px; }
        .card-type { display:inline-block; padding:3px 10px; background:#e0f2fe; color:#0369a1; border-radius:20px; font-size:12px; margin-bottom:14px; }
        .btn-details { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; background:linear-gradient(135deg,#3b82f6,#2563eb); color:white; text-decoration:none; border-radius:6px; font-size:13px; font-weight:600; }
        .no-results { grid-column:1/-1; text-align:center; padding:60px; background:white; border-radius:10px; color:#64748b; }
        .no-results i { font-size:48px; color:#94a3b8; margin-bottom:15px; display:block; }
        .alert-error { background:#fee2e2; color:#c33; padding:12px 16px; border-radius:5px; margin-bottom:20px; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="sidebar">
        <div class="sidebar-header"><h2>ALLIANCE</h2><p>Realtors</p></div>
        <div class="user-info">
            <div class="name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? ''); ?></div>
            <div class="email"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></div>
        </div>
        <a href="dashboard.php"   class="menu-item"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="properties.php"  class="menu-item active"><i class="fas fa-building"></i> Browse Properties</a>
        <a href="payments.php"    class="menu-item"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="invoices.php"    class="menu-item"><i class="fas fa-file-invoice"></i> Invoices</a>
        <a href="maintenance.php" class="menu-item"><i class="fas fa-tools"></i> Maintenance</a>
        <div class="menu-divider"></div>
        <a href="profile.php"     class="menu-item"><i class="fas fa-user-circle"></i> Profile</a>
        <a href="../logout.php"      class="menu-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="breadcrumb"><i class="fas fa-home"></i> Home / <span>Properties</span></div>
            <div><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></div>
        </div>

        <h1>Available Properties</h1>

        <?php if (isset($db_error)): ?>
            <div class="alert-error"><?php echo htmlspecialchars($db_error); ?></div>
        <?php endif; ?>

        <form method="GET" action="" class="search-box">
            <input type="text" name="search" placeholder="Search by title, location, or type..."
                   value="<?php echo htmlspecialchars($search ?? ''); ?>">
            <button type="submit"><i class="fas fa-search"></i> Search</button>
        </form>

        <?php if (!empty($search)): ?>
            <div class="search-info">
                <span>Found <strong><?php echo $total_properties; ?></strong>
                <?php echo $total_properties === 1 ? 'property' : 'properties'; ?>
                matching "<?php echo htmlspecialchars($search); ?>"</span>
                <a href="properties.php"><i class="fas fa-times"></i> Clear search</a>
            </div>
        <?php endif; ?>

        <div class="properties-grid">
            <?php if ($total_properties > 0): ?>
                <?php foreach ($properties as $i => $property):
                    $db_img = trim($property['image'] ?? '');
                    // Use DB image if valid filename, else assign one of 3 photos to first 3 cards
                    if (!empty($db_img) && $db_img !== 'default.jpg') {
                        $img_src = $base . $db_img;
                        $has_img = true;
                    } elseif ($i < 3) {
                        $img_src = $base . $three_images[$i];
                        $has_img = true;
                    } else {
                        $has_img = false;
                    }
                    $status = strtolower($property['status'] ?? 'available');
                ?>
                <div class="property-card">
                    <div class="card-img">
                        <?php if ($has_img): ?>
                            <img src="<?php echo htmlspecialchars($img_src); ?>"
                                 alt="<?php echo htmlspecialchars($property['title']); ?>"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <div class="card-img-placeholder" style="display:none;"><i class="fas fa-building"></i></div>
                        <?php else: ?>
                            <div class="card-img-placeholder"><i class="fas fa-building"></i></div>
                        <?php endif; ?>
                        <span class="card-status <?php echo $status; ?>"><?php echo ucfirst($status); ?></span>
                    </div>
                    <div class="card-content">
                        <h4><?php echo htmlspecialchars($property['title']); ?></h4>
                        <?php if (!empty($property['type'])): ?>
                            <span class="card-type"><?php echo htmlspecialchars($property['type']); ?></span>
                        <?php endif; ?>
                        <div class="card-location">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($property['location'] ?? 'Nairobi'); ?>
                        </div>
                        <div class="card-price">KES <?php echo number_format((float)($property['price'] ?? 0)); ?>/mo</div>
                        <a href="property_details.php?id=<?php echo (int)$property['id']; ?>" class="btn-details">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-building"></i>
                    <h3>No properties found</h3>
                    <p><?php echo !empty($search) ? 'Try a different search term.' : 'Check back later for new listings.'; ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>