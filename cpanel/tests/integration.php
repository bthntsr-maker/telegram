<?php
require dirname(__DIR__).'/app/api.php';
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function callApi(string $path,array $body=[],int $uid=123):array {
    db()->beginTransaction();
    try{$result=dispatch($path,['id'=>$uid,'first_name'=>'Test'],$body);db()->commit();return $result;}
    catch(Throwable $e){db()->rollBack();throw $e;}
}
initialize();
$proof=['proof'=>'test.user@gmail.com','request_id'=>'submission-test-12345'];
foreach(['user@example.com','user@gmail.com.evil','https://gmail.com','x@@gmail.com','a..b@gmail.com','a@gmail.com'."\nextra",''] as $invalid){
    try{callApi('submit',['proof'=>$invalid,'request_id'=>'invalid-email-12345']);throw new LogicException('invalid accepted');}catch(InvalidArgumentException){}
}
echo "PASS non-Gmail and malformed addresses rejected\n";
$normalized=callApi('submit',['proof'=>'  Test.User@GMAIL.COM  ','request_id'=>'normalized-email-12345']);
check(query('SELECT proof FROM submissions WHERE id=?',[$normalized['id']])->fetchColumn()==='test.user@gmail.com','Gmail case and whitespace normalized');
$s=callApi('submit',$proof);check(callApi('submit',$proof)['id']===$s['id'],'submission retry');
try{callApi('admin/credit',['user_id'=>123,'amount'=>1000,'request_id'=>'credit-test-1234567']);throw new RuntimeException('permission failed');}catch(DomainException){echo "PASS admin authorization\n";}
callApi('admin/submission',['id'=>$s['id'],'action'=>'approve','amount'=>2500],999);
check(callApi('state')['balance']===2500,'approval balance');
try{callApi('admin/submission',['id'=>$s['id'],'action'=>'approve','amount'=>2500],999);throw new RuntimeException('duplicate approved');}catch(InvalidArgumentException){echo "PASS duplicate approval blocked\n";}
$body=['amount'=>2000,'destination'=>'Test IBAN TR123456','request_id'=>'withdraw-test-123456'];
$w=callApi('withdraw',$body);check(callApi('withdraw',$body)['id']===$w['id'],'withdrawal retry');
check(callApi('state')['balance']===500,'funds reserved');
try{callApi('withdraw',array_replace($body,['request_id'=>'withdraw-other-1234']));throw new RuntimeException('overspent');}catch(InvalidArgumentException){echo "PASS insufficient funds\n";}
callApi('admin/withdrawal',['id'=>$w['id'],'action'=>'reject'],999);
check(callApi('state')['balance']===2500,'refund');
$c=['user_id'=>123,'amount'=>1000,'request_id'=>'credit-test-1234567'];callApi('admin/credit',$c,999);callApi('admin/credit',$c,999);
check(callApi('state')['balance']===3500,'credit once');
check(count(callApi('state',[],456)['submissions'])===0,'user isolation');
$data=['auth_date'=>(string)time(),'user'=>json_encode(['id'=>123])];ksort($data);$lines=[];foreach($data as $k=>$v)$lines[]=$k.'='.$v;
$key=hash_hmac('sha256',config()['bot_token'],'WebAppData',true);$data['hash']=hash_hmac('sha256',implode("\n",$lines),$key);
check(authenticate(http_build_query($data))['id']===123,'signed Telegram identity');
$data['hash']=str_repeat('0',64);try{authenticate(http_build_query($data));throw new LogicException('forged auth accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;echo "PASS forged authentication rejected\n";}
$backup=['format'=>'telegram-wallet-v1','tables'=>[]];foreach(['users','submissions','withdrawals','credits','web_submissions'] as $t)$backup['tables'][$t]=query("SELECT * FROM $t")->fetchAll();
try{callApi('admin/import',['backup'=>$backup],999);throw new LogicException('nonempty import allowed');}catch(InvalidArgumentException){echo "PASS nonempty import blocked\n";}
foreach(['web_submissions','credits','withdrawals','submissions','users'] as $t)query("DELETE FROM $t");
callApi('admin/import',['backup'=>$backup],999);
check(callApi('state')['balance']===3500,'import preserves balance');
check(count(callApi('state')['submissions'])===2,'import preserves history');
