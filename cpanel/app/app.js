'use strict';
const tg=window.Telegram?.WebApp;
tg?.ready();tg?.expand();
const $=s=>document.querySelector(s);
const money=n=>new Intl.NumberFormat('tr-TR',{style:'currency',currency:'TRY'}).format(n/100);
const labels={pending:'Bekliyor',approved:'Onaylandı',rejected:'Reddedildi',paid:'Ödendi'};
function notice(text,error=false){$('#notice').textContent=text;$('#notice').classList.toggle('error',error);}
async function api(path,body={}){
 if(!tg?.initData)throw Error('Uygulamayı Telegram botundaki düğmeden açmalısın.');
 const r=await fetch('api.php?route='+encodeURIComponent(path),{method:'POST',headers:{'Content-Type':'application/json','Authorization':'tma '+tg.initData},body:JSON.stringify(body)});
 const d=await r.json();if(!r.ok)throw Error(d.error||'İşlem başarısız');return d;
}
function node(tag,text,cls){const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;}
function list(selector,rows,render){const host=$(selector);host.replaceChildren();if(!rows.length)host.append(node('div','Henüz kayıt yok.','empty'));else rows.forEach(r=>host.append(render(r)));}
function card(row,title){const e=node('article',undefined,'item');e.append(node('span',labels[row.status]||row.status,'status'),node('strong',title));return e;}
function proof(e,url){if(/^[^\s@]+@gmail\.com$/i.test(url)){e.append(node('p',url));return;}try{const u=new URL(url);if(u.protocol!=='https:'||u.username||u.password)throw Error();const a=node('a','Kanıt bağlantısını aç ↗');a.href=u.href;a.target='_blank';a.rel='noopener noreferrer';e.append(node('p',url),a);}catch{e.append(node('p','Geçersiz kanıt bağlantısı'));}}
function cents(value){if(!/^\d+(\.\d{1,2})?$/.test(value))throw Error('Geçerli bir tutar gir.');const [a,b='']=value.split('.');const n=Number(a)*100+Number(b.padEnd(2,'0'));if(!Number.isSafeInteger(n)||n<=0||n>100000000)throw Error('Tutar sınırını kontrol et.');return n;}
function passwordControl(parent,row){
 if(!row.has_password)return;
 const box=node('div',undefined,'secret-panel');const b=node('button','Şifreyi göster');b.type='button';
 b.onclick=async()=>{b.disabled=true;try{const data=await api('admin/password',{id:row.id});const input=node('input');input.type='text';input.readOnly=true;input.value=data.password;input.setAttribute('aria-label','Başvuru şifresi');box.replaceChildren(input);const hide=node('button','Gizle');hide.type='button';const clear=()=>{input.value='';box.replaceChildren(b);b.disabled=false;};hide.onclick=clear;box.append(hide);setTimeout(clear,30000);}catch(e){notice(e.message,true);b.disabled=false;}};
 box.append(b);parent.append(box);
}
function paymentDetails(e,r){
 showRejection(e,r);
 e.append(node('p',r.network==='TRC20'?'USDT · TRC20':'Eski ödeme talebi'),node('p',r.destination));
 if(r.sent_usdt)e.append(node('p',`Gönderilen: ${r.sent_usdt} USDT`));
 if(r.txid)e.append(node('p',`TXID: ${r.txid}`));
}
function rejectionInput(e){const label=node('label','Ret gerekçesi');const input=node('textarea');input.maxLength=500;input.placeholder='Reddediyorsan nedenini yaz (3–500 karakter)';label.append(input);e.append(label);return input;}
function rejectionText(input){const reason=input.value.trim();if(Array.from(reason).length<3||Array.from(reason).length>500)throw Error('Ret gerekçesi 3–500 karakter olmalı.');return reason;}
function showRejection(e,row){if(row.rejection_reason)e.append(node('p',`Ret gerekçesi: ${row.rejection_reason}`));}
let state;
async function load(){state=await api('state');$('#demo-banner').hidden=!state.demo;$('#welcome').textContent=`Merhaba, ${state.name}`;$('#balance').textContent=money(state.balance);$('#storage-status').textContent=['postgresql','mysql'].includes(state.storage)?'Kalıcı veritabanı bağlı':'Geçici depolama · kayıtlar silinebilir';$('#admin-tab').hidden=!state.admin;
 list('#submissions',state.submissions,r=>{const e=card(r,`Başvuru #${r.id}`);proof(e,r.proof);if(r.amount)e.append(node('p',money(r.amount)));showRejection(e,r);return e;});
 list('#withdrawals',state.withdrawals,r=>{const e=card(r,`Talep #${r.id} · ${money(r.amount)}`);paymentDetails(e,r);return e;});
 if(state.admin){list('#pending-submissions',state.pending_submissions,r=>{const e=card(r,`#${r.id} · ${r.user_id}`);proof(e,r.proof);passwordControl(e,r);const input=node('input');input.type='number';input.step='0.01';input.min='0.01';input.placeholder='Onay tutarı (TL)';input.value='10.00';input.setAttribute('aria-label','Onay tutarı (TL)');e.append(input);const reason=rejectionInput(e);const actions=node('div',undefined,'actions');actions.append(action('Onayla',()=>api('admin/submission',{id:r.id,action:'approve',amount:cents(input.value)})),action('Reddet',()=>api('admin/submission',{id:r.id,action:'reject',reason:rejectionText(reason)}),true));e.append(actions);return e;});
 list('#reviewed-submissions',state.reviewed_submissions||[],r=>{const e=card(r,`#${r.id} · ${r.user_id}`);proof(e,r.proof);passwordControl(e,r);showRejection(e,r);return e;});
 list('#pending-withdrawals',state.pending_withdrawals,r=>{
  const e=card(r,`#${r.id} · ${money(r.amount)}`);e.append(node('p',`Kullanıcı: ${r.user_id}`));paymentDetails(e,r);
  let amountInput,txInput;
  if(r.network==='TRC20'){
   const label=node('label','Gönderilen USDT tutarı');amountInput=node('input');amountInput.type='text';amountInput.inputMode='decimal';amountInput.placeholder='Örn. 3.25';label.append(amountInput);e.append(label);
   const txLabel=node('label','Transfer işlem kimliği (TXID)');txInput=node('input');txInput.type='text';txInput.maxLength=64;txInput.placeholder='64 karakterlik işlem kimliği';txLabel.append(txInput);e.append(txLabel);
  }
  const reason=rejectionInput(e);const actions=node('div',undefined,'actions');actions.append(action('Ödendi',async()=>{
   const data={id:r.id,action:'paid'};
   if(r.network==='TRC20'){
    data.sent_usdt=amountInput.value.trim().replace(',','.');data.txid=txInput.value.trim();
    if(!/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/.test(data.sent_usdt)||!/[1-9]/.test(data.sent_usdt))throw Error('Gönderilen USDT tutarını gir.');
    if(!/^[a-fA-F0-9]{64}$/.test(data.txid))throw Error('64 karakterlik TXID gir.');
   }
   if(!confirm('TRC20 transferini tamamladın mı? Bu düğme para göndermez; yapılmış ödemeyi kaydeder.'))return false;
   return api('admin/withdrawal',data);
  }),action('Reddet ve iade et',()=>api('admin/withdrawal',{id:r.id,action:'reject',reason:rejectionText(reason)}),true));e.append(actions);return e;
 });}

}
function action(title,fn,danger=false){const b=node('button',title,danger?'danger':'');b.onclick=async()=>{b.disabled=true;try{if(await fn()===false)return;await load();notice('İşlem tamamlandı.');}catch(e){notice(e.message,true);}finally{b.disabled=false;}};return b;}
document.querySelectorAll('[data-tab]').forEach(b=>b.onclick=()=>{document.querySelectorAll('.page').forEach(p=>p.hidden=p.id!==b.dataset.tab);document.querySelectorAll('[data-tab]').forEach(x=>x.classList.toggle('active',x===b));});
function bind(selector,path,build){const form=$(selector);let rid=null;form.addEventListener('input',()=>rid=null);form.onsubmit=async e=>{e.preventDefault();const b=form.querySelector('button:not([type="button"])');b.disabled=true;try{rid??=crypto.randomUUID();const data=build(new FormData(form));await api(path,{...data,request_id:rid});rid=null;form.reset();if(selector==='#submission-form'){$('#account-password').type='password';$('#toggle-password').textContent='Göster';}await load();notice('İşlem tamamlandı.');}catch(err){notice(err.message,true);}finally{b.disabled=false;}};}
bind('#submission-form','submit',d=>{const email=d.get('proof').trim().toLowerCase();if(!/^[a-z0-9]+(?:\.[a-z0-9]+)*(?:\+[a-z0-9._-]+)?@gmail\.com$/.test(email))throw Error('Yalnızca geçerli bir @gmail.com adresi gönder.');return {proof:email,password:d.get('password'),consent:d.get('consent')==='on'};});
bind('#withdraw-form','withdraw',d=>{const address=d.get('destination').trim();if(!/^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(address))throw Error('Geçerli bir TRC20 cüzdan adresi gir.');const amount=cents(d.get('amount'));if(amount<10000)throw Error('Minimum çekim tutarı 100 TL’dir.');return {amount,destination:address};});
bind('#credit-form','admin/credit',d=>({amount:cents(d.get('amount')),user_id:Number(d.get('user_id'))}));
$('#refresh').onclick=()=>load().then(()=>notice('Bilgiler güncellendi.')).catch(e=>notice(e.message,true));
load().then(()=>notice('Hesabın güncel.')).catch(e=>notice(e.message,true));

$('#import-form').onsubmit=async e=>{e.preventDefault();const f=e.currentTarget;const button=f.querySelector('button');button.disabled=true;try{const file=f.querySelector('input').files[0];if(!file||file.size>2000000)throw Error('En fazla 2 MB yedek seçin.');if(!confirm('Boş veritabanına yedek aktarılsın mı?'))return;await api('admin/import',{backup:JSON.parse(await file.text())});await load();notice('Yedek aktarıldı. Bakiyeleri kontrol edin.');}catch(err){notice(err.message,true);}finally{button.disabled=false;}};

$('#toggle-password').onclick=()=>{const input=$('#account-password');const show=input.type==='password';input.type=show?'text':'password';$('#toggle-password').textContent=show?'Gizle':'Göster';};
