<?php
/* Entrega segura de documentos de verificação (uploads/agricultores/ está bloqueado ao acesso direto). */
require_once __DIR__.'/../includes/permissions.php';
require_role('superadmin');

$id = safe_int($_GET['id'] ?? 0);
$s = db()->prepare('SELECT id, farmer_id, document_type, file_path FROM farmer_documents WHERE id=?');
$s->execute([$id]);
$doc = $s->fetch();

$root = realpath(__DIR__ . '/../uploads/agricultores');
$rel = $doc ? ltrim(str_replace('\\', '/', (string)$doc['file_path']), '/') : '';
if (str_starts_with($rel, 'uploads/')) $rel = substr($rel, 8);
$file = ($doc && $root && str_starts_with($rel, 'agricultores/')) ? realpath(__DIR__ . '/../uploads/' . $rel) : false;

if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
$allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$inline = isset($allowed[$mime]);
if (!$inline) $mime = 'application/octet-stream';
$name = 'documento_' . (int)$doc['id'] . '_' . preg_replace('/[^a-z]/', '', (string)$doc['document_type']) . '.' . ($allowed[$mime] ?? pathinfo($file, PATHINFO_EXTENSION));

audit(user()['id'], 'view', 'farmer_documents', 'Consultou o documento #' . (int)$doc['id'] . ' do agricultor #' . (int)$doc['farmer_id']);

while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file);
exit;
