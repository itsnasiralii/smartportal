'use strict';
const $ = id => document.getElementById(id);
const val = id => $(id)?.value.trim() || '';
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let complaintRows = [];
let pending = 0;
function notice(message, error = false) {
    $('console-notice').textContent = message;
    $('console-notice').className = 'alert-box ' + (error ? 'error' : 'success');
    $('console-notice').hidden = false;
}
async function api(action, data = null) {
    let response;
    if (data !== null) {
        const form = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) Object.entries(data).forEach(([k, v]) => form.append(k, v));
        form.set('action', action);
        response = await fetch('api.php', {method: 'POST', body: form, headers: {'X-NOC-CSRF': window.nocCsrf || ''}});
    } else response = await fetch('api.php?' + new URLSearchParams({action}));
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'The request failed. Please try again.');
    return result;
}
async function run(work) {
    if (pending) return;
    pending++;
    document.querySelectorAll('button').forEach(b => { b.dataset.wasDisabled = b.disabled; b.disabled = true; });
    try { return await work(); } catch (error) { notice(error.message || 'Unable to reach the server.', true); }
    finally { pending--; document.querySelectorAll('button').forEach(b => { b.disabled = b.dataset.wasDisabled === 'true'; }); }
}
function switchTab(tabId) {
    document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === tabId));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.getAttribute('onclick')?.includes("'" + tabId + "'")));
    window.scrollTo({top:0, behavior:'instant'});
    $('console-notice').hidden = true;
    if (tabId === 'tab-outage') run(loadOutageHistory);
    if (tabId === 'tab-dashboard' || tabId === 'tab-complaints') run(loadDashboardTable);
    if (tabId === 'tab-roster') calculateRoster();
    if (tabId === 'tab-vpbx') run(loadVpbxData);
    if (tabId === 'tab-nms') searchNmsClients(1);
    if (tabId === 'tab-router') loadRouterCommandMeta();
}
function options(id, values) {
    const old = val(id);
    $(id).replaceChildren(...values.map(value => new Option(value, value)));
    if (values.includes(old)) $(id).value = old;
}
function field(id, label, type = 'text', values = null) {
    const box = document.createElement('div'); box.className = 'form-group';
    const caption = document.createElement('label'); caption.htmlFor = id; caption.textContent = label;
    const control = document.createElement(values ? 'select' : type === 'textarea' ? 'textarea' : 'input');
    control.id = id;
    if (values) values.forEach(value => control.add(new Option(value, value)));
    else if (type === 'textarea') control.rows = 3;
    else control.type = type;
    box.append(caption, control); return box;
}
function beforeGenerate(tab, ...fields) {
    const button = document.querySelector(`#${tab} .panel-body > button.btn-primary`);
    fields.forEach(f => button.before(f));
}
function customField(selectId, inputId, label) {
    const box = field(inputId, label);
    $(selectId).closest('.form-group').after(box);
    const sync = () => box.hidden = !['Other', 'Other / Manual', 'Custom'].includes(val(selectId));
    $(selectId).addEventListener('change', sync); sync();
}
function timeFields(prefix, tab) {
    const row = document.createElement('div'); row.className = 'form-row';
    row.append(field(prefix + '-time-mode', 'Incident time (Pakistan / UTC+05:00)', 'text', ['Automatic', 'Manual']), field(prefix + '-manual-time', 'Manual date and time (PKT)', 'datetime-local'));
    const button = document.createElement('button'); button.type = 'button'; button.className = 'btn-secondary'; button.textContent = 'Use current Pakistan time';
    button.onclick = () => {
        $(prefix + '-time-mode').value = 'Manual';
        const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {timeZone:'Asia/Karachi', year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', hourCycle:'h23'}).formatToParts(new Date()).map(p => [p.type,p.value]));
        $(prefix + '-manual-time').value = `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
        sync();
    };
    const sync = () => $(prefix + '-manual-time').disabled = val(prefix + '-time-mode') !== 'Manual';
    beforeGenerate(tab, row, button);
    $(prefix + '-time-mode').addEventListener('change', sync); sync();
}
function timeData(prefix) { return {time_mode: val(prefix+'-time-mode'), manual_time:val(prefix+'-manual-time')}; }
function selectedComplaint(id) { return complaintRows.find(r => String(r.id) === val(id)); }
function loadComplaint(record) {
    if (!record) return;
    $('esc-vendor-select').value = ''; $('esc-res-body').value = '';
    ['current-label','cust-label','stats-label','prog-label'].forEach(id => $(id).value = record.service_label);
    $('current-ticket').value = record.ticket || '';
    $('open-ticket').value = record.ticket || '';
    if (record.status !== 'CLOSED') $('open-complaint-select').value = record.id;
    $('closure-complaint-select').value = record.status === 'OPEN' ? record.id : '';
    if (NOC_OPTIONS.OPENING_ISSUES.includes(record.issue_type)) $('open-issue').value = record.issue_type;
    else { $('open-issue').value = 'Other / Manual'; $('open-custom-issue').value = record.issue_type || ''; }
    $('open-issue').dispatchEvent(new Event('change'));
    $('current-details').textContent = `${record.service_label}\nStatus: ${record.status} | Ticket: ${record.ticket || 'NA'}\nAdded: ${record.added_time || 'NA'} PKT\nOpened: ${record.reported_time || 'Not opened'}\nClosed: ${record.restoration_time || 'NA'}\nLast stage: ${record.last_stage || 'NA'}`;
}
function handleSelectOpenComplaint() { loadComplaint(selectedComplaint('open-complaint-select')); }
async function refreshSelectors() {
    const data = await api('list_complaints'); complaintRows = data.data;
    for (const [id, predicate] of [['current-complaint', () => true], ['open-complaint-select', r => r.status !== 'CLOSED'], ['closure-complaint-select', r => r.status === 'OPEN']]) {
        const selected = val(id); $(id).replaceChildren(new Option('-- Choose Complaint --', ''));
        complaintRows.filter(predicate).forEach(r => $(id).add(new Option(`${r.service_label} [${r.status}]`, r.id)));
        $(id).value = selected;
    }
}
async function handleAddComplaints(e) {
    e.preventDefault();
    await run(async () => {
        const result = await api('add_complaints', {raw_labels:val('bulk-labels'), default_issue:val('default-issue'), ticket:val('bulk-ticket')});
        notice(result.message); $('bulk-labels').value = '';
        await refreshSelectors(); await loadDashboardTable();
    });
}
async function loadDashboardTable() {
    const response = await fetch('api.php?' + new URLSearchParams({action:'get_dashboard', filter:'All Runtime Complaints'}));
    const result = await response.json(); if (!result.success) throw new Error(result.message || 'Unable to load dashboard.');
    const rows = result.data;
    const queue = rows.filter(r => r.status !== 'CLOSED');
    const filter = val('dashboard-filter');
    const visible = rows.filter(r => filter === 'All Runtime Complaints' || (filter === 'Queued + Open' ? r.status !== 'CLOSED' : r.status === {'Open Only':'OPEN','Queued Only':'QUEUED','Closed Only':'CLOSED'}[filter]));
    const service = r => `<strong>${r.service_id}</strong><br><small>${r.label}</small>`;
    const status = r => `<span class="badge-status badge-${r.status.toLowerCase()}">${escapeHtml(r.status)}</span>`;
    const render = values => '<tr>' + values.map(v => '<td>'+v+'</td>').join('') + '</tr>';
    const qTbody = document.querySelector('#complaints-queue-table tbody');
    if (qTbody) qTbody.innerHTML = queue.map(r => render([service(r),r.service_type,r.issue_type,r.added_time,r.reported_time,r.age_badge,r.ticket,status(r)])).join('') || '<tr><td colspan="8">No active complaints. Add service labels above to begin.</td></tr>';
    const dTbody = document.querySelector('#main-dashboard-table tbody');
    if (dTbody) dTbody.innerHTML = visible.map(r => render([service(r),r.service_type,r.issue_type,r.added_time,r.reported_time,r.age_badge,r.restoration_time,r.duration,r.ticket,r.last_stage,status(r)])).join('') || '<tr><td colspan="11">No complaints match this view.</td></tr>';
}
function results(prefix, data) {
    $(prefix+'-results').style.display = 'block';
    $(prefix+'-res-subject').value = data.subject; $(prefix+'-res-body').value = data.body;
    if ($(prefix+'-policy')) { $(prefix+'-policy').textContent = data.policy || ''; $(prefix+'-policy').hidden = !data.policy; }
    if (data.runtime) notice(data.runtime);
}
async function generateOpeningEmail() {
    await run(async () => {
        const data = await api('generate_opening', {complaint_id:val('open-complaint-select'),label:val('current-label'),issue:val('open-issue'),custom_issue:val('open-custom-issue'),ticket:val('open-ticket') || val('current-ticket'),priority:val('open-priority'),...timeData('open')});
        results('opening',data); await refreshSelectors(); await loadDashboardTable();
    });
}
async function generateClosureEmail() {
    await run(async () => {
        const data = await api('generate_closure', {complaint_id:val('closure-complaint-select'),found_at:val('closure-found-at'),custom_found_at:val('closure-custom-found'),root_cause:val('closure-root-cause'),custom_root_cause:val('closure-custom-root'),corrective_action:val('closure-action'),custom_action:val('closure-custom-action'),final_status:val('closure-final'),priority:val('closure-priority'),...timeData('closure')});
        results('closure',data); $('closure-runtime-notice').textContent = data.runtime; await refreshSelectors(); await loadDashboardTable();
    });
}
async function generateCustomerEmail() {
    await run(async () => results('cust', await api('generate_customer', {label:val('cust-label'),ticket:val('current-ticket'),issue_summary:val('cust-summary'),priority:val('cust-priority'),requested_action:val('cust-action'),findings:JSON.stringify([...document.querySelectorAll('[name="cust-finding"]:checked')].map(c => c.value))})));
}
async function generateStatsEmail() {
    await run(async () => results('stats', await api('generate_stats', {label:val('stats-label'),scenario:val('stats-scenario'),audience:val('stats-audience'),context:val('stats-context')})));
}
async function generateProgressEmail() {
    await run(async () => { results('prog', await api('generate_progress', {label:val('prog-label'),status:val('prog-status'),ettr:val('prog-ettr'),custom_ettr:val('prog-custom-ettr'),note:val('prog-note'),priority:val('prog-priority'),audience:val('prog-audience')})); await loadDashboardTable(); });
}
async function getMatrix(id) {
    const response = await fetch('api.php?' + new URLSearchParams({action:'get_vendor_matrix',vendor_id:id}));
    const result = await response.json(); if (!result.success) throw new Error('Unable to load vendor contacts.'); return result;
}
function contactTable(contacts) {
    const headers = ['Select', 'Level', 'Name', 'Designation', 'Escalation Time', 'Phone', 'Email'];
    const rows = contacts.map((c, i) => {
        const email = c.email ? c.email.trim() : '';
        const chk = email ? `<input type="checkbox" class="matrix-email-chk" value="${escapeHtml(email)}" onchange="updateSelectedEmails()" style="cursor:pointer; width:16px; height:16px;">` : '—';
        return `<tr>
            <td style="text-align:center;">${chk}</td>
            <td><strong>${escapeHtml(c.level)}</strong></td>
            <td>${escapeHtml(c.name)}</td>
            <td>${escapeHtml(c.designation || '—')}</td>
            <td>${escapeHtml(c.escalation_time || '—')}</td>
            <td>${escapeHtml(c.phone || '—')}</td>
            <td>${email ? `<a href="mailto:${escapeHtml(email)}">${escapeHtml(email)}</a>` : '—'}</td>
        </tr>`;
    }).join('');

    return `<table class="data-table">
        <thead>
            <tr>${headers.map(k => `<th style="${k==='Select'?'text-align:center; width:60px;':''}">${k}</th>`).join('')}</tr>
        </thead>
        <tbody>${rows || '<tr><td colspan="7">No contacts configured for this vendor.</td></tr>'}</tbody>
    </table>`;
}

function updateSelectedEmails() {
    const checked = [...document.querySelectorAll('.matrix-email-chk:checked')].map(el => el.value);
    const unique = [...new Set(checked)];
    const target = $('vendor-selected-emails');
    if (target) {
        target.value = unique.join('; ');
    }
}

function toggleAllCheckboxes(check) {
    document.querySelectorAll('.matrix-email-chk').forEach(el => el.checked = check);
    updateSelectedEmails();
}

async function handleVendorSelect(sel) {
    const sec = $('vendor-matrix-readonly-section');
    const emailSec = $('vendor-emails-copy-section');
    if (!sel.value) { 
        $('esc-res-body').value = ''; 
        if (sec) sec.style.display = 'none';
        if (emailSec) emailSec.style.display = 'none';
        return; 
    }
    await run(async () => {
        const target = sel.selectedOptions[0].textContent.trim();
        const data = await api('generate_escalation', {
            label:val('current-label') || '[Service details]',
            ticket:val('current-ticket'), target,
            level:'Initial engagement', format:'A', priority:'Normal'
        });
        $('esc-res-body').value = data.body;

        // Fetch and display the read-only escalation matrix table
        const matrixData = await getMatrix(sel.value);
        
        // Populate Outlook Emails Copy Bar for THIS vendor only
        if (emailSec) {
            emailSec.style.display = 'block';
            $('vendor-all-emails').value = matrixData.all_emails || 'No emails found';
            $('vendor-selected-emails').value = '';
        }

        if (sec) {
            sec.style.display = 'block';
            $('readonly-matrix-title').textContent = '🏢 ' + target + ' Escalation Matrix (' + matrixData.contacts.length + ' Contacts)';
            $('readonly-matrix-container').innerHTML = contactTable(matrixData.contacts);
        }
    });
}
async function loadMatrix(id) {
    if (!id) { $('matrix-view').replaceChildren(); $('matrix-emails').value = ''; return; }
    await run(async () => { const data = await getMatrix(id); $('matrix-view').innerHTML = contactTable(data.contacts); $('matrix-emails').value = data.all_emails; });
}
async function analyzeOutage(e) {
    e.preventDefault(); await run(async () => {
        notice('Processing outage report…'); const form = new FormData(); form.set('file',$('outage-file').files[0]);
        const data = await api('analyze_outage',form); $('outage-results').hidden=false; $('outage-output').value=data.output;
        ['full','simple'].forEach(kind => $('outage-'+kind).href='api.php?'+new URLSearchParams({action:'download_report',token:data.token,kind}));
        await loadOutageHistory(); notice('Outage analyzed. Both Excel reports are ready.');
    });
}
async function loadOutageHistory() {
    const data = await api('outage_history'); $('outage-handover').value=data.handover;
    $('outage-history').innerHTML=data.data.map(r=>'<tr>'+['id','processed_time','file_name','total_links','priority_count','summary','occurred_time'].map(k=>'<td>'+escapeHtml(r[k])+'</td>').join('')+'</tr>').join('') || '<tr><td colspan="7">No outages in this shift.</td></tr>';
}
async function resetOutageHistory() { if (confirm('Clear all outage entries from the shift history?')) await run(async()=>{ await api('reset_outage_history',{}); await loadOutageHistory(); notice('Shift history cleared.'); }); }
async function removeComplaint() {
    const record = selectedComplaint('current-complaint');
    if (!record) { notice('Select a complaint to remove.',true); return; }
    if (confirm('Remove '+record.service_label+' from the console?')) await run(async()=>{ await api('remove_complaint',{complaint_id:record.id}); await refreshSelectors(); await loadDashboardTable(); $('current-details').textContent=''; notice('Complaint removed.'); });
}
async function copyText(id) {
    try { await navigator.clipboard.writeText($(id).value); notice('Copied to clipboard.'); }
    catch { $(id).select(); notice('Clipboard unavailable. The text is selected; press Ctrl+C.',true); }
}
document.addEventListener('DOMContentLoaded', () => {
    options('default-issue',NOC_OPTIONS.COMPLAINT_DEFAULT_ISSUES);
    options('open-issue',NOC_OPTIONS.OPENING_ISSUES);
    options('closure-found-at',NOC_OPTIONS.ISSUE_FOUND_AT);
    options('closure-root-cause',NOC_OPTIONS.RCA_OPTIONS);
    options('closure-action',['Auto from Root Cause',...new Set(Object.values(NOC_OPTIONS.AUTO_ACTIONS)),'Other / Manual']);
    options('cust-action',NOC_OPTIONS.CUSTOMER_ACTIONS);
    options('stats-scenario',NOC_OPTIONS.STATS_SCENARIOS);
    options('prog-status',NOC_OPTIONS.PROGRESS_OPTIONS);
    options('prog-ettr',['Awaited','30 Minutes','1 Hour','2 Hours','4 Hours','Not Available','Not Applicable','Custom']);
    ['open','cust','prog'].forEach(p=>options(p+'-priority',['Normal','Follow-up','Urgent','Critical']));
    $('dashboard-filter').add(new Option('All Runtime Complaints','All Runtime Complaints'));
    document.querySelector('.checkbox-grid').innerHTML=NOC_OPTIONS.FINDING_OPTIONS.map((f,i)=>`<label for="finding-${i}"><input id="finding-${i}" type="checkbox" name="cust-finding" value="${escapeHtml(f)}">${escapeHtml(f)}</label>`).join('');
    beforeGenerate('tab-customer',field('cust-summary','Issue summary (optional)'));
    beforeGenerate('tab-stats',field('stats-audience','Audience','text',['Customer','Team / Vendor']),field('stats-context','Additional context','textarea'));
    beforeGenerate('tab-progress',field('prog-audience','Audience','text',['Customer','Team / Vendor']),field('prog-note','Additional ground update','textarea'));
    customField('prog-ettr','prog-custom-ettr','Custom ETTR');
    beforeGenerate('tab-closure',field('closure-final','Final service status'),field('closure-priority','Priority','text',['Normal','Follow-up','Urgent','Critical']));
    $('closure-final').value='Service is up and working normally.';
    customField('open-issue','open-custom-issue','Custom issue');
    customField('closure-found-at','closure-custom-found','Custom issue location');
    customField('closure-root-cause','closure-custom-root','Custom root cause');
    customField('closure-action','closure-custom-action','Custom corrective action');
    timeFields('open','tab-opening'); timeFields('closure','tab-closure');
    ['cust','stats'].forEach(prefix=>{const policy=document.createElement('div');policy.id=prefix+'-policy';policy.className='alert-box policy';policy.hidden=true;$(prefix+'-results').append(policy);});
    document.querySelectorAll('[id$="-res-subject"], [id$="-res-body"]').forEach(el=>el.readOnly=false);
    $('current-complaint').addEventListener('change',()=>loadComplaint(selectedComplaint('current-complaint')));
    $('closure-complaint-select').addEventListener('change',()=>loadComplaint(selectedComplaint('closure-complaint-select')));
    $('current-label').addEventListener('input',()=>{['cust','stats','prog'].forEach(p=>$(p+'-label').value=val('current-label'));$('open-complaint-select').value='';$('current-complaint').value='';$('esc-vendor-select').value='';$('esc-res-body').value='';});
    $('current-ticket').addEventListener('input',()=>$('open-ticket').value=val('current-ticket'));
    const header=document.querySelector('#main-dashboard-table thead tr'); const th=document.createElement('th');th.textContent='Last Stage';header.insertBefore(th,header.lastElementChild);
    run(async()=>{await refreshSelectors();await loadDashboardTable();});
    setInterval(()=>{if(!pending && !document.hidden) run(loadDashboardTable);},60000);
});

// ============================================================
// DUTY ROSTER & WORKLOAD ANALYTICS MODULE
// ============================================================
let rosterDataCache = null;

function applyRosterPreset(daysOn, daysOff) {
    if ($('roster-days-on')) $('roster-days-on').value = daysOn;
    if ($('roster-days-off')) $('roster-days-off').value = daysOff;
    calculateRoster();
}

function applyShiftPreset(startTime, hours) {
    if ($('roster-shift-start')) $('roster-shift-start').value = startTime;
    if ($('roster-shift-hours')) $('roster-shift-hours').value = hours;
    calculateRoster();
}

function syncRosterCheckTime() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    if ($('roster-check-date')) $('roster-check-date').value = `${y}-${m}-${d}`;
    if ($('roster-check-time')) $('roster-check-time').value = `${hh}:${mm}`;
    calculateRoster();
}

function switchRosterSubtab(subtab) {
    const tabs = ['daily', 'weekly', 'monthly', 'metrics'];
    tabs.forEach(t => {
        const pane = $('roster-view-' + t);
        if (pane) pane.style.display = (t === subtab) ? 'block' : 'none';
    });
    document.querySelectorAll('.roster-tab-pill').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('onclick')?.includes("'" + subtab + "'"));
    });
}

async function calculateRoster() {
    if (!$('roster-days-on')) return;
    const weekends = Array.from(document.querySelectorAll('input[name="roster-weekend"]:checked')).map(cb => cb.value);
    const params = {
        days_on: val('roster-days-on') || 4,
        days_off: val('roster-days-off') || 4,
        anchor: val('roster-anchor'),
        range_start: val('roster-start'),
        range_end: val('roster-end'),
        shift_start: val('roster-shift-start') || '07:00',
        shift_hours: val('roster-shift-hours') || 12,
        check_date: val('roster-check-date'),
        check_time: val('roster-check-time'),
        weekends: JSON.stringify(weekends)
    };

    try {
        const res = await api('calculate_roster', params);
        if (!res.success) {
            notice(res.message || 'Error calculating roster', true);
            return;
        }
        rosterDataCache = res;

        // Render KPIs
        const k = res.kpis;
        $('kpi-duty-days').textContent = k.duty_days + ' days';
        $('kpi-duty-pct').textContent = k.duty_pct + '% of period';
        $('kpi-off-days').textContent = k.off_days + ' days';
        $('kpi-off-pct').textContent = k.off_pct + '% of period';
        $('kpi-weekend-duties').textContent = k.weekend_duties + ' shifts';
        $('kpi-total-hours').textContent = k.total_hours + ' hrs';
        $('kpi-avg-weekly').textContent = '~' + k.avg_weekly_hours + ' hrs/wk';
        $('kpi-remaining-duty').textContent = k.remaining_duty + ' shifts';
        $('kpi-completed-duty').textContent = k.completed_duty + ' completed';

        // Render Monitor Banner
        const m = res.monitor;
        const banner = $('roster-status-banner');
        if (banner) {
            banner.className = 'roster-status-card' + (m.is_active ? '' : ' status-off');
            let badgeHtml = '';
            let msgHtml = '';
            if (m.is_active) {
                const remHours = Math.floor(m.remaining_minutes / 60);
                const remMins = m.remaining_minutes % 60;
                badgeHtml = `<span class="roster-status-badge duty"><span class="pulse-dot"></span> ACTIVE SHIFT IN PROGRESS</span>`;
                msgHtml = `Shift: <strong>${escapeHtml(m.active_text)}</strong> • <strong>${remHours}h ${remMins}m remaining</strong>`;
            } else if (m.is_upcoming) {
                const untilHours = Math.floor(m.until_minutes / 60);
                const untilMins = m.until_minutes % 60;
                badgeHtml = `<span class="roster-status-badge duty">🟡 UPCOMING SHIFT TODAY</span>`;
                msgHtml = `Shift begins at <strong>${escapeHtml(m.upcoming_text)}</strong> (in <strong>${untilHours}h ${untilMins}m</strong>)`;
            } else {
                badgeHtml = `<span class="roster-status-badge off">⚪ OFF DUTY</span>`;
                msgHtml = `Currently in off-duty period.`;
            }

            banner.innerHTML = `
                <div class="roster-status-header">
                    ${badgeHtml}
                    <span class="roster-status-time">🕒 Evaluated: ${escapeHtml(m.timestamp_str)}</span>
                </div>
                <div class="roster-status-message">${msgHtml}</div>
                <div class="roster-status-details">
                    <span><strong>Rotation State:</strong> Day ${m.block_pos} of ${m.block_tot} (${escapeHtml(m.block_type)} Block)</span>
                    <span><strong>Cycle Position:</strong> Day ${m.cycle_pos} of ${m.cycle_len} (${m.cycle_pct}% elapsed)</span>
                    <span><strong>Next Duty Block:</strong> ${escapeHtml(m.next_duty_str)}</span>
                    <span><strong>Next Off Block:</strong> ${escapeHtml(m.next_off_str)}</span>
                </div>
                <div class="roster-cycle-bar-track">
                    <div class="roster-cycle-bar-fill" style="width: ${Math.min(100, Math.max(0, m.cycle_pct))}%;"></div>
                </div>
            `;
        }

        // Render Daily Table
        const dailyBody = $('roster-daily-tbody');
        if (dailyBody) {
            dailyBody.innerHTML = res.daily.map(r => `
                <tr style="${r.is_duty ? 'background:#f0fdf4;' : ''}">
                    <td style="font-weight:600;">${escapeHtml(r.date_str)}</td>
                    <td>${escapeHtml(r.day)}</td>
                    <td>${r.is_duty ? '<span class="badge-duty">🟢 ON DUTY</span>' : '<span class="badge-off">⚪ OFF</span>'}</td>
                    <td>${r.is_duty ? escapeHtml(r.shift_start) + ' → ' + escapeHtml(r.shift_end) : '<span style="color:#94a3b8;">-</span>'}</td>
                    <td style="font-weight:${r.hours ? '600' : 'normal'};">${r.hours ? r.hours + ' hrs' : '0'}</td>
                    <td>${r.is_weekend ? (r.is_duty ? '<span class="badge-weekend-duty">Weekend Duty</span>' : '<span style="color:#94a3b8;">Weekend</span>') : '<span style="color:#64748b;">Weekday</span>'}</td>
                </tr>
            `).join('');
        }

        // Render Weekly Table
        renderWeeklyTable(res.daily);

        // Render Monthly Table
        renderMonthlyTable(res.daily);

        // Render Executive Metrics
        const metricsContainer = $('roster-metrics-content');
        if (metricsContainer) {
            metricsContainer.innerHTML = renderExecutiveMetrics(res);
        }

    } catch (err) {
        notice('Failed to calculate roster: ' + err.message, true);
    }
}

function renderWeeklyTable(daily) {
    const weeklyBody = $('roster-weekly-tbody');
    if (!weeklyBody) return;
    
    const weeks = [];
    let currentWeek = null;
    let weekIndex = 1;

    daily.forEach((r) => {
        if (!currentWeek || r.day === 'Monday') {
            if (currentWeek) weeks.push(currentWeek);
            currentWeek = {
                num: weekIndex++,
                startDate: r.date_str,
                endDate: r.date_str,
                dutyDays: 0,
                offDays: 0,
                hours: 0,
                weekendShifts: 0
            };
        }
        currentWeek.endDate = r.date_str;
        if (r.is_duty) {
            currentWeek.dutyDays++;
            currentWeek.hours += r.hours;
            if (r.is_weekend) currentWeek.weekendShifts++;
        } else {
            currentWeek.offDays++;
        }
    });
    if (currentWeek) weeks.push(currentWeek);

    weeklyBody.innerHTML = weeks.map(w => `
        <tr>
            <td style="font-weight:700;">Week ${w.num}</td>
            <td>${escapeHtml(w.startDate)} — ${escapeHtml(w.endDate)}</td>
            <td><strong style="color:#059669;">${w.dutyDays}</strong> days</td>
            <td>${w.offDays} days</td>
            <td style="font-weight:700;">${w.hours} hrs</td>
            <td>${w.weekendShifts > 0 ? '<span class="badge-weekend-duty">' + w.weekendShifts + ' shifts</span>' : '0'}</td>
        </tr>
    `).join('');
}

function renderMonthlyTable(daily) {
    const monthlyBody = $('roster-monthly-tbody');
    if (!monthlyBody) return;

    const months = {};
    daily.forEach(r => {
        const ym = r.raw_date.slice(0, 7);
        const [year, month] = ym.split('-');
        const monthName = new Date(parseInt(year), parseInt(month) - 1, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
        
        if (!months[ym]) {
            months[ym] = {
                name: monthName,
                totalDays: 0,
                dutyDays: 0,
                offDays: 0,
                hours: 0,
                weekendDuties: 0
            };
        }
        months[ym].totalDays++;
        if (r.is_duty) {
            months[ym].dutyDays++;
            months[ym].hours += r.hours;
            if (r.is_weekend) months[ym].weekendDuties++;
        } else {
            months[ym].offDays++;
        }
    });

    monthlyBody.innerHTML = Object.values(months).map(m => {
        const share = m.totalDays > 0 ? Math.round((m.dutyDays / m.totalDays) * 100) : 0;
        return `
            <tr>
                <td style="font-weight:700;">${escapeHtml(m.name)}</td>
                <td>${m.totalDays} days</td>
                <td><strong style="color:#059669;">${m.dutyDays}</strong> days</td>
                <td>${m.offDays} days</td>
                <td style="font-weight:700;">${m.hours} hrs</td>
                <td>${m.weekendDuties > 0 ? '<span class="badge-weekend-duty">' + m.weekendDuties + ' shifts</span>' : '0'}</td>
                <td><strong>${share}%</strong></td>
            </tr>
        `;
    }).join('');
}

function renderExecutiveMetrics(res) {
    const k = res.kpis;
    const completedPct = (k.duty_days > 0) ? Math.round((k.completed_duty / k.duty_days) * 100) : 0;
    
    return `
        <div class="metrics-summary-grid">
            <div class="metric-box">
                <div class="metric-box-title">🔄 Rotation Duty / Off Ratio</div>
                <div class="metric-box-val" style="color:#059669;">${k.duty_pct}% ON / ${k.off_pct}% OFF</div>
                <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">${k.duty_days} Duty Days vs ${k.off_days} Off Days</div>
            </div>
            <div class="metric-box">
                <div class="metric-box-title">⏱️ Average Weekly Workload</div>
                <div class="metric-box-val">${k.avg_weekly_hours} hrs/week</div>
                <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">Based on ${k.total_hours} total scheduled hours</div>
            </div>
            <div class="metric-box">
                <div class="metric-box-title">🗓️ Weekend Duty Exposure</div>
                <div class="metric-box-val" style="color:#d97706;">${k.weekend_duties} shifts</div>
                <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">${k.duty_days > 0 ? Math.round((k.weekend_duties / k.duty_days)*100) : 0}% of duty shifts fall on weekends</div>
            </div>
            <div class="metric-box">
                <div class="metric-box-title">⏳ Schedule Progress</div>
                <div class="metric-box-val">${k.remaining_duty} shifts remaining</div>
                <div style="font-size:0.8rem; color:#64748b; margin-top:4px;">${k.completed_duty} completed (${completedPct}% done)</div>
            </div>
        </div>
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:18px;">
            <div style="display:flex; justify-content:space-between; font-size:0.85rem; font-weight:600; margin-bottom:6px;">
                <span>Schedule Completion: ${completedPct}%</span>
                <span>${k.completed_duty} completed / ${k.duty_days} total shifts</span>
            </div>
            <div class="roster-cycle-bar-track" style="height:10px;">
                <div class="roster-cycle-bar-fill" style="width:${completedPct}%;"></div>
            </div>
        </div>
    `;
}

function exportRosterCsv() {
    if (!rosterDataCache || !rosterDataCache.daily) {
        notice('Please calculate the roster first before exporting.', true);
        return;
    }
    const headers = ['Date', 'Day', 'Day Type', 'Status', 'Shift Start', 'Shift End', 'Hours', 'Is Weekend'];
    const lines = [headers.join(',')];
    rosterDataCache.daily.forEach(r => {
        const dayType = r.is_weekend ? 'Weekend' : 'Weekday';
        const status = r.is_duty ? 'Duty' : 'Off';
        const shiftStart = r.is_duty ? r.shift_start : '-';
        const shiftEnd = r.is_duty ? r.shift_end : '-';
        const hours = r.is_duty ? r.hours : 0;
        const isWeekend = r.is_weekend ? 'YES' : 'NO';
        lines.push([
            `"${r.date_str}"`,
            `"${r.day}"`,
            `"${dayType}"`,
            `"${status}"`,
            `"${shiftStart}"`,
            `"${shiftEnd}"`,
            hours,
            `"${isWeekend}"`
        ].join(','));
    });
    // Add UTF-8 BOM (\uFEFF) to ensure Microsoft Excel detects UTF-8 correctly without garbage characters like â€“
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    const startStr = val('roster-start') || 'export';
    link.setAttribute('download', `cnoc_duty_roster_${startStr}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    notice('Duty roster CSV downloaded cleanly for Excel.');
}



// ============================================================
// REGULATORY & VPBX TESTING PORTAL MODULE
// ============================================================
let vpbxDataCache = { outgoing: [], incoming: [] };

function switchVpbxSection(section) {
    const isOut = section === 'outgoing';
    const outSec = $('vpbx-section-outgoing');
    const incSec = $('vpbx-section-incoming');
    const outBtn = $('vpbx-btn-sec-outgoing');
    const incBtn = $('vpbx-btn-sec-incoming');

    if (outSec) outSec.style.display = isOut ? 'block' : 'none';
    if (incSec) incSec.style.display = !isOut ? 'block' : 'none';

    if (outBtn) {
        outBtn.style.background = isOut ? '#3b82f6' : '#e2e8f0';
        outBtn.style.color = isOut ? '#ffffff' : '#334155';
    }
    if (incBtn) {
        incBtn.style.background = !isOut ? '#3b82f6' : '#e2e8f0';
        incBtn.style.color = !isOut ? '#ffffff' : '#334155';
    }
}

function switchVpbxOutSubtab(tab) {
    const isAdd = tab === 'add';
    const paneAdd = $('vpbx-out-pane-add');
    const paneEdit = $('vpbx-out-pane-edit');
    const tabAdd = $('vpbx-out-tab-add');
    const tabEdit = $('vpbx-out-tab-edit');

    if (paneAdd) paneAdd.style.display = isAdd ? 'block' : 'none';
    if (paneEdit) paneEdit.style.display = !isAdd ? 'block' : 'none';

    if (tabAdd) {
        tabAdd.style.borderBottom = isAdd ? '2px solid #2563eb' : 'none';
        tabAdd.style.color = isAdd ? '#2563eb' : '#64748b';
    }
    if (tabEdit) {
        tabEdit.style.borderBottom = !isAdd ? '2px solid #2563eb' : 'none';
        tabEdit.style.color = !isAdd ? '#2563eb' : '#64748b';
    }
}

function switchVpbxIncSubtab(tab) {
    const isAdd = tab === 'add';
    const paneAdd = $('vpbx-inc-pane-add');
    const paneEdit = $('vpbx-inc-pane-edit');
    const tabAdd = $('vpbx-inc-tab-add');
    const tabEdit = $('vpbx-inc-tab-edit');

    if (paneAdd) paneAdd.style.display = isAdd ? 'block' : 'none';
    if (paneEdit) paneEdit.style.display = !isAdd ? 'block' : 'none';

    if (tabAdd) {
        tabAdd.style.borderBottom = isAdd ? '2px solid #2563eb' : 'none';
        tabAdd.style.color = isAdd ? '#2563eb' : '#64748b';
    }
    if (tabEdit) {
        tabEdit.style.borderBottom = !isAdd ? '2px solid #2563eb' : 'none';
        tabEdit.style.color = !isAdd ? '#2563eb' : '#64748b';
    }
}

function syncVpbxTime(inputId) {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
    if ($(inputId)) $(inputId).value = timeStr;
}

function updateVpbxDialPreview() {
    const rawB = val('vpbx-out-party-b');
    const previewEl = $('vpbx-out-dial-preview') || $('vpbx-dial-preview');
    if (!previewEl) return;
    const digits = rawB.replace(/\D/g, '');
    if (!digits) {
        if ('value' in previewEl) previewEl.value = 'Enter Party B number first';
        else previewEl.textContent = 'Enter Party B number first';
        return;
    }
    const modeEl = document.querySelector('input[name="vpbx-out-dial-mode"]:checked') || document.querySelector('input[name="vpbx-dial-mode"]:checked');
    const dialMode = modeEl ? modeEl.value : 'With 66';
    
    let cleanNumber = digits;
    if (cleanNumber.length === 13 && cleanNumber.startsWith('66')) {
        cleanNumber = cleanNumber.substring(2);
    }
    const dialed = (dialMode === 'With 66') ? ('66' + cleanNumber) : cleanNumber;
    if ('value' in previewEl) previewEl.value = dialed;
    else previewEl.textContent = dialed;
}

function handleVpbxOutStatusChange(status) {
    const ivrEl = $('vpbx-out-ivr');
    const customEl = $('vpbx-out-ivr-custom');
    if (!ivrEl) return;
    if (status === 'Connected') {
        ivrEl.value = 'Standard IVR';
        ivrEl.disabled = true;
        if (customEl) {
            customEl.value = '';
            customEl.style.display = 'none';
        }
    } else {
        ivrEl.disabled = false;
        if (customEl) {
            customEl.style.display = (ivrEl.value === 'custom') ? 'block' : 'none';
        }
    }
}

function handleVpbxOutIvrChange(val) {
    const customEl = $('vpbx-out-ivr-custom');
    if (customEl) {
        customEl.style.display = (val === 'custom') ? 'block' : 'none';
        if (val === 'custom') customEl.focus();
    }
}

function handleVpbxIncStatusChange(status) {
    const ivrEl = $('vpbx-inc-ivr');
    const customEl = $('vpbx-inc-ivr-custom');
    if (!ivrEl) return;
    if (status === 'Received') {
        ivrEl.value = 'Standard IVR';
        ivrEl.disabled = true;
        if (customEl) {
            customEl.value = '';
            customEl.style.display = 'none';
        }
    } else {
        ivrEl.disabled = false;
        if (customEl) {
            customEl.style.display = (ivrEl.value === 'custom') ? 'block' : 'none';
        }
    }
}

function handleVpbxIncIvrChange(val) {
    const customEl = $('vpbx-inc-ivr-custom');
    if (customEl) {
        customEl.style.display = (val === 'custom') ? 'block' : 'none';
        if (val === 'custom') customEl.focus();
    }
}

function getVpbxStatusBadge(status) {
    const s = String(status || '').trim();
    if (s === 'Connected' || s === 'Received') {
        return `<span style="background:#dcfce7; color:#166534; font-weight:700; padding:4px 10px; border-radius:12px; font-size:12px; display:inline-block;">${escapeHtml(s)}</span>`;
    }
    if (s === 'Failed' || s === 'Blocked') {
        return `<span style="background:#fee2e2; color:#991b1b; font-weight:700; padding:4px 10px; border-radius:12px; font-size:12px; display:inline-block;">${escapeHtml(s)}</span>`;
    }
    return `<span style="background:#fef3c7; color:#92400e; font-weight:700; padding:4px 10px; border-radius:12px; font-size:12px; display:inline-block;">${escapeHtml(s)}</span>`;
}

async function loadVpbxData() {
    const res = await api('get_vpbx_data');
    vpbxDataCache = {
        outgoing: res.outgoing || [],
        incoming: res.incoming || [],
        ivrs: res.ivrs || []
    };
    renderVpbxIvrs(vpbxDataCache.ivrs);
    renderVpbxOutgoingTable();
    renderVpbxIncomingTable();
    renderVpbxCaseSelectors();
    updateVpbxDialPreview();
}

function renderVpbxOutgoingTable() {
    const tbody = $('vpbx-outgoing-tbody');
    if (!tbody) return;
    const data = vpbxDataCache.outgoing || [];
    if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:24px; color:#64748b;">No outgoing test cases logged yet.</td></tr>';
        return;
    }
    tbody.innerHTML = data.map(r => `
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="font-weight:700; text-align:center; color:#475569;">#${escapeHtml(r.case_num)}</td>
            <td style="font-weight:600; color:#0f172a;">${escapeHtml(r.client)}</td>
            <td style="color:#64748b; font-size:12px;">${escapeHtml(r.timestamp)}</td>
            <td style="font-family:monospace; font-size:12px;">${escapeHtml(r.master_num)}</td>
            <td style="font-family:monospace; font-size:12px;">${escapeHtml(r.child_num)}</td>
            <td style="font-family:monospace; font-weight:700; font-size:13px; color:#0f172a;">
                ${escapeHtml(String(r.dialed_party_b || '').replace(/[+\s]/g, '') || ((r.dial_mode === 'With 66') ? ('66' + r.party_b) : r.party_b))}
            </td>
            <td><span style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">${escapeHtml(r.operator)}</span></td>
            <td style="font-size:12px; max-width:200px;">${escapeHtml(r.ivr)}</td>
            <td style="text-align:center;">${getVpbxStatusBadge(r.status)}</td>
        </tr>
    `).join('');
}

function renderVpbxIncomingTable() {
    const tbody = $('vpbx-incoming-tbody');
    if (!tbody) return;
    const data = vpbxDataCache.incoming || [];
    if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:#64748b;">No incoming test cases logged yet.</td></tr>';
        return;
    }
    tbody.innerHTML = data.map(r => `
        <tr style="border-bottom:1px solid #e2e8f0;">
            <td style="font-weight:700; text-align:center; color:#475569;">#${escapeHtml(r.case_num)}</td>
            <td style="font-weight:600; color:#0f172a;">${escapeHtml(r.client)}</td>
            <td style="color:#64748b; font-size:12px;">${escapeHtml(r.timestamp)}</td>
            <td style="font-family:monospace; font-weight:600; font-size:12px;">${escapeHtml(r.party_a)}</td>
            <td style="font-family:monospace; font-size:12px;">${escapeHtml(r.party_b)}</td>
            <td><span style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">${escapeHtml(r.operator)}</span></td>
            <td style="font-size:12px; max-width:240px;">${escapeHtml(r.ivr)}</td>
            <td style="text-align:center;">${getVpbxStatusBadge(r.status)}</td>
        </tr>
    `).join('');
}

