<?php
declare(strict_types=1);
require_once __DIR__.'/notifications.php';
function dispatch(string $path,array $u,array $b): array {
    $uid=$u['id'];$admin=isAdmin($uid);ensureUser($uid);
    if($path==='state') {
        $r=['name'=>$u['first_name']??'Kullanıcı','admin'=>$admin,'demo'=>false,'storage'=>'mysql','balance'=>(int)query('SELECT balance FROM users WHERE id=?',[$uid])->fetchColumn()];
        foreach(['submissions','withdrawals'] as $t) {
            $fields=$t==='submissions'?'id,proof,status,amount':'id,amount,destination,status';
            $r[$t]=query("SELECT $fields FROM $t WHERE user_id=? ORDER BY id DESC LIMIT 100",[$uid])->fetchAll();
        }
        if($admin)foreach(['submissions','withdrawals'] as $t)$r['pending_'.$t]=query("SELECT * FROM $t WHERE status='pending' ORDER BY id LIMIT 100")->fetchAll();
        foreach($r['withdrawals'] as &$w){$crypto=query('SELECT network,sent_usdt,txid FROM withdrawal_crypto WHERE withdrawal_id=?',[$w['id']])->fetch();if($crypto)$w=array_merge($w,$crypto);}unset($w);
        if($admin){
            foreach($r['pending_withdrawals'] as &$w){$crypto=query('SELECT network,sent_usdt,txid FROM withdrawal_crypto WHERE withdrawal_id=?',[$w['id']])->fetch();if($crypto)$w=array_merge($w,$crypto);}unset($w);
            foreach($r['pending_submissions'] as &$row){$row['has_password']=(bool)query('SELECT 1 FROM submission_credentials WHERE submission_id=?',[$row['id']])->fetchColumn();}unset($row);
            $r['reviewed_submissions']=query("SELECT s.*, EXISTS(SELECT 1 FROM submission_credentials c WHERE c.submission_id=s.id) AS has_password FROM submissions s WHERE s.status<>'pending' ORDER BY s.id DESC LIMIT 100")->fetchAll();
        }
        $r['submissions']=attachRejections($r['submissions'],'submission');
        $r['withdrawals']=attachRejections($r['withdrawals'],'withdrawal');
        if($admin)$r['reviewed_submissions']=attachRejections($r['reviewed_submissions'],'submission');
        return $r;
    }
    if($path==='submit') {
        $p=$b['proof']??'';
        if(!is_string($p))throw new InvalidArgumentException('Yalnızca geçerli bir @gmail.com adresi gönderin');
        $p=strtolower(trim($p));
        if(strlen($p)>254 || !filter_var($p,FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*(?:\+[a-z0-9._-]+)?@gmail\.com$/D',$p))throw new InvalidArgumentException('Yalnızca geçerli bir @gmail.com adresi gönderin');
        $password=$b['password']??null;
        if(!is_string($password)||strlen($password)<1||strlen($password)>1024)throw new InvalidArgumentException('Şifre alanını doldurun');
        if(($b['consent']??false)!==true)throw new InvalidArgumentException('Hesap bilgilerini iletmek için onay vermelisiniz');
        $r=rid($b);query('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
        $old=query('SELECT submission_id FROM web_submissions WHERE user_id=? AND request_id=?',[$uid,$r])->fetch();
        if($old)return ['id'=>(int)$old['submission_id']];
        $canonical=canonicalGmail($p);
        if(!query('INSERT IGNORE INTO gmail_claims(canonical) VALUES (?)',[$canonical])->rowCount())throw new InvalidArgumentException('Bu Gmail adresi daha önce gönderilmiş');
        $local=explode('@',$canonical,2)[0];
        $existing=query("SELECT id FROM submissions WHERE LOWER(TRIM(proof)) LIKE '%@gmail.com' AND REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(TRIM(proof)),'@',1),'+',1),'.','')=? LIMIT 1",[$local])->fetch();
        if($existing)throw new InvalidArgumentException('Bu Gmail adresi daha önce gönderilmiş');
        query('INSERT INTO submissions(user_id,proof) VALUES (?,?)',[$uid,$p]);$id=(int)db()->lastInsertId();
        query('UPDATE gmail_claims SET submission_id=? WHERE canonical=?',[$id,$canonical]);
        query('INSERT INTO submission_credentials VALUES (?,?)',[$id,encryptPassword($password,$id)]);
        query('INSERT INTO web_submissions VALUES (?,?,?)',[$uid,$r,$id]);queueSubmissionNotification($id);return ['id'=>$id];
    }
    if($path==='withdraw')return reserveWithdrawal($uid,$b);
    if(str_starts_with($path,'admin/')) {
        if(!$admin)throw new DomainException('Admin yetkisi gerekli');
        if($path==='admin/notifications/save')return saveNotificationSettings($b);
        if($path==='admin/notifications/retry'){
            $settings=notificationSettings();
            if(!$settings['enabled'])throw new InvalidArgumentException('Önce bildirimleri açın');
            query("UPDATE notification_outbox SET attempts=0,next_attempt=? WHERE status='failed' AND chat_id=?",[time(),$settings['chat_id']]);return ['ok'=>true];
        }
        if($path==='admin/password') {
            $id=$b['id']??null;if(!is_int($id))throw new InvalidArgumentException('Başvuru numarası gerekli');
            $row=query('SELECT ciphertext FROM submission_credentials WHERE submission_id=?',[$id])->fetch();
            if(!$row)throw new InvalidArgumentException('Bu başvuruda kayıtlı şifre yok');
            return ['password'=>decryptPassword($row['ciphertext'],$id)];
        }
        if($path==='admin/submission')return decideSubmission($b);
        if($path==='admin/credit') {
            $n=cents($b['amount']??null);$target=$b['user_id']??null;$r=rid($b);
            if(!is_int($target)||$target<=0)throw new InvalidArgumentException('Kullanıcı kimliği gerekli');
            // Lock the administrator row to serialize retries with the same request ID.
            query('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
            if(query('SELECT request_id FROM credits WHERE request_id=?',[$r])->fetch())return ['ok'=>true];
            ensureUser($target);query('INSERT INTO credits VALUES (?,?,?,?,?)',[$r,$uid,$target,$n,time()]);
            query('UPDATE users SET balance=balance+? WHERE id=?',[$n,$target]);return ['ok'=>true];
        }
        if($path==='admin/withdrawal') {
            $id=$b['id']??null;$action=$b['action']??'';
            if(!is_int($id)||!in_array($action,['paid','reject'],true))throw new InvalidArgumentException('Geçersiz işlem');
            $r=query('SELECT * FROM withdrawals WHERE id=? FOR UPDATE',[$id])->fetch();
            if(!$r||$r['status']!=='pending')throw new InvalidArgumentException('Talep zaten işlendi veya yok');
            $reason=$action==='reject'?rejectionReason($b['reason']??null):null;
            $crypto=query('SELECT network FROM withdrawal_crypto WHERE withdrawal_id=?',[$id])->fetch();
            if($action==='paid'&&$crypto){
                $usdt=usdtAmount($b['sent_usdt']??null);$txid=$b['txid']??'';
                if(!is_string($txid)||!preg_match('/^[a-fA-F0-9]{64}$/D',$txid))throw new InvalidArgumentException('64 karakterlik işlem kimliğini (TXID) girin');
                query('UPDATE withdrawal_crypto SET sent_usdt=?,txid=? WHERE withdrawal_id=?',[$usdt,strtolower($txid),$id]);
            }
            query('UPDATE withdrawals SET status=? WHERE id=?',[$action==='paid'?'paid':'rejected',$id]);
            if($action==='reject'){query('INSERT INTO rejection_reasons(kind,record_id,reason) VALUES (?,?,?)',['withdrawal',$id,$reason]);query('UPDATE users SET balance=balance+? WHERE id=?',[$r['amount'],$r['user_id']]);}return ['ok'=>true];
        }
        if($path==='admin/import') {
            $backup=$b['backup']??[];
            if(($backup['format']??'')!=='telegram-wallet-v1')throw new InvalidArgumentException('Geçersiz yedek');
            foreach(['submissions','withdrawals','credits','web_submissions'] as $t)if(query("SELECT COUNT(*) FROM $t")->fetchColumn())throw new InvalidArgumentException('Aktarım yalnızca boş yeni veritabanına yapılır');
            if(query('SELECT COUNT(*) FROM users WHERE balance<>0')->fetchColumn())throw new InvalidArgumentException('Mevcut bakiyeler var');
            $columns=['users'=>['id','balance'],'submissions'=>['id','user_id','proof','status','amount'],'withdrawals'=>['id','user_id','amount','destination','status','request_id'],'credits'=>['request_id','admin_id','user_id','amount','created_at'],'web_submissions'=>['user_id','request_id','submission_id']];
            foreach($columns as $table=>$keys) {
                if(!isset($backup['tables'][$table])||!is_array($backup['tables'][$table]))throw new InvalidArgumentException('Eksik tablo');
                foreach($backup['tables'][$table] as $row) {
                    if(!is_array($row)||array_diff($keys,array_keys($row)))throw new InvalidArgumentException('Geçersiz kayıt');
                    $values=array_map(fn($k)=>$row[$k],$keys);
                    $sql='INSERT INTO '.$table.' ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')';
                    if($table==='users')$sql.=' ON DUPLICATE KEY UPDATE balance=VALUES(balance)';
                    query($sql,$values);
                }
            }
            return ['ok'=>true];
        }
    }
    throw new InvalidArgumentException('İşlem bulunamadı');
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    if($_SERVER['REQUEST_METHOD']!=='POST')jsonResponse(405,['error'=>'POST gerekli']);
    try {$raw=$_SERVER['HTTP_AUTHORIZATION']??'';$user=authenticate(str_starts_with($raw,'tma ')?substr($raw,4):'');}
    catch(Throwable){jsonResponse(401,['error'=>'Telegram üzerinden yeniden açın.']);}
    try {
        $size=(int)($_SERVER['CONTENT_LENGTH']??0);if($size<1||$size>2097152)throw new InvalidArgumentException('İstek boyutu geçersiz');
        $body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(!is_array($body))throw new InvalidArgumentException('Geçersiz istek');
        ensureFeatures();db()->beginTransaction();$result=dispatch($_GET['route']??'',$user,$body);db()->commit();if(in_array($_GET['route']??'',['submit','state'],true)){try{flushNotifications();}catch(Throwable){}}jsonResponse(200,$result);
    }catch(Throwable $e){if(isset($result))unset($result);try{if(db()->inTransaction())db()->rollBack();}catch(Throwable){}
        jsonResponse($e instanceof DomainException?403:($e instanceof InvalidArgumentException?400:500),['error'=>$e instanceof InvalidArgumentException||$e instanceof DomainException?$e->getMessage():'İşlem tamamlanamadı. Sunucu yapılandırmasını kontrol edin.']);}
}
