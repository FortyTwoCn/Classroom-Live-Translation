# 课堂同声传译 · 阶段 A 工程交付

本项目已有可运行的 PHP HTTP 服务、独立 Workerman 音频网关、Vue 页面、PostgreSQL 迁移、OSS S3 客户端、分片队列和 FFmpeg 归档路径。**阶段 A 真实服务闭环已通过，输入为明确标记的合成英语语音；物理麦克风尚未验证。** 阶段 B/C 按原始需求继续实施，这不是完整产品的完成声明。

需求原文逐字保存在 [`docs/requirements.original.md`](docs/requirements.original.md)。原文始终是验收依据，下面的阶段记录不删减需求。见 [`docs/acceptance.md`](docs/acceptance.md)、[`docs/architecture.md`](docs/architecture.md) 和 [`reports/PHASE-A.md`](reports/PHASE-A.md)。

## 当前验证结果

| 类别 | 结果 | 含义 |
|---|---|---|
| PHP / PostgreSQL | 48 项测试、164 断言通过 | 使用真实 PostgreSQL 17.11，包含权限、持久化、去重、时间偏移及协议 fixture |
| 前端构建 / 单元 | 构建通过，13 项单元通过 | 合成信号重采样、PCM、背压、语言资源 |
| 三客户端闭环 | 通过 | 真实 PHP/Workerman/PostgreSQL + 显式模拟 Qwen；7 包含 512 样本尾包；不等同真实模型 |
| 浏览器模拟 | 5 通过，1 跳过 | Edge 与 Windows WebKit；WebKit 此环境缺音频能力 |
| FFmpeg | 通过 | 人工 440 Hz PCM 的实际编码、ffprobe 和完整解码；不是课堂录音 |
| 真实 Qwen | **通过** | 北京地域，人工英语语音→中文/英文/乌克兰文；流式原译文和收尾通过，物理麦克风未验证 |
| 真实 OSS | **通过重试** | 2026-10-09 16:41 实测写读/HEAD/签名/Range/private/multipart/delete 全通过；初次403保留报告 |
| 真实服务三客户端闭环 | **通过** | AudioWorklet → PHP → 北京 Qwen → 三客户端 → PostgreSQL → OSS 原声归档/播放；175 包、278571 样本；合成语音，物理麦克风未测 |
| 真实设备、90 分钟、100 观众、生产 HTTPS、Docker | 未验证 | 不能用浏览器视口或 fixture 代替这些验收 |

独立原始记录：[`reports/phpunit.xml`](reports/phpunit.xml)、[`reports/gateway-simulated.json`](reports/gateway-simulated.json)、[`reports/qwen-real-beijing.json`](reports/qwen-real-beijing.json)、[`reports/oss-real-retry.json`](reports/oss-real-retry.json)、[`真实闭环`](frontend/tests/artifacts/actual-real-services/real-services-results.json)、[`frontend/TEST-RESULTS.md`](frontend/TEST-RESULTS.md)。

## 配置真实账号

`.env.example` 不包含真实密钥，`.env` 被 Git 与 Docker 构建上下文排除。不要在聊天、前端或日志里粘贴密钥。

- Qwen：填写 `QWEN_API_KEY`、`QWEN_WORKSPACE_ID`、`QWEN_REGION`、`QWEN_WS_ENDPOINT`。Endpoint 不带 `?model=`；留空按 Workspace 与地域生成官方默认值。例如新加坡的 `wss://{WorkspaceId}.ap-southeast-1.maas.aliyuncs.com/api-ws/v1/realtime`。地域、Workspace 和 Key 必须同一账号范围。默认模型严格为 `qwen3.8-livetranslate-flash-realtime`，只输出 text，源语言自动识别。
- OSS：私有 Bucket，`OSS_ENDPOINT=https://s3.oss-{region}.aliyuncs.com`，如 Region `cn-hangzhou`。Endpoint 不含 Bucket；SDK 自动用虚拟托管方式。填写 `OSS_BUCKET`、`OSS_ACCESS_KEY_ID`、`OSS_ACCESS_KEY_SECRET`，临时凭据可设 `OSS_SESSION_TOKEN`。
- 遇到 OSS 的 403 时需要账号管理员检查 RAM 授权、Bucket Policy、访问条件及 Bucket 所属账号。建议只允许项目对象前缀的读取、写入、删除与 multipart 操作；不要为排错把 Bucket 改成公开写入。探针路径为 `{OSS_PREFIX}/_connection-tests/{随机ID}/`。
- `APP_KEY` 用安全命令生成并备份，不能在已有加密数据后随意更换。生产 Cookie 必须 `SESSION_SECURE_COOKIE=true`。
- 模型/OSS 配置在进程启动时读取，修改后重启 PHP 网关与队列。阶段 A 不支持可随时切换 Bucket 的后台设置；存储目的地指纹会拒绝把旧片段上传到新 Bucket。