function renderVpbxCaseSelectors() {
    const outSel = $('vpbx-out-case-select');
    if (outSel) {
        const curVal = outSel.value;
        const outList = vpbxDataCache.outgoing || [];
        if (!outList.length) {
            outSel.innerHTML = '<option value="">-- No cases available --</option>';
        } else {
            outSel.innerHTML = outList.map(r => 
                `<option value="${r.case_num}">Case ${r.case_num}: ${escapeHtml(r.client)} (${escapeHtml(r.status)})</option>`
            ).join('');
            if (curVal && outList.some(r => String(r.case_num) === String(curVal))) {
                outSel.value = curVal;
            }
        }
        syncVpbxOutUpdateStatus();
        outSel.onchange = syncVpbxOutUpdateStatus;
    }

    const incSel = $('vpbx-inc-case-select');
    if (incSel) {
        const curVal = incSel.value;
        const incList = vpbxDataCache.incoming || [];
        if (!incList.length) {
            incSel.innerHTML = '<option value="">-- No cases available --</option>';
        } else {
            incSel.innerHTML = incList.map(r => 
                `<option value="${r.case_num}">Case ${r.case_num}: ${escapeHtml(r.client)} (${escapeHtml(r.status)})</option>`
            ).join('');
            if (curVal && incList.some(r => String(r.case_num) === String(curVal))) {
                incSel.value = curVal;
            }
        }
        syncVpbxIncUpdateStatus();
        incSel.onchange = syncVpbxIncUpdateStatus;
    }
}

