<?php
require __DIR__.'/../private/bootstrap.php';
$u=auth();$id=(int)($_GET['id']??0);$a=db('SELECT * FROM attachments WHERE id=?',[$id])->fetch();if(!$a)fail('Attachment not found',404);conv((int)$a['tenant_id'],(int)$a['conversation_id']);$file=__DIR__.'/uploads/'.$a['stored_name'];if(!is_file($file))fail('Attachment unavailable',404);header('Content-Type: '.$a['mime']);header('Content-Length: '.$a['size_bytes']);header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\''.rawurlencode($a['original_name']));header('Cache-Control: private, no-store');readfile($file);
