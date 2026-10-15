#!/usr/bin/env bash
# Smoke test HTTP do AgroMercado. Uso: BASE_URL=http://localhost/agro-marketplace bash tests/smoke.sh
set -Eeuo pipefail
BASE_URL="${BASE_URL:-http://localhost/agro-marketplace}"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
fail=0
check() { if [ "$2" = "$3" ]; then printf '  ok   %s\n' "$1"; else printf '  FAIL %s (esperado %s, obtido %s)\n' "$1" "$3" "$2"; fail=1; fi; }
status() { curl -s -o "$tmp/body" -w '%{http_code}' "$@"; }
clean() { if grep -qiE 'Fatal error|Warning:|Notice:|Deprecated:|Uncaught' "$tmp/body"; then printf '  FAIL %s mostra erro PHP\n' "$1"; fail=1; fi; }
login() { local jar="$tmp/$1.jar"; local t; t=$(curl -s -c "$jar" -b "$jar" "$BASE_URL/login.php" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'); curl -s -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' --data-urlencode "csrf=$t" --data-urlencode "email=$2" --data-urlencode "password=$3" "$BASE_URL/login.php"; }

echo "Páginas públicas"
for r in "" marketplace.php "marketplace.php?q=tomate" login.php cadastro.php "produto.php?id=1" "agricultor.php?id=1" termos.php privacidade.php cookies.php recuperar-password.php redefinir-password.php; do check "/$r" "$(status "$BASE_URL/$r")" 200; clean "/$r"; done
check "produto inexistente" "$(status "$BASE_URL/produto.php?id=999999")" 404

echo "Ficheiros protegidos"
for r in database/schema.sql config/config.php README.md .env.example debug-imagens.php uploads/; do check "/$r bloqueado" "$(status "$BASE_URL/$r")" 403; done

echo "Autenticação e permissões"
case "$(login admin jorge.sumbane@agromercado.local Admin@123)" in *admin/dashboard.php) check "login admin" ok ok;; *) check "login admin" falhou ok;; esac
case "$(login farmer joao@agromercado.local Farmer@123)" in *agricultor/dashboard.php) check "login agricultor" ok ok;; *) check "login agricultor" falhou ok;; esac
case "$(login buyer carlos_matola@agromercado.local Buyer@123)" in *comprador/dashboard.php) check "login comprador" ok ok;; *) check "login comprador" falhou ok;; esac
check "comprador → admin" "$(status -b "$tmp/buyer.jar" "$BASE_URL/admin/dashboard.php")" 403
check "agricultor → admin" "$(status -b "$tmp/farmer.jar" "$BASE_URL/admin/dashboard.php")" 403
check "API pedidos via GET" "$(status -b "$tmp/farmer.jar" "$BASE_URL/api/pedidos.php")" 405
check "webhook sem assinatura" "$(status -X POST -d '{}' "$BASE_URL/api/pagamentos.php?webhook=1&provider=teste")" 401

echo "Áreas autenticadas"
for p in admin/dashboard.php admin/utilizadores.php admin/pedidos.php admin/pagamentos.php admin/relatorios.php admin/zonas.php; do check "$p" "$(status -b "$tmp/admin.jar" "$BASE_URL/$p")" 200; clean "$p"; done
for p in agricultor/dashboard.php agricultor/produtos.php agricultor/pedidos.php agricultor/stock.php agricultor/inventario.php; do check "$p" "$(status -b "$tmp/farmer.jar" "$BASE_URL/$p")" 200; clean "$p"; done
for p in comprador/dashboard.php comprador/pedidos.php comprador/avaliacoes.php comprador/perfil.php carrinho.php; do check "$p" "$(status -b "$tmp/buyer.jar" "$BASE_URL/$p")" 200; clean "$p"; done

[ "$fail" = 0 ] && echo "Smoke test aprovado." || { echo "Smoke test com falhas."; exit 1; }
