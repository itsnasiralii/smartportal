<?php
require_once __DIR__ . '/auth.php';
require_once 'db.php';
require_once 'noc_helpers.php';
require_once 'router_config_parser.php';

$admin_passcode = getenv('NOC_ADMIN_PASSWORD') ?: 'admin123';
$authenticated = !empty($_SESSION['admin_logged_in']) || (($_SESSION['noc_role'] ?? '') === 'admin');

if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (hash_equals($admin_passcode, $_POST['password'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['noc_logged_in'] = true;
        if (empty($_SESSION['noc_user'])) {
            $_SESSION['noc_user'] = 'admin';
            $_SESSION['noc_role'] = 'admin';
        }
        $authenticated = true;
    } else {
        $login_error = "Invalid administrator passcode!";
    }
}

$status_msg = '';

// CRUD: DELETE COMPLAINT
if ($authenticated && isset($_POST['del_complaint'])) {
    $cid = intval($_POST['del_complaint']);
    $pdo->prepare("DELETE FROM complaints WHERE id = ?")->execute([$cid]);
    $status_msg = "Complaint record #{$cid} deleted successfully.";
}

// CRUD: ADD NEW VENDOR
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_vendor') {
    $vname = trim($_POST['vendor_name'] ?? '');
    if (!empty($vname)) {
        try {
            $pdo->prepare("INSERT INTO vendors (name) VALUES (?)")->execute([$vname]);
            $status_msg = "Vendor '{$vname}' added successfully.";
        } catch (PDOException $e) {
            $status_msg = "Vendor already exists or error: " . $e->getMessage();
        }
    }
}

// CRUD: DELETE VENDOR
if ($authenticated && isset($_POST['del_vendor'])) {
    $vid = intval($_POST['del_vendor']);
    $pdo->prepare("DELETE FROM vendors WHERE id = ?")->execute([$vid]);
    $pdo->prepare("DELETE FROM vendor_contacts WHERE vendor_id = ?")->execute([$vid]);
    $status_msg = "Vendor and all its contacts deleted.";
}

// CRUD: ADD CONTACT TO VENDOR
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_contact') {
    $vid = intval($_POST['vendor_id'] ?? 0);
    $lvl = trim($_POST['level'] ?? 'Level 1');
    $name = trim($_POST['name'] ?? '');
    $desig = trim($_POST['designation'] ?? '');
    $time = trim($_POST['escalation_time'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($vid > 0 && !empty($name) && !empty($email)) {
        $stmt = $pdo->prepare("INSERT INTO vendor_contacts (vendor_id, level, name, designation, escalation_time, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$vid, $lvl, $name, $desig, $time, $phone, $email]);
        $status_msg = "Contact person '{$name}' added to vendor matrix.";
    }
}

// CRUD: UPDATE VENDOR CONTACT
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_contact') {
    $cid = intval($_POST['contact_id'] ?? 0);
    $lvl = trim($_POST['level'] ?? 'Level 1');
    $name = trim($_POST['name'] ?? '');
    $desig = trim($_POST['designation'] ?? '');
    $time = trim($_POST['escalation_time'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($cid > 0 && !empty($name)) {
        $stmt = $pdo->prepare("UPDATE vendor_contacts SET level = ?, name = ?, designation = ?, escalation_time = ?, phone = ?, email = ? WHERE id = ?");
        $stmt->execute([$lvl, $name, $desig, $time, $phone, $email, $cid]);
        $status_msg = "Contact person '{$name}' updated successfully.";
    }
}

// CRUD: DELETE VENDOR CONTACT
if ($authenticated && isset($_POST['del_contact'])) {
    $cid = intval($_POST['del_contact']);
    $pdo->prepare("DELETE FROM vendor_contacts WHERE id = ?")->execute([$cid]);
    $status_msg = "Contact person removed from matrix.";
}

// CRUD: ADD NEW USER
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $uname = trim($_POST['new_username'] ?? '');
    $upass = (string)($_POST['new_password'] ?? '');
    $urole = in_array($_POST['new_role'] ?? '', ['admin', 'user']) ? $_POST['new_role'] : 'user';

    $req_perms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
    $valid_perms = array_values(array_intersect($req_perms, array_keys($NOC_FEATURES)));
    $perms_val = ($urole === 'admin' || count($valid_perms) === count($NOC_FEATURES)) ? 'all' : json_encode($valid_perms);

    if (!empty($uname) && !empty($upass)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, permissions) VALUES (?, ?, ?, ?)");
            $stmt->execute([$uname, password_hash($upass, PASSWORD_DEFAULT), $urole, $perms_val]);
            $status_msg = "User account '{$uname}' created successfully with role: " . ucfirst($urole) . ".";
        } catch (PDOException $e) {
            $status_msg = "Error creating user: Username '{$uname}' may already exist.";
        }
    } else {
        $status_msg = "Error: Username and password are required.";
    }
}

// CRUD: UPDATE USER PERMISSIONS
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user_permissions') {
    $uid = intval($_POST['user_id'] ?? 0);
    $req_perms = isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : [];
    $valid_perms = array_values(array_intersect($req_perms, array_keys($NOC_FEATURES)));
    $perms_val = (count($valid_perms) === count($NOC_FEATURES)) ? 'all' : json_encode($valid_perms);

    if ($uid > 0) {
        $stmt = $pdo->prepare("UPDATE users SET permissions = ? WHERE id = ?");
        $stmt->execute([$perms_val, $uid]);
        $status_msg = "Permissions updated successfully.";
    }
}

// CRUD: UPDATE USER PASSWORD
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user_password') {
    $uid = intval($_POST['user_id'] ?? 0);
    $upass = (string)($_POST['updated_password'] ?? '');

    if ($uid > 0 && !empty($upass)) {
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([password_hash($upass, PASSWORD_DEFAULT), $uid]);
        $status_msg = "Password updated successfully.";
    }
}

// CRUD: DELETE USER
if ($authenticated && isset($_POST['del_user'])) {
    $uid = intval($_POST['del_user']);
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $target_user = $stmt->fetchColumn();

    if ($target_user && strtolower($target_user) === strtolower($_SESSION['noc_user'] ?? '')) {
        $status_msg = "Error: You cannot delete your own currently active account.";
    } elseif ($uid > 0) {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
        $status_msg = "User account '{$target_user}' deleted successfully.";
    }
}

// CRUD: ADD VPBX IVR
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'admin_add_ivr') {
    $msg = trim($_POST['ivr_message'] ?? '');
    if (!empty($msg)) {
        try {
            $pdo->prepare("INSERT INTO vpbx_ivrs (message, is_default) VALUES (?, 0)")->execute([$msg]);
            $status_msg = "VPBX IVR option '{$msg}' added successfully.";
        } catch (PDOException $e) {
            $status_msg = "IVR option already exists or database error: " . $e->getMessage();
        }
    }
}

// CRUD: DELETE VPBX IVR
if ($authenticated && isset($_POST['del_vpbx_ivr'])) {
    $ivr_id = intval($_POST['del_vpbx_ivr']);
    $item = $pdo->query("SELECT * FROM vpbx_ivrs WHERE id = {$ivr_id}")->fetch();
    if ($item && empty($item['is_default']) && $item['message'] !== 'Standard IVR') {
        $pdo->prepare("DELETE FROM vpbx_ivrs WHERE id = ?")->execute([$ivr_id]);
        $status_msg = "IVR option '{$item['message']}' deleted.";
    } else {
        $status_msg = "Cannot delete core system default IVR.";
    }
}

