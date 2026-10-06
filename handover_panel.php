<div id="tab-handover" class="tab-content">
 <div class="panel-card">
  <div class="panel-header handover-header"><div><h2>Handover Monitoring</h2><p>CNOC complaint handler · Pending items turn red after <?= (int)$handoverConfig['overdueMinutes'] ?> minutes.</p><small>Records are saved in this browser. Copy your handover before changing computers.</small></div><div class="handover-summary"><strong id="handover-pending-count">0</strong><span>Pending</span><strong id="handover-overdue-count">0</strong><span>Overdue</span></div></div>
  <div class="panel-body">
   <form id="handover-form" onsubmit="addHandover(event)">
    <div class="form-group"><label for="handover-nms">NMS label</label><input id="handover-nms" placeholder="NA when unavailable"></div>
    <div class="form-group"><label for="handover-subject">Customer / primary email subject *</label><input id="handover-subject" required maxlength="2000"></div>
    <div id="handover-extra-subjects"></div>
    <button type="button" class="btn-secondary" onclick="addHandoverSubject()">+ Add another subject (vendor / team / other)</button>
    <div class="form-row">
     <div class="form-group col-half"><label for="handover-followup">Follow-up</label><select id="handover-followup" onchange="changeHandoverFollowup()"><option value="">Manual comment only</option><option value="vendor">Follow up with vendor</option><option value="customer">Follow up with customer</option><option value="team">Follow up with team</option></select></div>
     <div class="form-group col-half"><label for="handover-party">Vendor / team</label><select id="handover-party" disabled></select></div>
    </div>
    <div class="form-row">
     <div class="form-group col-half"><label for="handover-status">Complaint status</label><select id="handover-status"><option value="OPEN">Open / pending</option><option value="RESOLVED">Resolved</option></select><small>Keep Open when the link is restored but RCA or testing is awaited.</small></div>
     <div class="form-group col-half"><label for="handover-region">Region</label><select id="handover-region"></select></div>
    </div>
    <div class="form-group"><label for="handover-quick">Quick comment</label><select id="handover-quick" onchange="appendHandoverComment(this)"></select></div>
    <div class="form-group"><label for="handover-comment">Current status / manual comment</label><textarea id="handover-comment" rows="3" placeholder="CE issue, MV details, restored | RCA awaited, testing details..."></textarea></div>
    <div class="form-row"><div class="form-group col-half"><label for="handover-ticket">Ticket / reference</label><input id="handover-ticket" placeholder="NA"></div><div class="form-group col-half"><label for="handover-owner">CNOC engineer / shift (optional)</label><input id="handover-owner" placeholder="NA"></div></div>
    <button id="handover-save" class="btn-primary" type="submit">Add to Handover</button>
    <button type="button" class="btn-secondary" onclick="resetHandoverForm()">Cancel / clear form</button>
   </form>
   <div class="actions"><button class="btn-secondary" onclick="copyHandover()">Copy handover</button><?php if (($_SESSION['noc_role'] ?? '') === 'admin' || !empty($_SESSION['admin_logged_in'])): ?><a class="btn-secondary" href="admin.php#tab-admin-handover">Manage Handover Settings</a><?php endif; ?></div>
   <textarea id="handover-export" rows="8" readonly hidden aria-label="Handover text"></textarea>
   <div class="table-responsive"><table class="data-table handover-table"><thead><tr><th>NMS label / email subjects</th><th>Region</th><th>Current status</th><th>CNOC engineer</th><th>Started</th><th>Timer</th><th>Status</th><th>Action</th></tr></thead><tbody id="handover-body"></tbody></table></div>
  </div>
 </div>
</div>
