<?php
require dirname(__DIR__).'/app/api.php';
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function callApi(string $path,array $body=[],int $uid=123):array {
    db()->beginTransaction();
    try{$result=dispatch($path,['id'=>$uid,'first_name'=>'Test'],$body);db()->commit();return $result;}
    catch(Throwable $e){db()->rollBack();throw $e;}
}
initialize();ensureFeatures();
$proof=['proof'=>'test.user@gmail.com','password'=>'only-a-test-secret','consent'=>true,'request_id'=>'submission-test-12345'];
foreach(['user@example.com','user@gmail.com.evil','https://gmail.com','x@@gmail.com','a..b@gmail.com','a@gmail.com'."\nextra",''] as $invalid){
    try{callApi('submit',['proof'=>$invalid,'request_id'=>'invalid-email-12345']);throw new LogicException('invalid accepted');}catch(InvalidArgumentException){}
}
echo "PASS non-Gmail and malformed addresses rejected\n";
foreach([['proof'=>'valid.user@gmail.com','request_id'=>'no-password-1234567','consent'=>true],['proof'=>'valid.user@gmail.com','password'=>'fixture-only','request_id'=>'no-consent-12345678']] as $missing){
    try{callApi('submit',$missing);throw new LogicException('missing secret/consent accepted');}catch(InvalidArgumentException){}
}
echo "PASS password and explicit consent required\n";

