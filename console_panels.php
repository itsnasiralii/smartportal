<?php if (has_feature_access('outage')): ?>
<div id="tab-outage" class="tab-content <?= ($active_tab_id ?? '') === 'tab-outage' ? 'active' : '' ?>">
 <div class="panel-card">
  <div class="panel-header"><h2>📈 Outage Analyzer</h2><p>Analyze an alarm dump, export affected links, and prepare a shift handover.</p></div>
  <div class="panel-body">
   <form onsubmit="analyzeOutage(event)">
    <div class="form-group"><label for="outage-file">Alarm dump (.csv, .xlsx, .xls)</label><input id="outage-file" type="file" accept=".csv,.xlsx,.xls" required><small>Required columns: Location Info and Last Occurred (ST). Only ESS services are included; duplicate services and visibility alarms are excluded.</small></div>
    <button class="btn-primary">Analyze Outage</button>
   </form>
   <div id="outage-results" hidden>
    <div class="form-group"><label for="outage-output">Outlook-ready text</label><textarea id="outage-output" rows="16"></textarea><button class="btn-copy" onclick="copyText('outage-output')">Copy text</button></div>
    <div class="actions"><a id="outage-full" class="btn-primary">Download categorized Excel</a><a id="outage-simple" class="btn-secondary">Download simple links Excel</a></div>
   </div>
   <h3>Shift History</h3>
   <div class="actions"><button class="btn-secondary" onclick="loadOutageHistory()">Refresh History</button><button class="btn-danger" onclick="resetOutageHistory()">Clear Shift History</button></div>
   <div class="table-responsive"><table class="data-table"><thead><tr><th>Outage</th><th>Processed (PKT)</th><th>File</th><th>Links</th><th>Priority</th><th>Categories</th><th>Occurred</th></tr></thead><tbody id="outage-history"></tbody></table></div>
   <div class="form-group"><label for="outage-handover">Shift handover summary</label><textarea id="outage-handover" rows="10" readonly></textarea><button class="btn-copy" onclick="copyText('outage-handover')">Copy handover</button></div>
  </div>
 </div>
</div>
<?php endif; ?>
<?php if (has_feature_access('matrix')): ?>
<div id="tab-matrix" class="tab-content <?= ($active_tab_id ?? '') === 'tab-matrix' ? 'active' : '' ?>">
 <div class="panel-card">
  <div class="panel-header"><h2>🏪 Vendor Matrix</h2><p>Review escalation contacts and recipients. Add or remove vendors and contacts in the administration console.</p></div>
  <div class="panel-body">
   <div class="form-group"><label for="matrix-vendor">Vendor</label><select id="matrix-vendor" onchange="loadMatrix(this.value)"><option value="">-- Select Vendor --</option><?php foreach ($vendors as $v): ?><option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option><?php endforeach; ?></select></div>
   <div id="matrix-view" class="table-responsive"></div>
   <div class="form-group"><label for="matrix-emails">All vendor emails</label><textarea id="matrix-emails" rows="3" readonly></textarea><button class="btn-copy" onclick="copyText('matrix-emails')">Copy emails</button></div>
   <a class="btn-primary" href="admin.php">Manage Vendors &amp; Contacts</a>
  </div>
 </div>