function syncVpbxOutUpdateStatus() {
    const outSel = $('vpbx-out-case-select');
    const statSel = $('vpbx-out-update-status');
    if (!outSel || !statSel) return;
    const cid = parseInt(outSel.value, 10);
    const item = (vpbxDataCache.outgoing || []).find(r => r.case_num === cid);
    if (item && item.status) {
        statSel.value = item.status;
    }
}

function syncVpbxIncUpdateStatus() {
    const incSel = $('vpbx-inc-case-select');
    const statSel = $('vpbx-inc-update-status');
    if (!incSel || !statSel) return;
    const cid = parseInt(incSel.value, 10);
    const item = (vpbxDataCache.incoming || []).find(r => r.case_num === cid);
    if (item && item.status) {
        statSel.value = item.status;
    }
}

async function handleVpbxAddOutgoing(e) {
    if (e) e.preventDefault();
    const client = val('vpbx-out-client');
    const masterNum = val('vpbx-out-master');
    const childNum = val('vpbx-out-child');
    const partyB = val('vpbx-out-party-b');
    const modeEl = document.querySelector('input[name="vpbx-out-dial-mode"]:checked') || document.querySelector('input[name="vpbx-dial-mode"]:checked');
    const dialMode = modeEl ? modeEl.value : 'With 66';
    const operator = val('vpbx-out-operator');
    const status = val('vpbx-out-status');
    let ivr = val('vpbx-out-ivr');
    if (ivr === 'custom') {
        ivr = val('vpbx-out-ivr-custom');
        if (!ivr) {
            notice('Please specify the custom IVR message.', true);
            return;
        }
    }
    const timestamp = val('vpbx-out-time') || new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

    await run(async () => {
        const res = await api('add_vpbx_outgoing', {
            client,
            master_num: masterNum,
            child_num: childNum,
            party_b: partyB,
            dial_mode: dialMode,
            operator,
            status,
            ivr,
            timestamp
        });
        vpbxDataCache.outgoing = res.outgoing || [];
        vpbxDataCache.incoming = res.incoming || [];
        renderVpbxOutgoingTable();
        renderVpbxCaseSelectors();
        notice(res.message || 'Outgoing test case added.');
        if ($('vpbx-out-party-b')) $('vpbx-out-party-b').value = '';
        updateVpbxDialPreview();
    });
}

