'use strict';
function rtqExtractId(label) {
  const ids = String(label).match(/\b(?:LNK[A-Z]{2,5}\d+|(?:BGPDIA|DIA|DPLC|MPLS)\d+SL\d+)\b/gi) || [];
  // Underscores are word characters: support the usual NMS separator explicitly.
  const tokens = String(label).split(/[\s_]+/).filter(x => /^(?:LNK[A-Z]{2,5}\d+|(?:BGPDIA|DIA|DPLC|MPLS)\d+SL\d+)$/i.test(x));
  const unique = [...new Set([...ids,...tokens].map(x=>x.toUpperCase()))];
  return unique.length === 1 ? unique[0] : '';
}
function rtqCsv(rows, fields) {
 const cell = value => { let s=String(value ?? ''); if (/^[=+@\-\t\r]/.test(s)) s="'"+s; return '"'+s.replace(/"/g,'""')+'"'; };
 return '\uFEFF'+[fields,...rows.map(r=>fields.map(k=>k==='Database TT'?r.id:r.data[k]??''))].map(r=>r.map(cell).join(',')).join('\r\n');
}
if (typeof module !== 'undefined') module.exports={rtqExtractId,rtqCsv};
if (typeof document !== 'undefined') {
 const $=id=>document.getElementById(id), boot=window.rtqBoot, inputs={};
 let config=boot.config, records=[], editing=null, busy=false;
 const message=(s,error=false)=>{ $('rtq-message').textContent=s; $('rtq-message').style.color=error?'#b91c1c':'#0f766e'; };
 for(const key of boot.fields){
  const wrap=document.createElement('div'), label=document.createElement('label');
  const input=document.createElement(['Issue Found At','SLA Sheet Working','RCA','Status'].includes(key)?'select':'input');
  input.id='rtq-field-'+Object.keys(inputs).length; label.htmlFor=input.id; label.textContent=key;
  if(key.includes('Date')) input.type='datetime-local';
  if(['CSP TT','Issue Found At','SLA Sheet Working','RCA','Status'].includes(key)) input.required=true;
  input.maxLength=4000; wrap.append(label,input); $('rtq-fields').append(wrap); inputs[key]=input;
 }
 function options(){
  for(const key of ['Issue Found At','SLA Sheet Working','RCA','Status']) {
   const old=inputs[key].value; inputs[key].replaceChildren(new Option('Select…',''));
   for(const value of config[key]) inputs[key].add(new Option(value,value));
   if(old&&!config[key].includes(old)) inputs[key].add(new Option(old,old));
   inputs[key].value=old;
  }
  $('rtq-preset').replaceChildren(new Option('Select a case',''));
  config.presets.forEach((p,i)=>$('rtq-preset').add(new Option(p.name,String(i))));
  if($('rtq-settings')) $('rtq-settings').value=JSON.stringify(config,null,2);
 }
 async function request(body){
  const response=await fetch(body?'rtq.php':'rtq.php?data=1',body?{method:'POST',headers:{'Content-Type':'application/json','X-NOC-CSRF':boot.csrf},body:JSON.stringify(body)}:{});
  let result;try{result=await response.json();}catch{throw Error('Session expired or server unavailable. Reload and sign in again.');}
  if(!response.ok||result.error)throw Error(result.error||'Request failed');
  records=result.records;config=result.config;options();render();
 }
 function filtered(){const term=$('rtq-search').value.toLowerCase();return records.filter(r=>JSON.stringify(r.data).toLowerCase().includes(term));}
 function render(){
  $('rtq-count').textContent=`(${filtered().length} / ${records.length})`;
  $('rtq-records').replaceChildren();
  for(const row of filtered()){
   const tr=document.createElement('tr'), d=row.data;
   for(const value of [row.id,d['CSP TT']+' / '+d['NMS User Label'],d['Issue Found At'],d['SLA Sheet Working'],d.RCA+' / '+d['Teams Involved in TroubleShooting'],d.Status]){const td=document.createElement('td');td.textContent=value;tr.append(td);}
   const td=document.createElement('td'), edit=document.createElement('button');edit.textContent='Edit';edit.type='button';edit.className='btn-secondary';edit.onclick=()=>{
    editing=row;for(const key of boot.fields){const value=d[key]||'';if(inputs[key].tagName==='SELECT'&&value&&![...inputs[key].options].some(o=>o.value===value))inputs[key].add(new Option(value,value));inputs[key].value=value;}
    $('rtq-bulk').value='';$('rtq-bulk').disabled=true;message('Editing Database TT '+row.id);$('rtq-form').scrollIntoView({behavior:'smooth'});
   };td.append(edit);tr.append(td);$('rtq-records').append(tr);
  }
 }
 function clear(){editing=null;$('rtq-form').reset();$('rtq-bulk').disabled=false;$('rtq-preset').value='';}
 $('rtq-apply').onclick=()=>{const p=config.presets[$('rtq-preset').value];if(!p)return;for(const [key,value]of Object.entries(p))if(inputs[key])inputs[key].value=value;message('Case applied. Review status and dates before saving.');};
 $('rtq-clear').onclick=clear;$('rtq-search').oninput=render;
 inputs['NMS User Label'].addEventListener('change',()=>{if(!inputs.UniqueID.value)inputs.UniqueID.value=rtqExtractId(inputs['NMS User Label'].value);});
 $('rtq-form').onsubmit=async event=>{
  event.preventDefault();if(busy)return;
  const data=Object.fromEntries(boot.fields.map(k=>[k,inputs[k].value.trim()]));
  const labels=[...new Set($('rtq-bulk').value.split(/\r?\n/).map(s=>s.trim()).filter(Boolean))];
  if(!labels.length&&!data['NMS User Label'])return message('Enter an NMS label or bulk labels.',true);
  const batch=labels.length?labels.map(label=>({data:{...data,'NMS User Label':label,UniqueID:rtqExtractId(label)}})):[{id:editing?.id,version:editing?.version,data}];
  if(batch.some(r=>!r.data.UniqueID)&&!confirm('Some links have no unambiguous UniqueID. Save with the ID blank for manual review?'))return;
  busy=true;$('rtq-save').disabled=true;
  try{await request({action:'save',records:batch});clear();message(`${batch.length} record(s) saved.`);}catch(e){message(e.message,true);}finally{busy=false;$('rtq-save').disabled=false;}
 };
 function download(content,type,name){const url=URL.createObjectURL(new Blob([content],{type}));const a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
 $('rtq-export').onclick=()=>download(rtqCsv(filtered(),['Database TT',...boot.fields]),'text/csv;charset=utf-8','RTQ.csv');
 $('rtq-backup').onclick=()=>download(JSON.stringify({records,config},null,2),'application/json','RTQ-backup.json');
 if($('rtq-settings-save'))$('rtq-settings-save').onclick=async()=>{try{await request({action:'settings',config:JSON.parse($('rtq-settings').value)});message('RTQ settings saved.');}catch(e){message(e.message,true);}};
 options();request().catch(e=>message(e.message,true));
}