</div>
<?php endif; ?>
<?php if (has_feature_access('roster')): ?>
<div id="tab-roster" class="tab-content <?= ($active_tab_id ?? '') === 'tab-roster' ? 'active' : '' ?>">
 <div class="panel-card">
  <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
   <div>
    <h2>📅 CNOC Duty Roster &amp; Workload Analytics</h2>
    <p>Dynamic rotation engine (4/4, 5/2, 7/7, custom), live shift monitor, and workload distribution.</p>
   </div>
   <div style="display:flex; gap:8px;">
    <button type="button" class="btn-primary" onclick="calculateRoster()">⚡ Calculate Roster</button>
    <button type="button" class="btn-secondary" onclick="exportRosterCsv()">📥 Export CSV</button>
   </div>
  </div>
  <div class="panel-body">
   <div class="roster-container">
    <!-- LEFT SIDEBAR: CONTROLS & SETTINGS -->
    <div class="roster-sidebar">
     <div class="roster-section-title">⚡ Quick Rotation Presets</div>
     <div class="preset-btn-group">
      <button type="button" class="preset-pill" onclick="applyRosterPreset(4,4)">4 ON / 4 OFF</button>
      <button type="button" class="preset-pill" onclick="applyRosterPreset(5,2)">5 ON / 2 OFF</button>
      <button type="button" class="preset-pill" onclick="applyRosterPreset(3,3)">3 ON / 3 OFF</button>
      <button type="button" class="preset-pill" onclick="applyRosterPreset(7,7)">7 ON / 7 OFF</button>
      <button type="button" class="preset-pill" onclick="applyRosterPreset(14,14)">14 ON / 14 OFF</button>
      <button type="button" class="preset-pill" onclick="applyRosterPreset(2,2)">2 ON / 2 OFF</button>
      <button type="button" class="preset-pill" onclick="applyRosterPreset(6,3)">6 ON / 3 OFF</button>
      <button type="button" class="preset-pill" onclick="applyRosterPreset(21,21)">21 ON / 21 OFF</button>
     </div>

     <div class="roster-section-title" style="margin-top:16px;">🕒 Shift Presets</div>
     <div class="preset-btn-group">
      <button type="button" class="preset-pill" onclick="applyShiftPreset('07:00',12)">☀️ Day (07:00 - 19:00)</button>
      <button type="button" class="preset-pill" onclick="applyShiftPreset('19:00',12)">🌙 Night (19:00 - 07:00)</button>
     </div>

     <div class="roster-section-title" style="margin-top:16px;">🔄 Cycle Parameters</div>
     <div class="form-row">
      <div class="form-group col-half">
       <label for="roster-days-on">Days ON</label>
       <input type="number" id="roster-days-on" value="4" min="1" max="90" onchange="calculateRoster()">
      </div>
      <div class="form-group col-half">
       <label for="roster-days-off">Days OFF</label>
       <input type="number" id="roster-days-off" value="4" min="1" max="90" onchange="calculateRoster()">
      </div>
     </div>

     <div class="form-group">
      <label for="roster-anchor">Anchor Date (First Duty Day of Cycle)</label>
      <input type="date" id="roster-anchor" value="<?= date('Y-m-d') ?>" onchange="calculateRoster()">
      <small style="color:#64748b;">The cycle repeats continuously backward &amp; forward from this anchor date.</small>
     </div>

     <div class="roster-section-title" style="margin-top:16px;">📆 Schedule Range &amp; Shifts</div>
     <div class="form-row">
      <div class="form-group col-half">
       <label for="roster-start">Range Start</label>
       <input type="date" id="roster-start" value="<?= date('Y-m-d') ?>" onchange="calculateRoster()">
      </div>
      <div class="form-group col-half">
       <label for="roster-end">Range End</label>
       <input type="date" id="roster-end" value="<?= date('Y-m-d', strtotime('+90 days')) ?>" onchange="calculateRoster()">
      </div>
     </div>

     <div class="form-row">
      <div class="form-group col-half">
       <label for="roster-shift-start">Shift Start Time</label>
       <input type="time" id="roster-shift-start" value="07:00" onchange="calculateRoster()">
      </div>
      <div class="form-group col-half">
       <label for="roster-shift-hours">Shift Duration (Hours)</label>
       <input type="number" id="roster-shift-hours" value="12" min="1" max="24" onchange="calculateRoster()">
      </div>
     </div>

     <div class="form-group">
      <label>Designated Weekend Days</label>
      <div class="roster-weekend-pills">
       <label><input type="checkbox" name="roster-weekend" value="Monday" onchange="calculateRoster()"> Mon</label>
       <label><input type="checkbox" name="roster-weekend" value="Tuesday" onchange="calculateRoster()"> Tue</label>
       <label><input type="checkbox" name="roster-weekend" value="Wednesday" onchange="calculateRoster()"> Wed</label>
       <label><input type="checkbox" name="roster-weekend" value="Thursday" onchange="calculateRoster()"> Thu</label>
       <label><input type="checkbox" name="roster-weekend" value="Friday" onchange="calculateRoster()"> Fri</label>
       <label><input type="checkbox" name="roster-weekend" value="Saturday" checked onchange="calculateRoster()"> Sat</label>
       <label><input type="checkbox" name="roster-weekend" value="Sunday" checked onchange="calculateRoster()"> Sun</label>
      </div>
     </div>

     <div class="roster-section-title" style="margin-top:20px;">⏱️ Live Shift Monitor Check Time</div>
     <div class="form-row">
      <div class="form-group col-half">
       <label for="roster-check-date">Check Date</label>
       <input type="date" id="roster-check-date" value="<?= date('Y-m-d') ?>" onchange="calculateRoster()">
      </div>
      <div class="form-group col-half">
       <label for="roster-check-time">Check Time</label>
       <input type="time" id="roster-check-time" value="<?= date('H:i') ?>" onchange="calculateRoster()">
      </div>
     </div>
     <button type="button" class="btn-secondary" style="width:100%; margin-top:4px;" onclick="syncRosterCheckTime()">🔄 Sync Current PKT Time</button>

    </div>

    <!-- RIGHT MAIN CONTENT: STATUS BANNER, KPIS, TABS & TABLES -->
    <div class="roster-main">
     <!-- LIVE STATUS CARD -->
     <div id="roster-status-banner" class="roster-status-card">
      <div style="color:#64748b; font-size:0.9rem;">Evaluating live status...</div>
     </div>

     <!-- KPI CARDS GRID -->
     <div class="roster-kpi-grid">
      <div class="roster-kpi-card">
       <div class="kpi-title">📋 Duty Days</div>
       <div class="kpi-value" id="kpi-duty-days">—</div>
       <div class="kpi-sub" id="kpi-duty-pct">—</div>
      </div>
      <div class="roster-kpi-card">
       <div class="kpi-title">🏖️ Off Days</div>
       <div class="kpi-value" id="kpi-off-days">—</div>
       <div class="kpi-sub" id="kpi-off-pct">—</div>
      </div>
      <div class="roster-kpi-card">
       <div class="kpi-title">🗓️ Weekend Duties</div>
       <div class="kpi-value" id="kpi-weekend-duties">—</div>
       <div class="kpi-sub">Weekend shifts</div>
      </div>
      <div class="roster-kpi-card">
       <div class="kpi-title">⏱️ Total Hours</div>
       <div class="kpi-value" id="kpi-total-hours">—</div>
       <div class="kpi-sub" id="kpi-avg-weekly">— hrs/week</div>
      </div>
      <div class="roster-kpi-card">
       <div class="kpi-title">⏳ Remaining Duty</div>
       <div class="kpi-value" id="kpi-remaining-duty">—</div>
       <div class="kpi-sub" id="kpi-completed-duty">— completed</div>
      </div>
     </div>

     <!-- VIEW NAVIGATION TABS -->
     <div class="roster-subnav">
      <button type="button" class="roster-tab-pill active" onclick="switchRosterSubtab('daily')">📅 Daily Roster</button>
      <button type="button" class="roster-tab-pill" onclick="switchRosterSubtab('weekly')">📊 Weekly Summary</button>
      <button type="button" class="roster-tab-pill" onclick="switchRosterSubtab('monthly')">📆 Monthly Workload</button>
      <button type="button" class="roster-tab-pill" onclick="switchRosterSubtab('metrics')">📈 Executive Metrics</button>
     </div>

     <!-- VIEW 1: DAILY ROSTER -->
     <div id="roster-view-daily" class="roster-view-pane active">
      <div class="table-responsive" style="max-height:550px; overflow-y:auto;">
       <table class="data-table" id="roster-daily-table">
        <thead>
         <tr>
          <th>Date</th>
          <th>Day</th>
          <th>Status</th>
          <th>Shift Window</th>
          <th>Hours</th>
          <th>Weekend</th>
         </tr>
        </thead>
        <tbody id="roster-daily-tbody">
         <tr><td colspan="6" style="text-align:center; padding:20px; color:#64748b;">Loading duty roster...</td></tr>
        </tbody>
       </table>
      </div>
     </div>

     <!-- VIEW 2: WEEKLY SUMMARY -->
     <div id="roster-view-weekly" class="roster-view-pane" style="display:none;">
      <div class="table-responsive" style="max-height:550px; overflow-y:auto;">
       <table class="data-table" id="roster-weekly-table">
        <thead>
         <tr>
          <th>Week</th>
          <th>Date Range</th>
          <th>Duty Days</th>
          <th>Off Days</th>
          <th>Scheduled Hours</th>
          <th>Weekend Shifts</th>
         </tr>
        </thead>
        <tbody id="roster-weekly-tbody"></tbody>
       </table>
      </div>
     </div>

     <!-- VIEW 3: MONTHLY WORKLOAD -->
     <div id="roster-view-monthly" class="roster-view-pane" style="display:none;">
      <div class="table-responsive">
       <table class="data-table" id="roster-monthly-table">
        <thead>
         <tr>
          <th>Month</th>
          <th>Total Days</th>
          <th>Duty Days</th>
          <th>Off Days</th>
          <th>Total Hours</th>
          <th>Weekend Duties</th>
          <th>Duty Share</th>
         </tr>
        </thead>
        <tbody id="roster-monthly-tbody"></tbody>
       </table>
      </div>
     </div>

     <!-- VIEW 4: EXECUTIVE METRICS -->
     <div id="roster-view-metrics" class="roster-view-pane" style="display:none;">
      <div id="roster-metrics-content"></div>
     </div>

    </div>
   </div>
  </div>
 </div>
