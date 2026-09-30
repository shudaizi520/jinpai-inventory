# 金牌卖家进销存

一套适合个人、小团队和少量独立同行共同使用的自托管库存管理系统。程序可以公开，业务数据只保存在你自己的 MariaDB 数据库中。每个主账号是一个独立租户；员工账号归属于创建它的主账号，不能跨租户读取或修改库存。

## 主要能力

- 美国仓、运输中、国内仓、已售、零配件、维修等库存流程
- 主账号与员工权限、财务字段保护、自动锁屏
- 三种注册模式：关闭、邀请码、开放；默认使用邀请码
- 批量导入、导出、编辑和流转
- Docker 一键部署、自动数据库迁移、本地持久化数据
- CSRF、防爆破、会话轮换、租户隔离、并发版本保护和操作审计

## 多人协作与数据安全

- 页面每 10 秒检查一次当前主账号的轻量库存修订号；只有发生变化时才重新加载数据，不会反复扫描整张库存表。
- 更新版本只在数据加载成功后记录；网络失败会在下一轮重试，旧请求不会覆盖新仓库或新页面。编辑、锁屏和临时财务授权期间暂停静默刷新。
- 每条库存都有独立版本。两名成员同时编辑时，后提交的旧版本会收到“记录已被其他成员修改，请刷新后重试”的版本冲突提示，不会静默覆盖先提交的数据；批量操作只要有一条过期就会整批回滚。
- 主账号可在“系统设置 → 操作日志”查看本团队的库存和员工账号操作。删除库存会保留不含登录密码、密保答案、会话或数据库密码的业务快照，可在没有服务编号冲突时恢复一次。
- 修改员工密码或权限后，该员工已经登录的旧会话会被强制退出，主账号自己的会话不受影响。主账号修改自己的密码时只保留当前操作会话；通过密保找回密码会使全部旧会话失效。
- 所有日志、库存修订号和员工关系都按主账号租户隔离；员工不能查看操作日志，独立主账号之间也不能互相读取或恢复数据。
- 流转、退货、编辑或导入涉及财务锁定仓库时，需要验证财务密码。流转弹窗只临时授权这次操作，完成后自动重新锁定；没有财务权限的员工仍不能查看财务字段。
- 主流程电脑仓库不能有重复服务编号，单条及批量流转都执行检查。配件库存导入只匹配同状态记录，不会将已售配件改回库存；同编号匹配多条记录时，整次导入会取消，请在软件中逐条编辑。

这些机制只增加少量按主键查询和单行修订号写入，适合 NAS 上的个人和小团队使用。正常空闲时的 10 秒同步检查是常量级查询，不会遍历库存表。

## Docker 安装

要求：Docker Engine 24+，并支持 `docker compose`。正式镜像公开发布在 GitHub Container Registry（GHCR），支持常见的 `amd64` 和 `arm64` NAS，无需在 NAS 上编译 PHP 应用。

```bash
git clone https://github.com/shudaizi520/jinpai-inventory.git inventory
cd inventory
cp .env.example .env
```

编辑 `.env`，填写两个不同的高强度数据库密码：

```dotenv
DB_PASSWORD=数据库业务账号随机密码
MYSQL_ROOT_PASSWORD=数据库管理员随机密码
```

`APP_IMAGE` 默认是公开镜像 `ghcr.io/shudaizi520/jinpai-inventory:latest`。Linux / NAS 终端可用 `openssl rand -base64 32` 生成随机数据库密码。然后直接拉取并启动：

```bash
docker compose pull
docker compose up -d
docker compose ps
```

浏览器打开 `http://NAS地址:8080`。新数据库没有任何账号时，系统会自动进入一次性的管理员初始化页面；请在 NAS 局域网中设置管理员用户名、登录密码和密保问题。第一个管理员创建成功后，初始化入口会自动关闭。数据库使用软件专属的 Docker 命名卷 `inventory_db_data`，不会连接或修改 NAS 上已有的数据库容器；删除或重建应用容器也不会删除库存。

无人值守或批量部署时，可在 `.env` 取消 `BOOTSTRAP_ADMIN_USERNAME` 和 `BOOTSTRAP_ADMIN_PASSWORD` 两行注释并填写强密码，由容器首次启动时自动创建管理员。普通安装建议保持注释，使用浏览器初始化；自动创建的管理员登录后应在个人设置中补充密保信息。

不要提交 `.env`。生产环境建议通过 HTTPS 反向代理访问，并将 `.env` 权限设为仅管理员可读。

### TrueNAS YAML 安装

TrueNAS SCALE 可新建一个“自定义应用”，复制 [`compose.truenas.example.yaml`](compose.truenas.example.yaml) 的全部内容，把开头两个密码占位符分别替换成不同的随机密码后保存。这个 YAML 会直接从 GHCR 拉取应用镜像，并创建软件专属的 MariaDB 容器和 `inventory_db_data` 数据卷；不需要另建数据库应用，也不会使用 NAS 上其他软件的数据库。

GHCR 镜像是公开的，发布流水线会在退出登录后实际检查匿名拉取和两种 CPU 架构，因此朋友安装时不需要 GitHub Token 或账号。`latest` 跟随正式主分支；每次发布还会生成完整的 `sha-提交号` 标签，便于追踪源码。需要永久锁定或回滚时请使用下面记录的 `镜像@sha256:摘要`，因为摘要才不会被标签更新影响。

## 注册模式与员工

首个管理员登录后，进入“系统设置 → 注册管理”：

