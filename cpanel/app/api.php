<?php
declare(strict_types=1);
require __DIR__.'/common.php';
function dispatch(string $path,array $u,array $b): array {
    $uid=$u['id'];$admin=isAdmin($uid);ensureUser($uid);
    if($path==='state') {
        $r=['name'=>$u['first_name']??'Kullanıcı','admin'=>$admin,'demo'=>false,'storage'=>'mysql','balance'=>(int)query('SELECT balance FROM users WHERE id=?',[$uid])->fetchColumn()];
        foreach(['submissions','withdrawals'] as $t) {
            $fields=$t==='submissions'?'id,proof,status,amount':'id,amount,destination,status';
            $r[$t]=query("SELECT $fields FROM $t WHERE user_id=? ORDER BY id DESC LIMIT 100",[$uid])->fetchAll();
        }
        if($admin)foreach(['submissions','withdrawals'] as $t)$r['pending_'.$t]=query("SELECT * FROM $t WHERE status='pending' ORDER BY id LIMIT 100")->fetchAll();
        return $r;
    }
    if($path==='submit') {
        $p=$b['proof']??'';
        if(!is_string($p))throw new InvalidArgumentException('Yalnızca geçerli bir @gmail.com adresi gönderin');
        $p=strtolower(trim($p));
        if(strlen($p)>254 || !filter_var($p,FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*(?:\+[a-z0-9._-]+)?@gmail\.com$/D',$p))throw new InvalidArgumentException('Yalnızca geçerli bir @gmail.com adresi gönderin');
        $r=rid($b);query('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
        $old=query('SELECT submission_id FROM web_submissions WHERE user_id=? AND request_id=?',[$uid,$r])->fetch();
        if($old)return ['id'=>(int)$old['submission_id']];
        query('INSERT INTO submissions(user_id,proof) VALUES (?,?)',[$uid,$p]);$id=(int)db()->lastInsertId();
        query('INSERT INTO web_submissions VALUES (?,?,?)',[$uid,$r,$id]);return ['id'=>$id];
    }
    if($path==='withdraw')return reserveWithdrawal($uid,$b);
    if(str_starts_with($path,'admin/')) {
        if(!$admin)throw new DomainException('Admin yetkisi gerekli');
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
            query('UPDATE withdrawals SET status=? WHERE id=?',[$action==='paid'?'paid':'rejected',$id]);
            if($action==='reject')query('UPDATE users SET balance=balance+? WHERE id=?',[$r['amount'],$r['user_id']]);return ['ok'=>true];
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
        db()->beginTransaction();$result=dispatch($_GET['route']??'',$user,$body);db()->commit();jsonResponse(200,$result);
    }catch(Throwable $e){if(isset($result))unset($result);try{if(db()->inTransaction())db()->rollBack();}catch(Throwable){}
        jsonResponse($e instanceof DomainException?403:($e instanceof InvalidArgumentException?400:500),['error'=>$e instanceof InvalidArgumentException||$e instanceof DomainException?$e->getMessage():'İşlem tamamlanamadı. Sunucu yapılandırmasını kontrol edin.']);}
}
