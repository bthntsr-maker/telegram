<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
function startAdminSession(): void {
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
    session_name('bot_admin_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/admin/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
}
function adminSessionUser(): ?array {
    $user=$_SESSION['admin_user']??null;$account=readAdminAccount();
    if(!$account||!hash_equals($_SESSION['account_hash']??'',hash('sha256',$account['password_hash']))){unset($_SESSION['admin_user']);return null;}
    if(!$user||!isAdmin((int)$user['id'])||time()-($_SESSION['login_at']??0)>28800||time()-($_SESSION['last_seen']??0)>1800){unset($_SESSION['admin_user']);return null;}
    $_SESSION['last_seen']=time();return $user;
}
function checkAdminCsrf(string $provided,string $origin): bool {
    if(!isset($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],$provided))return false;
    if($origin==='')return true;
    $url=parse_url(config()['app_url']);$expected=$url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'');
    return hash_equals($expected,$origin);
}