- 关闭注册：登录页不显示注册入口，适合纯个人或内部团队。
- 邀请码注册（默认）：管理员生成一次性邀请码并设置有效期，适合邀请少量同行。
- 开放注册：任何能访问网站的人都能申请独立主账号，仍受频率和账号数上限保护。

同行注册后得到的是独立主账号，库存与其他主账号完全分开。你自己的员工不需要公开注册，应在“系统设置 → 员工管理”中添加并分配仓库、编辑、导入、财务等权限。

新数据库首次启动时，可在 `.env` 使用 `DEFAULT_REGISTRATION_MODE=closed`、`invite` 或 `open` 指定初始模式；默认是 `invite`。启动后以管理页面中的设置为准，修改 `.env` 不会覆盖管理员已经选择的模式。

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

更新前先记录当前镜像摘要并备份。第一条命令会输出类似 `ghcr.io/shudaizi520/jinpai-inventory@sha256:...` 的值，请保存为回滚目标：

```bash
docker image inspect --format '{{index .RepoDigests 0}}' "$(docker compose images -q app)"
./scripts/backup.sh
git pull --ff-only
docker compose pull
docker compose up -d
docker compose ps
docker image inspect --format '{{json .RepoDigests}}' "$(docker compose images -q app)"
```

应用启动时自动执行兼容迁移。需要回滚程序时，把下面的摘要替换为更新前保存的值；命令会持久修改 `.env`，后续 Compose 操作仍会使用同一个回滚版本：

```bash
rollback_image='<粘贴上面第一条命令输出的完整值>'
sed -i "s|^APP_IMAGE=.*|APP_IMAGE=${rollback_image}|" .env
docker compose pull
docker compose up -d
docker image inspect --format '{{json .RepoDigests}}' "$(docker compose images -q app)"
```

TrueNAS 自定义应用更新前，在应用详情中记录当前 `app` 容器所用镜像摘要。更新时把 YAML 的 `services.app.image` 改成新的完整 SHA 标签或镜像摘要并重新部署；回滚时把这一项改回记录的 `ghcr.io/shudaizi520/jinpai-inventory@sha256:...`，数据库卷保持不变。不要只记录 `latest`，因为它会随新版本移动。

本版本的迁移校验会先检查员工归属、主账号和库存所有权；发现旧数据库存在孤立或嵌套关系时会停止升级并保留原数据，不会自动删除或改绑。新增字段和表保持旧程序可读取原有账号与库存，但数据库结构可能已经升级，仍不要盲目回滚数据库。程序回滚优先使用更新前记录的提交；只有确认必须恢复数据库时才使用更新前备份，因为恢复会丢弃备份之后的新数据。

## HTTPS 反向代理

在 Nginx Proxy Manager、Caddy 或群晖反向代理中，把域名转发到 `NAS地址:8080`，启用 HTTPS 和 WebSocket/常规转发头。随后在 `.env` 设置：

```dotenv
SESSION_COOKIE_SECURE=true
TRUSTED_PROXIES=反向代理容器或主机的IP
```

多个可信代理 IP 用英文逗号分隔。不要填写 `0.0.0.0/0`，也不要把公网来源设为可信代理。修改后执行 `docker compose up -d`。

## 使用外部 MariaDB

内置数据库最省心。如果已有 MariaDB 11.x，也可以直接运行公开应用镜像：

```bash
docker run -d --name inventory-app --restart unless-stopped \
  -p 8080:80 \
  -e DB_HOST=你的数据库地址 -e DB_PORT=3306 \
  -e DB_NAME=inventory -e DB_USER=inventory \
  -e DB_PASSWORD='数据库密码' \
  ghcr.io/shudaizi520/jinpai-inventory:latest
```

数据库账号需要对指定数据库拥有建表、改表和数据读写权限。不要让数据库端口直接暴露到公网。

## 常见问题

- `Set DB_PASSWORD in .env`：尚未填写 `.env` 密码，或值为空。
- 应用容器反复重启：运行 `docker compose logs app`，通常是数据库密码不一致；如果显式启用了高级自动初始化，也要确认管理员密码符合规则。
- 数据库不健康：运行 `docker compose logs db`，确认 NAS 磁盘空间和卷权限。
- HTTPS 下反复掉线：确认 `SESSION_COOKIE_SECURE=true`，并正确配置可信代理 IP。
- 忘记主账号密码：使用登录页密保找回；员工密码由所属主账号在员工管理中重置。
- 邀请码无法使用：邀请码只能使用一次，并可能已过期或被管理员撤销。

## 开发与验证

本地修改源码时，使用构建覆盖文件，不会影响正式安装默认的 GHCR 拉取方式：

```bash
docker compose -f compose.yaml -f compose.build.yaml up -d --build
```

```bash
find php tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
node tests/browser/refresh_test.cjs
node tests/browser/financial_transition_test.cjs
node tests/browser/masked_save_test.cjs
sh tests/docker/compose_test.sh
sh tests/release/release_test.sh
sh tests/release/maintenance_scripts_test.sh
```

浏览器逻辑测试需要 Node.js 20+，只模拟浏览器和网络边界，不需要安装 npm 依赖。数据库集成测试需要设置 `TEST_DB_DSN`、`TEST_DB_USER` 和 `TEST_DB_PASSWORD`，仅使用隔离测试数据库（测试会创建和删除临时数据库，不能连接生产库）。安全问题请阅读 [SECURITY.md](SECURITY.md)。

## 许可证

本项目使用 MIT License，详见 [LICENSE](LICENSE)。第三方浏览器脚本保留各自版权声明。
