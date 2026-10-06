const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const context = vm.createContext({window:{handoverConfig:{overdueMinutes:30},addEventListener(){}},document:{addEventListener(){}},Date});
vm.runInContext(fs.readFileSync('handover.js','utf8'),context);
test('legacy records retain the subject and completion state',()=>{
  const item = context.normalizedHandover({subject:'Customer thread',status:'DONE',vendor:'Netsat'});
  assert.equal(item.subjects[0],'Customer thread'); assert.equal(item.status,'RESOLVED'); assert.equal(item.nms,'NA');
});
test('all email subjects are exported in order with NMS first',()=>{
  const item=context.normalizedHandover({subjects:['Customer','Vendor','Vendor ticket'],status:'OPEN',followup:'vendor',party:'Cybernet',comment:'Restored | RCA awaited',ticket:'TEST-123'});
  const text=context.handoverText([item]);
  assert.equal(text,'NMS label: NA\nEmail Subject: Customer\nEmail Subject: Vendor\nEmail Subject: Vendor ticket\nCurrent status: TEST-123 || Open || Follow up with Cybernet || Restored | RCA awaited');
});
test('resolved CE issue is distinct from open restoration / RCA',()=>{
  assert.equal(context.handoverCurrent({status:'RESOLVED',comment:'CE issue'}),'NA || Resolved || CE issue');
  assert.equal(context.handoverCurrent({status:'OPEN',followup:'customer',comment:'CE traces awaited'}),'NA || Open || Follow up with customer || CE traces awaited');
});
test('timer crosses the default 30 minute boundary and survives reload',()=>{
  const start='2026-10-06T00:00:00Z';
  assert.equal(context.handoverAge(start,Date.parse(start)+1799000).sec,1799);
  assert.equal(context.handoverAge(start,Date.parse(start)+1800000).sec,1800);
});
