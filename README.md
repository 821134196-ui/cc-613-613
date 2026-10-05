# 高校学生证优惠资格核验与挂失系统

窗口核验学生优惠时，**同时确认「学生是否在读（资格）」与「这张学生证是否有效（证件实体）」**，
确保学生补办新证后，**旧证一经挂失立即失效、补证办结后旧证号永久失效**，无法再用来享受优惠。

- 后端：PHP 8.2 + Laravel 12，Eloquent + SQLite（WAL），Sanctum 令牌认证
- 前端：React + Vite（学生端 / 窗口扫码核验端 / 教务受理端）
- 扫码：无真实扫码枪，提供**本地模拟扫码服务**（返回可扫的二维码令牌列表）
- 启动：**一条命令 `./start.sh`** 同时拉起前后端，首次自动建库与写入演示数据

---

## 一条命令启动

```bash
./start.sh
```

启动后：

| 入口 | 地址 |
| --- | --- |
| 前端总入口 | http://127.0.0.1:5173 |
| 窗口扫码核验 | http://127.0.0.1:5173/counter |
| 后端 API | http://127.0.0.1:8000 |

演示账号：

- 学生：`2023010101 / student123`（另有 `2023010202` 休学、`2022030303` 已补过证）
- 教务：`admin@school.edu.cn / admin123`

> 无 root 环境说明：仓库在没有系统 PHP 的机器上，会使用 `~/php-local` 下的便携 PHP
> （Debian 包解包 + RUNPATH）。若机器本身已装好 PHP 8.2（含 pdo_sqlite/mbstring 等），
> `start.sh` 也能直接用系统 PHP。

---

## 领域模型（资格与证件分离）

```
Student（学生 / 学籍资格）
  ├─ enrollment_status: active 在读 | suspended 休学 | graduated 毕业
  │
  └─ hasMany StudentCard（学生证实体，物理证件）
        ├─ card_no / version（补证换号、换版本）
        ├─ status: active 有效 → lost 已挂失 → replaced 已被新证替代（单向、不可逆）
        └─ qr_token（扫码核验只拿到这个 UUID，拿不到学号）

Student hasMany CardRequest（挂失 / 补证申请单，用于办理进度查询）
```

关键规则：

1. **资格与证件分别管理**。休学/恢复只改 `Student.enrollment_status`，从不触碰证件状态。
2. **证件状态机单向不可逆**：`active → lost / replaced`，`lost/replaced` 是终态。
   恢复在读**不会**把旧证改回 `active`。
3. **补证**：旧证 `lost → replaced`，签发全新 `card_no` 与全新 `qr_token` 的新证，旧证号不再被任何有效证件复用。
4. 所有状态迁移集中在 `app/Services/CardService.php`，用数据库事务包裹。

## 核验逻辑（服务端实时判定）

`app/Services/VerificationService.php::verifyByToken()`：

- 窗口扫码得到的只是 `qr_token`；每次核验都**按令牌实时查库**，不读取/信任页面上的旧结论。
- 必须 `card.status == active` **且** `student.enrollment_status == active` 才放行。
- 返回放行结论 + 分项检查（证件存在 / 证件有效 / 学籍在读）+ 中文原因。
- **最小化身份信息**：只返回姓名、院系、专业、**脱敏学号**（如 `2023****01`）、脱敏证件号、版本；
  响应体中不包含完整学号、手机号等。

---

## 四项重点安全检查（均已自动化验证）

测试位于 `backend/tests/`：

| # | 关注点 | 测试 |
| --- | --- | --- |
| 1 | **旧证拦截 / 旧证号永久失效** | `tests/Feature/OldCardBlockingTest.php` |
| 2 | **挂失与扫码核验并发** | `tests/Feature/ConcurrentVerificationTest.php` → 调用 `tests/concurrency/loss_vs_verify_race.php`（真实多进程 fork + 文件型 SQLite/WAL） |
| 3 | **恢复学籍不恢复旧证** | `tests/Feature/ReenrollmentDoesNotReviveOldCardTest.php` |
| 4 | **不能跨学生访问补证资料** | `tests/Feature/CrossStudentAccessTest.php` |

运行：

```bash
cd backend
vendor/bin/phpunit                       # 全部 16 个用例
php tests/concurrency/loss_vs_verify_race.php   # 单独跑多进程竞态
```

### 并发正确性是怎么保证的

- SQLite 配置为 **WAL + busy_timeout=5s**（`config/database.php`），读不阻塞写，写之间串行。
- 挂失/替代使用**条件式原子更新**：
  `UPDATE student_cards SET status='lost' ... WHERE id=? AND status='active'`，
  以受影响行数判断是否抢到，配合事务；状态只能单向迁移，因此**一旦拒绝就不可能再放行**。
- 核验端每次都重新实时查询服务端当前状态，**窗口本地页面的显示不能作为放行依据**
  （页面上的「重新核验」按钮也专门用于演示这一点：学生在另一终端挂失后，重新核验结论立即翻转）。
- 竞态脚本断言：每个核验进程观察到的结论**单调**（出现 `false` 后不再出现 `true`）、
  响应内部分项与结论自洽、终态旧证为 `lost`。

---

## 接口一览

公开：

- `POST /api/student/login`、`POST /api/admin/login`
- `POST /api/verify`（凭 `qr_token` 实时核验）
- `GET  /api/simulator/targets`（**仅本地模拟扫码**用的可扫目标）

学生端（学生令牌，仅本人，中间件 `student`）：

- `GET /api/student/me`、`POST /api/student/logout`
- `GET /api/student/cards`、`POST /api/student/cards/{card}/loss`
- `POST /api/student/reissues`
- `GET /api/student/requests`、`GET /api/student/requests/{request}`

教务端（admin ability）：

- `GET /api/admin/students`、`POST /api/admin/students/{student}/enrollment`
- `GET /api/admin/requests`、`POST /api/admin/requests/{request}/approve|reject`

越权处理：学生令牌只持 `student` ability（不能访问教务接口）；学生访问**他人**证件或申请
一律返回 **404**（不泄露资源是否存在），列表接口天然只返回本人数据。

---

## 目录结构

```
start.sh                         一条命令启动
backend/
  app/
    Models/        Student / StudentCard / CardRequest
    Services/      CardService（挂失/补证/学籍，事务+原子更新） VerificationService（实时核验+脱敏）
    Http/Controllers/Api/ ...    Http/Middleware/EnsureStudent.php
  database/migrations|seeders/
  tests/Feature/  tests/concurrency/
frontend/
  src/pages/  CounterVerify（窗口扫码） StudentLogin/StudentHome AdminLogin/AdminHome
  src/api.js  src/App.jsx  src/styles.css
```
