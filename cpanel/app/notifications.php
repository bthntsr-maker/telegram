<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
function notificationSettings(): array {
    $row=query('SELECT chat_id,enabled FROM notification_settings WHERE id=1')->fetch();
    return ['chat_id'=>$row&&$row['chat_id']!==null?(int)$row['chat_id']:null,'enabled'=>$row?(bool)$row['enabled']:false];
}
function saveNotificationSettings(array $body): array {
    $id=$body['chat_id']??null;$enabled=$body['enabled']??false;
    if(!is_bool($enabled))throw new InvalidArgumentException('Bildirim ayarı geçersiz');
    if($id!==null&&(!is_int($id)||$id<1||$id>4503599627370495))throw new InvalidArgumentException('Pozitif sayısal Telegram kullanıcı kimliği girin');
    if($enabled&&$id===null)throw new InvalidArgumentException('Bildirim alacak hesabın kimliğini girin');
    // Serialize saves and queued recipients: only future submissions go to a new recipient.
    query('INSERT IGNORE INTO notification_settings(id,chat_id,enabled) VALUES (1,NULL,0)');
    $old=query('SELECT * FROM notification_settings WHERE id=1 FOR UPDATE')->fetch();
    query('UPDATE notification_settings SET chat_id=?,enabled=? WHERE id=1',[$id,$enabled?1:0]);
    if(!$enabled||$old['chat_id']!=$id)query("UPDATE notification_outbox SET status='cancelled' WHERE status IN ('pending','failed')");
    return ['ok'=>true];
}
function queueSubmissionNotification(int $submission): void {
    query('INSERT IGNORE INTO notification_settings(id,chat_id,enabled) VALUES (1,NULL,0)');
    $settings=query('SELECT * FROM notification_settings WHERE id=1 FOR UPDATE')->fetch();
    if(!$settings['enabled']||!$settings['chat_id'])return;
    query('INSERT IGNORE INTO notification_outbox(submission_id,chat_id,next_attempt) VALUES (?,?,?)',[$submission,$settings['chat_id'],time()]);
}
function adminPanelUrl(): string {
    $url=parse_url(config()['app_url']);
    return $url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'').'/admin/';
}
function notificationPayload(array $row): array {
    return ['chat_id'=>(int)$row['chat_id'],'text'=>"📩 Yeni başvuru #".$row['submission_id']."\n\nGmail: ".$row['proof']."\nKullanıcı: ".$row['user_id']."\nDurum: Onay bekliyor",'reply_markup'=>['inline_keyboard'=>[[['text'=>'Admin panelini aç','url'=>adminPanelUrl()]]]]];
}
function sendNotificationPayload(array $payload): void {
    telegram('sendMessage',$payload,4);
}
function flushNotifications(?callable $sender=null): void {
    // Never send inside the submission's transaction; a Telegram failure must not undo a submission.
    if(db()->inTransaction())throw new RuntimeException('Notification must run after commit');
    if(!query("SELECT GET_LOCK('bot-notification-worker',0)")->fetchColumn())return;
    try{
        $row=query("SELECT o.*,s.proof,s.user_id FROM notification_outbox o JOIN submissions s ON s.id=o.submission_id WHERE o.status IN ('pending','failed') AND o.attempts<5 AND o.next_attempt<=? ORDER BY o.id LIMIT 1",[time()])->fetch();
        if(!$row)return;
        try{
            ($sender??'sendNotificationPayload')(notificationPayload($row));
            query("UPDATE notification_outbox SET status='sent',attempts=attempts+1 WHERE id=?",[$row['id']]);
        }catch(Throwable){
            $attempts=(int)$row['attempts']+1;
            query("UPDATE notification_outbox SET status='failed',attempts=?,next_attempt=? WHERE id=?",[$attempts,time()+min(3600,60*(2**($attempts-1))),$row['id']]);
        }
    }finally{query("SELECT RELEASE_LOCK('bot-notification-worker')");}
}
function notificationOverview(): array {
    $settings=notificationSettings();
    $settings['pending']=(int)query("SELECT COUNT(*) FROM notification_outbox WHERE status IN ('pending','failed')")->fetchColumn();
    $settings['failed']=(int)query("SELECT COUNT(*) FROM notification_outbox WHERE status='failed'")->fetchColumn();
    $settings['sent']=(int)query("SELECT COUNT(*) FROM notification_outbox WHERE status='sent'")->fetchColumn();
    return $settings;
}
function sendTestNotification(?callable $sender=null): void {
    $settings=notificationSettings();
    if(!$settings['chat_id'])throw new InvalidArgumentException('Önce bildirim hesabını kaydedin');
    try{($sender??'sendNotificationPayload')(['chat_id'=>$settings['chat_id'],'text'=>'✅ Test mesajı başarıyla gönderildi. Yeni başvuruları almak için panelde bildirimlerin açık olduğundan emin olun.','reply_markup'=>['inline_keyboard'=>[[['text'=>'Admin panelini aç','url'=>adminPanelUrl()]]]]]);}
    catch(Throwable){throw new InvalidArgumentException('Mesaj gönderilemedi. Bu hesaptan botu açıp /start yazdığınızı ve kullanıcı kimliğini kontrol edin.');}
}
