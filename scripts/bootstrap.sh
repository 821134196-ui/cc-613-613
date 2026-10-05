#!/usr/bin/env bash
#
# 环境引导：在没有 root 权限的机器上把静态 PHP 与 Composer 装到 ~/.local/bin。
# 若系统已有 php / composer，则直接使用，不重复安装。
set -euo pipefail

PHP_VERSION="8.4.23"
COMPOSER_VERSION="2.10.3"
LOCAL_BIN="$HOME/.local/bin"
mkdir -p "$LOCAL_BIN"

ensure_php() {
  if command -v php >/dev/null 2>&1 && php -m | grep -q pdo_sqlite; then
    return 0
  fi
  if [ -x "$LOCAL_BIN/php" ] && "$LOCAL_BIN/php" -m | grep -q pdo_sqlite; then
    return 0
  fi

  echo ">> 未找到带 pdo_sqlite 的 PHP，下载静态 PHP $PHP_VERSION 到 $LOCAL_BIN ..."
  local tmp
  tmp="$(mktemp -d)"
  curl -fSL --retry 3 -o "$tmp/php.tar.gz" \
    "https://dl.static-php.dev/static-php-cli/common/php-${PHP_VERSION}-cli-linux-x86_64.tar.gz"
  tar xzf "$tmp/php.tar.gz" -C "$tmp"
  mv "$tmp/php" "$LOCAL_BIN/php"
  chmod +x "$LOCAL_BIN/php"
  rm -rf "$tmp"
}

ensure_composer() {
  if command -v composer >/dev/null 2>&1; then
    return 0
  fi
  if [ -x "$LOCAL_BIN/composer" ]; then
    return 0
  fi

  echo ">> 下载 Composer $COMPOSER_VERSION 到 $LOCAL_BIN ..."
  local tmp
  tmp="$(mktemp -d)"
  curl -fSL --retry 3 -o "$tmp/composer.phar" \
    "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar"
  mv "$tmp/composer.phar" "$LOCAL_BIN/composer"
  chmod +x "$LOCAL_BIN/composer"
  rm -rf "$tmp"
}

ensure_php
ensure_composer

PHP_BIN="$(command -v php || echo "$LOCAL_BIN/php")"
COMPOSER_BIN="$(command -v composer || echo "$LOCAL_BIN/composer")"
echo "PHP:      $($PHP_BIN -r 'echo PHP_VERSION;')"
echo "Composer: $($PHP_BIN "$COMPOSER_BIN" --version 2>/dev/null | head -1)"
