/* IPv4 ACL generator: contiguous wildcard blocks, ordered exceptions, final permit. */
(function (root) {
    'use strict';
    const LIMIT = 2 ** 32;
    const TYPES = ['Cisco Standard', 'Cisco Extended', 'Huawei Basic', 'Huawei Advanced'];
    const defaults = [4, 100, 2000, 3000];
    function integer(value, label, low, high) {
        if (String(value).trim() === '' || !Number.isInteger(Number(value)) || Number(value) < low || Number(value) > high) {
            throw new Error(`${label} must be an integer from ${low} to ${high}.`);
        }
        return Number(value);
    }
    function ip(text) {
        const parts = String(text).trim().split('.');
        if (parts.length !== 4 || parts.some(p => !/^(0|[1-9]\d{0,2})$/.test(p) || Number(p) > 255)) {
            throw new Error(`Invalid IPv4 address: ${text}`);
        }
        return parts.reduce((n, p) => n * 256 + Number(p), 0);
    }
    function address(n) {
        return [24, 16, 8, 0].map(b => Math.floor(n / 2 ** b) % 256).join('.');
    }
    function network(text) {
        const parts = String(text).trim().split('/');
        if (parts.length > 2) throw new Error('Use an IPv4 address or CIDR.');
        const prefix = parts.length === 2 ? integer(parts[1], 'CIDR prefix', 0, 32) : 32;
        const size = 2 ** (32 - prefix);
        const start = Math.floor(ip(parts[0]) / size) * size;
        return {start, prefix, size, end: start + size - 1};
    }
    const opposite = action => action === 'permit' ? 'deny' : 'permit';
    const rule = (action, start, prefix, reason) => ({action, start, prefix, size: 2 ** (32-prefix), reason});
    function direct(start, end, action, scope) {
        const rules = [];
        for (let n = start; n <= end;) {
            let size = 1;
            while (size < LIMIT && n % (size * 2) === 0 && size * 2 <= end - n + 1) size *= 2;
            rules.push(rule(action, n, 32 - Math.log2(size), 'Direct range block'));
            n += size;
        }
        if (action === 'permit') rules.push(rule('deny', scope.start, scope.prefix, 'Deny remaining scope'));
        return rules;
    }
    function optimize(start, end, action, scope) {
        const cache = new Map();
        function solve(n, prefix, inherited) {
            const key = `${n}/${prefix}/${inherited}`;
            if (cache.has(key)) return cache.get(key);
            const size = 2 ** (32-prefix), last = n + size - 1;
            const desired = last < start || n > end ? opposite(action) : start <= n && last <= end ? action : null;
            let best;
            if (desired !== null) best = desired === inherited ? [] : [rule(desired, n, prefix, 'Exact optimized block')];
            else {
                const half = size / 2;
                best = [...solve(n, prefix+1, inherited), ...solve(n+half, prefix+1, inherited)];
                const broad = opposite(inherited);
                const candidate = [...solve(n, prefix+1, broad), ...solve(n+half, prefix+1, broad), rule(broad, n, prefix, 'Broad fallback; exceptions first')];
                if (candidate.length < best.length) best = candidate;
            }
            cache.set(key, best);
            return best;
        }
        return solve(scope.start, scope.prefix, 'permit');
    }
    function match(rules, n) {
        return rules.find(r => n >= r.start && n < r.start + r.size)?.action || 'permit';
    }
    function verify(rules, start, end, action, scope) {
        const boundaries = new Set([0, LIMIT, scope.start, scope.end+1, start, end+1]);
        rules.forEach(r => { boundaries.add(r.start); boundaries.add(r.start+r.size); });
        for (const n of boundaries) {
            if (n === LIMIT) continue;
            const expected = n >= start && n <= end ? action : n >= scope.start && n <= scope.end ? opposite(action) : 'permit';
            if (match(rules, n) !== expected) throw new Error(`Verification failed at ${address(n)}.`);
        }
        return boundaries.size - 1;
    }
    function port(text) {
        text = String(text || '').trim();
        if (!text || text.toLowerCase() === 'any') return '';
        if (!/^\d+(?:-\d+)?$/.test(text)) throw new Error('Ports: use blank, any, 443, or 1000-2000.');
        const values = text.split('-').map(p => integer(p, 'Port', 0, 65535));
        if (values.length === 1) return `eq ${values[0]}`;
        if (values[0] > values[1]) throw new Error('Port range start must not exceed end.');
        return `range ${values[0]} ${values[1]}`;
    }
    function validate(input) {
        const c = {...input};
        if (!TYPES.includes(c.vendor) || !['Named', 'Numbered'].includes(c.mode)) throw new Error('Choose an ACL type and identifier.');
        if (!['permit', 'deny'].includes(c.action)) throw new Error('Choose permit or deny.');
        c.start = ip(c.start); c.end = ip(c.end);
        if (c.start > c.end) throw new Error('Start IP cannot exceed end IP.');
        c.scope = network(String(c.scope || '').trim() || `${address(c.start)}/24`);
        if (c.start < c.scope.start || c.end > c.scope.end) throw new Error('The range must fit inside the scope. Enter a larger Scope CIDR.');
        c.seq = integer(c.seq, 'Starting rule', 1, 65534);
        c.step = integer(c.step, 'Rule increment', 1, 65534);
        c.cisco = c.vendor.startsWith('Cisco');
        c.advanced = ['Cisco Extended', 'Huawei Advanced'].includes(c.vendor);
        if (c.mode === 'Named') {
            c.ident = String(c.name || '').trim();
            if (!/^[A-Za-z][A-Za-z0-9_-]{0,31}$/.test(c.ident)) throw new Error('ACL name: 1–32 characters, starting with a letter; letters, digits, _ and - only.');
        } else {
            const n = integer(c.number, 'ACL number', 1, 3999);
            const valid = [n >= 1 && n <= 99 || n >= 1300 && n <= 1999,
                n >= 100 && n <= 199 || n >= 2000 && n <= 2699,
                n >= 2000 && n <= 2999, n >= 3000 && n <= 3999];
            if (!valid[TYPES.indexOf(c.vendor)]) throw new Error('ACL number is outside the allowed range shown below the selector.');
            c.ident = String(n);
        }
        if (c.advanced) {
            if (!['ip', 'tcp', 'udp', 'icmp'].includes(c.protocol)) throw new Error('Choose IP, TCP, UDP or ICMP.');
            const dst = String(c.destination || '').trim();
            c.destination = network(!dst || dst.toLowerCase() === 'any' ? '0.0.0.0/0' : dst);
            c.sp = port(c.sourcePort); c.dp = port(c.destinationPort);
            if ((c.sp || c.dp) && !['tcp', 'udp'].includes(c.protocol)) throw new Error('Ports require TCP or UDP.');
        } else {
            c.protocol = 'ip'; c.destination = network('0.0.0.0/0'); c.sp = c.dp = '';
        }
        return c;
    }
    function addrToken(r, cisco) {
        if (r.prefix === 0) return 'any';
        if (cisco && r.prefix === 32) return `host ${address(r.start)}`;
        return `${address(r.start)} ${address(r.size-1)}`;
    }
    function render(rules, c) {
        if (c.seq + rules.length*c.step > 65534) throw new Error('Final rule exceeds 65534. Reduce the starting rule or increment.');
        const lines = [];
        if (c.cisco && c.mode === 'Named') lines.push(`ip access-list ${c.advanced ? 'extended' : 'standard'} ${c.ident}`);
        else if (!c.cisco) lines.push((c.mode === 'Named' ? `acl name ${c.ident} ${c.advanced ? 'advance' : 'basic'}` : `acl number ${c.ident}`) + ' match-order config');
        [...rules, null].forEach((r, index) => {
            let body;
            if (!r) body = c.cisco ? (c.advanced ? 'permit ip any any' : 'permit any') : (c.advanced ? 'permit ip source any destination any' : 'permit source any');
            else if (c.cisco) body = c.advanced ? [r.action, c.protocol, addrToken(r, true), c.sp, addrToken(c.destination, true), c.dp].filter(Boolean).join(' ') : `${r.action} ${addrToken(r, true)}`;
            else body = [r.action, c.advanced ? c.protocol : '', `source ${addrToken(r, false)}`, c.sp ? `source-port ${c.sp}` : '', c.advanced ? `destination ${addrToken(c.destination, false)}` : '', c.dp ? `destination-port ${c.dp}` : ''].filter(Boolean).join(' ');
            lines.push(c.cisco ? (c.mode === 'Named' ? ` ${c.seq+index*c.step} ${body}` : `access-list ${c.ident} ${body}`) : ` rule ${c.seq+index*c.step} ${body}`);
        });
        if (!c.cisco || c.mode === 'Named') lines.push(c.cisco ? 'exit' : 'quit');
        return lines.join('\n');
    }
    function generate(input) {
        const config = validate(input);
        const classic = direct(config.start, config.end, config.action, config.scope);
        const optimized = optimize(config.start, config.end, config.action, config.scope);
        verify(classic, config.start, config.end, config.action, config.scope);
        const checked = verify(optimized, config.start, config.end, config.action, config.scope);
        return {config, classic, optimized, checked, classicCli: render(classic, config), optimizedCli: render(optimized, config)};
    }
    const api = {ip, address, network, direct, optimize, match, verify, validate, port, render, generate};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (!root.document) return;
    function init() {
        const form = document.getElementById('acl-form');
        if (!form) return;
        const el = name => document.getElementById(`acl-${name}`);
        const value = name => el(name).value.trim();
        const clear = () => {
            el('results').hidden = true;
            el('optimized-cli').value = el('classic-cli').value = '';
            el('status').textContent = 'Settings changed. Generate commands to see the result.';
            el('status').classList.remove('acl-error');
        };
        function sync() {
            const advanced = ['Cisco Extended', 'Huawei Advanced'].includes(value('vendor'));
            const named = value('mode') === 'Named';
            el('name-group').hidden = !named; el('number-group').hidden = named;
            el('name').disabled = !named; el('number').disabled = named;
            el('advanced').hidden = !advanced;
            el('advanced').disabled = !advanced;
            const ports = advanced && ['tcp', 'udp'].includes(value('protocol'));
            el('sourcePort').disabled = el('destinationPort').disabled = !ports;
            if (!ports) el('sourcePort').value = el('destinationPort').value = '';
            el('number-hint').textContent = ['Allowed: 1–99 or 1300–1999', 'Allowed: 100–199 or 2000–2699', 'Allowed: 2000–2999', 'Allowed: 3000–3999'][TYPES.indexOf(value('vendor'))];
        }
        form.addEventListener('input', clear);
        form.addEventListener('change', event => {
            if (event.target === el('vendor')) el('number').value = defaults[TYPES.indexOf(value('vendor'))];
            sync(); clear();
        });
        function fillTable(id, rules, config) {
            const body = el(id); body.replaceChildren();
            [...rules, rule('permit', 0, 0, 'Final fallback: remaining traffic')].forEach((r, i) => {
                const tr = document.createElement('tr');
                const values = [config.cisco && config.mode === 'Numbered' ? i+1 : config.seq+i*config.step,
                    r.action.toUpperCase(), address(r.start), address(r.start+r.size-1), `${address(r.start)}/${r.prefix}`, address(r.size-1), r.size.toLocaleString(), r.reason];
                values.forEach(v => { const td = document.createElement('td'); td.textContent = v; tr.appendChild(td); });
                body.appendChild(tr);
            });
        }
        form.addEventListener('submit', event => {
            event.preventDefault(); clear();
            try {
                const input = Object.fromEntries(['vendor','mode','number','name','start','end','action','scope','seq','step','protocol','destination','sourcePort','destinationPort'].map(k => [k, value(k)]));
                const result = generate(input), c = result.config;
                el('optimized-cli').value = result.optimizedCli;
                el('classic-cli').value = result.classicCli;
                el('counts').textContent = `Optimized: ${result.optimized.length+1} rules · Classic: ${result.classic.length+1} rules · Saved: ${result.classic.length-result.optimized.length}`;
                const criteria = c.advanced ? `For ${c.protocol.toUpperCase()} traffic to ${address(c.destination.start)}/${c.destination.prefix}, source port ${c.sp || 'any'}, destination port ${c.dp || 'any'}: ` : '';
                el('policy').textContent = `${criteria}${address(c.start)}–${address(c.end)} = ${c.action.toUpperCase()}; other sources in ${address(c.scope.start)}/${c.scope.prefix} = ${opposite(c.action).toUpperCase()}. Traffic outside this scope or criteria is permitted.`;
                fillTable('optimized-table', result.optimized, c); fillTable('classic-table', result.classic, c);
                el('status').textContent = `Verified source-address policy across all IPv4 intervals (${result.checked} boundaries). Commands ready.`;
                el('results').hidden = false;
            } catch (error) {
                el('status').textContent = error.message; el('status').classList.add('acl-error');
            }
        });
        document.querySelectorAll('[data-acl-copy]').forEach(button => button.addEventListener('click', async () => {
            const target = el(button.dataset.aclCopy);
            try {
                if (navigator.clipboard && root.isSecureContext) await navigator.clipboard.writeText(target.value);
                else { target.focus(); target.select(); if (!document.execCommand('copy')) throw new Error(); }
                el('status').textContent = 'Commands copied.';
            } catch (_) { target.focus(); target.select(); el('status').textContent = 'Press Ctrl+C to copy the selected commands.'; }
        }));
        sync();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})(typeof window !== 'undefined' ? window : globalThis);