async function handleVpbxUpdateOutgoing() {
    const outSel = $('vpbx-out-case-select');
    const caseId = outSel ? outSel.value : '';
    const newStatus = val('vpbx-out-update-status');
    if (!caseId) {
        notice('Please select a Case ID to update.', true);
        return;
    }
    await run(async () => {
        const res = await api('update_vpbx_outgoing', {
            case_id: caseId,
            new_status: newStatus
        });
        vpbxDataCache.outgoing = res.outgoing || [];
        vpbxDataCache.incoming = res.incoming || [];
        renderVpbxOutgoingTable();
        renderVpbxCaseSelectors();
        notice(res.message || 'Outgoing case updated.');
    });
}

async function handleVpbxDeleteOutgoing() {
    const outSel = $('vpbx-out-case-select');
    const caseId = outSel ? outSel.value : '';
    if (!caseId) {
        notice('Please select a Case ID to delete.', true);
        return;
    }
    if (!confirm('Are you sure you want to delete Outgoing Case #' + caseId + '?')) return;
    await run(async () => {
        const res = await api('delete_vpbx_outgoing', { case_id: caseId });
        vpbxDataCache.outgoing = res.outgoing || [];
        vpbxDataCache.incoming = res.incoming || [];
        renderVpbxOutgoingTable();
        renderVpbxCaseSelectors();
        notice(res.message || 'Outgoing case deleted.');
    });
}

