<?php
require_once __DIR__ . '/auth.php';
require_once 'db.php';
require_once 'noc_helpers.php';
$queued_open_complaints = $pdo->query("SELECT id, service_label, ticket, status FROM complaints WHERE status IN ('QUEUED', 'OPEN') ORDER BY id DESC")->fetchAll();
$open_complaints = $pdo->query("SELECT id, service_label, ticket FROM complaints WHERE status = 'OPEN' ORDER BY id DESC")->fetchAll();
$vendors = $pdo->query("SELECT id, name FROM vendors ORDER BY name ASC")->fetchAll();

$feature_tab_map = [
    'complaints' => 'tab-complaints',
    'opening' => 'tab-opening',
    'customer' => 'tab-customer',
    'escalation' => 'tab-escalation',
    'closure' => 'tab-closure',
    'stats' => 'tab-stats',
    'progress' => 'tab-progress',
    'dashboard' => 'tab-dashboard',
    'outage' => 'tab-outage',
    'matrix' => 'tab-matrix',
    'roster' => 'tab-roster',
    'vpbx' => 'tab-vpbx',
    'nms' => 'tab-nms',
    'router' => 'tab-router',
];
$active_tab_id = '';
foreach ($feature_tab_map as $fkey => $tId) {
    if (has_feature_access($fkey)) {
        $active_tab_id = $tId;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart System — Corporate NOC Console</title>
    <link rel="stylesheet" href="style.css">
    <?php if (has_feature_access('router')): ?>
    <link rel="stylesheet" href="acl_commands.css?v=<?= filemtime(__DIR__ . '/acl_commands.css') ?>">
    <?php endif; ?>
</head>
<body>
    <header class="top-navbar">
        <div class="brand-section">
            <div class="brand-title">
                <span>⚡ Welcome to Smart System</span>
                <span class="brand-badge">NOC User Console</span>
            </div>
        </div>
        <form method="post" class="session-actions" style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="noc_logout">
            <span style="font-size:0.85rem; font-weight:600; color:#475569;">👤 <strong><?= htmlspecialchars($_SESSION['noc_user'] ?? 'User') ?></strong></span>
            <?php if (($_SESSION['noc_role'] ?? '') === 'admin'): ?>
                <a href="admin.php" class="btn-secondary">Administration</a>
            <?php endif; ?>
            <button type="submit" class="btn-secondary">Sign Out</button>
        </form>
        <nav class="top-tabs-nav">
            <?php if (has_feature_access('complaints')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-complaints' ? 'active' : '' ?>" onclick="switchTab('tab-complaints')">📁 Complaint Manager</button>
            <?php endif; ?>
            <?php if (has_feature_access('opening')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-opening' ? 'active' : '' ?>" onclick="switchTab('tab-opening')">🚨 Opening</button>
            <?php endif; ?>
            <?php if (has_feature_access('customer')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-customer' ? 'active' : '' ?>" onclick="switchTab('tab-customer')">👤 Customer End / Findings</button>
            <?php endif; ?>
            <?php if (has_feature_access('escalation')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-escalation' ? 'active' : '' ?>" onclick="switchTab('tab-escalation')">📨 Vendor / Escalation</button>
            <?php endif; ?>
            <?php if (has_feature_access('closure')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-closure' ? 'active' : '' ?>" onclick="switchTab('tab-closure')">✅ Closure / RFO</button>
            <?php endif; ?>
            <?php if (has_feature_access('stats')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-stats' ? 'active' : '' ?>" onclick="switchTab('tab-stats')">🧪 Stats</button>
            <?php endif; ?>
            <?php if (has_feature_access('progress')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-progress' ? 'active' : '' ?>" onclick="switchTab('tab-progress')">🔄 Progress</button>
            <?php endif; ?>
            <?php if (has_feature_access('dashboard')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-dashboard' ? 'active' : '' ?>" onclick="switchTab('tab-dashboard')">📋 Dashboard</button>
            <?php endif; ?>
            <?php if (has_feature_access('outage')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-outage' ? 'active' : '' ?>" onclick="switchTab('tab-outage')">📈 Outage Analyzer</button>
            <?php endif; ?>
            <?php if (has_feature_access('matrix')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-matrix' ? 'active' : '' ?>" onclick="switchTab('tab-matrix')">🏪 Vendor Matrix</button>
            <?php endif; ?>
            <?php if (has_feature_access('roster')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-roster' ? 'active' : '' ?>" onclick="switchTab('tab-roster')">📅 Duty Roster</button>
            <?php endif; ?>
            <?php if (has_feature_access('vpbx')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-vpbx' ? 'active' : '' ?>" onclick="switchTab('tab-vpbx')">📞 Regulatory &amp; VPBX</button>
            <?php endif; ?>
            <?php if (has_feature_access('nms')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-nms' ? 'active' : '' ?>" onclick="switchTab('tab-nms')">🏷️ NMS Customer Search</button>
            <?php endif; ?>
            <?php if (has_feature_access('router')): ?>
                <button type="button" class="tab-btn <?= $active_tab_id === 'tab-router' ? 'active' : '' ?>" onclick="switchTab('tab-router')">🛠️ Router Commands</button>
                <button type="button" class="tab-btn" onclick="switchTab('tab-acl')">🛡️ ACL Commands</button>
            <?php endif; ?>
        </nav>
    </header>
    <div class="main-container">
        <div id="console-notice" class="alert-box success" role="status" aria-live="polite" hidden></div>

        <?php include 'console_panels.php'; ?>
        <?php include __DIR__ . '/acl_panel.php'; ?>
        <!-- TAB 1: COMPLAINT MANAGER -->
        <?php if (has_feature_access('complaints')): ?>
        <div id="tab-complaints" class="tab-content <?= $active_tab_id === 'tab-complaints' ? 'active' : '' ?>">
        <section class="panel-card common-context">
          <div class="panel-body">
            <div class="form-group"><label for="current-complaint">Load a saved complaint</label><select id="current-complaint"><option value="">-- Choose Complaint --</option></select></div>
            <div class="form-row"><div class="form-group col-half"><label for="current-label">Current service label</label><input id="current-label" placeholder="Select a complaint or enter a service label"></div><div class="form-group col-half"><label for="current-ticket">Reference ticket</label><input id="current-ticket" placeholder="Optional"></div></div>
            <div class="actions"><button class="btn-secondary" onclick="run(refreshSelectors)">Refresh Complaints</button><button class="btn-secondary" onclick="loadComplaint(selectedComplaint('current-complaint'))">Check Selected Complaint</button><button class="btn-danger" onclick="removeComplaint()">Remove Selected Complaint</button></div>
            <pre id="current-details"></pre>
          </div>
        </section>
            <div class="panel-card">
                <div class="panel-header">
                    <h2>📁 Parallel Complaint Queue</h2>
                    <p>Paste multiple complaints to process and track them in real-time.</p>
                </div>
                <div class="panel-body">
                    <form id="form-add-complaints" onsubmit="handleAddComplaints(event)">
                        <div class="form-group">
                            <label>Add Multiple Complaints (One per line)</label>
                            <textarea id="bulk-labels" rows="5" placeholder="ESSClient_DPLC_A.A Network_Tandlianwala-FSD_900Mbps_DPLC14722SL1&#10;ESSClient_Central_Turbo_AAA_Brouadband_Faisalabad_2_981Mbps_Turbo14648SL694"></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-half">
                                <label>Default Complaint Type</label>
                                <select id="default-issue">
                                    <option value="Link is down.">Link is down.</option>
                                    <option value="Service degradation.">Service degradation.</option>
                                    <option value="Intermittent connectivity.">Intermittent connectivity.</option>
                                    <option value="High latency observed.">High latency observed.</option>
                                    <option value="Packet loss observed.">Packet loss observed.</option>
                                    <option value="Slow browsing issue.">Slow browsing issue.</option>
                                </select>
                            </div>
                            <div class="form-group col-half">
                                <label>Reference Ticket (Optional)</label>
                                <input type="text" id="bulk-ticket" placeholder="e.g. TKT-99201">
                            </div>
                        </div>
                        <button type="submit" class="btn-primary">➕ Add Complaints to Runtime Queue</button>
                    </form>
                    <div id="complaint-status-alert" class="alert-box success" style="display:none; margin-top:20px;"></div>
                    <h3 style="margin-top:25px; margin-bottom:12px;">Active Queue Table</h3>
                    <div class="table-responsive">
                        <table class="data-table" id="complaints-queue-table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Type</th>
                                    <th>Issue</th>
                                    <th>Added</th>
                                    <th>Opened</th>
                                    <th>Age</th>
                                    <th>Ticket</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 2: OPENING EMAIL -->
        <?php if (has_feature_access('opening')): ?>
        <div id="tab-opening" class="tab-content <?= $active_tab_id === 'tab-opening' ? 'active' : '' ?>">
            <div class="panel-card">
                <div class="panel-header">
                    <h2>🚨 Open / Start a Complaint</h2>
                    <p>Acknowledge customer complaints and issue standardized corporate ticket reference emails.</p>
                </div>
                <div class="panel-body">
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label>Select Queued Complaint</label>
                            <select id="open-complaint-select" onchange="handleSelectOpenComplaint(this)">
                                <option value="">-- Choose Complaint --</option>
                                <?php foreach ($queued_open_complaints as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" data-label="<?php echo htmlspecialchars($c['service_label']); ?>" data-ticket="<?php echo htmlspecialchars($c['ticket']); ?>">
                                        <?php echo htmlspecialchars($c['service_label']); ?> [<?php echo $c['status']; ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-half">
                            <label>Reference Ticket</label>
                            <input type="text" id="open-ticket" placeholder="e.g. TKT-2026-001">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label>Issue Type</label>
                            <select id="open-issue">
                                <option value="Link is down.">Link is down.</option>
                                <option value="Service degradation.">Service degradation.</option>
                                <option value="Intermittent connectivity.">Intermittent connectivity.</option>
                                <option value="High latency observed.">High latency observed.</option>
                                <option value="Packet loss observed.">Packet loss observed.</option>
                            </select>
                        </div>
                        <div class="form-group col-half">
                            <label>Priority</label>
                            <select id="open-priority">
                                <option value="Normal">Normal</option>
                                <option value="Urgent">Urgent</option>
                                <option value="Critical">Critical</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="btn-primary" onclick="generateOpeningEmail()">🚀 Generate Opening Email</button>

                    <div id="opening-results" style="display:none; margin-top:25px;">
                        <div class="form-group">
                            <label>Subject</label>
                            <div class="copy-input-wrap">
                                <input type="text" id="opening-res-subject" readonly>
                                <button type="button" class="btn-copy" onclick="copyText('opening-res-subject')">📋 Copy</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Email Body</label>
                            <textarea id="opening-res-body" rows="9" readonly></textarea>
                            <button type="button" class="btn-copy" style="margin-top:8px;" onclick="copyText('opening-res-body')">📋 Copy Email Body</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 3: CUSTOMER END / FINDINGS -->
        <?php if (has_feature_access('customer')): ?>
        <div id="tab-customer" class="tab-content <?= $active_tab_id === 'tab-customer' ? 'active' : '' ?>">
            <div class="panel-card">
                <div class="panel-header">
                    <h2>👤 Customer-End Verification & Findings</h2>
                    <p>Communicate NOC findings when tests prove the link is intact on transmission core.</p>
                </div>
                <div class="panel-body">
                    <div class="form-group">
                        <label>Service Label</label>
                        <input type="text" id="cust-label" placeholder="ESSClient_DIA_...">
                    </div>
                    <div class="form-group">
                        <label>Findings Observed</label>
                        <div class="checkbox-grid">
                            <label><input type="checkbox" name="cust-finding" value="No problematic alarm observed at Node end"> No problematic alarm observed at Node end</label>
                            <label><input type="checkbox" name="cust-finding" value="Port is up"> Port is up</label>
                            <label><input type="checkbox" name="cust-finding" value="Tunnel is intact"> Tunnel is intact</label>
                            <label><input type="checkbox" name="cust-finding" value="Optical power is optimal"> Optical power is optimal</label>
                            <label><input type="checkbox" name="cust-finding" value="Traffic is observed at our end"> Traffic is observed at our end</label>
                            <label><input type="checkbox" name="cust-finding" value="Latency is optimal"> Latency is optimal</label>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label>Requested Customer Action</label>
                            <select id="cust-action">
                                <option value="Verify last-mile media">Verify last-mile media</option>
                                <option value="Check CPE/router and power">Check CPE/router and power</option>
                                <option value="Check SFP/optical module and equipment port">Check SFP/optical module and equipment port</option>
                                <option value="Reconnect the affected PPPoE user">Reconnect the affected PPPoE user</option>
                                <option value="Test through standalone laptop/device">Test through standalone laptop/device</option>
                                <option value="Bypass LAN and perform direct testing">Bypass LAN and perform direct testing</option>
                            </select>
                        </div>
                        <div class="form-group col-half">
                            <label>Priority</label>
                            <select id="cust-priority">
                                <option value="Normal">Normal</option>
                                <option value="Follow-up">Follow-up</option>
                                <option value="Urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="btn-primary" onclick="generateCustomerEmail()">Generate Customer Response</button>

                    <div id="cust-results" style="display:none; margin-top:25px;">
                        <div class="form-group">
                            <label>Subject</label>
                            <input type="text" id="cust-res-subject" readonly>
                        </div>
                        <div class="form-group">
                            <label>Generated Email</label>
                            <textarea id="cust-res-body" rows="9" readonly></textarea>
                            <button type="button" class="btn-copy" style="margin-top:8px;" onclick="copyText('cust-res-body')">📋 Copy Email Body</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 4: VENDOR / INTERNAL ESCALATION -->
        <?php if (has_feature_access('escalation')): ?>
        <div id="tab-escalation" class="tab-content <?= $active_tab_id === 'tab-escalation' ? 'active' : '' ?>">
            <div class="panel-card">
                <div class="panel-header">
                    <h2>📨 Vendor Escalation</h2>
                    <p>Select vendor to generate escalation email and view its escalation matrix.</p>
                </div>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="esc-vendor-select">Escalate To Vendor</label>
                        <select id="esc-vendor-select" onchange="handleVendorSelect(this)">
                            <option value="">-- Select Vendor --</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="esc-res-body">Generated Email Body</label>
                        <textarea id="esc-res-body" rows="12" placeholder="Select a vendor to generate the email. You can edit the message here."></textarea>
                    </div>

                    <!-- OUTLOOK READY EMAIL RECIPIENTS (COPY-PASTE BOX) -->
                    <div id="vendor-emails-copy-section" style="display:none; margin-top:24px; background:#f0fdf4; border:1.5px solid #bbf7d0; border-radius:8px; padding:18px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                            <label style="font-weight:700; color:#166534; font-size:1rem;">📋 Outlook Ready Email Addresses</label>
                            <button type="button" class="btn-copy" onclick="copyText('vendor-all-emails')">📋 Copy All Vendor Emails</button>
                        </div>
                        
                        <div class="form-group" style="margin-bottom:14px;">
                            <label style="font-size:0.85rem; color:#15803d; font-weight:600;">All Vendor Emails (L1 to L5 / Management):</label>
                            <div class="copy-input-wrap">
                                <input type="text" id="vendor-all-emails" readonly style="background:#fff; font-size:0.9rem; border-color:#86efac;">
                                <button type="button" class="btn-copy" onclick="copyText('vendor-all-emails')">Copy All</button>
                            </div>
                        </div>

                        <!-- DYNAMIC SELECTED EMAILS BAR -->
                        <div id="vendor-selected-emails-box" style="margin-top:10px; padding-top:12px; border-top:1px dashed #86efac;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label style="font-size:0.85rem; color:#0f766e; font-weight:700;">✅ Selected Emails from Table Below (Click checkboxes in table to pick):</label>
                                <button type="button" class="btn-copy" onclick="copyText('vendor-selected-emails')" style="background:#ccfbf1; color:#0f766e; border-color:#99f6e4;">📋 Copy Selected</button>
                            </div>
                            <div class="copy-input-wrap">
                                <input type="text" id="vendor-selected-emails" readonly placeholder="Check boxes in the table below to select specific emails..." style="background:#fff; font-size:0.9rem; border-color:#5eead4;">
                                <button type="button" class="btn-copy" onclick="copyText('vendor-selected-emails')" style="background:#ccfbf1; color:#0f766e; border-color:#99f6e4;">Copy</button>
                            </div>
                        </div>
                    </div>

                    <!-- READ ONLY ESCALATION MATRIX TABLE BELOW EMAIL BODY -->
                    <div id="vendor-matrix-readonly-section" style="display:none; margin-top:25px; border-top: 2px solid #e2e8f0; padding-top:20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                            <h3 id="readonly-matrix-title" style="color:#0f172a; font-size:1.15rem; margin:0;">🏢 Vendor Escalation Matrix</h3>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <button type="button" class="btn-secondary" onclick="toggleAllCheckboxes(true)" style="padding:4px 10px; font-size:0.8rem;">Select All</button>
                                <button type="button" class="btn-secondary" onclick="toggleAllCheckboxes(false)" style="padding:4px 10px; font-size:0.8rem;">Deselect All</button>
                                <span class="badge-status badge-closed" style="background:#f1f5f9; color:#475569; font-weight:600;">Read Only</span>
                            </div>
                        </div>
                        <div id="readonly-matrix-container" class="table-responsive">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 5: CLOSURE / RFO -->
        <?php if (has_feature_access('closure')): ?>
        <div id="tab-closure" class="tab-content <?= $active_tab_id === 'tab-closure' ? 'active' : '' ?>">
            <div class="panel-card">
                <div class="panel-header">
                    <h2>✅ Close an Active Complaint & RFO</h2>
                    <p>Auto-calculates total service impact duration and generates closure announcement.</p>
                </div>
                <div class="panel-body">
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label>Select OPEN Complaint to Close</label>
                            <select id="closure-complaint-select">
                                <option value="">-- Choose Open Complaint --</option>
                                <?php foreach ($open_complaints as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['service_label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-half">
                            <label>Issue Found At</label>
                            <select id="closure-found-at">
                                <option value="Customer">Customer</option>
                                <option value="Vendor">Vendor</option>
                                <option value="NOMC - Transmission Optical">NOMC - Transmission Optical</option>
                                <option value="NOMC - IP Core">NOMC - IP Core</option>
                                <option value="Region - Field Operations (FOPs)">Region - Field Operations (FOPs)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label>Root Cause</label>
                            <select id="closure-root-cause">
                                <option value="No Issue Observed">No Issue Observed</option>
                                <option value="Fiber Break (Single)">Fiber Break (Single)</option>
                                <option value="Fiber Break (Dual/Triple)">Fiber Break (Dual/Triple)</option>
                                <option value="Power Issue">Power Issue</option>
                                <option value="Port Down">Port Down</option>
                                <option value="Customer related issue">Customer related issue</option>
                                <option value="Vendor / Upstream Issue">Vendor / Upstream Issue</option>
                            </select>
                        </div>
                        <div class="form-group col-half">
                            <label>Corrective Action</label>
                            <select id="closure-action">
                                <option value="Auto from Root Cause">Auto from Root Cause</option>
                                <option value="Fiber restored">Fiber restored</option>
                                <option value="Power restored">Power restored</option>
                                <option value="Configuration rectified">Configuration rectified</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="btn-primary" onclick="generateClosureEmail()">Generate Closure Email</button>

                    <div id="closure-results" style="display:none; margin-top:25px;">
                        <div class="alert-box success" id="closure-runtime-notice"></div>
                        <div class="form-group">
                            <label>Subject</label>
                            <input type="text" id="closure-res-subject" readonly>
                        </div>
                        <div class="form-group">
                            <label>Closure Email Body</label>
                            <textarea id="closure-res-body" rows="10" readonly></textarea>
                            <button type="button" class="btn-copy" style="margin-top:8px;" onclick="copyText('closure-res-body')">📋 Copy Email Body</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 6: STATS / TROUBLESHOOTING -->
        <?php if (has_feature_access('stats')): ?>
        <div id="tab-stats" class="tab-content <?= $active_tab_id === 'tab-stats' ? 'active' : '' ?>">
            <div class="panel-card">
                <div class="panel-header">
                    <h2>🧪 Technical Stats & Troubleshooting Generator</h2>
                    <p>Request exact troubleshooting outputs (WinMTR, PCAPdroid, Speedtests) based on scenario.</p>
                </div>
                <div class="panel-body">
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label>Service Label</label>
                            <input type="text" id="stats-label" placeholder="ESSClient_DIA_...">
                        </div>
                        <div class="form-group col-half">
                            <label>Reported Scenario</label>
                            <select id="stats-scenario">
                                <option value="Packet Loss / High Latency">Packet Loss / High Latency</option>
                                <option value="Slow Speed / Throughput">Slow Speed / Throughput</option>
                                <option value="Turbo Slow Speed">Turbo Slow Speed</option>
                                <option value="Banking Application Issue">Banking Application Issue</option>
                                <option value="TikTok Issue">TikTok Issue</option>
                                <option value="PPPoE / BRAS Issue">PPPoE / BRAS Issue</option>
                                <option value="Voice / PRI / SIP Issue">Voice / PRI / SIP Issue</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="btn-primary" onclick="generateStatsEmail()">Generate Stats Request</button>

                    <div id="stats-results" style="display:none; margin-top:25px;">
                        <div class="form-group">
                            <label>Subject</label>
                            <input type="text" id="stats-res-subject" readonly>
                        </div>
                        <div class="form-group">
                            <label>Email Body</label>
                            <textarea id="stats-res-body" rows="9" readonly></textarea>
                            <button type="button" class="btn-copy" style="margin-top:8px;" onclick="copyText('stats-res-body')">📋 Copy Email Body</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 7: PROGRESS UPDATE -->
        <?php if (has_feature_access('progress')): ?>
        <div id="tab-progress" class="tab-content <?= $active_tab_id === 'tab-progress' ? 'active' : '' ?>">
            <div class="panel-card">
                <div class="panel-header">
                    <h2>🔄 Ground Progress Updates</h2>
                    <p>Send interim customer progress updates with estimated restoration times.</p>
                </div>
                <div class="panel-body">
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label>Service Label</label>
                            <input type="text" id="prog-label" placeholder="ESSClient_...">
                        </div>
                        <div class="form-group col-half">
                            <label>Current Status</label>
                            <select id="prog-status">
                                <option value="Concerned team engaged">Concerned team engaged</option>
                                <option value="Fault localization in progress">Fault localization in progress</option>
                                <option value="Team dispatched">Team dispatched</option>
                                <option value="Team reached site">Team reached site</option>
                                <option value="Fiber splicing in progress">Fiber splicing in progress</option>
                                <option value="Rerouting in progress">Rerouting in progress</option>
                                <option value="Vendor engaged">Vendor engaged</option>
                                <option value="Monitoring link stability">Monitoring link stability</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-half">
                            <label>ETTR</label>
                            <select id="prog-ettr">
                                <option value="Awaited">Awaited</option>
                                <option value="30 Minutes">30 Minutes</option>
                                <option value="1 Hour">1 Hour</option>
                                <option value="2 Hours">2 Hours</option>
                                <option value="4 Hours">4 Hours</option>
                                <option value="Not Available">Not Available</option>
                            </select>
                        </div>
                        <div class="form-group col-half">
                            <label>Priority</label>
                            <select id="prog-priority">
                                <option value="Normal">Normal</option>
                                <option value="Follow-up">Follow-up</option>
                                <option value="Urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="btn-primary" onclick="generateProgressEmail()">Generate Progress Email</button>

                    <div id="prog-results" style="display:none; margin-top:25px;">
                        <div class="form-group">
                            <label>Subject</label>
                            <input type="text" id="prog-res-subject" readonly>
                        </div>
                        <div class="form-group">
                            <label>Email Body</label>
                            <textarea id="prog-res-body" rows="9" readonly></textarea>
                            <button type="button" class="btn-copy" style="margin-top:8px;" onclick="copyText('prog-res-body')">📋 Copy Email Body</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 8: DASHBOARD -->
        <?php if (has_feature_access('dashboard')): ?>
        <div id="tab-dashboard" class="tab-content <?= $active_tab_id === 'tab-dashboard' ? 'active' : '' ?>">
            <div class="panel-card">
                <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <h2>📋 Real-Time Incident Dashboard</h2>
                        <p>Live status and running duration of telecom complaints.</p>
                    </div>
                    <div>
                        <select id="dashboard-filter" onchange="loadDashboardTable()" style="padding:8px 14px; border-radius:6px; border:1px solid #cbd5e1;">
                            <option value="Queued + Open">Queued + Open</option>
                            <option value="Open Only">Open Only</option>
                            <option value="Queued Only">Queued Only</option>
                            <option value="Closed Only">Closed Only</option>
                        </select>
                        <button class="btn-secondary" onclick="loadDashboardTable()" style="margin-left:8px;">🔄 Refresh</button>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="table-responsive">
                        <table class="data-table" id="main-dashboard-table">
                            <thead>
                                <tr>
                                    <th>Service ID</th>
                                    <th>Type</th>
                                    <th>Issue</th>
                                    <th>Added Time</th>
                                    <th>Opened Time</th>
                                    <th>Running Age</th>
                                    <th>Closed Time</th>
                                    <th>Total Duration</th>
                                    <th>Ticket</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($active_tab_id)): ?>
        <div class="panel-card" style="margin-top:20px; text-align:center; padding:40px;">
            <h2>🔒 No Feature Access Assigned</h2>
            <p style="color:#64748b; margin-top:10px;">Your account currently has no active feature permissions enabled. Please contact an administrator to assign feature tabs.</p>
        </div>
        <?php endif; ?>

    </div>
    <script>window.nocCsrf = <?= json_encode($_SESSION['csrf']) ?>; window.nocUserRole = <?= json_encode($_SESSION['noc_role'] ?? 'user') ?>; const NOC_OPTIONS = <?= file_get_contents(__DIR__ . '/noc_options.json') ?>;</script>
    <script src="noc_console.js?v=<?= filemtime(__DIR__ . '/noc_console.js') ?>"></script>
    <?php if (has_feature_access('router')): ?>
    <script src="acl_commands.js?v=<?= filemtime(__DIR__ . '/acl_commands.js') ?>"></script>
    <?php endif; ?>
</body>
</html>

