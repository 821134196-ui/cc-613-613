#!/usr/bin/env bash
#
# 高校学生证优惠资格核验与挂失系统 —— 一条命令启动
#
#   ./start.sh
#
# 启动后：
#   前端（学生端 / 窗口扫码 / 教务端）  http://127.0.0.1:5173
#   后端 API                            http://127.0.0.1:8000
#
# 首次启动会自动完成：composer/npm 依赖安装（若缺失）、SQLite 建库与演示数据。
# 按 Ctrl+C 会同时关闭前后端。

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"

BACKEND_HOST="127.0.0.1"
BACKEND_PORT="${BACKEND_PORT:-8000}"
FRONTEND_HOST="127.0.0.1"
FRONTEND_PORT="${FRONTEND_PORT:-5173}"

# ---------- 选择 PHP ----------
# 优先使用项目自带的本地静态 PHP（无 root 安装于 ~/php-local），否则用系统 php。
LOCAL_PHP="$HOME/php-local/bin/php"
LOCAL_PHP_ROOT="$HOME/php-local/root"
if [ -x "$LOCAL_PHP" ]; then
  PHP="$LOCAL_PHP"
  # 扩展 ini 扫描目录 + 本地共享库路径（RUNPATH 已处理库，这里双保险）
  export PHP_INI_SCAN_DIR="$HOME/php-local/conf.d${PHP_INI_SCAN_DIR:+:$PHP_INI_SCAN_DIR}"
  export LD_LIBRARY_PATH="$LOCAL_PHP_ROOT/usr/lib/x86_64-linux-gnu:$LOCAL_PHP_ROOT/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
else
  PHP="$(command -v php || true)"
fi

if [ -z "${PHP:-}" ] || ! "$PHP" -v >/dev/null 2>&1; then
  echo "✗ 未找到可用的 PHP（需要 8.2+ 且含 pdo_sqlite/mbstring 等扩展）。" >&2
  exit 1
fi

echo "• 使用 PHP: $PHP"
"$PHP" -v | head -1
command -v node >/dev/null && echo "• Node: $(node -v)"

# ---------- 依赖（缺失才安装） ----------
if [ ! -f "$BACKEND/vendor/autoload.php" ]; then
  echo "• 首次运行：安装后端依赖…"
  if [ -x "$HOME/php-local/bin/composer" ]; then
    "$HOME/php-local/bin/composer" install --working-dir="$BACKEND" --no-interaction
  else
    composer install --working-dir="$BACKEND" --no-interaction
  fi
fi
if [ ! -d "$FRONTEND/node_modules" ]; then
  echo "• 首次运行：安装前端依赖…"
  (cd "$FRONTEND" && npm install)
fi

# ---------- 数据库（SQLite）建库 + 演示数据 ----------
# 全新检出没有 .env 时，从模板生成并补齐 SQLite 配置与 APP_KEY。
if [ ! -f "$BACKEND/.env" ]; then
  echo "• 未发现 .env，由模板生成…"
  cp "$BACKEND/.env.example" "$BACKEND/.env"
  sed -i 's|^APP_NAME=.*|APP_NAME="学生优惠资格核验系统"|' "$BACKEND/.env"
  grep -q '^DB_DATABASE=' "$BACKEND/.env" || echo 'DB_DATABASE=database/database.sqlite' >> "$BACKEND/.env"
  "$PHP" "$BACKEND/artisan" key:generate --force --no-interaction
fi

DB_FILE="$BACKEND/database/database.sqlite"
mkdir -p "$BACKEND/database"
touch "$DB_FILE"

echo "• 执行数据库迁移…"
"$PHP" "$BACKEND/artisan" migrate --force --no-interaction

# 仅在库为空（没有任何学生）时写入演示数据，避免重复 seed。
STUDENT_COUNT="$("$PHP" "$BACKEND/artisan" tinker --no-interaction --execute='echo \App\Models\Student::count();' 2>/dev/null | tail -1 || echo 0)"
if [ "${STUDENT_COUNT:-0}" = "0" ]; then
  echo "• 写入演示数据…"
  "$PHP" "$BACKEND/artisan" db:seed --force --no-interaction
fi

# ---------- 端口占用提示 ----------
wait_for_port() {
  local host="$1" port="$2" i=0
  while (( i < 60 )); do
    if (exec 3<>"/dev/tcp/$host/$port") 2>/dev/null; then exec 3<&-; return 0; fi
    sleep 0.5; i=$((i+1))
  done
  return 1
}

PIDS=()
cleanup() {
  echo
  echo "• 正在停止服务…"
  for pid in "${PIDS[@]:-}"; do
    [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  done
  wait 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# ---------- 启动后端（PHP 内置服务器 + Laravel 路由） ----------
ROUTER="$BACKEND/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
echo "• 启动后端 API  http://$BACKEND_HOST:$BACKEND_PORT"
(
  cd "$BACKEND/public"
  exec "$PHP" -S "$BACKEND_HOST:$BACKEND_PORT" "$ROUTER"
) &
PIDS+=($!)

# ---------- 启动前端（Vite，已把 /api 代理到后端） ----------
echo "• 启动前端      http://$FRONTEND_HOST:$FRONTEND_PORT"
(
  cd "$FRONTEND"
  exec npm run dev -- --host "$FRONTEND_HOST" --port "$FRONTEND_PORT"
) &
PIDS+=($!)

if wait_for_port "$FRONTEND_HOST" "$FRONTEND_PORT"; then
  echo
  echo "────────────────────────────────────────────────────────"
  echo " 系统已启动："
  echo "   前端入口（请用浏览器打开）: http://$FRONTEND_HOST:$FRONTEND_PORT"
  echo "   窗口扫码核验:               http://$FRONTEND_HOST:$FRONTEND_PORT/counter"
  echo "   学生登录:                   2023010101 / student123"
  echo "   教务登录:                   admin@school.edu.cn / admin123"
  echo " 按 Ctrl+C 停止全部服务。"
  echo "────────────────────────────────────────────────────────"
else
  echo "✗ 前端服务未能在预期时间内启动，请查看上方日志。" >&2
  exit 1
fi

wait