async function handleVpbxAddIncoming(e) {
    if (e) e.preventDefault();
    const client = val('vpbx-inc-client');
    const partyA = val('vpbx-inc-party-a');
    const partyB = val('vpbx-inc-party-b');
    const operator = val('vpbx-inc-operator');
    const status = val('vpbx-inc-status');
    let ivr = val('vpbx-inc-ivr');
    if (ivr === 'custom') {
        ivr = val('vpbx-inc-ivr-custom');
        if (!ivr) {
            notice('Please specify the custom IVR message.', true);
            return;
        }
    }
    const timestamp = val('vpbx-inc-time') || new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });

    await run(async () => {
        const res = await api('add_vpbx_incoming', {
            client,
            party_a: partyA,
            party_b: partyB,
            operator,
            status,
            ivr,
            timestamp
        });
        vpbxDataCache.outgoing = res.outgoing || [];
        vpbxDataCache.incoming = res.incoming || [];
        renderVpbxIncomingTable();
        renderVpbxCaseSelectors();
        notice(res.message || 'Incoming test case added.');
        if ($('vpbx-inc-party-a')) $('vpbx-inc-party-a').value = '';
    });
}

async function handleVpbxUpdateIncoming() {
    const incSel = $('vpbx-inc-case-select');
    const caseId = incSel ? incSel.value : '';
    const newStatus = val('vpbx-inc-update-status');
    if (!caseId) {
        notice('Please select a Case ID to update.', true);
        return;
    }
    await run(async () => {
        const res = await api('update_vpbx_incoming', {
            case_id: caseId,
            new_status: newStatus
        });
        vpbxDataCache.outgoing = res.outgoing || [];
        vpbxDataCache.incoming = res.incoming || [];
        renderVpbxIncomingTable();
        renderVpbxCaseSelectors();
        notice(res.message || 'Incoming case updated.');
    });
}

async function handleVpbxDeleteIncoming() {
    const incSel = $('vpbx-inc-case-select');
    const caseId = incSel ? incSel.value : '';
    if (!caseId) {
        notice('Please select a Case ID to delete.', true);
        return;
    }
    if (!confirm('Are you sure you want to delete Incoming Case #' + caseId + '?')) return;
    await run(async () => {
        const res = await api('delete_vpbx_incoming', { case_id: caseId });
        vpbxDataCache.outgoing = res.outgoing || [];
        vpbxDataCache.incoming = res.incoming || [];
        renderVpbxIncomingTable();
        renderVpbxCaseSelectors();
        notice(res.message || 'Incoming case deleted.');
    });
}

async function clearVpbxSessions() {
    if (!confirm('Are you sure you want to clear ALL runtime test sessions? Both outgoing and incoming lists will be emptied.')) return;
    await run(async () => {
        const res = await api('clear_vpbx_data', {});
        vpbxDataCache.outgoing = [];
        vpbxDataCache.incoming = [];
        renderVpbxOutgoingTable();
        renderVpbxIncomingTable();
        renderVpbxCaseSelectors();
        notice(res.message || 'All runtime sessions cleared.');
    });
}

