# 高校学生证优惠资格核验与挂失系统

窗口核验学生优惠时，同时确认**在读资格**与**学生证有效性**；学生端支持挂失、补证与进度查询；窗口扫码即得服务端实时核验结论（仅展示必要的掩码身份信息）。

## 一条命令启动

```bash
./dev.sh
```

脚本会自动：检查/安装用户目录下的 PHP 8.4 与 Composer（无需 root）→ 安装后端依赖 → 迁移并写入 SQLite 演示数据 → 安装并构建 React 前端 → 启动服务。

启动后访问：

- 系统首页：<http://127.0.0.1:8000/app/>
- 学生端：<http://127.0.0.1:8000/app/#/student/login>
- 窗口端：<http://127.0.0.1:8000/app/#/staff/login>

其他用法：

```bash
./dev.sh --dev        # 前端热更新开发模式（Vite 5173 + Laravel 8000）
./dev.sh --reset      # 清空数据库并重新写入演示数据
./dev.sh --test       # 运行全部后端测试（含真实跨进程并发测试）
./dev.sh --test-fast  # 快速测试（排除并发组）
```

## 演示账号

| 角色 | 账号 | 密码 | 说明 |
| --- | --- | --- | --- |
| 学生 | `2023010001` | `student123` | 张三，在读，有效证 |
| 学生 | `2023010002` | `student123` | 李四，已补证：旧证号 `-01` 永久失效，新证 `-02` 有效 |
| 学生 | `2023010003` | `student123` | 王五，休学中（资格中止，证件实体未动） |
| 学生 | `2023010004` | `student123` | 赵六，已挂失待补证 |
| 窗口 | `staff@example.edu.cn` | `staff123` | 窗口工作人员 |

## 核心业务规则与实现

### 资格与证件分别管理

- `students.enrollment_status`：在读资格（`enrolled` / `suspended`），休学、恢复只改这一列，**从不触碰证件表**。
- `student_cards.status`：证件状态，单向状态机：

  ```
  active ──挂失──▶ lost ──补证完成──▶ replaced
  active └────────补证完成──────────▶ replaced
  ```

  `lost` / `replaced` 均为终态，**不可逆转、不可复用**。

### 核验的两个必要条件（`VerificationService`）

窗口每次扫码只把二维码中的 `scan_token` 发到服务端，由服务端用单条 JOIN 语句读取最新已提交状态并实时计算：

1. 证件维度：`active`（未挂失、未被补证取代）；
2. 资格维度：学生当前 `enrolled`。

任一不满足即拒绝，并给出明确原因码：`card_lost` / `card_replaced` / `not_enrolled` / `card_not_found`。响应只含姓名、学院、**掩码学号**（如 `2023****01`）与掩码证号，完整学号不出现在任何窗口端响应中。

### 旧证拦截

- 挂失在 `CardService::reportLost()` 的事务内把证件置为 `lost`，**即时不可用**；重复挂失幂等。
- 补证完成 `issueReplacement()` 在**同一个 IMMEDIATE 事务**内把申请所指旧证与其他有效证置为 `replaced`，并签发全新证号（学号-版次，全局唯一索引兜底）与全新扫码 token。旧证号保留唯一记录但永久失效，补证完成后再次出示一律 `card_replaced`。
- 补证受理用“条件更新原子认领”，并发重复完成只有一笔发证，另一笔得到 422。

### 并发核验（挂失与扫码同时发生）

- SQLite 配置为 **WAL + busy_timeout=5s + IMMEDIATE 写事务**（`config/database.php`）。
- 核验是单语句只读快照：读到的是该语句开始时最新已提交状态，读不阻塞写；挂失/补证写事务在服务端串行提交。
- 因此结论严格取决于服务端提交顺序：挂失先提交则本次扫码拒绝；核验先执行则其放行仅代表核验那一刻的状态，挂失提交后的下一次扫码必然拒绝。窗口端不缓存结论、不接受客户端声称的状态，**不能仅凭本地页面显示放行**。
- `tests/Feature/ConcurrencyTest.php` 用多个真实 OS 进程连接同一个 SQLite 文件，复现持锁交错、并发挂失、并发完成补证等场景。

### 恢复学籍不恢复旧证

`EnrollmentService::resume()` 只把资格改回 `enrolled`。已挂失证保持 `lost`、已补证旧证保持 `replaced`——证件终态由证件实体自行负责，与学籍变动完全解耦。

### 只能查询自己的证件与申请

- 学生端与窗口端使用不同的认证守卫（`students` 表 / `users` 表，Sanctum token 隔离），学生 token 调用窗口接口一律 401。
- 证件列表、补证申请列表都以当前学生为作用域；跨学生传 `card_id` 提交补证 → 404；直接访问他人申请 id → 策略 403；替他人挂失 → 策略 403。

## 技术栈与目录

- 后端：PHP 8.4 + Laravel 12，Eloquent，SQLite（WAL）
- 前端：React 18 + React Router + Vite 6 + axios；二维码由 `qrcode` 本地生成
- 扫码模拟：窗口端“本地模拟扫描枪”从演示接口选取证件生成二维码并发起实时核验（生产环境中 token 只存在于实体二维码）

```
backend/
  app/
    Models/            Student（资格） / StudentCard（证件） / ReplacementApplication
    Services/          VerificationService / CardService / EnrollmentService
    Http/Controllers/Api/  学生端、窗口端、核验、学籍接口
    Policies/          本人归属校验
  database/
    migrations/        资格表、证件表、补证申请表（唯一索引保证证号永不复用）
    seeders/           4 名演示学生 + 窗口账号
  tests/
    Feature/           核验/生命周期/学籍分离/越权 + ConcurrencyTest（真实多进程）
    concurrency/actor.php  并发测试的子进程脚本
frontend/
  src/pages/student/   登录、我的证件与挂失、补证与进度
  src/pages/staff/     扫码核验、补证受理、学籍管理
scripts/
  bootstrap.sh         无 root 环境安装静态 PHP / Composer
  e2e.sh               真实 HTTP 端到端检查（23 项）
dev.sh                 一条命令入口
```

## 测试结果

- Feature 快速测试：24 个（核验双条件、掩码隐私、旧证终态、补证原子性、越权 403/404、学籍分离）
- 跨进程并发测试：5 个（挂失/核验交错、写锁串行、并发补证只发一张新证、恢复学籍不复活旧证、replaced 历经学籍变动仍失效）
- HTTP 端到端脚本：23 项断言全部通过
