# 金牌卖家进销存

一套适合个人、小团队和少量独立同行共同使用的自托管库存管理系统。程序可以公开，业务数据只保存在你自己的 MariaDB 数据库中。每个主账号是一个独立租户；员工账号归属于创建它的主账号，不能跨租户读取或修改库存。

## 主要能力

- 美国仓、运输中、国内仓、已售、零配件、维修等库存流程
- 主账号与员工权限、财务字段保护、自动锁屏
- 三种注册模式：关闭、邀请码、开放；默认使用邀请码
- 批量导入、导出、编辑和流转
- Docker 一键部署、自动数据库迁移、本地持久化数据
- CSRF、防爆破、会话轮换、租户隔离和输入校验

## Docker 安装

要求：Docker Engine 24+，并支持 `docker compose`。

```bash
git clone <你的 GitHub 仓库地址> inventory
cd inventory
cp .env.example .env
```

编辑 `.env`，至少填写三个不同的高强度密码：

```dotenv
DB_PASSWORD=数据库业务账号随机密码
MYSQL_ROOT_PASSWORD=数据库管理员随机密码
BOOTSTRAP_ADMIN_PASSWORD=首个系统管理员密码（至少12位且包含三类字符）
```

Linux / NAS 终端可用 `openssl rand -base64 32` 生成随机数据库密码。然后启动：

```bash
docker compose up -d --build
docker compose ps
```

浏览器打开 `http://NAS地址:8080`，使用 `.env` 中的 `BOOTSTRAP_ADMIN_USERNAME`（默认 `admin`）和管理员密码登录。第一次启动会自动建表并创建管理员；数据库使用 Docker 命名卷 `inventory_db_data`，删除或重建应用容器不会删除库存。

不要提交 `.env`。生产环境建议通过 HTTPS 反向代理访问，并将 `.env` 权限设为仅管理员可读。

## 注册模式与员工

首个管理员登录后，进入“系统设置 → 注册管理”：

- 关闭注册：登录页不显示注册入口，适合纯个人或内部团队。
- 邀请码注册（默认）：管理员生成一次性邀请码并设置有效期，适合邀请少量同行。
- 开放注册：任何能访问网站的人都能申请独立主账号，仍受频率和账号数上限保护。

同行注册后得到的是独立主账号，库存与其他主账号完全分开。你自己的员工不需要公开注册，应在“系统设置 → 员工管理”中添加并分配仓库、编辑、导入、财务等权限。

## 数据备份与恢复

创建压缩备份：

```bash
./scripts/backup.sh
```

备份默认保存在 `backups/`，不会被 Git 跟踪。建议再复制到 NAS 的另一块磁盘或加密云盘。恢复会覆盖当前数据库，先停止人员使用并再次备份：

```bash
./scripts/restore.sh backups/inventory-YYYYmmdd-HHMMSS.sql.gz
```

脚本会要求输入 `RESTORE` 二次确认。恢复完成后执行：

```bash
docker compose restart app
```

## 更新与回滚

更新前先备份：

```bash
./scripts/backup.sh
git pull --ff-only
docker compose up -d --build
docker compose ps
```

应用启动时自动执行兼容迁移。需要回滚程序时，先记录当前提交，再切回已知可用的版本并重建：

```bash
git log --oneline -5
git checkout <旧版本标签或提交>
docker compose up -d --build
```

数据库结构可能已经升级，不要盲目回滚数据库；只有在确认需要时，才使用更新前备份恢复。恢复会丢弃备份之后的新数据。

## HTTPS 反向代理

在 Nginx Proxy Manager、Caddy 或群晖反向代理中，把域名转发到 `NAS地址:8080`，启用 HTTPS 和 WebSocket/常规转发头。随后在 `.env` 设置：

```dotenv
SESSION_COOKIE_SECURE=true
TRUSTED_PROXIES=反向代理容器或主机的IP
```

多个可信代理 IP 用英文逗号分隔。不要填写 `0.0.0.0/0`，也不要把公网来源设为可信代理。修改后执行 `docker compose up -d`。

## 使用外部 MariaDB

内置数据库最省心。如果已有 MariaDB 11.x，可只构建并运行应用镜像：

```bash
docker build -t inventory-app:local .
docker run -d --name inventory-app --restart unless-stopped \
  -p 8080:80 \
  -e DB_HOST=你的数据库地址 -e DB_PORT=3306 \
  -e DB_NAME=inventory -e DB_USER=inventory \
  -e DB_PASSWORD='数据库密码' \
  -e BOOTSTRAP_ADMIN_USERNAME=admin \
  -e BOOTSTRAP_ADMIN_PASSWORD='首次管理员密码' \
  inventory-app:local
```

数据库账号需要对指定数据库拥有建表、改表和数据读写权限。不要让数据库端口直接暴露到公网。

## 常见问题

- `Set DB_PASSWORD in .env`：尚未填写 `.env` 密码，或值为空。
- 应用容器反复重启：运行 `docker compose logs app`，通常是数据库密码不一致或管理员初始密码不符合规则。
- 数据库不健康：运行 `docker compose logs db`，确认 NAS 磁盘空间和卷权限。
- HTTPS 下反复掉线：确认 `SESSION_COOKIE_SECURE=true`，并正确配置可信代理 IP。
- 忘记主账号密码：使用登录页密保找回；员工密码由所属主账号在员工管理中重置。
- 邀请码无法使用：邀请码只能使用一次，并可能已过期或被管理员撤销。

## 开发与验证

```bash
find php tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
sh tests/docker/compose_test.sh
sh tests/release/release_test.sh
sh tests/release/maintenance_scripts_test.sh
```

数据库集成测试需要设置 `TEST_DB_DSN`、`TEST_DB_USER` 和 `TEST_DB_PASSWORD`。安全问题请阅读 [SECURITY.md](SECURITY.md)。

## 许可证

本项目使用 MIT License，详见 [LICENSE](LICENSE)。第三方浏览器脚本保留各自版权声明。
