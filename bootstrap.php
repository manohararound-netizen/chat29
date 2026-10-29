<?php
declare(strict_types=1);
ini_set('display_errors','0');
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; font-src \'self\'; connect-src \'self\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'none\'');
$configFile=__DIR__.'/config.php';
if (!is_file($configFile)) {http_response_code(503);exit('CHAT29 configuration missing. Follow README setup instructions.');}
$cfg=require $configFile;
if(!is_array($cfg) || !preg_match('/^[a-f0-9]{64}$/i',$cfg['app_key']??'')) {http_response_code(503);exit('Configure a valid random app_key.');}
$https=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
if (PHP_SAPI!=='cli' && !$https && !in_array($_SERVER['HTTP_HOST']??'',['localhost','127.0.0.1'],true)) {http_response_code(400);exit('HTTPS is required.');}
session_name('CHAT29SESSID');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
try {$pdo=new PDO('mysql:host='.$cfg['db_host'].';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);}catch(Throwable $e){error_log('CHAT29 DB: '.$e->getMessage());http_response_code(503);exit('Database unavailable. Check configuration.');}
function db(string $sql,array $params=[]):PDOStatement {global $pdo;$s=$pdo->prepare($sql);$s->execute($params);return $s;}
function h(?string $s):string{return htmlspecialchars($s??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf():string {if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function check_csrf():void {if(!hash_equals(csrf(),(string)($_POST['csrf']??$_SERVER['HTTP_X_CSRF_TOKEN']??'')))fail('Invalid security token',419);}
function fail(string $msg,int $status=400):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'error'=>$msg]);exit;}
function out(array $a):never {header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]+$a,JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function me():?array {if(empty($_SESSION['uid']))return null;return db('SELECT id,name,email FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetch()?:null;}
function auth():array {$u=me();if(!$u)fail('Please sign in',401);return $u;}
function tenant(int $id):array {$u=auth();$t=db('SELECT t.id,t.name,t.slug,t.owner_id,m.role FROM tenants t JOIN memberships m ON m.tenant_id=t.id WHERE t.id=? AND m.user_id=? AND m.active=1',[$id,$u['id']])->fetch();if(!$t)fail('Workspace not found or access denied',403);return $t;}
function role(array $t,array $allowed=['owner','admin']):void {if(!in_array($t['role'],$allowed,true))fail('Insufficient permissions',403);}
function conv(int $tenantId,int $conversationId):array {$u=auth();tenant($tenantId);$c=db('SELECT c.* FROM conversations c LEFT JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=? WHERE c.id=? AND c.tenant_id=? AND (c.kind="public" OR cm.user_id IS NOT NULL)',[$u['id'],$conversationId,$tenantId])->fetch();if(!$c)fail('Conversation not found or access denied',403);return $c;}
function limit_action(string $key,int $max=20,int $seconds=60):void {$id=hash('sha256',($_SERVER['REMOTE_ADDR']??'').':'.$key);$now=time();$v=$_SESSION['limits'][$id]??['start'=>$now,'count'=>0];if($now-$v['start']>$seconds)$v=['start'=>$now,'count'=>0];$v['count']++;$_SESSION['limits'][$id]=$v;if($v['count']>$max)fail('Too many requests. Try again later.',429);}
function audit(int $tenantId,string $action,array $detail=[]):void {$u=me();db('INSERT INTO audit_logs (tenant_id,user_id,action,details) VALUES (?,?,?,?)',[$tenantId,$u['id']??null,$action,json_encode($detail)]);}