</div>
<?php endif; ?>
<?php if (has_feature_access('vpbx')): ?>
<div id="tab-vpbx" class="tab-content <?= ($active_tab_id ?? '') === 'tab-vpbx' ? 'active' : '' ?>">
 <div class="panel-card">
  <!-- HERO BANNER -->
  <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
   <div>
    <div style="color:#059669; font-size:0.75rem; letter-spacing:.12em; font-weight:800; text-transform:uppercase; margin-bottom:4px;">Voice assurance • Live runtime analyzer</div>
    <h2 style="margin:0; font-size:1.35rem; color:#0f172a;">📞 Regulatory &amp; VPBX Testing Portal</h2>
    <p style="margin:4px 0 0; color:#64748b; font-size:0.88rem;">Clean operator session testing view &bull; Outgoing and incoming call verification.</p>
   </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
     <?php if (($_SESSION['noc_role'] ?? '') === 'admin' || !empty($_SESSION['admin_logged_in'])): ?>
     <button type="button" class="btn-secondary" style="border:1px solid #7c3aed; color:#7c3aed; font-weight:700;" onclick="toggleVpbxAdminIvrDrawer()">⚙️ Admin IVR Library</button>
     <?php endif; ?>
     <button type="button" class="btn-secondary" onclick="exportVpbxCsv('outgoing')">📥 Export Outgoing CSV</button>
     <button type="button" class="btn-secondary" onclick="exportVpbxCsv('incoming')">📥 Export Incoming CSV</button>
     <button type="button" class="btn-danger" onclick="clearVpbxSessions()">🗑️ Clear All Runtime Sessions</button>
    </div>
   </div>

   <?php if (($_SESSION['noc_role'] ?? '') === 'admin' || !empty($_SESSION['admin_logged_in'])): ?>
   <!-- ADMIN MODE: IVR LIBRARY MANAGER DRAWER -->
   <div id="vpbx-admin-ivr-drawer" style="display:none; background:#f5f3ff; border:1px solid #ddd6fe; border-radius:8px; padding:16px; margin: 0 20px 20px 20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
     <div style="font-weight:700; color:#5b21b6; font-size:0.95rem;">⚙️ VPBX Admin Mode: IVR Library Manager</div>
     <button type="button" class="btn-secondary" style="font-size:0.75rem; padding:2px 8px;" onclick="toggleVpbxAdminIvrDrawer()">Close</button>
    </div>
    <div style="display:flex; gap:10px; margin-bottom:14px;">
     <input type="text" id="vpbx-admin-new-ivr" placeholder="Enter new IVR announcement text (e.g. Line busy, All circuits are busy...)" style="flex:1;">
     <button type="button" class="btn-primary" style="background:#7c3aed; border-color:#6d28d9;" onclick="handleVpbxAddIvr()">➕ Add IVR</button>
    </div>
    <div style="font-size:0.8rem; font-weight:700; color:#6b21a8; margin-bottom:6px;">Configured IVR Options:</div>
    <div id="vpbx-admin-ivr-list" style="display:flex; flex-wrap:wrap; gap:8px; max-height:180px; overflow-y:auto;">
     <!-- Dynamically filled with badges + delete buttons -->
    </div>
   </div>
   <?php endif; ?>

   <div class="panel-body">
   <!-- MAIN CALL SECTION TABS (OUTGOING vs INCOMING) -->
   <div style="display:flex; gap:8px; border-bottom:2px solid #e2e8f0; margin-bottom:20px;">
    <button type="button" id="vpbx-nav-out" class="tab-btn active" style="font-weight:700; border-bottom:2px solid #059669; margin-bottom:-2px; background:none;" onclick="switchVpbxSection('outgoing')">📤 Outgoing Call Section</button>
    <button type="button" id="vpbx-nav-inc" class="tab-btn" style="font-weight:700; border-bottom:2px solid transparent; margin-bottom:-2px; background:none;" onclick="switchVpbxSection('incoming')">📥 Incoming Call Section</button>
   </div>

   <!-- SECTION 1: OUTGOING -->
   <div id="vpbx-sec-outgoing">
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:18px; margin-bottom:24px;">
     <!-- OUTGOING SUB-NAV: ADD vs UPDATE/DELETE -->
     <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div style="display:flex; gap:8px;">
       <button type="button" id="vpbx-out-subtab-add" class="btn-primary" style="padding:6px 14px; font-size:0.85rem;" onclick="switchVpbxOutSubtab('add')">➕ Add Outgoing Case</button>
       <button type="button" id="vpbx-out-subtab-edit" class="btn-secondary" style="padding:6px 14px; font-size:0.85rem;" onclick="switchVpbxOutSubtab('edit')">✏️ Update / 🗑️ Delete Case</button>
      </div>
      <button type="button" class="btn-secondary" style="padding:4px 10px; font-size:0.8rem;" onclick="loadVpbxData()">🔄 Refresh Table</button>
     </div>

     <!-- SUB-PANE: ADD OUTGOING CASE -->
     <div id="vpbx-out-pane-add">
      <form onsubmit="handleVpbxAddOutgoing(event)">
       <div class="form-row">
        <div class="form-group col-half">
          <label for="vpbx-out-client">Client Name *</label>
          <input type="text" id="vpbx-out-client" value="Nasir Penthouse" required placeholder="e.g. Nasir Penthouse">
         </div>
         <div class="form-group col-half">
          <label for="vpbx-out-master">Master Number *</label>
          <input type="text" id="vpbx-out-master" value="02138459201" required placeholder="10 or 11 digits">
        </div>
       </div>

       <div class="form-row">
        <div class="form-group col-half">
         <label for="vpbx-out-child">Child Number (11-digit mobile starting 03) *</label>
         <input type="text" id="vpbx-out-child" placeholder="03XXXXXXXXX" required maxlength="11">
        </div>
        <div class="form-group col-half">
         <label for="vpbx-out-party-b">Party B Number (Customer, starting 03) *</label>
         <input type="text" id="vpbx-out-party-b" placeholder="03XXXXXXXXX" required maxlength="13" oninput="updateVpbxDialPreview()">
        </div>
       </div>

       <div class="form-row">
        <div class="form-group col-half">
         <label>Party B Dialing Mode</label>
         <div style="display:flex; gap:16px; align-items:center; padding-top:6px;">
          <label style="display:flex; align-items:center; gap:6px; font-size:0.9rem; cursor:pointer;">
           <input type="radio" name="vpbx-out-dial-mode" value="With 66" checked onchange="updateVpbxDialPreview()"> With 66
          </label>
          <label style="display:flex; align-items:center; gap:6px; font-size:0.9rem; cursor:pointer;">
           <input type="radio" name="vpbx-out-dial-mode" value="Without 66" onchange="updateVpbxDialPreview()"> Without 66
          </label>
         </div>
        </div>
        <div class="form-group col-half">
         <label for="vpbx-out-dial-preview">Number to Dial (Party B Preview)</label>
         <input type="text" id="vpbx-out-dial-preview" readonly style="background:#f1f5f9; font-weight:700; color:#0f172a;" value="Enter Party B number first">
        </div>
       </div>

       <div class="form-row">
        <div class="form-group col-half">
         <label for="vpbx-out-operator">Operator</label>
         <select id="vpbx-out-operator">
          <option value="Jazz">Jazz</option>
          <option value="Zong">Zong</option>
          <option value="Ufone">Ufone</option>
          <option value="Telenor">Telenor</option>
         </select>
        </div>
        <div class="form-group col-half">
         <label for="vpbx-out-status">Status</label>
         <select id="vpbx-out-status" onchange="handleVpbxOutStatusChange(this.value)">
          <option value="Connected">Connected</option>
          <option value="Failed">Failed</option>
          <option value="Busy / No Answer">Busy / No Answer</option>
         </select>
        </div>
       </div>

       <div class="form-row">
        <div class="form-group col-half">
         <label for="vpbx-out-ivr">IVR / Announcement</label>
         <select id="vpbx-out-ivr" onchange="handleVpbxOutIvrChange(this.value)">
          <option value="Standard IVR">Standard IVR</option>
          <option value="The dialed number is not available right now.">The dialed number is not available right now.</option>
          <option value="The dialed number is powered off.">The dialed number is powered off.</option>
          <option value="Number is not reachable at the moment.">Number is not reachable at the moment.</option>
          <option value="Call not reached at the moment.">Call not reached at the moment.</option>
          <option value="Apka Mila hovo Number Banda hai">Apka Mila hovo Number Banda hai</option>
          <option value="Continous IVR playing">Continous IVR playing</option>
          <option value="custom">Specify Other...</option>
         </select>
         <input type="text" id="vpbx-out-ivr-custom" placeholder="Specify other IVR message..." style="display:none; margin-top:6px;">
        </div>
        <div class="form-group col-half">
         <label for="vpbx-out-time">Time Stamp</label>
         <div style="display:flex; gap:8px;">
          <input type="text" id="vpbx-out-time" value="<?= date('h:i A') ?>" style="flex:1;">
          <button type="button" class="btn-secondary" onclick="syncVpbxTime('vpbx-out-time')">🕒 Refresh Time</button>
         </div>
        </div>
       </div>

       <button type="submit" class="btn-primary" style="margin-top:6px;">➕ Add Outgoing Record</button>
      </form>
     </div>

     <!-- SUB-PANE: UPDATE / DELETE OUTGOING CASE -->
     <div id="vpbx-out-pane-edit" style="display:none;">
      <div class="form-row" style="align-items:flex-end;">
       <div class="form-group col-half">
        <label for="vpbx-out-case-select">Select Case ID</label>
        <select id="vpbx-out-case-select">
         <option value="">-- No cases available --</option>
        </select>
       </div>
       <div class="form-group col-half">
        <label for="vpbx-out-update-status">New Status</label>
        <select id="vpbx-out-update-status">
         <option value="Connected">Connected</option>
         <option value="Failed">Failed</option>
         <option value="Busy / No Answer">Busy / No Answer</option>
        </select>
       </div>
      </div>
      <div style="display:flex; gap:10px; margin-top:10px;">
       <button type="button" class="btn-secondary" onclick="handleVpbxUpdateOutgoing()">💾 Update Status</button>
       <button type="button" class="btn-danger" onclick="handleVpbxDeleteOutgoing()">🗑️ Delete Case</button>
      </div>
     </div>
    </div>

    <!-- OUTGOING TABLE REPORT -->
    <div style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:18px;">
     <div style="font-size:1.05rem; font-weight:800; color:#0f172a; margin-bottom:12px;">📊 Outgoing Call Test Report</div>
     <div class="table-responsive">
      <table class="data-table" id="vpbx-outgoing-table">
       <thead>
        <tr style="background:#f8fafc; border-bottom:2px solid #cbd5e1; color:#1e293b; font-size:11px; text-transform:uppercase;">
         <th>Case</th>
         <th>Client Name</th>
         <th>Time Stamp</th>
         <th>Master Number</th>
         <th>Child Number</th>
         <th>Customer (Party B)</th>
         <th>Operator</th>
         <th>IVR / Announcement</th>
         <th style="text-align:center;">Status</th>
        </tr>
       </thead>
       <tbody id="vpbx-outgoing-tbody">
        <tr><td colspan="9" style="text-align:center; padding:20px; color:#64748b;">Loading outgoing test cases...</td></tr>
       </tbody>
      </table>
     </div>
    </div>
   </div>

   <!-- SECTION 2: INCOMING -->
   <div id="vpbx-sec-incoming" style="display:none;">
    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:18px; margin-bottom:24px;">
     <!-- INCOMING SUB-NAV: ADD vs UPDATE/DELETE -->
     <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
      <div style="display:flex; gap:8px;">
       <button type="button" id="vpbx-inc-subtab-add" class="btn-primary" style="padding:6px 14px; font-size:0.85rem;" onclick="switchVpbxIncSubtab('add')">➕ Add Incoming Case</button>
       <button type="button" id="vpbx-inc-subtab-edit" class="btn-secondary" style="padding:6px 14px; font-size:0.85rem;" onclick="switchVpbxIncSubtab('edit')">✏️ Update / 🗑️ Delete Case</button>
      </div>
      <button type="button" class="btn-secondary" style="padding:4px 10px; font-size:0.8rem;" onclick="loadVpbxData()">🔄 Refresh Table</button>
     </div>

     <!-- SUB-PANE: ADD INCOMING CASE -->
     <div id="vpbx-inc-pane-add">
      <form onsubmit="handleVpbxAddIncoming(event)">
       <div class="form-row">
        <div class="form-group col-half">
         <label for="vpbx-inc-client">Client Name *</label>
         <input type="text" id="vpbx-inc-client" value="HERBYZON" required placeholder="e.g. Nasir Voice Assurance">
        </div>
        <div class="form-group col-half">
         <label for="vpbx-inc-party-a">Party A (Customer, 11-digit starting 03) *</label>
         <input type="text" id="vpbx-inc-party-a" placeholder="03XXXXXXXXX" required maxlength="11">
        </div>
       </div>

       <div class="form-row">
        <div class="form-group col-half">
         <label for="vpbx-inc-party-b">Party B (Master Number) *</label>
         <input type="text" id="vpbx-inc-party-b" value="3703653389" required placeholder="10 or 11 digits">
        </div>
        <div class="form-group col-half">
         <label for="vpbx-inc-operator">Operator</label>
         <select id="vpbx-inc-operator">
          <option value="Jazz">Jazz</option>
          <option value="Zong">Zong</option>
          <option value="Ufone">Ufone</option>
          <option value="Telenor">Telenor</option>
         </select>
        </div>
       </div>

       <div class="form-row">
        <div class="form-group col-half">
         <label for="vpbx-inc-status">Status</label>
         <select id="vpbx-inc-status" onchange="handleVpbxIncStatusChange(this.value)">
          <option value="Blocked">Blocked</option>
          <option value="Received">Received</option>
         </select>
        </div>
        <div class="form-group col-half">
         <label for="vpbx-inc-ivr">IVR / Announcement</label>
         <select id="vpbx-inc-ivr" onchange="handleVpbxIncIvrChange(this.value)">
          <option value="Standard IVR">Standard IVR</option>
          <option value="Main Menu (2)">Main Menu (2)</option>
          <option value="The dialed number is not available right now.">The dialed number is not available right now.</option>
          <option value="The dialed number is powered off.">The dialed number is powered off.</option>
          <option value="Number is not reachable at the moment.">Number is not reachable at the moment.</option>
          <option value="Call not reached at the moment.">Call not reached at the moment.</option>
          <option value="Apka Mila hovo Number Banda hai">Apka Mila hovo Number Banda hai</option>
          <option value="Continous IVR playing">Continous IVR playing</option>
          <option value="custom">Specify Other...</option>
         </select>
         <input type="text" id="vpbx-inc-ivr-custom" placeholder="Specify other IVR message..." style="display:none; margin-top:6px;">
        </div>
       </div>

       <div class="form-row">
        <div class="form-group col-half">
         <label for="vpbx-inc-time">Time Stamp</label>
         <div style="display:flex; gap:8px;">
          <input type="text" id="vpbx-inc-time" value="<?= date('h:i A') ?>" style="flex:1;">
          <button type="button" class="btn-secondary" onclick="syncVpbxTime('vpbx-inc-time')">🕒 Refresh Time</button>
         </div>
        </div>
       </div>

       <button type="submit" class="btn-primary" style="margin-top:6px;">➕ Add Incoming Record</button>
      </form>
     </div>

     <!-- SUB-PANE: UPDATE / DELETE INCOMING CASE -->
     <div id="vpbx-inc-pane-edit" style="display:none;">
      <div class="form-row" style="align-items:flex-end;">
       <div class="form-group col-half">
        <label for="vpbx-inc-case-select">Select Case ID</label>
        <select id="vpbx-inc-case-select">
         <option value="">-- No cases available --</option>
        </select>
       </div>
       <div class="form-group col-half">
        <label for="vpbx-inc-update-status">New Status</label>
        <select id="vpbx-inc-update-status">
         <option value="Received">Received</option>
         <option value="Blocked">Blocked</option>
        </select>
       </div>
      </div>
      <div style="display:flex; gap:10px; margin-top:10px;">
       <button type="button" class="btn-secondary" onclick="handleVpbxUpdateIncoming()">💾 Update Status</button>
       <button type="button" class="btn-danger" onclick="handleVpbxDeleteIncoming()">🗑️ Delete Case</button>
      </div>
     </div>
    </div>

    <!-- INCOMING TABLE REPORT -->
    <div style="background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:18px;">
     <div style="font-size:1.05rem; font-weight:800; color:#0f172a; margin-bottom:12px;">📊 Incoming Call Test Report</div>
     <div class="table-responsive">
      <table class="data-table" id="vpbx-incoming-table">
       <thead>
        <tr style="background:#f8fafc; border-bottom:2px solid #cbd5e1; color:#1e293b; font-size:11px; text-transform:uppercase;">
         <th>Case</th>
         <th>Client Name</th>
         <th>Time Stamp</th>
         <th>Party A (Customer)</th>
         <th>Party B (Master)</th>
         <th>Operator</th>
         <th>IVR / Announcement</th>
         <th style="text-align:center;">Status</th>
        </tr>
       </thead>
       <tbody id="vpbx-incoming-tbody">
        <tr><td colspan="8" style="text-align:center; padding:20px; color:#64748b;">Loading incoming test cases...</td></tr>
       </tbody>
      </table>
     </div>
    </div>
   </div>

  </div>
 </div>
