'use strict';
const HANDOVER_KEY = 'smartportal_handover_v1';
let handoverItems = [], editingHandover = null;
const hEl = id => document.getElementById('handover-' + id);
const hConfig = window.handoverConfig;
function escH(value) { const d = document.createElement('div'); d.textContent = value ?? ''; return d.innerHTML; }
function normalizedHandover(x) {
    return {...x, subjects: Array.isArray(x.subjects) && x.subjects.length ? x.subjects : [x.subject || 'NA'], nms: x.nms || 'NA', status: x.status === 'DONE' || x.status === 'RESOLVED' ? 'RESOLVED' : 'OPEN'};
}
function loadHandover() {
    try {
        const saved = JSON.parse(localStorage.getItem(HANDOVER_KEY) || '[]');
        if (!Array.isArray(saved)) throw new Error('Invalid records');
        handoverItems = saved.map(normalizedHandover);
    } catch (err) { alert('Saved handovers could not be read. No records have been overwritten.'); hEl('save').disabled = true; return; }
    renderHandover();
}
function persistHandover(next) {
    try { localStorage.setItem(HANDOVER_KEY, JSON.stringify(next)); handoverItems = next; renderHandover(); return true; }
    catch (err) { alert('Could not save handover. Browser storage may be full or disabled. Your form has been kept.'); return false; }
}
function fillHandoverOptions(select, options, placeholder, selected = '') {
    select.replaceChildren(new Option(placeholder, ''));
    [...new Set([...options, ...(selected && !options.includes(selected) ? [selected] : [])])].forEach(v => select.add(new Option(v, v)));
    select.value = selected;
}
function changeHandoverFollowup(selected = '') {
    const type = hEl('followup').value;
    fillHandoverOptions(hEl('party'), type === 'vendor' ? hConfig.vendors : type === 'team' ? hConfig.teams : [], 'Select vendor / team', selected);
    hEl('party').disabled = !['vendor', 'team'].includes(type);
    hEl('party').required = !hEl('party').disabled;
}
function addHandoverSubject(value = '') {
    const row = document.createElement('div'); row.className = 'form-group';
    const label = document.createElement('label'); label.textContent = 'Additional email subject (optional)';
    const input = document.createElement('input'); input.className = 'handover-extra'; input.maxLength = 2000; input.value = value;
    input.id = 'subject-' + crypto.randomUUID(); label.htmlFor = input.id;
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn-secondary'; remove.textContent = 'Remove subject'; remove.onclick = () => row.remove();
    row.append(label, input, remove); hEl('extra-subjects').append(row);
}
function appendHandoverComment(select) {
    if (select.value) hEl('comment').value = [hEl('comment').value.trim(), select.value].filter(Boolean).join(' | ');
    select.value = '';
}
function resetHandoverForm() {
    editingHandover = null; hEl('form').reset(); hEl('extra-subjects').replaceChildren(); changeHandoverFollowup(); hEl('save').textContent = 'Add to Handover';
}
function addHandover(event) {
    event.preventDefault();
    const subject = hEl('subject').value.trim(); if (!subject) { hEl('subject').focus(); return; }
    const previous = handoverItems.find(x => x.id === editingHandover);
    const status = hEl('status').value;
    const record = {
        ...(previous || {}), id: previous?.id ?? crypto.randomUUID(), subject,
        subjects: [subject, ...Array.from(document.querySelectorAll('.handover-extra'), input => input.value.trim()).filter(Boolean)],
        nms: hEl('nms').value.trim() || 'NA', region: hEl('region').value || 'NA',
        followup: hEl('followup').value, party: hEl('party').disabled ? '' : hEl('party').value,
        comment: hEl('comment').value.trim(), ticket: hEl('ticket').value.trim() || 'NA', owner: hEl('owner').value.trim() || 'NA',
        startedAt: previous?.startedAt || new Date().toISOString(), status,
        completedAt: status === 'RESOLVED' ? previous?.completedAt || new Date().toISOString() : null
    };
    if (persistHandover(previous ? handoverItems.map(x => x.id === previous.id ? record : x) : [record, ...handoverItems])) resetHandoverForm();
}
function editHandover(index) {
    const x = handoverItems[index]; resetHandoverForm(); editingHandover = x.id;
    for (const key of ['nms', 'ticket', 'owner', 'comment', 'status']) hEl(key).value = x[key] || '';
    hEl('subject').value = x.subjects[0]; x.subjects.slice(1).forEach(addHandoverSubject);
    fillHandoverOptions(hEl('region'), hConfig.regions, 'NA', x.region || '');
    hEl('followup').value = x.followup || (x.vendor ? 'vendor' : ''); changeHandoverFollowup(x.party || x.vendor || '');
    hEl('save').textContent = 'Save changes'; hEl('form').scrollIntoView({behavior:'smooth'});
}
function completeHandover(index) {
    persistHandover(handoverItems.map((x, i) => i === index ? {...x, status:'RESOLVED', completedAt:new Date().toISOString()} : x));
}
function removeHandover(index) {
    if (confirm('Remove this handover complaint?')) {
        const id = handoverItems[index].id;
        if (persistHandover(handoverItems.filter((_, i) => i !== index)) && editingHandover === id) resetHandoverForm();
    }
}
function handoverAge(start, now = Date.now()) {
    const sec = Math.max(0, Math.floor((now - new Date(start).getTime()) / 1000)) || 0;
    return {sec, text: `${Math.floor(sec / 3600)}h ${String(Math.floor(sec % 3600 / 60)).padStart(2,'0')}m ${String(sec % 60).padStart(2,'0')}s`};
}
function handoverCurrent(x) {
    const followup = x.followup === 'customer' ? 'Follow up with customer' : ['vendor','team'].includes(x.followup) && x.party ? 'Follow up with ' + x.party : !x.followup && x.vendor ? 'Follow up with ' + x.vendor : '';
    return [x.ticket || 'NA', x.status === 'RESOLVED' ? 'Resolved' : 'Open', followup, x.comment].filter(Boolean).join(' || ');
}
function handoverText(items) { return items.map(x => ['NMS label: ' + (x.nms || 'NA'), ...x.subjects.map(s => 'Email Subject: ' + s), 'Current status: ' + handoverCurrent(x)].join('\n')).join('\n\n'); }
async function copyHandover() {
    const text = handoverText(handoverItems); hEl('export').hidden = false; hEl('export').value = text;
    try { await navigator.clipboard.writeText(text); } catch (_) { hEl('export').focus(); hEl('export').select(); }
}
function renderHandover() {
    let pending = 0, overdue = 0;
    hEl('body').innerHTML = handoverItems.map((x, i) => {
        const age = handoverAge(x.startedAt), done = x.status === 'RESOLVED', over = !done && age.sec >= hConfig.overdueMinutes * 60;
        if (!done) pending++; if (over) overdue++;
        return `<tr class="${over ? 'handover-overdue' : done ? 'handover-done' : ''}"><td class="handover-subject"><strong>NMS label: ${escH(x.nms)}</strong>${x.subjects.map(s => '<div>Email Subject: ' + escH(s) + '</div>').join('')}</td><td>${escH(x.region || 'NA')}</td><td>${escH(handoverCurrent(x))}</td><td>${escH(x.owner || 'NA')}</td><td>${escH(new Date(x.startedAt).toLocaleString())}</td><td class="handover-timer ${over ? 'overdue' : ''}">${done ? 'Completed' : age.text}</td><td>${done ? 'RESOLVED' : over ? 'OVERDUE' : 'OPEN'}</td><td class="handover-actions"><button class="btn-copy" data-action="edit" data-index="${i}">Edit</button> ${!done ? `<button class="btn-copy" data-action="complete" data-index="${i}">Resolve</button>` : ''} <button class="btn-danger" data-action="remove" data-index="${i}">Remove</button></td></tr>`;
    }).join('') || '<tr><td colspan="8" class="handover-empty">No handover items yet.</td></tr>';
    hEl('pending-count').textContent = pending; hEl('overdue-count').textContent = overdue;
}
document.addEventListener('DOMContentLoaded', () => {
    fillHandoverOptions(hEl('region'), hConfig.regions, 'NA');
    fillHandoverOptions(hEl('quick'), hConfig.comments, 'Select a quick comment'); changeHandoverFollowup();
    hEl('body').addEventListener('click', e => {
        const button = e.target.closest('button[data-action]'); if (!button) return;
        ({edit:editHandover, complete:completeHandover, remove:removeHandover})[button.dataset.action](Number(button.dataset.index));
    });
    loadHandover(); setInterval(renderHandover, 1000);
});
window.addEventListener('storage', event => { if (event.key === HANDOVER_KEY) loadHandover(); });