function exportVpbxCsv(type) {
    const isOut = type === 'outgoing';
    const list = isOut ? (vpbxDataCache.outgoing || []) : (vpbxDataCache.incoming || []);
    if (!list.length) {
        notice(`No ${type} test cases to export.`, true);
        return;
    }

    let headers = [];
    let rows = [];

    if (isOut) {
        headers = ['Case', 'Client Name', 'Time Stamp', 'Master Number', 'Child Number', 'Customer (Party B)', 'Operator', 'IVR / Announcement', 'Status'];
        rows = list.map(r => {
            const displayNum = String(r.dialed_party_b || '').replace(/[+\s]/g, '') || ((r.dial_mode === 'With 66') ? ('66' + r.party_b) : r.party_b);
            return [
                r.case_num,
                `"${String(r.client || '').replace(/"/g, '""')}"`,
                `"${String(r.timestamp || '').replace(/"/g, '""')}"`,
                `"${String(r.master_num || '').replace(/"/g, '""')}"`,
                `"${String(r.child_num || '').replace(/"/g, '""')}"`,
                `"${String(displayNum).replace(/"/g, '""')}"`,
                `"${String(r.operator || '').replace(/"/g, '""')}"`,
                `"${String(r.ivr || '').replace(/"/g, '""')}"`,
                `"${String(r.status || '').replace(/"/g, '""')}"`
            ];
        });
    } else {
        headers = ['Case', 'Client Name', 'Time Stamp', 'Party A (Customer)', 'Party B (Master)', 'Operator', 'IVR / Announcement', 'Status'];
        rows = list.map(r => [
            r.case_num,
            `"${String(r.client || '').replace(/"/g, '""')}"`,
            `"${String(r.timestamp || '').replace(/"/g, '""')}"`,
            `"${String(r.party_a || '').replace(/"/g, '""')}"`,
            `"${String(r.party_b || '').replace(/"/g, '""')}"`,
            `"${String(r.operator || '').replace(/"/g, '""')}"`,
            `"${String(r.ivr || '').replace(/"/g, '""')}"`,
            `"${String(r.status || '').replace(/"/g, '""')}"`
        ]);
    }

    const lines = [headers.join(','), ...rows.map(row => row.join(','))];
    const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    const dateStr = new Date().toISOString().slice(0, 10);
    link.setAttribute('download', `vpbx_${type}_report_${dateStr}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    notice(`${isOut ? 'Outgoing' : 'Incoming'} CSV report exported cleanly for Excel.`);
}

function renderVpbxIvrs(ivrs) {
    const list = ivrs || [];
    ['vpbx-out-ivr', 'vpbx-inc-ivr'].forEach(selectId => {
        const sel = $(selectId);
        if (!sel) return;
        const curVal = sel.value;
        sel.innerHTML = list.map(item => `<option value="${escapeHtml(item.message)}">${escapeHtml(item.message)}</option>`).join('') +
            '<option value="custom">Specify Other...</option>';
        if (curVal && (list.some(item => item.message === curVal) || curVal === 'custom')) {
            sel.value = curVal;
        }
    });

    const adminContainer = $('vpbx-admin-ivr-list');
    if (adminContainer) {
        if (!list.length) {
            adminContainer.innerHTML = '<span style="color:#64748b; font-size:12px;">No IVR options defined.</span>';
        } else {
            adminContainer.innerHTML = list.map(item => {
                if (item.is_default || item.message === 'Standard IVR') {
                    return `<span style="background:#e2e8f0; color:#334155; padding:4px 10px; border-radius:6px; font-size:12px; font-weight:600;">${escapeHtml(item.message)} (Default)</span>`;
                }
                const safeMsg = item.message.replace(/'/g, "\\'");
                return `<span style="background:#ede9fe; color:#5b21b6; padding:4px 10px; border-radius:6px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
                    ${escapeHtml(item.message)}
                    <button type="button" title="Delete IVR option" style="background:none; border:none; cursor:pointer; color:#dc2626; font-size:12px; padding:0; line-height:1;" onclick="handleVpbxDeleteIvr(${item.id}, '${safeMsg}')">✖</button>
                </span>`;
            }).join('');
        }
    }
}

function toggleVpbxAdminIvrDrawer() {
    const drawer = $('vpbx-admin-ivr-drawer');
    if (!drawer) return;
    const isHidden = drawer.style.display === 'none' || !drawer.style.display;
    drawer.style.display = isHidden ? 'block' : 'none';
    if (isHidden) {
        $('vpbx-admin-new-ivr')?.focus();
    }
}

async function handleVpbxAddIvr() {
    const msg = val('vpbx-admin-new-ivr');
    if (!msg) {
        notice('Please enter IVR announcement text.', true);
        return;
    }
    await run(async () => {
        const res = await api('add_vpbx_ivr', { message: msg });
        vpbxDataCache.ivrs = res.ivrs || [];
        renderVpbxIvrs(vpbxDataCache.ivrs);
        if ($('vpbx-admin-new-ivr')) $('vpbx-admin-new-ivr').value = '';
        notice(res.message || 'IVR option added successfully.');
    });
}

async function handleVpbxDeleteIvr(id, msg) {
    if (!confirm(`Are you sure you want to delete IVR option "${msg}"?`)) return;
    await run(async () => {
        const res = await api('delete_vpbx_ivr', { ivr_id: id });
        vpbxDataCache.ivrs = res.ivrs || [];
        renderVpbxIvrs(vpbxDataCache.ivrs);
        notice(res.message || 'IVR option deleted.');
    });
}

// ============================================================
// NMS CUSTOMER SEARCH JAVASCRIPT ENGINE
// ============================================================
let currentNmsStatus = 'all';
let currentNmsPage = 1;
let currentNmsClientData = null;
let nmsSearchDebounceTimer = null;

function setNmsStatusFilter(status, btn) {
    currentNmsStatus = status;
    document.querySelectorAll('#nms-status-pills .nms-pill').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    searchNmsClients(1);
}

function clearNmsFilters() {
    ['nms-f-link', 'nms-f-client', 'nms-f-service', 'nms-f-dept', 'nms-f-label', 'nms-f-site', 'nms-f-isp', 'nms-f-vlan', 'nms-f-tier', 'nms-f-region', 'nms-f-action', 'nms-f-handling', 'nms-f-start', 'nms-f-end', 'nms-f-user'].forEach(id => {
        const el = $(id);
        if (el) el.value = '';
    });
    document.querySelectorAll('.nms-col-search').forEach(input => input.value = '');
    currentNmsStatus = 'all';
    document.querySelectorAll('#nms-status-pills .nms-pill').forEach(p => {
        p.classList.toggle('active', p.getAttribute('onclick')?.includes("'all'"));
    });
    searchNmsClients(1);
}

function changeNmsLimit(limit) {
    searchNmsClients(1);
}

function getNmsFilterParams(page = 1) {
    const params = {
        action: 'search_nms_clients',
        page: page,
        limit: $('nms-limit-select')?.value || 10,
        status_filter: currentNmsStatus,
        unique_link_id: val('nms-f-link'),
        client_name: val('nms-f-client'),
        service: val('nms-f-service'),
        department: val('nms-f-dept'),
        nms_label: val('nms-f-label'),
        site: val('nms-f-site'),
        isp: val('nms-f-isp'),
        vlan: val('nms-f-vlan'),
        tier_level: val('nms-f-tier'),
        deployed_region: val('nms-f-region'),
        last_action: val('nms-f-action'),
        handling_region: val('nms-f-handling'),
        start_date: val('nms-f-start'),
        end_date: val('nms-f-end'),
        username: val('nms-f-user')
    };

    document.querySelectorAll('.nms-col-search').forEach(input => {
        const col = input.dataset.col;
        const v = input.value.trim();
        if (col && v) params[col] = v;
    });

    return params;
}

async function searchNmsClients(page = 1) {
    currentNmsPage = page;
    const tbody = $('nms-table-body');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="16" style="text-align:center; padding:30px; color:#64748b;"><span class="pulse-dot" style="margin-right:8px;"></span> Loading matching customer links...</td></tr>';

    try {
        const params = getNmsFilterParams(page);
        const res = await api('search_nms_clients', params);
        renderNmsTable(res);
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="16" style="text-align:center; padding:24px; color:#dc2626;">Error searching records: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderNmsTable(res) {
    const tbody = $('nms-table-body');
    if (!tbody) return;
    const records = res.records || [];
    const total = res.total || 0;
    const page = res.page || 1;
    const pages = res.pages || 1;
    const limit = res.limit || 10;

    if ($('nms-total-badge')) {
        $('nms-total-badge').textContent = `${total.toLocaleString()} Links Loaded`;
    }

    const startIdx = total === 0 ? 0 : ((page - 1) * limit + 1);
    const endIdx = Math.min(page * limit, total);
    if ($('nms-pagination-info')) {
        $('nms-pagination-info').textContent = `Showing ${startIdx.toLocaleString()} to ${endIdx.toLocaleString()} of ${total.toLocaleString()} entries`;
    }

    if (!records.length) {
        tbody.innerHTML = '<tr><td colspan="16" style="text-align:center; padding:36px; color:#64748b; font-size:13px;">No customer links matched your search criteria. Try clearing some filters.</td></tr>';
        renderNmsPagination(page, pages);
        return;
    }

    tbody.innerHTML = records.map((r, i) => {
        const rowNum = startIdx + i;
        const statusClass = getNmsStatusClass(r.status, r.approval_status);
        const displayStatus = r.approval_status || r.status || 'Active';
        const safeLink = escapeHtml(r.unique_link_id || '—');

        const isZte = Number(r.is_zte) === 1;
        const labelStyle = isZte 
            ? 'color:#1d4ed8; font-weight:700; background:#eff6ff; padding:2px 6px; border-radius:4px; border:1px solid #bfdbfe;' 
            : 'color:#334155;';
        const zteBadge = isZte 
            ? '<span style="display:inline-block; margin-left:6px; background:#2563eb; color:#ffffff; font-size:10px; font-weight:800; padding:1px 5px; border-radius:3px; vertical-align:middle; letter-spacing:0.5px;">🔵 ZTE</span>' 
            : '';

        return `
            <tr style="border-bottom:1px solid #f1f5f9; ${isZte ? 'background:#f8faff;' : ''} transition:background 0.1s ease;" onmouseover="this.style.background='${isZte ? '#eff6ff' : '#f8fafc'}'" onmouseout="this.style.background='${isZte ? '#f8faff' : ''}'">
                <td style="text-align:center; font-weight:700; color:#64748b; font-size:11px;">${rowNum}</td>
                <td>
                    <span class="nms-link-badge" onclick="openNmsDetailModal(${r.id})" title="Click to view complete technical details">${safeLink}</span>
                </td>
                <td style="font-weight:700; color:#0f172a; white-space:nowrap;">${escapeHtml(r.client_name || '—')}</td>
                <td style="font-family:monospace; font-size:11.5px; max-width:340px; word-break:break-all;" title="${escapeHtml(r.nms_user_label || '')}">
                    <span style="${labelStyle}">${escapeHtml(r.nms_user_label || '—')}</span>${zteBadge}
                </td>
                <td><span style="background:#f1f5f9; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:600; color:#334155;">${escapeHtml(r.service || '—')}</span></td>
                <td style="font-size:11px; color:#475569;">${escapeHtml(r.department || '—')}</td>
                <td style="font-family:monospace; font-size:11px; color:#334155;">${escapeHtml(r.site || '—')}</td>
                <td style="font-size:11.5px; font-weight:600; color:#475569;">${escapeHtml(r.region || '—')}</td>
                <td style="font-size:11.5px; color:#334155;">${escapeHtml(r.last_action_taken || '—')}</td>
                <td style="font-size:11px; color:#64748b; white-space:nowrap;">${escapeHtml(r.last_action_taken_on || '—')}</td>
                <td style="text-align:center; font-size:11.5px; font-weight:700; color:#475569;">${escapeHtml(r.tier_level || '—')}</td>
                <td style="text-align:center; white-space:nowrap;">
                    <span class="nms-status-badge ${statusClass}">● ${escapeHtml(displayStatus)}</span>
                </td>
                <td style="font-size:11px; color:#64748b; white-space:nowrap;">${escapeHtml(r.go_ahead_date || '—')}</td>
                <td style="text-align:right; font-weight:700; font-family:monospace; font-size:12px; color:#0f172a;">${escapeHtml(r.solution_design_bw || '—')}</td>
                <td style="font-size:11px; color:#475569; font-family:monospace;">${escapeHtml(r.dashboard_username || '—')}</td>
                <td style="text-align:center; white-space:nowrap;">
                    <button type="button" class="btn-secondary" style="padding:4px 8px; font-size:11px; font-weight:600;" onclick="openNmsDetailModal(${r.id})">👁 Details</button>
                </td>
            </tr>
        `;
    }).join('');

    renderNmsPagination(page, pages);
}

function getNmsStatusClass(status, approval) {
    const s = String(approval || status || '').toLowerCase();
    if (s.includes('approved')) return 'nms-status-approved';
    if (s.includes('pending')) return 'nms-status-pending';
    if (s.includes('active')) return 'nms-status-active';
    if (s.includes('rejected')) return 'nms-status-rejected';
    if (s.includes('suspended')) return 'nms-status-suspended';
    if (s.includes('terminated')) return 'nms-status-terminated';
    return 'nms-status-active';
}

function renderNmsPagination(current, totalPages) {
    const container = $('nms-pagination-controls');
    if (!container) return;

    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';
    const btnStyle = (active, disabled) => `
        padding: 5px 10px;
        font-size: 11.5px;
        border-radius: 4px;
        border: 1px solid ${active ? '#2563eb' : '#cbd5e1'};
        background: ${active ? '#2563eb' : disabled ? '#f8fafc' : '#fff'};
        color: ${active ? '#fff' : disabled ? '#94a3b8' : '#334155'};
        cursor: ${disabled ? 'default' : 'pointer'};
        font-weight: ${active ? '700' : '600'};
    `;

    // Prev button
    html += `<button type="button" style="${btnStyle(false, current === 1)}" ${current === 1 ? 'disabled' : `onclick="searchNmsClients(${current - 1})"`}>&lt;</button>`;

    // Windowed page numbers
    let startPage = Math.max(1, current - 2);
    let endPage = Math.min(totalPages, current + 2);

    if (startPage > 1) {
        html += `<button type="button" style="${btnStyle(current === 1, false)}" onclick="searchNmsClients(1)">1</button>`;
        if (startPage > 2) html += `<span style="padding:4px; color:#94a3b8;">...</span>`;
    }

    for (let p = startPage; p <= endPage; p++) {
        html += `<button type="button" style="${btnStyle(p === current, false)}" onclick="searchNmsClients(${p})">${p}</button>`;
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) html += `<span style="padding:4px; color:#94a3b8;">...</span>`;
        html += `<button type="button" style="${btnStyle(current === totalPages, false)}" onclick="searchNmsClients(${totalPages})">${totalPages}</button>`;
    }

    // Next button
    html += `<button type="button" style="${btnStyle(false, current === totalPages)}" ${current === totalPages ? 'disabled' : `onclick="searchNmsClients(${current + 1})"`}>&gt;</button>`;

    container.innerHTML = html;
}

function exportNmsExcel() {
    const params = getNmsFilterParams(1);
    delete params.page;
    delete params.limit;
    params.action = 'export_nms_clients';
    const qs = new URLSearchParams(params).toString();
    window.location.href = 'api.php?' + qs;
}

async function openNmsDetailModal(clientId) {
    const modal = $('nms-detail-modal');
    if (!modal) return;
    modal.style.display = 'flex';
    $('nms-modal-body').innerHTML = '<div style="text-align:center; padding:40px; color:#64748b;"><span class="pulse-dot" style="margin-right:8px;"></span> Loading full link specifications...</div>';

    try {
        const res = await api('get_nms_client', { id: clientId });
        const c = res.client;
        currentNmsClientData = c;
        $('nms-modal-title').textContent = `${c.unique_link_id || 'Link'} — ${c.client_name || 'Client'}`;
        if ($('nms-modal-footer-info')) {
            $('nms-modal-footer-info').textContent = `Database ID: #${c.id} | Row: #${c.row_num} | Status: ${c.status || 'Active'} | Approval: ${c.approval_status || 'Pending'}`;
        }
        renderNmsModalTabContent('overview');
    } catch (err) {
        $('nms-modal-body').innerHTML = `<div style="text-align:center; padding:30px; color:#dc2626;">Failed to load details: ${escapeHtml(err.message)}</div>`;
    }
}

