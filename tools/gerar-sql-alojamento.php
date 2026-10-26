<?php
/**
 * Gera database/hosting.sql a partir de database/schema.sql, para importar num alojamento
 * partilhado (InfinityFree, cPanel, ...), onde a base de dados já existe com outro nome.
 *
 *   - retira CREATE DATABASE / USE (o phpMyAdmin do alojamento importa para a base escolhida);
 *   - mantém o catálogo de demonstração (categorias, agricultores, produtos) para o site ter conteúdo;
 *   - deixa TODAS as contas sem palavra-passe utilizável: as palavras-passe de demonstração
 *     são conhecidas e não podem ser usadas num site público. O SuperAdmin é configurado
 *     depois em setup.php (ver HOSPEDAGEM.md).
 *
 * Uso (na pasta do projeto):  C:\xampp\php\php.exe tools\gerar-sql-alojamento.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$src = $root . '/database/schema.sql';
$dst = $root . '/database/hosting.sql';
if (!is_file($src)) { fwrite(STDERR, "Não encontrei database/schema.sql\n"); exit(1); }

$out = [];
$removed = 0;
foreach (file($src, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+)\b/i', $line)) { $removed++; continue; }
    $out[] = $line;
}

$header = [
    '-- AgroMercado — base de dados para alojamento (gerada por tools/gerar-sql-alojamento.php)',
    '-- Importe este ficheiro no phpMyAdmin do alojamento, com a base de dados do alojamento selecionada.',
    '-- Não editar à mão: altere database/schema.sql e volte a gerar.',
    '',
];
$footer = [
    '',
    '-- Segurança: as palavras-passe de demonstração são públicas, por isso nenhuma conta fica utilizável.',
    '-- O SuperAdmin define email e palavra-passe em setup.php (ver HOSPEDAGEM.md).',
    "UPDATE users SET password = '!configurar-em-setup';",
    '',
];
file_put_contents($dst, implode("\n", array_merge($header, $out, $footer)));
printf("database/hosting.sql gerado (%d linhas de CREATE DATABASE/USE retiradas).\n", $removed);