</div>
<?php endif; ?>
<!-- ============================================================ -->
<!-- TAB: NMS CUSTOMER LABEL SEARCH                               -->
<!-- ============================================================ -->
<?php if (has_feature_access('nms')): ?>
<div id="tab-nms" class="tab-content <?= ($active_tab_id ?? '') === 'tab-nms' ? 'active' : '' ?>">
 <div class="panel-card">
  <div class="panel-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
   <div>
    <h2>🏷️ NMS Customer Search Portal</h2>
    <p>Real-time customer database, NMS label inspection, technical link specifications, and transmission routing.</p>
   </div>
   <div style="display:flex; gap:8px; align-items:center;">
    <span class="badge-status" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-weight:700; font-size:12px;" id="nms-total-badge">4,587 Links Loaded</span>
   </div>
  </div>

  <div class="panel-body" style="padding:20px;">
   <!-- TOP MULTI-FILTER FORM -->
   <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:18px; margin-bottom:18px;">
    <form id="nms-filter-form" onsubmit="event.preventDefault(); searchNmsClients(1);">
     <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px;">
      
      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🆔 Unique / FLL Link ID</label>
       <input type="text" id="nms-f-link" placeholder="e.g. DIA16748SL301" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">👤 Client Name</label>
       <input type="text" id="nms-f-client" placeholder="e.g. Spark Links" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🛠️ Service</label>
       <input type="text" id="nms-f-service" placeholder="e.g. DIA, Turbonet" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🏢 Department</label>
       <input type="text" id="nms-f-dept" placeholder="e.g. GCSS, MKT" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🏷️ NMS Label</label>
       <input type="text" id="nms-f-label" placeholder="Search NMS Label..." style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">📍 Site</label>
       <input type="text" id="nms-f-site" placeholder="Site ID or Name" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🌐 ISP</label>
       <input type="text" id="nms-f-isp" placeholder="e.g. TWA, PTCL" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🔢 VLAN ID</label>
       <input type="text" id="nms-f-vlan" placeholder="e.g. 4050" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🔢 Tier Level</label>
       <input type="text" id="nms-f-tier" placeholder="e.g. 1, 2" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🗺️ Deployed Region</label>
       <input type="text" id="nms-f-region" placeholder="Central, South, North" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🗺️ Last Action Taken</label>
       <input type="text" id="nms-f-action" placeholder="e.g. Downgrade, Mod" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">🧭 Handling Region</label>
       <input type="text" id="nms-f-handling" placeholder="e.g. HQ, South" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">📅 Start Date</label>
       <input type="date" id="nms-f-start" style="width:100%; padding:6px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">📅 End Date</label>
       <input type="date" id="nms-f-end" style="width:100%; padding:6px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

      <div>
       <label style="font-size:11px; font-weight:700; color:#475569; display:block; margin-bottom:4px;">👤 Username</label>
       <input type="text" id="nms-f-user" placeholder="Dashboard Username" style="width:100%; padding:7px 10px; font-size:12px; border:1px solid #cbd5e1; border-radius:6px;">
      </div>

     </div>

     <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px; border-top:1px solid #e2e8f0; padding-top:12px;">
      <button type="button" class="btn-secondary" onclick="clearNmsFilters()" style="padding:7px 16px; font-size:12px;">✖ Clear</button>
      <button type="submit" class="btn-primary" style="padding:7px 20px; font-size:12px;">🔍 Search</button>
     </div>
    </form>
   </div>

   <!-- STATUS PILLS FILTER & EXPORT BAR -->
   <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
    <div style="display:flex; flex-wrap:wrap; gap:6px;" id="nms-status-pills">
     <button type="button" class="nms-pill active" onclick="setNmsStatusFilter('all', this)">📑 All</button>
     <button type="button" class="nms-pill" onclick="setNmsStatusFilter('Approved', this)">✅ Approved</button>
     <button type="button" class="nms-pill" onclick="setNmsStatusFilter('Rejected', this)">❌ Rejected</button>
     <button type="button" class="nms-pill" onclick="setNmsStatusFilter('Active', this)">● Active</button>
     <button type="button" class="nms-pill" onclick="setNmsStatusFilter('Suspended', this)">⏸ Suspended</button>
     <button type="button" class="nms-pill" onclick="setNmsStatusFilter('Terminated', this)">⛔ Terminated</button>
     <button type="button" class="nms-pill" onclick="setNmsStatusFilter('ZTE', this)" style="border-color:#93c5fd; color:#1d4ed8; font-weight:700;">🔵 ZTE Links</button>
    </div>

    <div>
     <button type="button" class="btn-danger" onclick="exportNmsExcel()" style="background:#b91c1c; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
      📥 Download Excel
     </button>
    </div>
   </div>

   <!-- DATA TABLE -->
   <div class="table-responsive" style="border:1px solid #cbd5e1; border-radius:8px; overflow-x:auto; background:#fff;">
    <table class="data-table nms-table" style="width:100%; border-collapse:collapse; font-size:12px;">
     <thead>
      <!-- PRIMARY COLUMN HEADERS (DARK THEME) -->
      <tr style="background:#1e293b; color:#f8fafc; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; text-align:left;">
       <th style="padding:10px 8px; width:35px; text-align:center;">#</th>
       <th style="padding:10px 10px; white-space:nowrap;">UNIQUE / FLL LINK ID</th>
       <th style="padding:10px 10px; white-space:nowrap;">CLIENT NAME</th>
       <th style="padding:10px 10px; white-space:nowrap; min-width:260px;">NMS LABEL</th>
       <th style="padding:10px 10px; white-space:nowrap;">SERVICE</th>
       <th style="padding:10px 10px; white-space:nowrap;">DEPARTMENT</th>
       <th style="padding:10px 10px; white-space:nowrap;">SITE</th>
       <th style="padding:10px 10px; white-space:nowrap;">DEPLOYED REGION</th>
       <th style="padding:10px 10px; white-space:nowrap;">LAST ACTION TAKEN</th>
       <th style="padding:10px 10px; white-space:nowrap;">LAST DATE</th>
       <th style="padding:10px 8px; white-space:nowrap; text-align:center;">TIER LEVEL</th>
       <th style="padding:10px 10px; white-space:nowrap; text-align:center;">STATUS</th>
       <th style="padding:10px 10px; white-space:nowrap;">GO AHEAD</th>
       <th style="padding:10px 10px; white-space:nowrap; text-align:right;">SOLUTION BW</th>
       <th style="padding:10px 10px; white-space:nowrap;">USERNAME</th>
       <th style="padding:10px 10px; white-space:nowrap; text-align:center;">ACTION</th>
      </tr>
      <!-- SECONDARY PER-COLUMN SEARCH ROW -->
      <tr style="background:#0f172a; border-top:1px solid #334155;">
       <th style="padding:4px 6px;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_unique_id" placeholder="Search..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_client" placeholder="Search..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_nms_label" placeholder="Search label..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_service" placeholder="Search..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_department" placeholder="Search..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_site" placeholder="Search..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_region" placeholder="Search..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_last_action" placeholder="Search..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_last_date" placeholder="Search..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_tier" placeholder="Tier..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_status" placeholder="Status..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_go_ahead" placeholder="Date..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_bw" placeholder="BW..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"><input type="text" class="nms-col-search" data-col="col_username" placeholder="User..." style="width:100%; font-size:11px; padding:3px 6px; border-radius:4px; border:1px solid #475569; background:#1e293b; color:#fff;"></th>
       <th style="padding:4px 6px;"></th>
      </tr>
     </thead>
     <tbody id="nms-table-body">
      <tr><td colspan="16" style="text-align:center; padding:30px; color:#64748b;">Loading customer links database...</td></tr>
     </tbody>
    </table>
   </div>

   <!-- PAGINATION & CONTROLS FOOTER -->
   <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-top:14px; padding:10px 4px;">
    <div style="font-size:12px; color:#475569;" id="nms-pagination-info">
     Showing 1 to 10 of 4,587 entries
    </div>

    <div style="display:flex; align-items:center; gap:8px;">
     <label style="font-size:12px; color:#475569;">Rows per page:</label>
     <select id="nms-limit-select" onchange="changeNmsLimit(this.value)" style="padding:4px 8px; font-size:12px; border:1px solid #cbd5e1; border-radius:4px;">
      <option value="10">10</option>
      <option value="25">25</option>
      <option value="50">50</option>
      <option value="100">100</option>
     </select>
    </div>

    <div style="display:flex; gap:4px; align-items:center;" id="nms-pagination-controls">
     <!-- Filled dynamically by JavaScript -->
    </div>
   </div>

  </div>
 </div>