function closeNmsDetailModal() {
    const modal = $('nms-detail-modal');
    if (modal) modal.style.display = 'none';
}

function switchNmsModalTab(tabKey, btn) {
    document.querySelectorAll('.nms-m-tab').forEach(t => t.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderNmsModalTabContent(tabKey);
}

function renderNmsModalTabContent(tabKey) {
    const body = $('nms-modal-body');
    if (!body || !currentNmsClientData) return;
    const c = currentNmsClientData;

    const item = (label, val) => `
        <div class="nms-spec-card">
            <div class="spec-label">${escapeHtml(label)}</div>
            <div class="spec-value">${escapeHtml(val || '—')}</div>
        </div>
    `;

    let html = '';
    if (tabKey === 'overview') {
        html = `
            <div class="nms-spec-grid">
                ${item('Unique / FLL Link ID', c.unique_link_id)}
                ${item('Client Name', c.client_name)}
                ${item('Department', c.department)}
                ${item('Service', c.service)}
                ${item('Industry Segment', c.industry_segment)}
                ${item('Customer Type', c.customer_type)}
                ${item('Status', c.status)}
                ${item('Approval Status', c.approval_status)}
                ${item('FLL Sale ID', c.fll_sale_id)}
                ${item('MSISDN', c.msisdn)}
                ${item('Source City', c.source_city)}
                ${item('Sink City', c.sink_city)}
                ${item('OTC', (c.otc ? `${c.otc} (${c.otc_type || 'PKR'})` : '—'))}
                ${item('MRC', (c.mrc ? `${c.mrc} (${c.mrc_type || 'PKR'})` : '—'))}
                ${item('NTN Number', c.ntn_no)}
                ${item('NMS User Label', c.nms_user_label)}
            </div>
        `;
    } else if (tabKey === 'technical') {
        html = `
            <div class="nms-spec-grid">
                ${item('Solution Design BW (Mbps)', c.solution_design_bw)}
                ${item('Configured BW (Mbps)', c.configured_bw)}
                ${item('Bandwidth Percentage', c.bw_percentage)}
                ${item('Tier Level', c.tier_level)}
                ${item('Core Network Protection', c.core_network_protection)}
                ${item('Last Mile Protection', c.last_mile_protection)}
                ${item('Re-Routing Requirement', c.rerouting_requirement)}
                ${item('SLA Target', c.sla)}
                ${item('VLAN ID', c.vlan)}
                ${item('TXN NMS', c.txn_nms)}
                ${item('Routed Static IPs', c.routed_static_ips)}
                ${item('Solution Type', c.solution_type)}
                ${item('ISP Provider', c.isp)}
                ${item('Gateway IPs', c.gateway_ips)}
                ${item('Peering IPs', c.peering_ips)}
                ${item('ASN Number', c.asn_number)}
                ${item('Advertised IPs', c.advertised_ips)}
                ${item('FTTH Panel', c.ftth_panel)}
                ${item('ONT Type', c.ont_type)}
                ${item('ONT Owner', c.ont_owner)}
            </div>
        `;
    } else if (tabKey === 'transport') {
        html = `
            <div class="nms-spec-grid">
                ${item('Optical Network', c.optical_network)}
                ${item('Optical Source Node', c.optical_source_node)}
                ${item('Optical Source Board Port', c.optical_source_port)}
                ${item('Optical Sink Node', c.optical_sink_node)}
                ${item('Optical Sink Board Port', c.optical_sink_port)}
                ${item('Network Type (L1)', c.network_type_l1)}
                ${item('Node Type', c.node_type)}
                ${item('TXN Network (L1)', c.txn_network_l1)}
                ${item('Datacom Network', c.datacom_network)}
                ${item('Datacom Location', c.datacom_location)}
                ${item('Datacom Type', c.datacom_type)}
                ${item('Datacom Node', c.datacom_node)}
                ${item('Datacom Port', c.datacom_port)}
                ${item('Microwave Network', c.microwave_network)}
                ${item('MW Source Node', c.mw_source_node)}
                ${item('MW Source Port', c.mw_source_port)}
                ${item('MW Sink Node', c.mw_sink_node)}
                ${item('MW Sink Port', c.mw_sink_port)}
                ${item('Last Mile Medium', c.last_mile_medium)}
                ${item('Last Mile Vendor', c.last_mile_vendor)}
                ${item('Hop Length', c.hop_length)}
                ${item('Fiber Length', c.fiber_length)}
                ${item('Tower / Pole Length', c.tower_pole_length)}
                ${item('Tower / Pole Owner', c.tower_pole_owner)}
                ${item('Network Layers', c.network_layers)}
            </div>
        `;
    } else if (tabKey === 'site') {
        html = `
            <div class="nms-spec-grid">
                ${item('Site ID / Name', c.site)}
                ${item('Site Owner', c.site_owner)}
                ${item('Deployed Region', c.region)}
                ${item('Sub Region', c.sub_region)}
                ${item('Site Latitude', c.site_latitude)}
                ${item('Site Longitude', c.site_longitude)}
                ${item('Client Latitude', c.client_latitude)}
                ${item('Client Longitude', c.client_longitude)}
                ${item('Polygon Area', c.polygon)}
                ${item('Physical Address', c.address)}
            </div>
        `;
    } else if (tabKey === 'contacts') {
        html = `
            <div class="nms-spec-grid">
                ${item('Key Account Manager (KAM)', c.kam_name)}
                ${item('KAM Email', c.kam_email)}
                ${item('KAM Contact', c.kam_contact)}
                ${item('Handling Region', c.handling_region)}
                ${item('Point of Contact (POC)', c.poc_name)}
                ${item('POC Email', c.poc_email)}
                ${item('POC Contact No', c.poc_contact_no)}
                ${item('Dashboard Username', c.dashboard_username)}
                ${item('Dashboard Required', c.dashboard_required)}
                ${item('Customer MRTG', c.customer_mrtg)}
                ${item('Instance Created', c.instance_created)}
                ${item('Traffic Statistics', c.traffic_statistics)}
                ${item('Work Order', c.work_order)}
            </div>
        `;
    } else if (tabKey === 'timeline') {
        html = `
            <div class="nms-spec-grid">
                ${item('Go Ahead Date', c.go_ahead_date)}
                ${item('Deployment Date', c.deployment_date)}
                ${item('Change Request Date', c.change_request_date)}
                ${item('Last Action Taken', c.last_action_taken)}
                ${item('Last Action Taken On', c.last_action_taken_on)}
                ${item('Is Delay', c.is_delay)}
                ${item('Delay At', c.delay_at)}
                ${item('Delay Remarks', c.delay_remarks)}
                ${item('Feasibility Comments', c.feasibility_comments)}
                ${item('Is Terminated', c.is_terminated)}
                ${item('Termination Workorder', c.termination_workorder)}
                ${item('Termination Go Ahead', c.termination_go_ahead)}
                ${item('Termination Implementation', c.termination_implementation)}
                ${item('Configuration Deleted', c.configuration_deleted)}
                ${item('Termination Reason', c.termination_reason)}
                ${item('Termination Remarks', c.termination_remarks)}
            </div>
        `;
    }

    body.innerHTML = html;
}

// Bind event listeners for column searches
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.nms-col-search').forEach(input => {
        input.addEventListener('input', () => {
            clearTimeout(nmsSearchDebounceTimer);
            nmsSearchDebounceTimer = setTimeout(() => {
                searchNmsClients(1);
            }, 300);
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeNmsDetailModal();
    });
});

