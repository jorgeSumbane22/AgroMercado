<?php
require_once __DIR__.'/../includes/auth.php';$data=api_guard();$s=db()->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0');$s->execute([(int)user()['id']]);json_out(['success'=>true,'message'=>'Notificações marcadas como lidas','updated'=>$s->rowCount()]);