</div>

<!-- ============================================================ -->
<!-- CUSTOMER TECHNICAL SPECIFICATION MODAL                       -->
<!-- ============================================================ -->
<div id="nms-detail-modal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.65); z-index:9999; backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:16px;">
 <div class="modal-card" style="background:#fff; border-radius:12px; width:100%; max-width:960px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); overflow:hidden;">
  
  <div style="padding:16px 20px; background:#1e293b; color:#fff; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #334155;">
   <div>
    <div style="font-size:11px; text-transform:uppercase; color:#94a3b8; font-weight:700; letter-spacing:0.05em;">Customer Link Specifications</div>
    <div style="font-size:1.15rem; font-weight:800; color:#fff;" id="nms-modal-title">Link Details</div>
   </div>
   <button type="button" onclick="closeNmsDetailModal()" style="background:none; border:none; color:#cbd5e1; font-size:24px; cursor:pointer; line-height:1;">&times;</button>
  </div>

  <div style="display:flex; gap:4px; background:#f1f5f9; padding:8px 16px; border-bottom:1px solid #e2e8f0; overflow-x:auto;">
   <button type="button" class="nms-m-tab active" onclick="switchNmsModalTab('overview', this)">📋 Overview &amp; Commercial</button>
   <button type="button" class="nms-m-tab" onclick="switchNmsModalTab('technical', this)">⚙️ Bandwidth &amp; IP Design</button>
   <button type="button" class="nms-m-tab" onclick="switchNmsModalTab('transport', this)">🌐 Transport &amp; Node Routing</button>
   <button type="button" class="nms-m-tab" onclick="switchNmsModalTab('site', this)">📍 Site &amp; Coordinates</button>
   <button type="button" class="nms-m-tab" onclick="switchNmsModalTab('contacts', this)">👥 Contacts &amp; KAM</button>
   <button type="button" class="nms-m-tab" onclick="switchNmsModalTab('timeline', this)">📅 Timeline &amp; Actions</button>
  </div>

  <div style="padding:20px; overflow-y:auto; flex:1;" id="nms-modal-body">
   <!-- Filled dynamically by JavaScript -->
  </div>

  <div style="padding:12px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
   <div style="font-size:12px; color:#64748b;" id="nms-modal-footer-info"></div>
   <button type="button" class="btn-secondary" onclick="closeNmsDetailModal()">Close</button>
  </div>

 </div>
