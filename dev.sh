#!/usr/bin/env bash
#
# 一条命令启动：高校学生证优惠资格核验与挂失系统
#
# 用法：
#   ./dev.sh              # 安装依赖 + 迁移种子 + 构建前端 + 启动（打开 http://127.0.0.1:8000）
#   ./dev.sh --dev        # 前端热更新开发模式（Vite 5173 + Laravel 8000）
#   ./dev.sh --reset      # 清空数据库并重新写入演示数据后启动
#   ./dev.sh --test       # 运行全部后端测试（含跨进程并发测试）
#   ./dev.sh --test-fast  # 只运行快速测试（排除并发组）
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"
LOCAL_BIN="$HOME/.local/bin"
export PATH="$LOCAL_BIN:$PATH"

PHP_BIN="$(command -v php || echo "$LOCAL_BIN/php")"
COMPOSER_BIN="$(command -v composer || echo "$LOCAL_BIN/composer")"
COMPOSER="$PHP_BIN $COMPOSER_BIN"

MODE="serve"
for arg in "$@"; do
  case "$arg" in
    --dev) MODE="dev" ;;
    --reset) MODE="reset" ;;
    --test) MODE="test" ;;
    --test-fast) MODE="test-fast" ;;
    *) echo "未知参数: $arg"; exit 2 ;;
  esac
done

echo "==> [1/5] 检查 PHP / Composer"
bash "$ROOT/scripts/bootstrap.sh"

echo "==> [2/5] 安装后端依赖"
if [ ! -d "$BACKEND/vendor" ]; then
  (cd "$BACKEND" && $COMPOSER install --no-interaction)
fi

if [ "$MODE" = "test" ] || [ "$MODE" = "test-fast" ]; then
  echo "==> 运行测试"
  if [ "$MODE" = "test" ]; then
    (cd "$BACKEND" && $PHP_BIN artisan test)
  else
    (cd "$BACKEND" && $PHP_BIN artisan test --exclude-group=concurrency)
  fi
  exit 0
fi

echo "==> [3/5] 准备环境、数据库与演示数据"
cd "$BACKEND"
if [ ! -f .env ]; then
  cp .env.example .env
  # 默认即 sqlite；清理 mysql 占位项
  sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
  sed -i '/^DB_HOST=/d;/^DB_PORT=/d;/^DB_DATABASE=/d;/^DB_USERNAME=/d;/^DB_PASSWORD=/d' .env
fi
[ -f database/database.sqlite ] || touch database/database.sqlite
grep -q '^APP_KEY=base64:' .env || $PHP_BIN artisan key:generate

if [ "$MODE" = "reset" ]; then
  $PHP_BIN artisan migrate:fresh --seed --force
else
  $PHP_BIN artisan migrate --force
  # 仅在库为空时播种演示数据
  STUDENT_COUNT="$($PHP_BIN artisan tinker --execute='echo \App\Models\Student::count();' 2>/dev/null | tail -1 | tr -d '[:space:]')"
  if [ "$STUDENT_COUNT" = "0" ]; then
    $PHP_BIN artisan db:seed --force
  fi
fi

echo "==> [4/5] 安装前端依赖"
if [ ! -d "$FRONTEND/node_modules" ]; then
  (cd "$FRONTEND" && npm install --no-audit --no-fund)
fi

cleanup() {
  echo
  echo "==> 正在停止服务…"
  [ -n "${ARTISAN_PID:-}" ] && kill "$ARTISAN_PID" 2>/dev/null || true
  [ -n "${VITE_PID:-}" ] && kill "$VITE_PID" 2>/dev/null || true
  wait 2>/dev/null || true
}
trap cleanup INT TERM EXIT

if [ "$MODE" = "dev" ]; then
  echo "==> [5/5] 启动开发服务"
  echo "    前端（热更新）: http://127.0.0.1:5173"
  echo "    API:           http://127.0.0.1:8000/api"
  (cd "$BACKEND" && $PHP_BIN artisan serve --host=127.0.0.1 --port=8000) &
  ARTISAN_PID=$!
  (cd "$FRONTEND" && npm run dev) &
  VITE_PID=$!
  wait -n "$ARTISAN_PID" "$VITE_PID"
else
  echo "==> [5/5] 构建前端并启动服务"
  (cd "$FRONTEND" && npm run build)
  echo
  echo "============================================================"
  echo " 系统已启动： http://127.0.0.1:8000"
  echo " 学生端：     http://127.0.0.1:8000/app/#/student/login"
  echo " 窗口端：     http://127.0.0.1:8000/app/#/staff/login"
  echo " 演示账号见首页，按 Ctrl+C 停止"
  echo "============================================================"
  exec $PHP_BIN artisan serve --host=0.0.0.0 --port=8000
fi
