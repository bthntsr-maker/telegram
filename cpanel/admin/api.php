<?php
declare(strict_types=1);
require __DIR__.'/session.php';
require_once dirname(__DIR__).'/app/api.php';
try{
    startAdminSession();$route=$_GET['route']??'';
    if($_SERVER['REQUEST_METHOD']==='GET'&&$route==='session')jsonResponse(200,['csrf'=>$_SESSION['csrf'],'user'=>adminSessionUser(),'needs_setup'=>!is_file(adminAuthPath())]);
    if($_SERVER['REQUEST_METHOD']!=='POST')jsonResponse(405,['error'=>'POST gerekli']);
    if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json')||!checkAdminCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??'',$_SERVER['HTTP_ORIGIN']??''))jsonResponse(403,['error'=>'Oturum doğrulanamadı. Sayfayı yenileyin.']);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>8192)throw new InvalidArgumentException('İstek çok büyük');
    $body=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(!is_array($body))throw new InvalidArgumentException('Geçersiz istek');
    if($route==='setup'){
        createAdminAccount((string)($body['setup_key']??''),(string)($body['username']??''),(string)($body['password']??''));
        jsonResponse(200,['ok'=>true]);
    }
    if($route==='login'){
        $user=checkAdminPassword((string)($body['username']??''),(string)($body['password']??''),$_SERVER['REMOTE_ADDR']??'');
        session_regenerate_id(true);$_SESSION['admin_user']=$user;$_SESSION['account_hash']=hash('sha256',readAdminAccount()['password_hash']);$_SESSION['login_at']=time();$_SESSION['last_seen']=time();$_SESSION['csrf']=bin2hex(random_bytes(32));jsonResponse(200,['user'=>$user,'csrf'=>$_SESSION['csrf']]);
    }
    $user=adminSessionUser();if(!$user)jsonResponse(401,['error'=>'Yönetici girişi gerekli']);
    if($route==='logout'){
        $_SESSION=[];session_destroy();setcookie('bot_admin_session','',['expires'=>time()-3600,'path'=>'/admin/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);jsonResponse(200,['ok'=>true]);
    }
    if(!in_array($route,['dashboard','admin/submission','admin/withdrawal','admin/credit','admin/password','admin/notifications/save','admin/notifications/test','admin/notifications/retry'],true))throw new InvalidArgumentException('İşlem bulunamadı');
    ensureFeatures();
    if($route==='admin/notifications/test'){sendTestNotification();jsonResponse(200,['ok'=>true]);}
    db()->beginTransaction();
    $result=dispatch($route==='dashboard'?'state':$route,$user,$body);
    if($route==='dashboard'){
        $result['notifications']=notificationOverview();
        $result['summary']=['users'=>(int)query('SELECT COUNT(*) FROM users')->fetchColumn(),'balance'=>(int)query('SELECT COALESCE(SUM(balance),0) FROM users')->fetchColumn(),'pending_submissions'=>(int)query("SELECT COUNT(*) FROM submissions WHERE status='pending'")->fetchColumn(),'pending_withdrawals'=>(int)query("SELECT COUNT(*) FROM withdrawals WHERE status='pending'")->fetchColumn()];
        $result['users']=query('SELECT id,balance FROM users ORDER BY id DESC LIMIT 100')->fetchAll();
        $result['payment_history']=query("SELECT w.*,c.network,c.sent_usdt,c.txid FROM withdrawals w LEFT JOIN withdrawal_crypto c ON c.withdrawal_id=w.id WHERE w.status<>'pending' ORDER BY w.id DESC LIMIT 100")->fetchAll();
    }
    if($route==='dashboard')$result['payment_history']=attachRejections($result['payment_history'],'withdrawal');
    db()->commit();if(in_array($route,['dashboard','admin/notifications/retry'],true)){try{flushNotifications();}catch(Throwable){}}jsonResponse(200,$result);
}catch(Throwable $e){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable){}
    jsonResponse($e instanceof InvalidArgumentException?400:500,['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'İşlem tamamlanamadı. Lütfen tekrar deneyin.']);}