$active_admin_tab = 'tab-admin-complaints';

// CRUD: ROUTER COMMAND LIBRARY
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_router_command') {
    $active_admin_tab = 'tab-admin-router';
    $title = trim($_POST['router_title'] ?? '');
    $platform = trim($_POST['router_platform'] ?? '');
    $category = trim($_POST['router_category'] ?? '');
    $template = trim($_POST['router_template'] ?? '');
    $description = trim($_POST['router_description'] ?? '');
    $sort_order = intval($_POST['router_sort_order'] ?? 100);

    if ($title !== '' && $platform !== '' && $category !== '' && $template !== '') {
        $stmt = $pdo->prepare("INSERT INTO router_commands (title, platform, category, command_template, description, is_active, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)");
        $stmt->execute([$title, $platform, $category, $template, $description, $sort_order]);
        $status_msg = "Router command added to the database.";
    } else {
        $status_msg = "Title, platform, category and command template are required.";
    }
}

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_router_command') {
    $active_admin_tab = 'tab-admin-router';
    $id = intval($_POST['router_id'] ?? 0);
    $title = trim($_POST['router_title'] ?? '');
    $platform = trim($_POST['router_platform'] ?? '');
    $category = trim($_POST['router_category'] ?? '');
    $template = trim($_POST['router_template'] ?? '');
    $description = trim($_POST['router_description'] ?? '');
    $sort_order = intval($_POST['router_sort_order'] ?? 100);
    $is_active = isset($_POST['router_is_active']) ? 1 : 0;

    if ($id > 0 && $title !== '' && $platform !== '' && $category !== '' && $template !== '') {
        $stmt = $pdo->prepare("UPDATE router_commands SET title = ?, platform = ?, category = ?, command_template = ?, description = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$title, $platform, $category, $template, $description, $is_active, $sort_order, $id]);
        $status_msg = "Router command updated.";
    } else {
        $status_msg = "Unable to update router command. Check required fields.";
    }
}

if ($authenticated && isset($_POST['del_router_command'])) {
    $active_admin_tab = 'tab-admin-router';
    $id = intval($_POST['del_router_command']);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM router_commands WHERE id = ?")->execute([$id]);
        $status_msg = "Router command deleted.";
    }
}

// IMPORT SANITIZED ROUTER CONFIGURATION(S) INTO INVENTORY
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_router_config') {
    $active_admin_tab = 'tab-admin-router';
    $hostnameOverride = trim($_POST['inventory_hostname'] ?? '');
    $pastedConfig = trim($_POST['router_config_text'] ?? '');
    $imports = [];
    $errors = [];

    $uploadedNames = $_FILES['router_config_files']['name'] ?? [];
    $uploadedTemps = $_FILES['router_config_files']['tmp_name'] ?? [];
    $uploadedSizes = $_FILES['router_config_files']['size'] ?? [];
    $uploadedErrors = $_FILES['router_config_files']['error'] ?? [];

    if (!is_array($uploadedNames)) {
        $uploadedNames = [$uploadedNames];
        $uploadedTemps = [$uploadedTemps];
        $uploadedSizes = [$uploadedSizes];
        $uploadedErrors = [$uploadedErrors];
    }

    foreach ($uploadedNames as $idx => $name) {
        if (trim((string)$name) === '') continue;
        $error = $uploadedErrors[$idx] ?? UPLOAD_ERR_NO_FILE;
        $size = (int)($uploadedSizes[$idx] ?? 0);
        $tmp = (string)($uploadedTemps[$idx] ?? '');

        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = basename((string)$name) . ': upload failed';
            continue;
        }
        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            $errors[] = basename((string)$name) . ': file must be between 1 byte and 8 MB';
            continue;
        }

        $contents = (string)file_get_contents($tmp);
        if (trim($contents) === '') {
            $errors[] = basename((string)$name) . ': empty configuration';
            continue;
        }

        try {
            // Hostname override is only safe for a single import; batch files use their own sysname.
            $override = count(array_filter($uploadedNames)) === 1 && $pastedConfig === '' ? $hostnameOverride : '';
            $imports[] = router_import_config($pdo, $contents, basename((string)$name), $override);
        } catch (Throwable $e) {
            error_log((string)$e);
            $errors[] = basename((string)$name) . ': parser/import error';
        }
    }

    if ($pastedConfig !== '') {
        try {
            $imports[] = router_import_config($pdo, $pastedConfig, 'Pasted configuration', $hostnameOverride);
        } catch (Throwable $e) {
            error_log((string)$e);
            $errors[] = 'Pasted configuration: parser/import error';
        }
    }

    if (!$imports && !$errors) {
        $status_msg = 'Upload one or more router configuration files or paste a configuration.';
    } else {
        $parts = [];
        foreach ($imports as $summary) {
            $parts[] = sprintf(
                '%s: %d clients, %d interfaces, %d VRFs, %d BGP peers, %d QoS profiles, %d static routes',
                $summary['hostname'],
                $summary['clients'],
                $summary['interfaces'],
                $summary['vrfs'],
                $summary['bgp_peers'],
                $summary['qos_profiles'],
                $summary['static_routes']
            );
        }
        if ($errors) $parts[] = 'Warnings: ' . implode('; ', $errors);
        $status_msg = implode(' | ', $parts);
    }
}

if ($authenticated && isset($_POST['del_router_device'])) {
    $active_admin_tab = 'tab-admin-router';
    $deviceId = intval($_POST['del_router_device']);
    if ($deviceId > 0) {
        $pdo->prepare("DELETE FROM router_devices WHERE id = ?")->execute([$deviceId]);
        $status_msg = 'Stored router inventory deleted.';
    }
}

// CRUD: RE-SYNC NMS EXCEL
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resync_nms_excel') {
    $active_admin_tab = 'tab-admin-nms';
    $importScript = 'C:/Users/Nasir Ali/.gemini/antigravity/brain/41d766af-f61e-433d-8578-47857f8b5f1a/scratch/import_nms_excel.php';
    if (file_exists($importScript)) {
        ob_start();
        include $importScript;
        $importOutput = ob_get_clean();
        $status_msg = "NMS Customer Database successfully re-synchronized from Excel!";
    } else {
        $status_msg = "Import script not found.";
    }
}

