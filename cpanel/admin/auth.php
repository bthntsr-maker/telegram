<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/common.php';
function adminAuthPath(): string {return getenv('ADMIN_AUTH_FILE')?:dirname(__DIR__,2).'/bot-admin-auth.json';}
function readAdminAccount(): ?array {
    if(!is_file(adminAuthPath()))return null;
    $data=json_decode(file_get_contents(adminAuthPath()),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($data)||!isset($data['username'],$data['password_hash'],$data['id']))throw new RuntimeException('Invalid admin account');
    return $data;
}
function createAdminAccount(string $key,string $username,string $password): void {
    if(!hash_equals(config()['setup_key'],$key))throw new InvalidArgumentException('Kurulum anahtarı doğrulanamadı');
    $username=trim($username);
    if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D',$username))throw new InvalidArgumentException('Kullanıcı adı 3–40 harf, rakam, nokta, tire veya alt çizgiden oluşmalı');
    if(strlen($password)<12||strlen($password)>72)throw new InvalidArgumentException('Şifre 12–72 karakter olmalı');
    $hash=password_hash($password,PASSWORD_DEFAULT);
    if(!$hash)throw new RuntimeException('Password hashing failed');
    $file=@fopen(adminAuthPath(),'x');
    if(!$file)throw new InvalidArgumentException('Yönetici hesabı zaten oluşturulmuş');
    try{
        if(!chmod(adminAuthPath(),0600))throw new RuntimeException('Cannot secure account file');
        $data=json_encode(['username'=>$username,'password_hash'=>$hash,'id'=>(int)config()['admin_ids'][0]],JSON_THROW_ON_ERROR);
        if(fwrite($file,$data)!==strlen($data)||!fflush($file))throw new RuntimeException('Cannot create account');
    }finally{fclose($file);}
}
function ensureAdminRateLimit(): void {
    db()->exec('CREATE TABLE IF NOT EXISTS admin_login_attempts (ip_hash CHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, reset_at BIGINT NOT NULL) ENGINE=InnoDB');
}
function checkAdminPassword(string $username,string $password,string $ip): array {
    ensureAdminRateLimit();$key=hash_hmac('sha256',$ip,config()['setup_key']);$now=time();
    db()->beginTransaction();
    try{
        query('INSERT IGNORE INTO admin_login_attempts(ip_hash,attempts,reset_at) VALUES (?,0,?)',[$key,$now+900]);
        $row=query('SELECT * FROM admin_login_attempts WHERE ip_hash=? FOR UPDATE',[$key])->fetch();
        $tries=$row['reset_at']<=$now?0:(int)$row['attempts'];$reset=$row['reset_at']<=$now?$now+900:(int)$row['reset_at'];
        if($tries>=5){db()->commit();throw new InvalidArgumentException('Çok fazla giriş denemesi. 15 dakika sonra tekrar deneyin.');}
        query('UPDATE admin_login_attempts SET attempts=?,reset_at=? WHERE ip_hash=?',[$tries+1,$reset,$key]);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    $account=readAdminAccount();
    // A constant-cost dummy hash keeps missing-account/user requests on the password hashing path.
    $hash=$account['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $valid=password_verify($password,$hash);
    if(!$account||!hash_equals($account['username'],trim($username))||!$valid||!isAdmin((int)$account['id']))throw new InvalidArgumentException('Kullanıcı adı veya şifre hatalı');
    query('DELETE FROM admin_login_attempts WHERE ip_hash=?',[$key]);
    return ['id'=>(int)$account['id'],'first_name'=>$account['username']];
}