$normalized=callApi('submit',['proof'=>'  Test.User@GMAIL.COM  ','password'=>'only-a-test-secret','consent'=>true,'request_id'=>'normalized-email-12345']);
check(query('SELECT proof FROM submissions WHERE id=?',[$normalized['id']])->fetchColumn()==='test.user@gmail.com','Gmail case and whitespace normalized');
$s=callApi('submit',$proof);check(callApi('submit',$proof)['id']===$s['id'],'submission retry');
try{callApi('admin/credit',['user_id'=>123,'amount'=>10000,'request_id'=>'credit-test-1234567']);throw new RuntimeException('permission failed');}catch(DomainException){echo "PASS admin authorization\n";}
callApi('admin/submission',['id'=>$s['id'],'action'=>'approve','amount'=>25000],999);
check(callApi('state')['balance']===25000,'approval balance');
try{callApi('admin/submission',['id'=>$s['id'],'action'=>'approve','amount'=>25000],999);throw new RuntimeException('duplicate approved');}catch(InvalidArgumentException){echo "PASS duplicate approval blocked\n";}
$beforeBalance=callApi('state')['balance'];$beforeCount=(int)query('SELECT COUNT(*) FROM withdrawals')->fetchColumn();
foreach([1,9999] as $below){
    try{callApi('withdraw',['amount'=>$below,'destination'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','request_id'=>'below-minimum-123456']);throw new LogicException('below minimum accepted');}catch(InvalidArgumentException $e){check(str_contains($e->getMessage(),'100 TL'),'below minimum rejected by minimum rule');}
}
check(callApi('state')['balance']===$beforeBalance && (int)query('SELECT COUNT(*) FROM withdrawals')->fetchColumn()===$beforeCount,'rejected minimum does not change balance or create request');
$boundary=callApi('withdraw',['amount'=>10000,'destination'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','request_id'=>'exact-minimum-123456']);
check(callApi('state')['balance']===$beforeBalance-10000,'exactly 100 TL accepted');
callApi('admin/withdrawal',['id'=>$boundary['id'],'action'=>'reject'],999);
check(callApi('state')['balance']===$beforeBalance,'100 TL rejection refunds reservation');
$body=['amount'=>20000,'destination'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','request_id'=>'withdraw-test-123456'];
$w=callApi('withdraw',$body);check(callApi('withdraw',$body)['id']===$w['id'],'withdrawal retry');
check(callApi('state')['balance']===5000,'funds reserved');
try{callApi('withdraw',array_replace($body,['request_id'=>'withdraw-other-1234']));throw new RuntimeException('overspent');}catch(InvalidArgumentException){echo "PASS insufficient funds\n";}
callApi('admin/withdrawal',['id'=>$w['id'],'action'=>'reject'],999);
check(callApi('state')['balance']===25000,'refund');
$c=['user_id'=>123,'amount'=>10000,'request_id'=>'credit-test-1234567'];callApi('admin/credit',$c,999);callApi('admin/credit',$c,999);
check(callApi('state')['balance']===35000,'credit once');
check(count(callApi('state',[],456)['submissions'])===0,'user isolation');
check(!str_contains(json_encode(callApi('state')), 'only-a-test-secret'),'password absent from normal state');
try{callApi('admin/password',['id'=>$s['id']]);throw new LogicException('password exposed');}catch(DomainException){echo "PASS password admin authorization\n";}
check(callApi('admin/password',['id'=>$s['id']],999)['password']==='only-a-test-secret','admin can decrypt password');
$encrypted=query('SELECT ciphertext FROM submission_credentials WHERE submission_id=?',[$s['id']])->fetchColumn();
check(!str_contains($encrypted,'only-a-test-secret'),'encrypted at rest');
try{decryptPassword($encrypted,$s['id']+1);throw new LogicException('wrong AAD accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;echo "PASS ciphertext bound to submission\n";}
check(tronAddress('T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb'),'TRON checksum valid');
check(!tronAddress('T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwa'),'TRON checksum rejects changed address');
try{callApi('withdraw',['amount'=>10000,'destination'=>'0x1234','request_id'=>'wrong-address-12345']);throw new LogicException('wrong network accepted');}catch(InvalidArgumentException){echo "PASS invalid wallet rejected\n";}
$paid=callApi('withdraw',['amount'=>10000,'destination'=>'T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb','request_id'=>'paid-usdt-12345678']);
try{callApi('admin/withdrawal',['id'=>$paid['id'],'action'=>'paid'],999);throw new LogicException('payment without TXID');}catch(InvalidArgumentException){echo "PASS paid requires amount and TXID\n";}
callApi('admin/withdrawal',['id'=>$paid['id'],'action'=>'paid','sent_usdt'=>'0.125','txid'=>str_repeat('a',64)],999);
$history=callApi('state')['withdrawals'];
check($history[0]['sent_usdt']==='0.125' && $history[0]['txid']===str_repeat('a',64),'crypto payment details retained');
check(callApi('state')['balance']===25000,'paid reservation not charged twice');
$data=['auth_date'=>(string)time(),'user'=>json_encode(['id'=>123])];ksort($data);$lines=[];foreach($data as $k=>$v)$lines[]=$k.'='.$v;
$key=hash_hmac('sha256',config()['bot_token'],'WebAppData',true);$data['hash']=hash_hmac('sha256',implode("\n",$lines),$key);
check(authenticate(http_build_query($data))['id']===123,'signed Telegram identity');
$data['hash']=str_repeat('0',64);try{authenticate(http_build_query($data));throw new LogicException('forged auth accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;echo "PASS forged authentication rejected\n";}
$backup=['format'=>'telegram-wallet-v1','tables'=>[]];foreach(['users','submissions','withdrawals','credits','web_submissions'] as $t)$backup['tables'][$t]=query("SELECT * FROM $t")->fetchAll();
try{callApi('admin/import',['backup'=>$backup],999);throw new LogicException('nonempty import allowed');}catch(InvalidArgumentException){echo "PASS nonempty import blocked\n";}
foreach(['submission_credentials','withdrawal_crypto','web_submissions','credits','withdrawals','submissions','users'] as $t)query("DELETE FROM $t");
callApi('admin/import',['backup'=>$backup],999);
check(callApi('state')['balance']===25000,'import preserves balance');
check(count(callApi('state')['submissions'])===2,'import preserves history');
