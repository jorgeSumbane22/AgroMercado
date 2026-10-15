<?php
require_once __DIR__ . '/auth.php';
function is_admin(): bool { return logged_in() && user()['role']==='superadmin'; }
function is_farmer(): bool { return logged_in() && user()['role']==='farmer'; }
function is_buyer(): bool { return logged_in() && user()['role']==='buyer'; }
?>