// FETCH DATA FOR ADMIN
$complaints = $authenticated ? $pdo->query("SELECT * FROM complaints ORDER BY id DESC")->fetchAll() : [];
$vendors = $authenticated ? $pdo->query("SELECT * FROM vendors ORDER BY name ASC")->fetchAll() : [];
$all_users = $authenticated ? $pdo->query("SELECT id, username, role, permissions, created_at FROM users ORDER BY id ASC")->fetchAll() : [];
$vpbx_ivrs = $authenticated ? $pdo->query("SELECT * FROM vpbx_ivrs ORDER BY is_default DESC, id ASC")->fetchAll() : [];
$nms_total_count = $authenticated ? (int)$pdo->query("SELECT COUNT(*) FROM nms_clients")->fetchColumn() : 0;
$nms_zte_count = $authenticated ? (int)$pdo->query("SELECT COUNT(*) FROM nms_clients WHERE is_zte = 1")->fetchColumn() : 0;
$router_commands_admin = $authenticated ? $pdo->query("SELECT * FROM router_commands ORDER BY platform COLLATE NOCASE, category COLLATE NOCASE, sort_order ASC, title COLLATE NOCASE")->fetchAll() : [];
$router_devices_admin = $authenticated ? $pdo->query("
    SELECT d.id, d.hostname, d.platform, d.role, d.site_code, d.software_version, d.router_id, d.bgp_asn,
           d.source_name, d.imported_at,
           (SELECT COUNT(*) FROM router_interfaces i WHERE i.device_id = d.id) AS interface_count,
           (SELECT COUNT(DISTINCT client_name) FROM router_interfaces i WHERE i.device_id = d.id AND TRIM(COALESCE(client_name,'')) <> '') AS client_count,
           (SELECT COUNT(*) FROM router_vrfs v WHERE v.device_id = d.id) AS vrf_count,
           (SELECT COUNT(*) FROM router_bgp_peers p WHERE p.device_id = d.id) AS peer_count,
           (SELECT COUNT(*) FROM router_qos_profiles q WHERE q.device_id = d.id) AS qos_count,
           (SELECT COUNT(*) FROM router_static_routes s WHERE s.device_id = d.id) AS static_route_count,
           (SELECT COUNT(*) FROM router_ospf_processes o WHERE o.device_id = d.id) AS ospf_count,
           (SELECT COUNT(*) FROM router_isis_processes x WHERE x.device_id = d.id) AS isis_count
    FROM router_devices d
    ORDER BY d.hostname COLLATE NOCASE
")->fetchAll() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin CRUD Console — Smart System</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <header class="top-navbar">
        <div class="brand-section">
            <div class="brand-title">
                <span>🛡️ Smart System — Admin CRUD Control Panel</span>
                <span class="brand-badge" style="background:#fee2e2; color:#991b1b; border-color:#fca5a5;">Restricted</span>
            </div>
            <?php if ($authenticated): ?>
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:0.85rem; font-weight:600; color:#475569;">👤 Logged in: <strong><?= htmlspecialchars($_SESSION['noc_user'] ?? 'Administrator') ?></strong></span>
                <a href="index.php" class="btn-secondary" style="padding:6px 12px; font-size:0.85rem;">User Console</a>
                <form method="post" style="margin:0;"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>"><input type="hidden" name="logout" value="1"><button class="btn-danger">Sign Out</button></form>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($authenticated): ?>
        <nav class="top-tabs-nav">
            <button type="button" class="tab-btn <?= ($active_admin_tab ?? '') === 'tab-admin-complaints' ? 'active' : '' ?>" onclick="switchAdminTab('tab-admin-complaints')">🗂️ Manage Complaints (CRUD)</button>
            <button type="button" class="tab-btn <?= ($active_admin_tab ?? '') === 'tab-admin-vendors' ? 'active' : '' ?>" onclick="switchAdminTab('tab-admin-vendors')">🏪 Vendor Matrix Manager (CRUD)</button>
            <button type="button" class="tab-btn <?= ($active_admin_tab ?? '') === 'tab-admin-users' ? 'active' : '' ?>" onclick="switchAdminTab('tab-admin-users')">👤 User Authentication (CRUD)</button>
            <button type="button" class="tab-btn <?= ($active_admin_tab ?? '') === 'tab-admin-vpbx' ? 'active' : '' ?>" onclick="switchAdminTab('tab-admin-vpbx')">📞 VPBX IVRs (CRUD)</button>
            <button type="button" class="tab-btn <?= ($active_admin_tab ?? '') === 'tab-admin-nms' ? 'active' : '' ?>" onclick="switchAdminTab('tab-admin-nms')">🏷️ NMS Customer DB</button>
            <button type="button" class="tab-btn <?= ($active_admin_tab ?? '') === 'tab-admin-router' ? 'active' : '' ?>" onclick="switchAdminTab('tab-admin-router')">🛠️ Router Commands</button>
        </nav>
        <?php endif; ?>
    </header>

    <div class="main-container">
        <?php if (!$authenticated): ?>
            <!-- LOGIN SCREEN -->
            <div class="panel-card" style="max-width:500px; margin: 40px auto;">
                <div class="panel-header">
                    <h2>Admin Authentication</h2>
                    <p>Enter administrator passcode to access CRUD functions.</p>
                </div>
                <div class="panel-body">
                    <?php if (!empty($login_error)): ?>
                        <div class="alert-box" style="background:#fee2e2; color:#991b1b;"><?php echo htmlspecialchars($login_error); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="admin.php"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group">
                            <label>Passcode</label>
                            <input type="password" name="password" required autofocus placeholder="Default: admin123">
                        </div>
                        <button type="submit" class="btn-primary" style="width:100%;">Unlock Admin Console</button>
                    </form>
                </div>
            </div>
        <?php else: ?>

            <?php if (!empty($status_msg)): ?>
                <div class="alert-box success"><?php echo htmlspecialchars($status_msg); ?></div>
            <?php endif; ?>

            <!-- ADMIN TAB 1: COMPLAINTS CRUD -->
            <div id="tab-admin-complaints" class="tab-content <?= ($active_admin_tab ?? '') === 'tab-admin-complaints' ? 'active' : '' ?>">
                <div class="panel-card">
                    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <h2>All Incidents & Complaints Database</h2>
                            <p>Full view and management of runtime complaint records.</p>
                        </div>
                        <span class="badge-status badge-queued"><?php echo count($complaints); ?> Records Total</span>
                    </div>
                    <div class="panel-body">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Service Label</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Added Time</th>
                                        <th>Reported Time</th>
                                        <th>Closed Time</th>
                                        <th>Root Cause</th>
                                        <th>Ticket</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($complaints)): ?>
                                        <tr><td colspan="10" style="text-align:center; padding:20px; color:#64748b;">No complaints in database.</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($complaints as $c): ?>
                                    <tr>
                                        <td>#<?php echo $c['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($c['service_label']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($c['service_type']); ?></td>
                                        <td><span class="badge-status badge-<?php echo strtolower($c['status']); ?>"><?php echo $c['status']; ?></span></td>
                                        <td><?php echo format_dt($c['added_time']); ?></td>
                                        <td><?php echo $c['reported_time'] ? format_dt($c['reported_time']) : '—'; ?></td>
                                        <td><?php echo $c['restoration_time'] ? format_dt($c['restoration_time']) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($c['root_cause'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($c['ticket'] ?: '—'); ?></td>
                                        <td>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Delete complaint record?')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>"><input type="hidden" name="del_complaint" value="<?php echo $c['id']; ?>"><button class="btn-danger">Remove</button></form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ADMIN TAB 2: VENDORS MATRIX CRUD -->
            <div id="tab-admin-vendors" class="tab-content <?= ($active_admin_tab ?? '') === 'tab-admin-vendors' ? 'active' : '' ?>">
                <div class="panel-card">
                    <div class="panel-header">
                        <h2>🏪 Vendor Escalation Matrix Management</h2>
                        <p>Add new telecom vendors and manage tiered contacts (L1, L2, L3, L4, L5) saved in database.</p>
                    </div>
                    <div class="panel-body">
                        <div class="form-row" style="margin-bottom:30px;">
                            <!-- ADD VENDOR -->
                            <div class="col-half" style="background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #e2e8f0;">
                                <h3>➕ 1. Add New Vendor</h3>
                                <form method="POST" action="admin.php" style="margin-top:14px;"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="add_vendor">
                                    <div class="form-group">
                                        <label>Vendor Name</label>
                                        <input type="text" name="vendor_name" required placeholder="e.g. Wateen / Cybernet">
                                    </div>
                                    <button type="submit" class="btn-primary">Add Vendor</button>
                                </form>
                            </div>

                            <!-- ADD CONTACT TO MATRIX -->
                            <div class="col-half" style="background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #e2e8f0;">
                                <h3>➕ 2. Add Contact Person to Vendor</h3>
                                <form method="POST" action="admin.php" style="margin-top:14px;"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="add_contact">
                                    <div class="form-row">
                                        <div class="form-group col-half">
                                            <label>Select Vendor</label>
                                            <select name="vendor_id" required>
                                                <?php foreach ($vendors as $v): ?>
                                                    <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-half">
                                            <label>Escalation Level</label>
                                            <select name="level">
                                                <option value="Level 1">Level 1</option>
                                                <option value="Level 2 (Central Region)">Level 2 (Central Region)</option>
                                                <option value="Level 2 (South Region)">Level 2 (South Region)</option>
                                                <option value="Level 2 (North Region)">Level 2 (North Region)</option>
                                                <option value="Level 3">Level 3</option>
                                                <option value="Level 4">Level 4</option>
                                                <option value="Level 5">Level 5</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-half">
                                            <label>Contact Name</label>
                                            <input type="text" name="name" required placeholder="e.g. Ali Nadeem">
                                        </div>
                                        <div class="form-group col-half">
                                            <label>Designation</label>
                                            <input type="text" name="designation" placeholder="e.g. Regional Head">
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-half">
                                            <label>Escalation Time</label>
                                            <input type="text" name="escalation_time" placeholder="e.g. 1 Hour / 3 hours">
                                        </div>
                                        <div class="form-group col-half">
                                            <label>Phone Number</label>
                                            <input type="text" name="phone" placeholder="0300-XXXXXXX">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Email Address</label>
                                        <input type="email" name="email" required placeholder="contact@vendor.com">
                                    </div>
                                    <button type="submit" class="btn-primary">Add Contact</button>
                                </form>
                            </div>
                        </div>

                        <!-- DROPDOWN BASED VENDOR MATRIX VIEWER & CRUD -->
                        <div style="background:#ffffff; border:1.5px solid #cbd5e1; border-radius:10px; padding:20px; margin-top:10px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
                                <div>
                                    <h3 style="margin:0; font-size:1.2rem; color:#0f172a;">🎯 Select Vendor to View & Manage Contacts</h3>
                                    <p style="margin:4px 0 0 0; color:#64748b; font-size:0.88rem;">Pick any vendor from the dropdown to instantly view its escalation matrix without endless scrolling.</p>
                                </div>
                                <div style="min-width:280px;">
                                    <select id="admin-vendor-select" onchange="filterAdminVendor(this.value)" style="font-size:1rem; padding:10px 14px; border-color:#059669; font-weight:600;">
                                        <option value="">-- Choose Vendor to View Matrix --</option>
                                        <?php foreach ($vendors as $v): ?>
                                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div id="admin-vendor-cards-wrapper">
                                <?php foreach ($vendors as $idx => $v): ?>
                                    <?php 
                                    $stmt = $pdo->prepare("SELECT * FROM vendor_contacts WHERE vendor_id = ? ORDER BY id ASC");
                                    $stmt->execute([$v['id']]);
                                    $contacts = $stmt->fetchAll();
                                    ?>
                                    <div class="admin-vendor-card" id="admin-vcard-<?php echo $v['id']; ?>" style="<?php echo $idx === 0 ? '' : 'display:none;'; ?> border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                                        <div style="background:#f1f5f9; padding:14px 20px; display:flex; justify-content:space-between; align-items:center;">
                                            <h4 style="font-size:1.1rem; color:#0f172a; margin:0;">🏢 <?php echo htmlspecialchars($v['name']); ?> <span class="badge-status badge-queued" style="margin-left:8px;"><?php echo count($contacts); ?> Contacts</span></h4>
                                            <form method="post" style="display:inline" onsubmit="return confirm('Delete vendor <?php echo htmlspecialchars($v['name']); ?> and ALL its contacts?')">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="del_vendor" value="<?php echo $v['id']; ?>">
                                                <button class="btn-danger">🗑️ Delete Vendor</button>
                                            </form>
                                        </div>
                                        <div class="table-responsive" style="margin-top:0;">
                                            <table class="data-table">
                                                <thead>
                                                    <tr>
                                                        <th>Level</th>
                                                        <th>Name</th>
                                                        <th>Designation</th>
                                                        <th>Time</th>
                                                        <th>Phone</th>
                                                        <th>Email</th>
                                                        <th>Action</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($contacts)): ?>
                                                        <tr><td colspan="7" style="color:#64748b; padding:16px; text-align:center;">No contacts added for this vendor yet.</td></tr>
                                                    <?php endif; ?>
                                                    <?php foreach ($contacts as $cnt): ?>
                                                        <tr>
                                                            <td><strong><?php echo htmlspecialchars($cnt['level']); ?></strong></td>
                                                            <td><?php echo htmlspecialchars($cnt['name']); ?></td>
                                                            <td><?php echo htmlspecialchars($cnt['designation'] ?: '—'); ?></td>
                                                            <td><?php echo htmlspecialchars($cnt['escalation_time'] ?: '—'); ?></td>
                                                            <td><?php echo htmlspecialchars($cnt['phone'] ?: '—'); ?></td>
                                                            <td><a href="mailto:<?php echo htmlspecialchars($cnt['email']); ?>"><?php echo htmlspecialchars($cnt['email']); ?></a></td>
                                                            <td style="white-space:nowrap;">
                                                                <button type="button" class="btn-secondary" style="padding:5px 10px; font-size:0.8rem; margin-right:4px;" 
                                                                    onclick='openEditContactModal(<?php echo json_encode($cnt, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>)'>✏️ Edit</button>
                                                                <form method="post" style="display:inline" onsubmit="return confirm('Remove <?php echo htmlspecialchars($cnt['name']); ?>?')">
                                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                                    <input type="hidden" name="del_contact" value="<?php echo $cnt['id']; ?>">
                                                                    <button class="btn-danger">Remove</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ADMIN TAB 3: USER AUTHENTICATION CRUD -->
            <div id="tab-admin-users" class="tab-content <?= ($active_admin_tab ?? '') === 'tab-admin-users' ? 'active' : '' ?>">
                <div class="panel-card">
                    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                        <div>
                            <h2>👤 User Authentication &amp; Access Control</h2>
                            <p>Manage employee login accounts and roles for the Smart System portal.</p>
                        </div>
                        <span class="badge-status badge-queued"><?php echo count($all_users); ?> Accounts Registered</span>
                    </div>
                    <div class="panel-body">
                        <!-- CREATE NEW USER -->
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:24px;">
                            <h3 style="margin-bottom:14px; font-size:1.05rem; color:#0f172a;">➕ Create New User Account</h3>
                            <form method="POST" action="admin.php">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="add_user">
                                <div class="form-row">
                                    <div class="form-group col-half">
                                        <label>Username</label>
                                        <input type="text" name="new_username" placeholder="e.g. ijaz" required autocomplete="off">
                                    </div>
                                    <div class="form-group col-half">
                                        <label>Password</label>
                                        <input type="password" name="new_password" placeholder="e.g. ijaz123" required autocomplete="new-password">
                                    </div>
                                    <div class="form-group" style="width:170px; flex-shrink:0;">
                                        <label>Role</label>
                                        <select name="new_role">
                                            <option value="user">User (Console Only)</option>
                                            <option value="admin">Admin (Full Access)</option>
                                        </select>
                                    </div>
                                </div>
                                <div style="margin-top:14px; margin-bottom:18px;">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                        <label style="font-weight:700; color:#334155; font-size:0.9rem; margin:0;">🔒 Allowed Feature Tabs</label>
                                        <div style="display:flex; gap:8px;">
                                            <button type="button" class="btn-secondary" style="padding:2px 8px; font-size:0.75rem;" onclick="toggleAllPermCheckboxes('new-user-perms', true)">Select All</button>
                                            <button type="button" class="btn-secondary" style="padding:2px 8px; font-size:0.75rem;" onclick="toggleAllPermCheckboxes('new-user-perms', false)">Deselect All</button>
                                        </div>
                                    </div>
                                    <div id="new-user-perms" class="checkbox-grid" style="grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap:8px; background:#fff; padding:12px; border:1px solid #cbd5e1; border-radius:6px;">
                                        <?php foreach ($NOC_FEATURES as $fkey => $flabel): ?>
                                            <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer;">
                                                <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($fkey) ?>" checked>
                                                <span><?= htmlspecialchars($flabel) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <small style="color:#64748b; font-size:0.8rem;">Note: Accounts with role "Admin" always have full access to all features and admin console.</small>
                                </div>
                                <button type="submit" class="btn-primary">➕ Create User Account</button>
                            </form>
                        </div>

                        <!-- REGISTERED USERS TABLE -->
                        <h3 style="margin-bottom:12px; font-size:1.05rem; color:#0f172a;">Active Corporate Users</h3>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Feature Access</th>
                                        <th>Created Date</th>
                                        <th>Reset Password</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_users as $u): ?>
                                        <tr>
                                            <td>#<?php echo $u['id']; ?></td>
                                            <td style="font-weight:700;">
                                                👤 <?php echo htmlspecialchars($u['username']); ?>
                                                <?php if (strtolower($u['username']) === strtolower($_SESSION['noc_user'] ?? '')): ?>
                                                    <span style="font-size:0.75rem; color:#059669; font-weight:normal; margin-left:4px;">(Current User)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-pill <?php echo $u['role'] === 'admin' ? 'status-green' : 'status-closed'; ?>">
                                                    <?php echo strtoupper($u['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($u['role'] === 'admin'): ?>
                                                    <span class="badge-status badge-queued" style="background:#fef3c7; color:#92400e; font-weight:700;">⭐ All Features (Admin)</span>
                                                <?php else:
                                                    $u_perms = $u['permissions'] ?? 'all';
                                                    $p_count = 0;
                                                    $p_arr = [];
                                                    if ($u_perms === 'all' || empty($u_perms)) {
                                                        $p_count = count($NOC_FEATURES);
                                                        $p_arr = array_keys($NOC_FEATURES);
                                                    } else {
                                                        $decoded = json_decode($u_perms, true);
                                                        if (is_array($decoded)) {
                                                            $p_arr = $decoded;
                                                            $p_count = count($decoded);
                                                        }
                                                    }
                                                ?>
                                                    <div style="display:flex; align-items:center; gap:8px;">
                                                        <span class="status-pill <?= $p_count === count($NOC_FEATURES) ? 'status-green' : ($p_count > 0 ? 'status-open' : 'status-closed') ?>">
                                                            <?= $p_count ?> / <?= count($NOC_FEATURES) ?> Tabs
                                                        </span>
                                                        <button type="button" class="btn-secondary" style="padding:3px 8px; font-size:0.75rem;" onclick='openEditPermissionsModal(<?= json_encode([
                                                            'id' => (int)$u['id'],
                                                            'username' => (string)$u['username'],
                                                            'role' => (string)$u['role'],
                                                            'permissions' => ($u_perms === 'all' || empty($u_perms)) ? array_keys($NOC_FEATURES) : $p_arr
                                                        ]) ?>)'>⚙️ Permissions</button>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                                            <td>
                                                <form method="POST" action="admin.php" style="display:flex; gap:6px; align-items:center;">
                                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                    <input type="hidden" name="action" value="update_user_password">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <input type="password" name="updated_password" placeholder="New password" required style="padding:6px 10px; font-size:0.82rem; width:150px;">
                                                    <button type="submit" class="btn-secondary" style="padding:6px 10px; font-size:0.82rem;">Update</button>
                                                </form>
                                            </td>
                                            <td>
                                                <?php if (strtolower($u['username']) !== strtolower($_SESSION['noc_user'] ?? '')): ?>
                                                    <form method="POST" action="admin.php" onsubmit="return confirm('Delete user account <?php echo htmlspecialchars($u['username']); ?>?');">
                                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                        <input type="hidden" name="del_user" value="<?php echo $u['id']; ?>">
                                                        <button type="submit" class="btn-danger">Delete</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8; font-size:0.8rem; font-style:italic;">Active Session</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODAL: EDIT USER PERMISSIONS -->
            <div id="editPermissionsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999; justify-content:center; align-items:center;">
                <div style="background:#fff; width:95%; max-width:560px; border-radius:12px; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,0.25);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h3 style="margin:0; font-size:1.2rem; color:#0f172a;">⚙️ User Feature Access Permissions</h3>
                        <button type="button" onclick="closeEditPermissionsModal()" style="border:none; background:transparent; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
                    </div>
                    <p style="font-size:0.9rem; color:#475569; margin-bottom:14px;">Toggle feature tabs on/off for user: <strong id="modal-perm-username" style="color:#0f172a;"></strong></p>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="update_user_permissions">
                        <input type="hidden" name="user_id" id="modal-perm-user-id">

                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <span style="font-size:0.85rem; font-weight:600; color:#334155;">Available Features (<?= count($NOC_FEATURES) ?>)</span>
                            <div style="display:flex; gap:6px;">
                                <button type="button" class="btn-secondary" style="padding:2px 8px; font-size:0.75rem;" onclick="toggleAllPermCheckboxes('modal-perm-grid', true)">Select All</button>
                                <button type="button" class="btn-secondary" style="padding:2px 8px; font-size:0.75rem;" onclick="toggleAllPermCheckboxes('modal-perm-grid', false)">Deselect All</button>
                            </div>
                        </div>

                        <div id="modal-perm-grid" class="checkbox-grid" style="grid-template-columns: 1fr 1fr; gap:10px; background:#f8fafc; padding:14px; border:1px solid #e2e8f0; border-radius:8px; max-height:300px; overflow-y:auto;">
                            <?php foreach ($NOC_FEATURES as $fkey => $flabel): ?>
                                <label style="display:flex; align-items:center; gap:8px; font-size:0.85rem; cursor:pointer;">
                                    <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($fkey) ?>" id="modal-perm-check-<?= htmlspecialchars($fkey) ?>">
                                    <span><?= htmlspecialchars($flabel) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                            <button type="button" class="btn-secondary" onclick="closeEditPermissionsModal()">Cancel</button>
                            <button type="submit" class="btn-primary">💾 Save Permissions</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ADMIN TAB 4: VPBX IVR MANAGEMENT CRUD -->
            <div id="tab-admin-vpbx" class="tab-content <?= ($active_admin_tab ?? '') === 'tab-admin-vpbx' ? 'active' : '' ?>">
                <div class="panel-card">
                    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h2>📞 VPBX IVR &amp; Announcement Library Management</h2>
                            <p>Configure custom IVR messages, network announcements, and carrier error responses for the VPBX Testing Portal.</p>
                        </div>
                        <a href="index.php" class="btn-primary" style="text-decoration:none; padding:8px 16px; font-weight:700;">🚀 Open Live VPBX Testing Portal</a>
                    </div>
                    <div class="panel-body">
                        <div class="form-row" style="margin-bottom:30px;">
                            <div class="col-half" style="background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #e2e8f0;">
                                <h3>➕ Add New IVR Option</h3>
                                <form method="POST" action="admin.php" style="margin-top:14px;">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="admin_add_ivr">
                                    <div class="form-group">
                                        <label>IVR Announcement / Issue Description *</label>
                                        <input type="text" name="ivr_message" required placeholder="e.g. The subscriber is currently busy.">
                                    </div>
                                    <button type="submit" class="btn-primary">Add IVR Option</button>
                                </form>
                            </div>
                        </div>

                        <h3>📋 Current Configured IVR Options (<?= count($vpbx_ivrs ?? []) ?>)</h3>
                        <div class="table-responsive" style="margin-top:14px;">
                            <table class="data-table">
                                <thead>
                                    <tr style="background:#f8fafc;">
                                        <th>ID</th>
                                        <th>IVR Message / Prompt</th>
                                        <th>Type</th>
                                        <th>Date Added</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($vpbx_ivrs ?? []) as $ivr): ?>
                                    <tr>
                                        <td>#<?= $ivr['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($ivr['message']) ?></strong></td>
                                        <td><?= $ivr['is_default'] ? '<span class="badge-status" style="background:#dcfce7; color:#166534; font-weight:700;">Core Default</span>' : '<span class="badge-status" style="background:#ede9fe; color:#6d28d9; font-weight:700;">Custom IVR</span>' ?></td>
                                        <td><?= htmlspecialchars($ivr['created_at']) ?></td>
                                        <td>
                                            <?php if (empty($ivr['is_default']) && $ivr['message'] !== 'Standard IVR'): ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this IVR option?')">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="del_vpbx_ivr" value="<?= $ivr['id'] ?>">
                                                <button class="btn-danger">Delete</button>
                                            </form>
                                            <?php else: ?>
                                                <span style="color:#94a3b8; font-size:0.85rem; font-style:italic;">Protected</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ADMIN TAB: ROUTER COMMANDS -->
            <div id="tab-admin-router" class="tab-content <?= ($active_admin_tab ?? '') === 'tab-admin-router' ? 'active' : '' ?>">
                <div class="panel-card" style="margin-bottom:20px; border-left:5px solid #2563eb;">
                    <div class="panel-header">
                        <h2>📥 Router Configuration Inventory</h2>
                        <p>Upload one or many sanitized Huawei configurations. Only high-value operational facts are stored: Router → Client → Service/Site → VRF → Interface → IP/Peer → QoS/Policies/Routing.</p>
                    </div>
                    <div class="panel-body">
                        <form method="POST" action="admin.php" enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="import_router_config">

                            <div class="form-row">
                                <div class="form-group col-half">
                                    <label>Configuration Files</label>
                                    <input type="file" name="router_config_files[]" accept=".txt,.cfg,.conf,.log" multiple>
                                    <small>Upload multiple routers together. TXT/CFG/CONF/LOG, maximum 8 MB each.</small>
                                </div>
                                <div class="form-group col-half">
                                    <label>Hostname Override (Optional)</label>
                                    <input type="text" name="inventory_hostname" placeholder="Leave blank to use sysname from config">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Or Paste Configuration</label>
                                <textarea name="router_config_text" rows="7" placeholder="sysname KHI-PE-NE40EX8A-B1&#10;#&#10;interface Eth-Trunk31.846&#10; description ...&#10; vlan-type dot1q 846&#10; ip binding vpn-instance CUSTOMER_VRF&#10; ip address 192.0.2.1 255.255.255.252"></textarea>
                            </div>

                            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                <button type="submit" class="btn-primary">⚙️ Parse &amp; Store Router Inventory</button>
                                <small style="color:#64748b;">Raw configs are not stored. Authentication/cipher/RSA/SNMP/AAA lines are not persisted by the inventory parser.</small>
                            </div>
                        </form>

                        <h3 style="margin:28px 0 12px;">Stored Routers (<?= count($router_devices_admin ?? []) ?>)</h3>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Router</th>
                                        <th>Role / Site</th>
                                        <th>Platform / Software</th>
                                        <th>Clients</th>
                                        <th>Interfaces / VRFs</th>
                                        <th>Routing</th>
                                        <th>QoS / Static</th>
                                        <th>Source</th>
                                        <th>Imported</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach (($router_devices_admin ?? []) as $device): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($device['hostname']) ?></strong>
                                            <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($device['router_id'] ?: 'No router-id') ?><?= !empty($device['bgp_asn']) ? ' • AS' . htmlspecialchars($device['bgp_asn']) : '' ?></div>
                                        </td>
                                        <td><?= htmlspecialchars(trim(($device['site_code'] ?: '') . ' ' . ($device['role'] ?: 'Router'))) ?></td>
                                        <td>
                                            <?= htmlspecialchars($device['platform'] ?: 'Huawei') ?>
                                            <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($device['software_version'] ?: '—') ?></div>
                                        </td>
                                        <td><?= (int)$device['client_count'] ?></td>
                                        <td><?= (int)$device['interface_count'] ?> / <?= (int)$device['vrf_count'] ?></td>
                                        <td><?= (int)$device['peer_count'] ?> BGP • <?= (int)$device['ospf_count'] ?> OSPF • <?= (int)$device['isis_count'] ?> IS-IS</td>
                                        <td><?= (int)$device['qos_count'] ?> QoS • <?= (int)$device['static_route_count'] ?> static</td>
                                        <td><?= htmlspecialchars($device['source_name'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($device['imported_at'] ?: '—') ?></td>
                                        <td>
                                            <form method="POST" action="admin.php" onsubmit="return confirm('Delete this stored router inventory?')">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="del_router_device" value="<?= (int)$device['id'] ?>">
                                                <button type="submit" class="btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($router_devices_admin)): ?>
                                    <tr><td colspan="10" style="text-align:center; padding:22px; color:#64748b;">No router configurations imported yet.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="panel-card" style="margin-bottom:20px;">
                    <div class="panel-header">
                        <h2>🛠️ Router Command Database</h2>
                        <p>Add, edit, enable/disable or remove commands. The user console only shows commands after a router platform and troubleshooting task are selected.</p>
                    </div>
                    <div class="panel-body">
                        <form method="POST" action="admin.php">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                            <input type="hidden" name="action" value="add_router_command">
                            <div class="form-row">
                                <div class="form-group col-half">
                                    <label>Command Title *</label>
                                    <input type="text" name="router_title" required placeholder="e.g. ARP on Vlanif">
                                </div>
                                <div class="form-group col-half">
                                    <label>Router / Platform *</label>
                                    <input type="text" name="router_platform" required placeholder="e.g. S9306">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-half">
                                    <label>Troubleshooting Category *</label>
                                    <input type="text" name="router_category" required placeholder="e.g. ARP">
                                </div>
                                <div class="form-group col-half">
                                    <label>Sort Order</label>
                                    <input type="number" name="router_sort_order" value="100">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Command Template *</label>
                                <input type="text" name="router_template" required placeholder="display arp interface Vlanif {vlan}">
                                <small>Supported variables: {interface}, {vlan}, {trunk}, {ip}, {vrf}, {peer_ip}, {search}, {policy}, {prefix}</small>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="router_description" rows="2" placeholder="What this command checks and when to use it"></textarea>
                            </div>
                            <button type="submit" class="btn-primary">➕ Add Command to Database</button>
                        </form>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h2>Configured Router Commands</h2>
                            <p><?= count($router_commands_admin ?? []) ?> database records</p>
                        </div>
                        <a href="index.php" class="btn-primary">Open Router Commands</a>
                    </div>
                    <div class="panel-body">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Platform</th>
                                        <th>Category</th>
                                        <th>Title</th>
                                        <th>Command</th>
                                        <th>Status</th>
                                        <th>Order</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach (($router_commands_admin ?? []) as $rc): ?>
                                    <tr>
                                        <td>#<?= (int)$rc['id'] ?></td>
                                        <td><strong><?= htmlspecialchars($rc['platform']) ?></strong></td>
                                        <td><?= htmlspecialchars($rc['category']) ?></td>
                                        <td><?= htmlspecialchars($rc['title']) ?></td>
                                        <td><code style="white-space:normal; word-break:break-word;"><?= htmlspecialchars($rc['command_template']) ?></code></td>
                                        <td>
                                            <span class="status-pill <?= !empty($rc['is_active']) ? 'status-green' : 'status-closed' ?>">
                                                <?= !empty($rc['is_active']) ? 'Active' : 'Hidden' ?>
                                            </span>
                                        </td>
                                        <td><?= (int)$rc['sort_order'] ?></td>
                                        <td style="white-space:nowrap;">
                                            <button type="button" class="btn-secondary" style="padding:5px 9px;" onclick='openEditRouterCommandModal(<?= json_encode([
                                                "id" => (int)$rc["id"],
                                                "title" => (string)$rc["title"],
                                                "platform" => (string)$rc["platform"],
                                                "category" => (string)$rc["category"],
                                                "template" => (string)$rc["command_template"],
                                                "description" => (string)($rc["description"] ?? ""),
                                                "is_active" => (int)$rc["is_active"],
                                                "sort_order" => (int)$rc["sort_order"]
                                            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                                            <form method="POST" action="admin.php" style="display:inline;" onsubmit="return confirm('Delete this router command?')">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                                <input type="hidden" name="del_router_command" value="<?= (int)$rc['id'] ?>">
                                                <button type="submit" class="btn-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($router_commands_admin)): ?>
                                    <tr><td colspan="8" style="text-align:center; padding:25px; color:#64748b;">No router commands configured.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ADMIN TAB: NMS CUSTOMER DB -->
            <div id="tab-admin-nms" class="tab-content <?= ($active_admin_tab ?? '') === 'tab-admin-nms' ? 'active' : '' ?>">
                <div class="panel-card" style="margin-bottom:20px; border-left:5px solid #2563eb;">
                    <div class="panel-body" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                        <div>
                            <h3 style="margin:0 0 6px 0; color:#0f172a; font-size:1.15rem;">🏷️ NMS Customer Links Repository</h3>
                            <p style="margin:0; color:#64748b; font-size:0.9rem;">
                                Currently serving <strong style="color:#0f172a;"><?= number_format($nms_total_count ?? 4587) ?></strong> customer links indexed from <code>Clients_2026-09-14.xlsx</code>.
                            </p>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <form method="POST" action="admin.php" style="margin:0;" onsubmit="return confirm('Re-sync database from Clients_2026-09-14.xlsx? This will refresh all customer records.')">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                <input type="hidden" name="action" value="resync_nms_excel">
                                <button type="submit" class="btn-secondary" style="padding:8px 16px; font-weight:700;">
                                    🔄 Re-Sync from Excel
                                </button>
                            </form>
                            <a href="index.php" target="_blank" class="btn-primary" style="padding:8px 18px; font-weight:700; text-decoration:none;">
                                🚀 Open Customer Search Portal
                            </a>
                        </div>
                    </div>
                </div>

                <div class="panel-card">
                    <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <h2>Indexed Customer Links Preview (Top 15)</h2>
                            <p>Fast SQLite cache of all customer attributes, NMS user labels, and transmission routes.</p>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <span class="badge-status" style="background:#dbeafe; color:#1d4ed8; font-weight:700;">🔵 <?= number_format($nms_zte_count ?? 338) ?> ZTE Links</span>
                            <span class="badge-status badge-queued"><?= number_format($nms_total_count ?? 4587) ?> Total Records</span>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Unique Link ID</th>
                                        <th>Client Name</th>
                                        <th>Service</th>
                                        <th>Department</th>
                                        <th>Site</th>
                                        <th>Region</th>
                                        <th>Status</th>
                                        <th>Approval</th>
                                        <th>BW (Mbps)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $preview_rows = $pdo->query("SELECT id, row_num, unique_link_id, client_name, service, department, site, region, status, approval_status, solution_design_bw, is_zte FROM nms_clients ORDER BY id ASC LIMIT 15")->fetchAll();
                                    foreach ($preview_rows as $pr):
                                        $is_pr_zte = !empty($pr['is_zte']);
                                    ?>
                                    <tr style="<?= $is_pr_zte ? 'background:#eff6ff;' : '' ?>">
                                        <td>#<?= $pr['row_num'] ?></td>
                                        <td>
                                            <span class="nms-link-badge"><?= htmlspecialchars($pr['unique_link_id']) ?></span>
                                            <?php if ($is_pr_zte): ?><span style="background:#2563eb; color:#fff; font-size:9px; font-weight:700; padding:1px 4px; border-radius:3px; margin-left:4px;">🔵 ZTE</span><?php endif; ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($pr['client_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($pr['service']) ?></td>
                                        <td><?= htmlspecialchars($pr['department']) ?></td>
                                        <td><?= htmlspecialchars($pr['site']) ?></td>
                                        <td><?= htmlspecialchars($pr['region']) ?></td>
                                        <td><span class="badge-status" style="background:#e0f2fe; color:#0369a1;"><?= htmlspecialchars($pr['status']) ?></span></td>
                                        <td><span class="badge-status" style="background:#dcfce7; color:#166534;"><?= htmlspecialchars($pr['approval_status']) ?></span></td>
                                        <td style="font-weight:700;"><?= htmlspecialchars($pr['solution_design_bw']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MODAL: EDIT ROUTER COMMAND -->
            <div id="editRouterCommandModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
                <div style="background:#fff; width:95%; max-width:720px; border-radius:12px; padding:24px; max-height:90vh; overflow:auto;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h3 style="margin:0;">✏️ Edit Router Command</h3>
                        <button type="button" onclick="closeEditRouterCommandModal()" style="border:none;background:transparent;font-size:1.4rem;cursor:pointer;">&times;</button>
                    </div>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="update_router_command">
                        <input type="hidden" name="router_id" id="edit-router-id">
                        <div class="form-row">
                            <div class="form-group col-half"><label>Title *</label><input id="edit-router-title" name="router_title" required></div>
                            <div class="form-group col-half"><label>Platform *</label><input id="edit-router-platform" name="router_platform" required></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-half"><label>Category *</label><input id="edit-router-category" name="router_category" required></div>
                            <div class="form-group col-half"><label>Sort Order</label><input id="edit-router-sort" name="router_sort_order" type="number"></div>
                        </div>
                        <div class="form-group"><label>Command Template *</label><input id="edit-router-template" name="router_template" required></div>
                        <div class="form-group"><label>Description</label><textarea id="edit-router-description" name="router_description" rows="3"></textarea></div>
                        <label style="display:flex; align-items:center; gap:8px; margin-bottom:18px;"><input id="edit-router-active" name="router_is_active" type="checkbox" value="1"> Active / visible to users</label>
                        <div style="display:flex; justify-content:flex-end; gap:10px;">
                            <button type="button" class="btn-secondary" onclick="closeEditRouterCommandModal()">Cancel</button>
                            <button type="submit" class="btn-primary">💾 Save Command</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- MODAL: EDIT VENDOR CONTACT -->
            <div id="editContactModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:999; justify-content:center; align-items:center;">
                <div style="background:#fff; width:95%; max-width:550px; border-radius:12px; padding:24px; box-shadow:0 10px 30px rgba(0,0,0,0.25);">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                        <h3 style="margin:0; font-size:1.2rem; color:#0f172a;">✏️ Edit Contact Person</h3>
                        <button type="button" onclick="closeEditContactModal()" style="border:none; background:transparent; font-size:1.4rem; cursor:pointer; color:#64748b;">&times;</button>
                    </div>
                    <form method="POST" action="admin.php">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="update_contact">
                        <input type="hidden" name="contact_id" id="modal-contact-id">
                        
                        <div class="form-row">
                            <div class="form-group col-half">
                                <label>Level</label>
                                <input type="text" name="level" id="modal-contact-level" required>
                            </div>
                            <div class="form-group col-half">
                                <label>Contact Name</label>
                                <input type="text" name="name" id="modal-contact-name" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-half">
                                <label>Designation</label>
                                <input type="text" name="designation" id="modal-contact-desig">
                            </div>
                            <div class="form-group col-half">
                                <label>Escalation Time</label>
                                <input type="text" name="escalation_time" id="modal-contact-time">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="phone" id="modal-contact-phone">
                        </div>

                        <div class="form-group">
                            <label>Email Address(es)</label>
                            <input type="text" name="email" id="modal-contact-email" placeholder="e.g. email1@vendor.com; email2@vendor.com">
                            <small style="color:#64748b; font-size:0.8rem;">You can fix typo, remove glued text, or separate multiple emails with semicolons.</small>
                        </div>

                        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                            <button type="button" class="btn-secondary" onclick="closeEditContactModal()">Cancel</button>
                            <button type="submit" class="btn-primary">💾 Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <script>
    function switchAdminTab(tabId) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

        event.target.classList.add('active');
        document.getElementById(tabId).classList.add('active');
    }

    function filterAdminVendor(vendorId) {
        const cards = document.querySelectorAll('.admin-vendor-card');
        if (!vendorId) {
            // If empty, show the first vendor card by default
            cards.forEach((c, idx) => c.style.display = idx === 0 ? 'block' : 'none');
            return;
        }
        cards.forEach(c => {
            if (c.id === 'admin-vcard-' + vendorId) {
                c.style.display = 'block';
            } else {
                c.style.display = 'none';
            }
        });
    }

    function openEditContactModal(contact) {
        document.getElementById('modal-contact-id').value = contact.id;
        document.getElementById('modal-contact-level').value = contact.level || '';
        document.getElementById('modal-contact-name').value = contact.name || '';
        document.getElementById('modal-contact-desig').value = contact.designation || '';
        document.getElementById('modal-contact-time').value = contact.escalation_time || '';
        document.getElementById('modal-contact-phone').value = contact.phone || '';
        document.getElementById('modal-contact-email').value = contact.email || '';
        
        document.getElementById('editContactModal').style.display = 'flex';
    }

    function closeEditContactModal() {
        document.getElementById('editContactModal').style.display = 'none';
    }

    function toggleAllPermCheckboxes(containerId, checkAll) {
        const container = document.getElementById(containerId);
        if (!container) return;
        container.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.checked = checkAll;
        });
    }

    function openEditPermissionsModal(user) {
        document.getElementById('modal-perm-user-id').value = user.id;
        document.getElementById('modal-perm-username').textContent = user.username + ' (' + user.role + ')';
        const perms = Array.isArray(user.permissions) ? user.permissions : [];
        document.querySelectorAll('#modal-perm-grid input[type="checkbox"]').forEach(cb => {
            cb.checked = perms.includes(cb.value);
        });
        document.getElementById('editPermissionsModal').style.display = 'flex';
    }

    function closeEditPermissionsModal() {
        document.getElementById('editPermissionsModal').style.display = 'none';
    }

    function openEditRouterCommandModal(command) {
        document.getElementById('edit-router-id').value = command.id;
        document.getElementById('edit-router-title').value = command.title || '';
        document.getElementById('edit-router-platform').value = command.platform || '';
        document.getElementById('edit-router-category').value = command.category || '';
        document.getElementById('edit-router-template').value = command.template || '';
        document.getElementById('edit-router-description').value = command.description || '';
        document.getElementById('edit-router-sort').value = command.sort_order ?? 100;
        document.getElementById('edit-router-active').checked = Number(command.is_active) === 1;
        document.getElementById('editRouterCommandModal').style.display = 'flex';
    }

    function closeEditRouterCommandModal() {
        document.getElementById('editRouterCommandModal').style.display = 'none';
    }

    // Auto select first vendor in dropdown on load if present
    document.addEventListener('DOMContentLoaded', () => {
        const sel = document.getElementById('admin-vendor-select');
        if (sel && sel.options.length > 1) {
            sel.selectedIndex = 1;
            filterAdminVendor(sel.value);
        }
    });
    </script>
</body>
</html>