</div>
<?php endif; ?>

<?php if (has_feature_access('router')): ?>
<div id="tab-router" class="tab-content <?= ($active_tab_id ?? '') === 'tab-router' ? 'active' : '' ?>">
 <div class="panel-card router-shell">
  <div class="panel-header router-command-header">
   <div>
    <div class="router-eyebrow">Database-backed • CNOC command selector</div>
    <h2>🛠️ Router Commands</h2>
    <p>Select stored data from dropdowns, enter only runtime values, then copy the generated command.</p>
   </div>
   <div class="router-readonly-badge">READ-ONLY</div>
  </div>

  <div class="panel-body">
   <div class="router-filter-box">
    <div class="router-filter-grid">
     <div class="form-group">
      <label for="router-device-filter">🖥️ Stored Router</label>
      <select id="router-device-filter" onchange="handleRouterDeviceChange()">
       <option value="">Select Stored Router</option>
      </select>
     </div>

     <div class="form-group">
      <label for="router-client-filter">👤 Client / Service</label>
      <select id="router-client-filter" onchange="handleRouterClientChange()" disabled>
       <option value="">All Clients / Services</option>
      </select>
     </div>

     <div class="form-group">
      <label for="router-inventory-vrf">🧩 Stored VRF</label>
      <select id="router-inventory-vrf" onchange="handleRouterInventoryVrfChange()" disabled>
       <option value="">All VRFs</option>
      </select>
     </div>

     <div class="form-group">
      <label for="router-interface-filter">🔌 Interface</label>
      <select id="router-interface-filter" onchange="handleRouterInterfaceChange()" disabled>
       <option value="">Select Interface</option>
      </select>
     </div>

     <div class="form-group">
      <label for="router-peer-filter">📡 Peer IP</label>
      <select id="router-peer-filter" onchange="handleRouterPeerChange()" disabled>
       <option value="">Select Peer IP</option>
      </select>
     </div>

     <div class="form-group">
      <label for="router-platform-filter">🧭 Router / Platform</label>
      <select id="router-platform-filter" onchange="handleRouterPlatformChange()">
       <option value="">Select Router / Platform</option>
      </select>
     </div>

     <div class="form-group">
      <label for="router-category-filter">🧰 Troubleshooting Task</label>
      <select id="router-category-filter" onchange="handleRouterCategoryChange()" disabled>
       <option value="">Select Task</option>
      </select>
     </div>

     <div class="form-group">
      <label for="router-command-select">⌨️ Command</label>
      <select id="router-command-select" onchange="handleRouterCommandSelection()" disabled>
       <option value="">All Relevant Commands</option>
      </select>
     </div>

     <div class="form-group">
      <label for="router-command-filter">🔎 Search</label>
      <input id="router-command-filter" type="search" placeholder="Search selected task..." oninput="queueRouterCommandSearch()" disabled>
     </div>

     <div class="form-group" data-router-var="vlan" hidden>
      <label for="router-vlan">🔢 VLAN ID</label>
      <input id="router-vlan" placeholder="e.g. 846" oninput="renderRouterCommands()">
     </div>

     <div class="form-group" data-router-var="trunk" hidden>
      <label for="router-trunk">🔗 Eth-Trunk</label>
      <input id="router-trunk" placeholder="e.g. 31" oninput="renderRouterCommands()">
     </div>

     <div class="form-group" data-router-var="ip" hidden>
      <label for="router-ip">🌐 Destination IP</label>
      <input id="router-ip" placeholder="e.g. 192.0.2.10" oninput="renderRouterCommands()">
     </div>

     <div class="form-group" data-router-var="vrf" hidden>
      <label for="router-vrf">🧩 VPN / VRF</label>
      <input id="router-vrf" placeholder="e.g. CUSTOMER_VRF" oninput="renderRouterCommands()">
     </div>

     <div class="form-group" data-router-var="peer_ip" hidden>
      <label for="router-peer-ip">📡 BGP Peer IP</label>
      <input id="router-peer-ip" placeholder="e.g. 192.0.2.2" oninput="renderRouterCommands()">
     </div>

     <div class="form-group" data-router-var="search" hidden>
      <label for="router-config-search">🔍 Config Search Text</label>
      <input id="router-config-search" placeholder="VLAN / IP / keyword" oninput="renderRouterCommands()">
     </div>

     <div class="form-group" data-router-var="policy" hidden>
      <label for="router-policy">🛡️ Route Policy</label>
      <input id="router-policy" placeholder="e.g. CUSTOMER_IMPORT" oninput="renderRouterCommands()">
     </div>

     <div class="form-group" data-router-var="prefix" hidden>
      <label for="router-prefix">📚 IP Prefix List</label>
      <input id="router-prefix" placeholder="e.g. CUSTOMER_PREFIX" oninput="renderRouterCommands()">
     </div>
    </div>

    <div class="router-filter-footer">
     <div id="router-selection-hint">You can select a stored router to auto-fill client/VRF/interface/IP data, or use the command selector manually.</div>
     <button type="button" class="btn-secondary" onclick="resetRouterCommandInputs(true)">Reset All</button>
    </div>
   </div>

   <div id="router-flow-note" class="router-flow-note" hidden>
    <strong>Recommended troubleshooting flow:</strong> Interface → ARP → VPN/VRF → Ping → Routing → BGP / Policy
   </div>

   <div class="router-results-head">
    <div>
     <h3>Command Output</h3>
     <span id="router-command-count" class="router-command-count"></span>
    </div>
   </div>

   <div class="table-responsive router-table-wrap">
    <table class="data-table router-command-table">
     <thead>
      <tr>
       <th>Command Name</th>
       <th>Purpose</th>
       <th>Generated Command</th>
       <th>Status</th>
       <th>Action</th>
      </tr>
     </thead>
     <tbody id="router-command-tbody">
      <tr><td colspan="5" class="router-table-empty">Select a router/platform and task to begin.</td></tr>
     </tbody>
    </table>
   </div>
  </div>
 </div>
</div>
<?php endif; ?>
