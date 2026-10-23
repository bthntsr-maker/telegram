'use strict';
const tg=window.Telegram?.WebApp;
tg?.ready();tg?.expand();
const $=s=>document.querySelector(s);
const money=n=>new Intl.NumberFormat('tr-TR',{style:'currency',currency:'TRY'}).format(n/100);
const labels={pending:'Bekliyor',approved:'Onaylandı',rejected:'Reddedildi',paid:'Ödendi'};
function notice(text,error=false){$('#notice').textContent=text;$('#notice').classList.toggle('error',error);}
async function api(path,body={}){
 if(!tg?.initData)throw Error('Uygulamayı Telegram botundaki düğmeden açmalısın.');
 const r=await fetch('/api/'+path,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'tma '+tg.initData},body:JSON.stringify(body)});
 const d=await r.json();if(!r.ok)throw Error(d.error||'İşlem başarısız');return d;
}
function node(tag,text,cls){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;}
function list(selector,rows,render){const host=$(selector);host.replaceChildren();if(!rows.length)host.append(node('div','Henüz kayıt yok.','empty'));else rows.forEach(r=>host.append(render(r)));}
function card(row,title){const e=node('article',undefined,'item');e.append(node('span',labels[row.status]||row.status,'status'),node('strong',title));return e;}
function proof(e,url){try{const u=new URL(url);if(u.protocol!=='https:'||u.username||u.password)throw Error();const a=node('a','Kanıt bağlantısını aç ↗');a.href=u.href;a.target='_blank';a.rel='noopener noreferrer';e.append(node('p',url),a);}catch{e.append(node('p','Geçersiz kanıt bağlantısı'));}}
function cents(value){if(!/^\d+(\.\d{1,2})?$/.test(value))throw Error('Geçerli bir tutar gir.');const [a,b='']=value.split('.');const n=Number(a)*100+Number(b.padEnd(2,'0'));if(!Number.isSafeInteger(n)||n<=0||n>100000000)throw Error('Tutar sınırını kontrol et.');return n;}
let state;
async function load(){state=await api('state');$('#demo-banner').hidden=!state.demo;$('#welcome').textContent=`Merhaba, ${state.name}`;$('#balance').textContent=money(state.balance);$('#storage-status').textContent=state.storage==='postgresql'?'Kalıcı veritabanı bağlı':'Geçici depolama · kayıtlar silinebilir';$('#admin-tab').hidden=!state.admin;
 list('#submissions',state.submissions,r=>{const e=card(r,`Başvuru #${r.id}`);proof(e,r.proof);if(r.amount)e.append(node('p',money(r.amount)));return e;});
 list('#withdrawals',state.withdrawals,r=>{const e=card(r,`Talep #${r.id} · ${money(r.amount)}`);e.append(node('p',r.destination));return e;});
 if(state.admin){list('#pending-submissions',state.pending_submissions,r=>{const e=card(r,`#${r.id} · ${r.user_id}`);proof(e,r.proof);const input=node('input');input.type='number';input.step='0.01';input.min='0.01';input.placeholder='Onay tutarı (TL)';input.setAttribute('aria-label','Onay tutarı (TL)');e.append(input);const actions=node('div',undefined,'actions');actions.append(action('Onayla',()=>api('admin/submission',{id:r.id,action:'approve',amount:cents(input.value)})),action('Reddet',()=>api('admin/submission',{id:r.id,action:'reject'}),true));e.append(actions);return e;});
 list('#pending-withdrawals',state.pending_withdrawals,r=>{const e=card(r,`#${r.id} · ${money(r.amount)}`);e.append(node('p',`Kullanıcı: ${r.user_id}`),node('p',r.destination));const actions=node('div',undefined,'actions');actions.append(action('Ödendi',async()=>{if(!confirm('Gerçek para transferini tamamladın mı?'))return false;return api('admin/withdrawal',{id:r.id,action:'paid'});}),action('Reddet ve iade et',()=>api('admin/withdrawal',{id:r.id,action:'reject'}),true));e.append(actions);return e;});}
}
function action(title,fn,danger=false){const b=node('button',title,danger?'danger':'');b.onclick=async()=>{b.disabled=true;try{if(await fn()===false)return;await load();notice('İşlem tamamlandı.');}catch(e){notice(e.message,true);}finally{b.disabled=false;}};return b;}
document.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{document.querySelectorAll('.page').forEach(p=>p.hidden=p.id!==b.dataset.tab);document.querySelectorAll('[data-tab]').forEach(x=>x.classList.toggle('active',x===b));});
function bind(selector,path,build){const form=$(selector);let rid=null;form.addEventListener('input',()=>rid=null);form.onsubmit=async e=>{e.preventDefault();const b=form.querySelector('button');b.disabled=true;try{rid??=crypto.randomUUID();const data=build(new FormData(form));await api(path,{...data,request_id:rid});rid=null;form.reset();await load();notice('İşlem tamamlandı.');}catch(err){notice(err.message,true);}finally{b.disabled=false;}};}
bind('#submission-form','submit',d=>({proof:d.get('proof').trim()}));
bind('#withdraw-form','withdraw',d=>({amount:cents(d.get('amount')),destination:d.get('destination')}));
bind('#credit-form','admin/credit',d=>({amount:cents(d.get('amount')),user_id:Number(d.get('user_id'))}));
$('#refresh').onclick=()=>load().then(()=>notice('Bilgiler güncellendi.')).catch(e=>notice(e.message,true));
load().then(()=>notice('Hesabın güncel.')).catch(e=>notice(e.message,true));

$('#export-backup').onclick=async()=>{const b=$('#export-backup');b.disabled=true;try{const data=await api('admin/export');const url=URL.createObjectURL(new Blob([JSON.stringify(data)],{type:'application/json'}));const a=node('a');a.href=url;a.download='telegram-yedek.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);notice('Yedek indirildi. Güvenli saklayın.');}catch(e){notice(e.message,true);}finally{b.disabled=false;}};
