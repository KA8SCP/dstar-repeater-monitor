const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname,'../index.php'), 'utf8');
const script = source.split('<script>')[1].split('</script>')[0]
 .replace(/<\?=json_encode\(\$initial,[\s\S]*?\?>/, '{reflectors:[],last_heard:[],summary:{total:0,online:0,offline:0,users:0,modules:0}}')
 .replace(/<\?=REFRESH_SECONDS\*1000\?>/, '15000');
const elements = new Map();
const context = {console, Date, Set, Notification:{permission:'denied'},
 document:{querySelector(id){if(!elements.has(id))elements.set(id,{value:'',style:{},addEventListener(){}});return elements.get(id);}},
 window:{},setInterval(){},setTimeout(){},clearTimeout(){},AbortController,
 fetch:async()=>({ok:true,json:async()=>({enabled:false})})};
vm.createContext(context);
vm.runInContext(script, context);
const bm = context.card({name:'WB1GOF DMR 312543',type:'BRANDMEISTER',host:'brandmeister.network',online:true,radio:{'Color code':0},talkgroups:[{slot:1,talkgroup:'3125',kind:'Static'}],url:'https://brandmeister.network/#/device/312543'});
assert(bm.includes('DMR / BrandMeister') && bm.includes('3125') && !bm.includes('DPLUS Modules'));
assert(bm.includes('>0</span>'));
const pi=context.card({name:'W1ATD Multimode',type:'PISTAR',online:true,modes:['DMR'],last_heard:[{callsign:'<script>alert(1)</script>',mode:'DMR TS2',target:'TG 3125'}]});
assert(pi.includes('DMR TS2') && pi.includes('TG 3125') && !pi.includes('<script>'));
const old=context.card({name:'WB1GOF',type:'DPLUS_GATEWAY',online:true,modules:[],users:[]});
assert(old.includes('DPLUS Gateway') && old.includes('DPLUS Remote Users'));
console.log('PASS: frontend rendering, DMR/Pi-Star fields, HTML escaping, zero values, and original DPLUS cards');

assert(pi.includes('Gateway Activity') && pi.includes('Local RF Activity'));
assert(source.includes('<title>Digital Repeater Monitor</title>'));

assert(bm.includes('Open BrandMeister Last Heard'));

assert(!source.includes('Reported Users') && !source.includes('Reported Modules'));
assert(source.includes('Time (Eastern)') && source.includes('x.display_time'));
assert(source.includes('Page Viewers'));
console.log('PASS: simplified summary, normalized network time, and Page Viewers panel');

assert.equal(context.easternTime('2026-09-30T16:00:00Z'),'2026-09-30 12:00:00 EDT');
assert.equal(context.easternTime('2026-01-15T17:00:00Z'),'2026-01-15 12:00:00 EST');
for (const type of ['BRANDMEISTER','PISTAR','DPLUS_GATEWAY']) {
 const row={callsign:'A1AAA',time:'2026-09-30T16:00:00Z',display_time:'2026-09-30 12:00:00 EDT'};
 const html=context.card({name:'Test',type,online:true,last_heard:[row],dplus_last_heard:[row],g2_link_version:'4',g2_last_heard:[row],local_rf_activity:[row],last_seen_display:row.display_time});
 assert(html.includes(row.display_time) && !html.includes(row.time));
 assert(!html.includes('Time (UTC)'));
}
console.log('PASS: all card activity renders Eastern timestamps; summer and winter labels correct');

assert(!/[\u00c2\u00c3\u00e2\u00f0]/.test(source), 'No encoding corruption in page source');
console.log('PASS: page source retains correct UTF-8 characters');
