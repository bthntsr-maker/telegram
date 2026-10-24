<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // Avoid credential-bearing driver exception logs.
function config(): array {
    static $c;
    if ($c === null) {
        $file = getenv('BOT_CONFIG_FILE') ?: dirname(__DIR__, 2) . '/bot-config.php';
        if (!is_file($file)) throw new RuntimeException('Configuration missing');
        $c = require $file;
        foreach (['db_host','db_name','db_user','db_password','bot_token','app_url','webhook_secret','setup_key'] as $k) {
            if (!is_string($c[$k] ?? null) || !$c[$k] || str_contains($c[$k], 'REPLACE_')) throw new RuntimeException('Configuration incomplete');
        }
        if (strlen($c['webhook_secret']) < 32 || strlen($c['setup_key']) < 32 || empty($c['admin_ids'])) throw new RuntimeException('Configuration incomplete');
    }
    return $c;
}
function db(): PDO {
    static $db;
    if (!$db) {
        $c = config();
        $db = new PDO('mysql:host='.$c['db_host'].';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    }
    return $db;
}
function query(string $sql, array $params=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($params); return $s; }
function isAdmin(int $uid): bool { return in_array($uid, array_map('intval', config()['admin_ids']), true); }
function telegram(string $method, array $body): array {
    $ch=curl_init('https://api.telegram.org/bot'.config()['bot_token'].'/'.$method);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch); $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($raw===false || $status!==200) throw new RuntimeException('Telegram request failed');
    $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if (!($data['ok']??false)) throw new RuntimeException('Telegram request failed');
    return $data;
}
function authenticate(string $raw): array {
    if (strlen($raw)>16384) throw new RuntimeException('Authentication failed');
    $data=[];
    foreach(explode('&',$raw) as $pair) {
        $parts=explode('=',$pair,2);
        if(count($parts)!==2) throw new RuntimeException('Authentication failed');
        $key=urldecode($parts[0]);
        if(array_key_exists($key,$data)) throw new RuntimeException('Authentication failed');
        $data[$key]=urldecode($parts[1]);
    }
    $hash=$data['hash']??''; unset($data['hash']); ksort($data,SORT_STRING);
    $lines=[];foreach($data as $k=>$v)$lines[]=$k.'='.$v;
    $secret=hash_hmac('sha256',config()['bot_token'],'WebAppData',true);
    if(!hash_equals(hash_hmac('sha256',implode("\n",$lines),$secret),$hash)) throw new RuntimeException('Authentication failed');
    $age=time()-(int)($data['auth_date']??0);
    if($age < -30 || $age > 3600) throw new RuntimeException('Authentication expired');
    $user=json_decode($data['user']??'',true,512,JSON_THROW_ON_ERROR);
    if(!is_int($user['id']??null) || $user['id']<=0) throw new RuntimeException('Authentication failed');
    return $user;
}
function jsonResponse(int $status,array $data): never {
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
function initialize(): void {
    // Run once from the protected setup screen, never on normal requests.
    $sql=file_get_contents(__DIR__.'/schema.sql');
    foreach(explode(';',$sql) as $s) if(trim($s)) db()->exec($s);
}
function cents(mixed $v): int { if(!is_int($v)||$v<1||$v>100000000)throw new InvalidArgumentException('Geçersiz tutar');return $v; }
function rid(array $b): string { $r=$b['request_id']??'';if(!is_string($r)||strlen($r)<16||strlen($r)>100)throw new InvalidArgumentException('İşlem kimliği gerekli');return $r; }
function ensureUser(int $uid): void { query('INSERT IGNORE INTO users(id) VALUES (?)',[$uid]); }
function reserveWithdrawal(int $uid,array $b): array {
    $n=cents($b['amount']??null);
    if($n<10000)throw new InvalidArgumentException('Minimum çekim tutarı 100 TL’dir');
    $r=rid($b);$dest=$b['destination']??'';
    if(!is_string($dest)||!tronAddress(trim($dest)))throw new InvalidArgumentException('Geçerli bir TRC20 cüzdan adresi girin');
    // Serialize operations for this user before checking the idempotency record.
    query('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
    $old=query('SELECT id FROM withdrawals WHERE user_id=? AND request_id=?',[$uid,$r])->fetch();
    if($old)return ['id'=>(int)$old['id']];
    if(!query('UPDATE users SET balance=balance-? WHERE id=? AND balance>=?',[$n,$uid,$n])->rowCount())throw new InvalidArgumentException('Yetersiz bakiye');
    query('INSERT INTO withdrawals(user_id,amount,destination,request_id) VALUES (?,?,?,?)',[$uid,$n,trim($dest),$r]);
    $id=(int)db()->lastInsertId();
    query('INSERT INTO withdrawal_crypto(withdrawal_id) VALUES (?)',[$id]);
    return ['id'=>$id];
}
function decideSubmission(array $b): array {
    $id=$b['id']??null;$action=$b['action']??'';
    if(!is_int($id)||!in_array($action,['approve','reject'],true))throw new InvalidArgumentException('Geçersiz işlem');
    $n=$action==='approve'?cents($b['amount']??null):0;
    $row=query('SELECT * FROM submissions WHERE id=? FOR UPDATE',[$id])->fetch();
    if(!$row||$row['status']!=='pending')throw new InvalidArgumentException('Başvuru zaten işlendi veya yok');
    query('UPDATE submissions SET status=?,amount=? WHERE id=?',[$action==='approve'?'approved':'rejected',$n,$id]);
    if($n)query('UPDATE users SET balance=balance+? WHERE id=?',[$n,$row['user_id']]);
    return ['ok'=>true];
}

function ensureFeatures(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS submission_credentials (submission_id BIGINT PRIMARY KEY, ciphertext TEXT NOT NULL, FOREIGN KEY(submission_id) REFERENCES submissions(id)) ENGINE=InnoDB');
    db()->exec("CREATE TABLE IF NOT EXISTS withdrawal_crypto (withdrawal_id BIGINT PRIMARY KEY, network VARCHAR(16) NOT NULL DEFAULT 'TRC20', sent_usdt VARCHAR(40) NULL, txid CHAR(64) NULL, FOREIGN KEY(withdrawal_id) REFERENCES withdrawals(id)) ENGINE=InnoDB");
}
function passwordKey(): string {
    $path=getenv('BOT_KEY_FILE') ?: dirname(__DIR__,2).'/bot-encryption.key';
    $file=fopen($path,'c+b');
    if(!$file)throw new RuntimeException('Encryption key unavailable');
    try {
        if(!chmod($path,0600)||!flock($file,LOCK_EX))throw new RuntimeException('Cannot secure encryption key');
        $key=stream_get_contents($file);
        if($key==='') { $key=random_bytes(32); if(fwrite($file,$key)!==32||!fflush($file))throw new RuntimeException('Cannot save encryption key'); }
        if(strlen($key)!==32)throw new RuntimeException('Encryption key invalid');
        return $key;
    } finally {fclose($file);}
}
function encryptPassword(string $password,int $id): string {
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($password,'aes-256-gcm',passwordKey(),OPENSSL_RAW_DATA,$iv,$tag,'submission:'.$id,16);
    if($cipher===false)throw new RuntimeException('Encryption failed');
    return base64_encode($iv.$tag.$cipher);
}
function decryptPassword(string $encoded,int $id): string {
    $raw=base64_decode($encoded,true);
    if($raw===false||strlen($raw)<29)throw new RuntimeException('Invalid ciphertext');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',passwordKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'submission:'.$id);
    if($plain===false)throw new RuntimeException('Decryption failed');
    return $plain;
}
function tronAddress(string $address): bool {
    if(!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/D',$address))return false;
    $alphabet='123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';$bytes=[0];
    foreach(str_split($address) as $char){
        $carry=strpos($alphabet,$char);
        if($carry===false)return false;
        for($i=count($bytes)-1;$i>=0;$i--){$carry+=$bytes[$i]*58;$bytes[$i]=$carry&255;$carry>>=8;}
        while($carry>0){array_unshift($bytes,$carry&255);$carry>>=8;}
    }
    $raw=pack('C*',...$bytes);
    return strlen($raw)===25&&ord($raw[0])===65&&hash_equals(substr(hash('sha256',hash('sha256',substr($raw,0,21),true),true),0,4),substr($raw,21));
}
function usdtAmount(mixed $value): string {
    if(!is_string($value)||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,8})?$/D',$value)||!preg_match('/[1-9]/',$value))throw new InvalidArgumentException('Gönderilen USDT tutarını girin');
    return $value;
}
