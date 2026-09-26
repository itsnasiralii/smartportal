'use strict';
const assert = require('node:assert/strict');
const acl = require('../acl_commands.js');
const base = {vendor:'Cisco Standard', mode:'Numbered', number:4, name:'CNOC_ACL',
    start:'192.168.1.7', end:'192.168.1.253', scope:'', action:'deny', seq:5, step:5,
    protocol:'ip', destination:'any', sourcePort:'', destinationPort:''};
let count = 0;
// Independently evaluate every address for boundary cases and deterministic ranges.
let seed = 41;
const random = () => { seed = (seed*1664525+1013904223) >>> 0; return seed % 256; };
const ranges = [[7,253],[14,30],[14,130],[0,255],[0,0],[255,255]];
for(let i=0;i<100;i++) ranges.push([random(),random()].sort((a,b)=>a-b));
for (const [lo,hi] of ranges) for (const action of ['permit','deny']) {
    const result = acl.generate({...base,start:`192.168.1.${lo}`,end:`192.168.1.${hi}`,action});
    assert.ok(result.optimized.length <= result.classic.length);
    for(const rules of [result.classic,result.optimized]) {
        for(let n=0;n<256;n++) {
            const addr = acl.ip(`192.168.1.${n}`);
            let actual = 'permit';
            for(const rule of rules) if(addr >= rule.start && addr < rule.start+rule.size) {actual=rule.action;break;}
            assert.equal(actual, n>=lo && n<=hi ? action : action==='deny'?'permit':'deny');
        }
        assert.equal(acl.match(rules,acl.ip('192.168.2.0')),'permit');
    }
    count++;
}
// /0 and IPv4 end boundary must not overflow signed 32-bit arithmetic.
for(const [start,end] of [['0.0.0.0','255.255.255.255'],['0.0.0.0','0.0.0.0'],['255.255.255.255','255.255.255.255']]) {
    const r=acl.generate({...base,start,end,scope:'0.0.0.0/0'});
    assert.equal(acl.match(r.optimized,acl.ip(start)),'deny');
}
const types=['Cisco Standard','Cisco Extended','Huawei Basic','Huawei Advanced'];
const numbers=[4,100,2000,3000];
for(const [i,vendor] of types.entries()) for(const mode of ['Named','Numbered']) {
    const result=acl.generate({...base,vendor,mode,number:numbers[i],protocol:'tcp',destination:'10.0.0.5',destinationPort:'443'});
    const advanced = i===1 || i===3;
    assert.equal(result.optimizedCli.includes('443'),advanced);
    if(i===1) assert.ok(result.optimizedCli.includes('host 10.0.0.5 eq 443'));
    if(i===3) assert.ok(result.optimizedCli.includes('destination 10.0.0.5 0.0.0.0 destination-port eq 443'));
    if(i>=2) assert.ok(result.optimizedCli.includes('match-order config'));
    if(mode==='Named') assert.ok(result.optimizedCli.includes('CNOC_ACL'));
}
assert.equal(acl.generate({...base,start:'192.168.1.14',end:'192.168.1.15'}).classicCli,
    'access-list 4 deny 192.168.1.14 0.0.0.1\naccess-list 4 permit any');
assert.equal(acl.generate({...base,vendor:'Cisco Extended',mode:'Named',protocol:'tcp',destination:'10.0.0.5',destinationPort:'443',start:'192.168.1.14',end:'192.168.1.15'}).classicCli,
    'ip access-list extended CNOC_ACL\n 5 deny tcp 192.168.1.14 0.0.0.1 host 10.0.0.5 eq 443\n 10 permit ip any any\nexit');
for(const update of [{start:'256.1.1.1'}, {end:'192.168.0.1'}, {scope:'10.0.0.0/8'},
    {scope:'0.0.0.0/33'}, {number:100}, {seq:0}, {step:0}, {seq:65534},
    {mode:'Named',name:'bad\nquit'}, {vendor:'Cisco Extended',number:100,protocol:'icmp',destinationPort:'80'}]) {
    assert.throws(()=>acl.generate({...base,...update}));
}
for(const p of ['-1','65536','90-80','22;quit']) assert.throws(()=>acl.port(p));
console.log(`PASS: ${count} full /24 policy checks, all 8 ACL variants, exact CLI fixtures, /0 boundaries and validation.`);
