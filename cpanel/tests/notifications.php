<?php
// Use ONLY a disposable local MySQL database and fixture bot config. Never calls Telegram.
require_once dirname(__DIR__).'/app/api.php';
function ncheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
function napi(string $path,array $body=[],int $uid=999):array{
    db()->beginTransaction();try{$r=dispatch($path,['id'=>$uid,'first_name'=>'Test'],$body);db()->commit();return $r;}catch(Throwable $e){db()->rollBack();throw $e;}
}
function newNotificationSubmission(string $name):array{return napi('submit',['proof'=>$name.'@gmail.com','password'=>'notification-fixture-secret','consent'=>true,'request_id'=>'notification-'.$name.'-123456'],123);}
initialize();ensureFeatures();
try{napi('admin/notifications/save',['chat_id'=>777,'enabled'=>true],123);throw new LogicException('nonadmin changed settings');}catch(DomainException){echo "PASS settings require admin\n";}
try{napi('admin/notifications/save',['chat_id'=>0,'enabled'=>true]);throw new LogicException('invalid receiver');}catch(InvalidArgumentException){echo "PASS invalid receiver blocked\n";}
try{napi('admin/notifications/save',['chat_id'=>null,'enabled'=>true]);throw new LogicException('missing receiver');}catch(InvalidArgumentException){echo "PASS enabled requires receiver\n";}
napi('admin/notifications/save',['chat_id'=>777,'enabled'=>true]);
ncheck(notificationSettings()===['chat_id'=>777,'enabled'=>true]&&!isAdmin(777),'receiver saved without admin role');
$first=newNotificationSubmission('notification.first');
newNotificationSubmission('notification.first');
ncheck((int)query('SELECT COUNT(*) FROM notification_outbox WHERE submission_id=?',[$first['id']])->fetchColumn()===1,'submission retry queues only one notification');
$sent=[];$sender=function($p)use(&$sent){$sent[]=$p;};
flushNotifications($sender);flushNotifications($sender);
ncheck(count($sent)===1&&$sent[0]['chat_id']===777,'committed notification sent once to receiver');
ncheck(str_contains($sent[0]['text'],'notification.first@gmail.com')&&!str_contains(json_encode($sent[0]),'notification-fixture-secret'),'notification contains Gmail but no password');
ncheck($sent[0]['reply_markup']['inline_keyboard'][0][0]['url']==='https://example.org/admin/','panel link included');
ncheck(!array_key_exists('notifications',napi('state',[],123)),'receiver settings not exposed to users');
$second=newNotificationSubmission('notification.failed');
flushNotifications(function($p){throw new RuntimeException('fixture delivery failure');});
ncheck(query('SELECT status FROM notification_outbox WHERE submission_id=?',[$second['id']])->fetchColumn()==='failed','failed delivery recorded');
ncheck((int)query('SELECT COUNT(*) FROM submissions WHERE id=?',[$second['id']])->fetchColumn()===1,'failed delivery does not lose submission');
flushNotifications($sender);ncheck(count($sent)===1,'retry respects backoff');
query('UPDATE notification_outbox SET next_attempt=0 WHERE submission_id=?',[$second['id']]);flushNotifications($sender);
ncheck(count($sent)===2&&query('SELECT status FROM notification_outbox WHERE submission_id=?',[$second['id']])->fetchColumn()==='sent','failed delivery retried after backoff');
db()->beginTransaction();dispatch('submit',['id'=>123],['proof'=>'notification.rollback@gmail.com','password'=>'fixture-secret','consent'=>true,'request_id'=>'notification-rollback-12345']);db()->rollBack();
ncheck((int)query("SELECT COUNT(*) FROM submissions WHERE proof='notification.rollback@gmail.com'")->fetchColumn()===0,'rolled-back submission cannot create a notification');
$third=newNotificationSubmission('notification.exhausted');query("UPDATE notification_outbox SET status='failed',attempts=5,next_attempt=0 WHERE submission_id=?",[$third['id']]);flushNotifications($sender);
ncheck(count($sent)===2,'exhausted retries stop');napi('admin/notifications/retry');flushNotifications($sender);ncheck(count($sent)===3,'admin can retry exhausted notification');
$fourth=newNotificationSubmission('notification.disabled');napi('admin/notifications/save',['chat_id'=>777,'enabled'=>false]);
ncheck(query('SELECT status FROM notification_outbox WHERE submission_id=?',[$fourth['id']])->fetchColumn()==='cancelled','disable cancels queued deliveries');
$disabled=newNotificationSubmission('notification.off');ncheck((int)query('SELECT COUNT(*) FROM notification_outbox WHERE submission_id=?',[$disabled['id']])->fetchColumn()===0,'disabled notifications do not queue');
napi('admin/notifications/save',['chat_id'=>777,'enabled'=>true]);$previous=newNotificationSubmission('notification.previous');napi('admin/notifications/save',['chat_id'=>888,'enabled'=>true]);
ncheck(query('SELECT status FROM notification_outbox WHERE submission_id=?',[$previous['id']])->fetchColumn()==='cancelled','changed receiver cancels old unsent notifications');
$new=newNotificationSubmission('notification.next');flushNotifications($sender);
ncheck(end($sent)['chat_id']===888&&!isAdmin(888),'next submission goes to new account without admin role');
sendTestNotification($sender);ncheck(end($sent)['chat_id']===888,'test message uses configured receiver');
try{sendTestNotification(function(){throw new RuntimeException('fixture failure');});throw new LogicException('test failure hidden');}catch(InvalidArgumentException $e){ncheck(str_contains($e->getMessage(),'/start'),'test failure explains bot start prerequisite');}