资料核对于 2026-10-09：[Qwen3.8 模型](https://www.alibabacloud.com/help/en/model-studio/qwen3-8-livetranslate-flash-realtime)、[含 3.8 章节的实时指南](https://www.alibabacloud.com/help/en/model-studio/qwen3-5-livetranslate-flash-realtime)、[OSS AWS SDK](https://help.aliyun.com/zh/oss/developer-reference/use-aws-sdks-to-access-oss)、[RAM 授权](https://help.aliyun.com/zh/oss/user-guide/ram-policy/)。版本差异及未验证限制另见 [`docs/qwen-verification.md`](docs/qwen-verification.md)、[`docs/storage.md`](docs/storage.md)。

## 从空环境运行

需要 PHP **8.5**、Composer、PostgreSQL 17、Node 24 / pnpm 10、FFmpeg 与 ffprobe。锁文件当前在 PHP 8.5 上解析，不声称能在 PHP 8.3 上直接安装。PHP 开启 pdo_pgsql、mbstring、openssl、curl、sockets、fileinfo、zip；Linux 网关/队列另开启 pcntl、posix，生产缓存使用 Redis 扩展。

```sh
cp .env.example .env
composer install
php artisan key:generate
# 填写 PostgreSQL 和模型/OSS 配置，创建空数据库 classroom
php artisan migrate --force
php artisan classroom:admin ADMIN_STUDENT_ID '管理员姓名'
# 上一命令在终端秘密询问独有密码，不内置通用密码
cd frontend
pnpm install --frozen-lockfile
pnpm build
cd ..
```

分别运行以下进程；HTTP 仅用于 localhost 开发：

```sh
php -S 127.0.0.1:8080 -t public public/router.php
php bin/gateway.php start
php artisan queue:work --queue=recordings --timeout=150 --tries=6 --sleep=1
php artisan queue:work --queue=archives --timeout=7200 --tries=4 --sleep=1
```

打开 `http://localhost:8080`。管理员登录后可创建课程；用户创建课堂但不会自动开麦，点击开始并确认录音告知后才申请浏览器权限。阶段 A 可用 CLI `php artisan classroom:user STUDENT_ID '姓名'` 创建普通用户，初始密码严格为学号后六字符且首次强制改密。管理员初始化用独立密码，普通用户学号少于六字符拒绝创建。Excel 与完整管理后台属于未完成的阶段 B。

本次 Windows 已在项目外的 `../../work/runtime/` 准备便携 PHP、Composer、PostgreSQL、FFmpeg；PostgreSQL 正在 `127.0.0.1:55432`，本地随机密码只写入 `.env` 和该工作目录。新电脑应按上面准备环境，或用 Docker；工作目录里的运行时不是完整源码的依赖替代品。Windows 本机可用 `bin/start-local.ps1` 启动四个本地进程，PID 保存至 `storage/local-processes.json`，重复启动前先关闭之前的进程。实际管理员仍由安全命令创建。

## Docker Compose 与 HTTPS

本机没有 Docker，以下配置已提供但**未实际构建/部署验收**。Compose 使用 PostgreSQL、Redis、PHP-FPM、独立网关、上传与归档两个队列、Nginx。静态资源由镜像内 Vite 构建，Nginx 使用同一构建结果。

```sh
docker compose build
docker compose up -d db redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan classroom:admin ADMIN_STUDENT_ID '管理员姓名'
docker compose up -d
```

本地 HTTP 端口仅绑定 127.0.0.1。生产请使用 [`deploy/nginx-https.conf.example`](deploy/nginx-https.conf.example)，替换实际域名/证书路径、挂载证书，发布 443 端口，并设：

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://classroom.example.edu
SESSION_SECURE_COOKIE=true
WS_PUBLIC_URL=wss://classroom.example.edu/ws
WS_ALLOWED_ORIGINS=https://classroom.example.edu
QWEN_ALLOW_TEST_ENDPOINT=false
```

公网 HTTP 不能作为生产收音入口。Nginx `/ws` 转发给唯一 Workerman 进程，普通观众不会创建上游。数据库 advisory lock 防止误启动第二个音频网关，连接改变后强制停止。Compose `unless-stopped` 监督进程；重启不会自动开麦。正常升级请先在界面暂停/结束课堂，等 `session.finished` 和归档状态；进程被强杀时只标中断，不声称尾句已经完成。

命名卷 `postgres_data` 是数据库、`recordings` 是可恢复的原声暂存/清单/日志、`redis_data` 是缓存持久数据；禁止把 recordings 改成容器临时目录。不要执行 `docker compose down -v` 来做日常重启。Queue retry_after 为 7500 秒，大于 7200 秒归档超时，重叠任务有锁；上传与归档不阻塞网关中的模型 Socket。

OSS CORS 配置精确的网站 Origin，允许 GET/HEAD，允许 Range 请求并暴露 Content-Range、Accept-Ranges、Content-Length、ETag。Bucket 保持私有；播放 URL 默认 120 秒，到期后重新向有授权的 HTTP API 领取。已发 URL 在窗口内可能仍有效，阶段 A 没有即时撤销代理。

## 可重复测试

创建独立的 `classroom_test` PostgreSQL 数据库，使用同一数据库用户或专用测试用户。绝不针对生产库重置测试数据。

```sh
APP_ENV=testing DB_DATABASE=classroom_test php artisan migrate --force
php vendor/bin/phpunit --log-junit reports/phpunit.xml
cd frontend
pnpm test
pnpm exec playwright install chromium webkit
pnpm test:e2e
```

PHPUnit 固定数据库名称为 classroom_test，Feature 用事务回滚；单元 fixture 不调用外部模型或 OSS。Windows Playwright 打包 Chromium 在本机启动失败，实际用 `PLAYWRIGHT_CHROMIUM_CHANNEL=msedge` 执行 Chromium 引擎测试，详见前端报告。

三客户端真实 PHP 网关测试在本次 Windows 环境可重复：

```powershell
./bin/start-test-services.ps1
node ./tests/e2e/gateway.mjs ../../work/gateway-fixture.json
```

该脚本启动 18080/18081/18082 三个 localhost 测试进程，固定 APP_ENV=testing、classroom_test，使用假 Key 和明确模拟 provider；服务端生产配置会拒绝 localhost 模型 Endpoint。模拟后进程 PID 在 `../../work/test-processes.json`，只停止这些 PID。测试 fixture 代码不进入生产镜像。

真实外部测试独立执行，**不会静默退回模拟**：

```sh
php bin/check-config.php
php bin/probe-oss.php > reports/oss-real.json
# speech.pcm 是无文件头、16kHz/单声道/16位小端 PCM 语音；不能用 WebM/WAV 文件字节
ffmpeg -i speech.wav -ac 1 -ar 16000 -f s16le speech.pcm
php bin/probe-qwen.php speech.pcm zh > reports/qwen-real-zh.json
php bin/probe-qwen.php speech.pcm en > reports/qwen-real-en.json
php bin/probe-qwen.php speech.pcm uk > reports/qwen-real-uk.json
```

exit 0 才代表相应脚本成功，exit 2 为缺配置/输入而未验证，exit 1 是实际失败；Qwen 沉默输入、未完成响应、只流式但无最终文本不会判为通过。运行真实模型探针会产生账号用量。探针报告只保留计数、状态、usage，不保存私有课堂原文。

## 备份、恢复与失败重试

备份 PostgreSQL、`.env` 中 APP_KEY/配置密钥（独立加密保管）、持久 recordings 卷和 OSS 对象。数据库保存 key 与配置指纹，不保存永久播放 URL。当前所有本地原声输入保留，自动保留周期和清理尚未实现，不声称备份中已删除。

```sh
# Docker 内生成自定义格式备份，之后复制到独立加密存储
docker compose exec db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc -f /tmp/classroom.dump'
docker compose cp db:/tmp/classroom.dump ./classroom.dump
# 在独立空库恢复并人工验证，不覆盖生产库
pg_restore --no-owner --dbname=classroom_restore classroom.dump
```

本地 PostgreSQL 结构/会话数据已成功备份恢复到独立新库；完整课堂对象与主密钥联动恢复仍未验收。FFmpeg、OSS 暂时故障时，原始分片和 manifest 保留；先修正环境，再 `php artisan queue:retry all` 或 `php artisan classroom:archive LESSON_ID`。重试前确认课程已结束和配置版本一致。归档失败可以继续阅读字幕，只有完成编码/完整解码/上传/HEAD 校验才显示可播放。

## 进入下一阶段的门槛

必须先通过真实 Qwen 文字模式、源文与译文事件、session.finish 尾句；真实 OSS 私有写读/HEAD/删除/签名/Range/multipart；一台实际麦克风 + 至少两位观众同步字幕 + PostgreSQL 持久化 + OSS 原声回放。未达门槛不会把阶段 A 标为已完成。

阶段 B 全部角色/权限、90 天设备登录、交接、校对历史、Excel、完整课程管理、二维码、大字幕、四格式导出均仍是首版必做。阶段 C 恢复、术语、定位、分享撤销、导出快照、90 分钟/100 观众、用量和部署验收保留，详见验收跟踪。附件真正的二期（摘要、私人笔记、合成译音等）没有开发。
