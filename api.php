<?php
require __DIR__.'/../private/bootstrap.php';
$action=(string)($_GET['action']??$_POST['action']??'');
if($_SERVER['REQUEST_METHOD']==='POST')check_csrf();
if($action==='bootstrap'){ $u=me();if(!$u)out(['user'=>null,'csrf'=>csrf()]);$ws=db('SELECT t.id,t.name,t.slug,m.role FROM tenants t JOIN memberships m ON m.tenant_id=t.id WHERE m.user_id=? AND m.active=1 ORDER BY t.id',[$u['id']])->fetchAll();out(['user'=>$u,'workspaces'=>$ws,'csrf'=>csrf()]);}
if($action==='register'){
 limit_action('register',6,300);$name=trim((string)($_POST['name']??''));$email=strtolower(trim((string)($_POST['email']??'')));$pass=(string)($_POST['password']??'');$company=trim((string)($_POST['company']??''));
 if(mb_strlen($name)<2||mb_strlen($name)>120||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<12||strlen($pass)>200||mb_strlen($company)<2||mb_strlen($company)>150)fail('Provide a name, valid email, company name and password of at least 12 characters');
 $slug=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($company)),'-').'-'.bin2hex(random_bytes(3));
 $pdo->beginTransaction();try{db('INSERT INTO users(name,email,password_hash) VALUES (?,?,?)',[$name,$email,password_hash($pass,PASSWORD_ARGON2ID)]);$uid=(int)$pdo->lastInsertId();db('INSERT INTO tenants(name,slug,owner_id) VALUES (?,?,?)',[$company,$slug,$uid]);$tid=(int)$pdo->lastInsertId();db('INSERT INTO memberships(tenant_id,user_id,role) VALUES (?, ?,"owner")',[$tid,$uid]);db('INSERT INTO conversations(tenant_id,kind,title,created_by) VALUES (?,"public","general",?)',[$tid,$uid]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();if($e instanceof PDOException && $e->getCode()==='23000')fail('This email is already registered',409);error_log($e->getMessage());fail('Unable to create workspace',500);}session_regenerate_id(true);$_SESSION['uid']=$uid;out(['tenant_id'=>$tid]);
}
if($action==='login'){limit_action('login',8,300);$email=strtolower(trim((string)($_POST['email']??'')));$pass=(string)($_POST['password']??'');$u=db('SELECT id,password_hash FROM users WHERE email=?',[$email])->fetch();if(!$u||!password_verify($pass,$u['password_hash'])){usleep(250000);fail('Incorrect email or password',401);}session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];out([]);}
if($action==='logout'){$_SESSION=[];session_regenerate_id(true);out([]);}
if($action==='invite_register'){
 limit_action('invite_register',8,300);$token=(string)($_POST['token']??'');$name=trim((string)($_POST['name']??''));$pass=(string)($_POST['password']??'');
 if(!preg_match('/^[a-f0-9]{64}$/',$token)||mb_strlen($name)<2||mb_strlen($name)>120||strlen($pass)<12)fail('Invalid registration details');
 $pdo->beginTransaction();try{$i=db('SELECT * FROM invites WHERE token_hash=? AND accepted_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',$token)])->fetch();if(!$i)throw new RuntimeException('Invitation expired or invalid');$existing=db('SELECT id FROM users WHERE email=?',[$i['email']])->fetchColumn();if($existing)throw new RuntimeException('Account exists. Sign in to accept the invitation.');db('INSERT INTO users(name,email,password_hash) VALUES (?,?,?)',[$name,$i['email'],password_hash($pass,PASSWORD_ARGON2ID)]);$uid=(int)$pdo->lastInsertId();db('INSERT INTO memberships(tenant_id,user_id,role) VALUES(?,?,?)',[$i['tenant_id'],$uid,$i['role']]);db('UPDATE invites SET accepted_at=NOW() WHERE id=?',[$i['id']]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();fail($e instanceof RuntimeException?$e->getMessage():'Unable to register invitation',400);}session_regenerate_id(true);$_SESSION['uid']=$uid;out(['tenant_id'=>(int)$i['tenant_id']]);
}
$u=auth();
if($action==='accept_invite'){
 $token=(string)($_POST['token']??'');if(!preg_match('/^[a-f0-9]{64}$/',$token))fail('Invalid invitation');
 $pdo->beginTransaction();try{$invite=db('SELECT * FROM invites WHERE token_hash=? AND accepted_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',$token)])->fetch();if(!$invite)throw new RuntimeException('Invitation expired or invalid');if(strtolower($invite['email'])!==strtolower($u['email']))throw new RuntimeException('Sign in with the invited email address');db('INSERT INTO memberships(tenant_id,user_id,role,active) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE active=1',[$invite['tenant_id'],$u['id'],$invite['role']]);db('UPDATE invites SET accepted_at=NOW() WHERE id=?',[$invite['id']]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();fail($e instanceof RuntimeException?$e->getMessage():'Could not accept invitation');}out(['tenant_id'=>(int)$invite['tenant_id']]);
}
if($action==='workspaces'){out(['workspaces'=>db('SELECT t.id,t.name,t.slug,m.role FROM tenants t JOIN memberships m ON m.tenant_id=t.id WHERE m.user_id=? AND m.active=1 ORDER BY t.id',[$u['id']])->fetchAll()]);}
$tid=(int)($_REQUEST['tenant_id']??0);$t=tenant($tid);
if($action==='sidebar'){
 $channels=db('SELECT c.id,c.title,c.kind, (SELECT COUNT(*) FROM messages msg WHERE msg.conversation_id=c.id AND msg.deleted_at IS NULL AND msg.id>COALESCE((SELECT last_message_id FROM read_states r WHERE r.conversation_id=c.id AND r.user_id=?),0)) unread FROM conversations c LEFT JOIN conversation_members cm ON cm.conversation_id=c.id AND cm.user_id=? WHERE c.tenant_id=? AND (c.kind="public" OR cm.user_id IS NOT NULL) ORDER BY c.kind,c.title',[$u['id'],$u['id'],$tid])->fetchAll();
 $members=db('SELECT u.id,u.name,u.email,m.role,m.active FROM memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? ORDER BY u.name',[$tid])->fetchAll();out(['workspace'=>$t,'channels'=>$channels,'members'=>$members]);
}
if($action==='messages'){
 $cid=(int)($_GET['conversation_id']??0);conv($tid,$cid);$before=max(0,(int)($_GET['before']??0));$rows=db('SELECT m.id,m.user_id,u.name,m.body,m.created_at,m.edited_at,m.deleted_at FROM messages m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? AND m.conversation_id=? AND (?=0 OR m.id<?) ORDER BY m.id DESC LIMIT 60',[$tid,$cid,$before,$before])->fetchAll();$files=db('SELECT id,original_name,mime,size_bytes,user_id,created_at FROM attachments WHERE tenant_id=? AND conversation_id=? ORDER BY id DESC LIMIT 40',[$tid,$cid])->fetchAll();out(['messages'=>array_reverse($rows),'attachments'=>$files]);
}
if($action==='send'){
 limit_action('send',100,60);$cid=(int)($_POST['conversation_id']??0);conv($tid,$cid);$body=trim((string)($_POST['body']??''));$key=(string)($_POST['client_key']??'');if($body===''||mb_strlen($body)>5000||!preg_match('/^[a-zA-Z0-9-]{12,64}$/',$key))fail('Invalid message');
 db('INSERT INTO messages(tenant_id,conversation_id,user_id,body,client_key) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$tid,$cid,$u['id'],$body,$key]);out(['id'=>(int)$pdo->lastInsertId()]);
}
if($action==='edit'||$action==='delete'){
 $mid=(int)($_POST['message_id']??0);$m=db('SELECT id,conversation_id,user_id FROM messages WHERE id=? AND tenant_id=? AND deleted_at IS NULL',[$mid,$tid])->fetch();if(!$m)fail('Message not found',404);conv($tid,(int)$m['conversation_id']);if((int)$m['user_id']!==(int)$u['id'])fail('Only the author can modify a message',403);
 if($action==='edit'){$body=trim((string)($_POST['body']??''));if($body===''||mb_strlen($body)>5000)fail('Invalid message');db('UPDATE messages SET body=?,edited_at=NOW() WHERE id=?',[$body,$mid]);}else db('UPDATE messages SET body="",deleted_at=NOW() WHERE id=?',[$mid]);out([]);
}
if($action==='read'){$cid=(int)($_POST['conversation_id']??0);conv($tid,$cid);$max=(int)db('SELECT COALESCE(MAX(id),0) FROM messages WHERE conversation_id=? AND tenant_id=?',[$cid,$tid])->fetchColumn();db('INSERT INTO read_states(conversation_id,user_id,last_message_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE last_message_id=GREATEST(last_message_id,VALUES(last_message_id))',[$cid,$u['id'],$max]);out([]);}
if($action==='channel'){
 role($t);$title=trim((string)($_POST['title']??''));$kind=(string)($_POST['kind']??'public');if(!in_array($kind,['public','private'],true)||mb_strlen($title)<2||mb_strlen($title)>70)fail('Invalid channel');db('INSERT INTO conversations(tenant_id,kind,title,created_by) VALUES(?,?,?,?)',[$tid,$kind,$title,$u['id']]);$cid=(int)$pdo->lastInsertId();if($kind==='private')db('INSERT INTO conversation_members(conversation_id,user_id) VALUES(?,?)',[$cid,$u['id']]);audit($tid,'channel.create',['id'=>$cid]);out(['id'=>$cid]);
}
if($action==='dm'){
 $other=(int)($_POST['user_id']??0);if($other===(int)$u['id'])fail('Choose another member');$exists=db('SELECT 1 FROM memberships WHERE tenant_id=? AND user_id=? AND active=1',[$tid,$other])->fetchColumn();if(!$exists)fail('Employee not found',404);
 $existing=db('SELECT c.id FROM conversations c JOIN conversation_members a ON a.conversation_id=c.id AND a.user_id=? JOIN conversation_members b ON b.conversation_id=c.id AND b.user_id=? WHERE c.tenant_id=? AND c.kind="dm" LIMIT 1',[$u['id'],$other,$tid])->fetchColumn();if($existing)out(['id'=>(int)$existing]);
 $pdo->beginTransaction();try{db('INSERT INTO conversations(tenant_id,kind,title,created_by) VALUES (?,"dm","",?)',[$tid,$u['id']]);$cid=(int)$pdo->lastInsertId();db('INSERT INTO conversation_members(conversation_id,user_id) VALUES (?,?),(?,?)',[$cid,$u['id'],$cid,$other]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();fail('Could not start conversation',500);}out(['id'=>$cid]);
}
if($action==='invite'){
 role($t);limit_action('invite',20,3600);$email=strtolower(trim((string)($_POST['email']??'')));$r=(string)($_POST['role']??'member');if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($r,['admin','member'],true))fail('Invalid invitation');$token=bin2hex(random_bytes(32));db('INSERT INTO invites(tenant_id,email,role,token_hash,expires_at,created_by) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 7 DAY),?)',[$tid,$email,$r,hash('sha256',$token),$u['id']]);audit($tid,'member.invite',['email'=>$email]);$base=rtrim($cfg['app_url'],'/');out(['link'=>$base.'/?invite='.$token]);
}
if($action==='member_role'){
 role($t,['owner']);$uid=(int)($_POST['user_id']??0);$r=(string)($_POST['role']??'');if($uid===(int)$t['owner_id']||!in_array($r,['admin','member'],true))fail('Cannot change owner role');db('UPDATE memberships SET role=? WHERE tenant_id=? AND user_id=? AND role!="owner"',[$r,$tid,$uid]);audit($tid,'member.role',['user_id'=>$uid,'role'=>$r]);out([]);
}
if($action==='workspace_name'){
 role($t,['owner']);$name=trim((string)($_POST['name']??''));if(mb_strlen($name)<2||mb_strlen($name)>150)fail('Invalid company name');db('UPDATE tenants SET name=? WHERE id=?',[$name,$tid]);audit($tid,'workspace.rename');out([]);
}
if($action==='upload'){
 limit_action('upload',15,300);$cid=(int)($_POST['conversation_id']??0);conv($tid,$cid);if(empty($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)fail('Upload failed');$f=$_FILES['file'];if($f['size']>5*1024*1024||$f['size']<1)fail('Maximum file size is 5 MB');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','application/pdf'=>'pdf','text/plain'=>'txt'];if(!isset($allowed[$mime]))fail('Only PNG, JPEG, WebP, PDF and TXT are supported');
 $stored=bin2hex(random_bytes(24)).'.'.$allowed[$mime];$dir=__DIR__.'/uploads';if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$stored))fail('Could not save file',500);chmod($dir.'/'.$stored,0600);$original=mb_substr(basename((string)$f['name']),0,200);db('INSERT INTO attachments(tenant_id,conversation_id,user_id,stored_name,original_name,mime,size_bytes) VALUES (?,?,?,?,?,?,?)',[$tid,$cid,$u['id'],$stored,$original,$mime,$f['size']]);out(['id'=>(int)$pdo->lastInsertId()]);
}
fail('Unknown action',404);