/* Router Commands Library — database-backed, relevant-only */
let routerCommandMeta = null;
let routerCommands = [];
let routerCommandSearchTimer = null;

function routerCommandValues() {
    return {
        vlan: val('router-vlan'),
        trunk: val('router-trunk'),
        ip: val('router-ip'),
        vrf: val('router-vrf'),
        peer_ip: val('router-peer-ip'),
        search: val('router-config-search'),
        policy: val('router-policy'),
        prefix: val('router-prefix')
    };
}

function buildRouterCommand(template) {
    const values = routerCommandValues();
    return String(template || '').replace(/\{([a-z0-9_]+)\}/gi, (full, key) => values[key] || full);
}

function routerCommandMissingVariables(template) {
    const values = routerCommandValues();
    const missing = [];
    for (const match of String(template || '').matchAll(/\{([a-z0-9_]+)\}/gi)) {
        const key = match[1];
        if (!values[key] && !missing.includes(key)) missing.push(key);
    }
    return missing;
}

async function loadRouterCommandMeta() {
    if (!$('router-platform-filter')) return;
    if (routerCommandMeta) return;

    const response = await fetch('api.php?' + new URLSearchParams({action:'get_router_meta'}));
    const result = await response.json();
    if (!response.ok || !result.success) {
        notice(result.message || 'Unable to load router command categories.', true);
        return;
    }

    routerCommandMeta = result;
    const platformSelect = $('router-platform-filter');
    platformSelect.replaceChildren(new Option('Select Router / Platform', ''));
    (result.platforms || []).forEach(platform => platformSelect.add(new Option(platform, platform)));
}

function handleRouterPlatformChange() {
    const platform = val('router-platform-filter');
    const categorySelect = $('router-category-filter');
    const commandSelect = $('router-command-select');
    const search = $('router-command-filter');

    routerCommands = [];
    categorySelect.replaceChildren(new Option('Select Task', ''));
    categorySelect.disabled = !platform;
    commandSelect.replaceChildren(new Option('All Relevant Commands', ''));
    commandSelect.disabled = true;
    search.value = '';
    search.disabled = true;
    clearRouterRuntimeValues();
    updateRouterVariableVisibility();
    renderRouterCommands();

    if (!platform || !routerCommandMeta) {
        setRouterHint('Select a router/platform and troubleshooting task to load commands.');
        return;
    }

    const categories = routerCommandMeta.categories?.[platform] || [];
    categories.forEach(item => {
        const label = item.count > 1 ? `${item.name} (${item.count})` : item.name;
        categorySelect.add(new Option(label, item.name));
    });
    setRouterHint('Select the troubleshooting task for ' + platform + '.');
}

function handleRouterCategoryChange() {
    const category = val('router-category-filter');
    $('router-command-filter').value = '';
    $('router-command-filter').disabled = !category;
    $('router-command-select').replaceChildren(new Option('All Relevant Commands', ''));
    $('router-command-select').disabled = true;
    clearRouterRuntimeValues();

    if (!category) {
        routerCommands = [];
        updateRouterVariableVisibility();
        renderRouterCommands();
        setRouterHint('Select a troubleshooting task.');
        return;
    }

    loadRelevantRouterCommands();
}

function handleRouterCommandSelection() {
    updateRouterVariableVisibility();
    renderRouterCommands();
}

function queueRouterCommandSearch() {
    clearTimeout(routerCommandSearchTimer);
    routerCommandSearchTimer = setTimeout(loadRelevantRouterCommands, 250);
}

async function loadRelevantRouterCommands() {
    const platform = val('router-platform-filter');
    const category = val('router-category-filter');
    if (!platform || !category) return;

    const params = new URLSearchParams({
        action: 'get_router_commands',
        platform,
        category,
        q: val('router-command-filter')
    });

    const response = await fetch('api.php?' + params);
    const result = await response.json();
    if (!response.ok || !result.success) {
        notice(result.message || 'Unable to load router commands.', true);
        return;
    }

    routerCommands = Array.isArray(result.commands) ? result.commands : [];

    const commandSelect = $('router-command-select');
    const previous = commandSelect.value;
    commandSelect.replaceChildren(new Option('All Relevant Commands', ''));
    routerCommands.forEach(command => commandSelect.add(new Option(command.title, String(command.id))));
    commandSelect.disabled = routerCommands.length === 0;
    if ([...commandSelect.options].some(opt => opt.value === previous)) commandSelect.value = previous;

    updateRouterVariableVisibility();
    renderRouterCommands();
    $('router-flow-note').hidden = false;
    setRouterHint(routerCommands.length ? 'Stored values come from the database. Enter only the runtime fields shown below.' : 'No command is stored for this selection.');
}

function getVisibleRouterCommands() {
    const selectedId = val('router-command-select');
    if (!selectedId) return routerCommands;
    return routerCommands.filter(command => String(command.id) === selectedId);
}

function updateRouterVariableVisibility() {
    const required = new Set();
    getVisibleRouterCommands().forEach(command => {
        for (const match of String(command.template || '').matchAll(/\{([a-z0-9_]+)\}/gi)) {
            required.add(match[1]);
        }
    });

    document.querySelectorAll('[data-router-var]').forEach(group => {
        group.hidden = !required.has(group.dataset.routerVar);
    });
}

function setRouterHint(message) {
    const hint = $('router-selection-hint');
    if (hint) hint.textContent = message;
}

function clearRouterRuntimeValues() {
    ['router-vlan','router-trunk','router-ip','router-vrf','router-peer-ip','router-config-search','router-policy','router-prefix']
        .forEach(id => { if ($(id)) $(id).value = ''; });
}

function renderRouterCommands() {
    const tbody = $('router-command-tbody');
    if (!tbody) return;

    const visible = getVisibleRouterCommands();
    $('router-command-count').textContent = visible.length
        ? visible.length + ' relevant command' + (visible.length === 1 ? '' : 's')
        : '';

    if (!val('router-platform-filter') || !val('router-category-filter')) {
        tbody.innerHTML = '<tr><td colspan="5" class="router-table-empty">Select a router/platform and task to begin.</td></tr>';
        return;
    }

    if (!visible.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="router-table-empty">No command matches this selection.</td></tr>';
        return;
    }

    tbody.innerHTML = visible.map(command => {
        const generated = buildRouterCommand(command.template);
        const missing = routerCommandMissingVariables(command.template);
        const status = missing.length
            ? '<span class="router-command-status pending">Needs ' + missing.map(escapeHtml).join(', ') + '</span>'
            : '<span class="router-command-status ready">Ready</span>';

        return `
            <tr>
                <td>
                    <strong>${escapeHtml(command.title || 'Router command')}</strong>
                    <div class="router-row-meta">${escapeHtml(command.platform || '')} • ${escapeHtml(command.category || '')}</div>
                </td>
                <td class="router-purpose-cell">${escapeHtml(command.description || '—')}</td>
                <td><code class="router-command-inline">${escapeHtml(generated)}</code></td>
                <td>${status}</td>
                <td><button type="button" class="btn-copy" onclick="copyRouterCommand(${Number(command.id)})">📋 Copy</button></td>
            </tr>`;
    }).join('');
}

async function copyRouterCommand(id) {
    const command = routerCommands.find(item => Number(item.id) === Number(id));
    if (!command) return;

    const missing = routerCommandMissingVariables(command.template);
    if (missing.length) {
        notice('Fill required variable(s): ' + missing.join(', '), true);
        return;
    }

    try {
        await navigator.clipboard.writeText(buildRouterCommand(command.template));
        notice('Router command copied.');
    } catch {
        notice('Unable to copy automatically. Copy it manually.', true);
    }
}

function resetRouterCommandInputs(resetSelectors = true) {
    clearRouterRuntimeValues();

    if (resetSelectors) {
        if ($('router-platform-filter')) $('router-platform-filter').value = '';
        if ($('router-category-filter')) {
            $('router-category-filter').replaceChildren(new Option('Select Task', ''));
            $('router-category-filter').disabled = true;
        }
        if ($('router-command-select')) {
            $('router-command-select').replaceChildren(new Option('All Relevant Commands', ''));
            $('router-command-select').disabled = true;
        }
        if ($('router-command-filter')) {
            $('router-command-filter').value = '';
            $('router-command-filter').disabled = true;
        }
        routerCommands = [];
        $('router-flow-note').hidden = true;
        setRouterHint('Select a router/platform and troubleshooting task to load commands.');
    }

    updateRouterVariableVisibility();
    renderRouterCommands();
}

// router-auto-load
document.addEventListener('DOMContentLoaded', () => {
    if ($('tab-router')?.classList.contains('active')) loadRouterCommandMeta();
});
