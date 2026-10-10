# 课堂同声传译网站

可运行的Laravel/PHP、Workerman、PostgreSQL、Vue 3、阿里云OSS S3和Qwen3.8实时翻译工程。先完成真实音频闭环，再实现账户、课堂管理和可靠性。生产不会回退模拟字幕。附件逐字保存在[原始需求](docs/requirements.original.md)，[验收跟踪](docs/acceptance.md)保留全部二十章；附件真正二期的摘要、私人笔记和合成译音未开发。

阶段交付：[A：真实服务闭环](reports/PHASE-A.md)、[B：完整产品](reports/PHASE-B.md)、[C：恢复与课堂体验](reports/PHASE-C.md)。设计见[架构](docs/architecture.md)、[账户与导入](docs/accounts.md)、[录音权交接](docs/recorder-handoffs.md)、[校对、分享与导出](docs/B-content-sharing-exports.md)、[设置与恢复](docs/C-settings-recovery.md)、[术语与用量](docs/C-glossary-usage-qwen.md)。

## 当前验证

| 类别 | 结果与边界 |
|---|---|
| 最终后端回归 | **231测试、1303断言通过**；真实PostgreSQL，外部provider使用明确fixture；130 PHP文件语法及依赖检查通过 |
| 前端 | **24单元通过、严格TypeScript/生产构建通过**；Edge/WebKit模拟测试5通过、1音频能力跳过 |
| B产品/音频交接 | 实际PHP/WS/PG：12产品组、6音频组及创建冲突流程通过；音源、字幕和provider为明确fixture |
| C产品/采集故障 | 实际服务9产品组、3采集故障组及2项恢复/权威暂停回归通过；回放组真实只读OSS，其历史字幕位置为合成索引 |
| 真实Qwen | **北京地域通过**；合成英语语音→中文、英语、乌克兰语，原译文、尾句及术语测试；物理麦克风未测 |
| 真实OSS | **通过重试**；私有读写/HEAD/签名/Range/multipart/删除，以及历史配置恢复、后台异步连接测试通过；初次403记录保留 |
| 真实模型＋OSS闭环 | 三浏览器实际AudioWorklet→PHP→Qwen→PG→OSS原声播放通过；C轮换测试674包、1,077,589样本，两个上游顺序衔接；输入是合成英语语音 |
| 进程/数据恢复 | 强杀并重启实际网关、旧epoch拒绝、禁用账户撤销通过；独立新库完整行校验、历史OSS恢复及实际FFmpeg重建通过 |
| 本地部署 | 六进程启动、11迁移、空业务库无自动模型会话通过；原生Nginx自签HTTPS/WSS、Secure Cookie及三客户端通过 |
| 长课与100观看者 | 最终源码60秒/100客户端烟测通过；90分钟收音及字幕一致性通过，初次归档180秒等待超时，单独恢复258秒后通过。详情见[C报告](reports/PHASE-C.md)及[长测说明](docs/load-testing.md)，provider/S3明确模拟，观看者是Node WS客户端 |
| 外部验收 | 物理麦克风、Android/iPhone/iPad/Safari、真实供应商90分钟/100真人、公网受信任证书、Docker/Linux部署、异地主密钥恢复 **未验证** |

原始结果在[reports](reports)、[前端测试说明](frontend/TEST-RESULTS.md)及带分类的截图目录。模拟、真实模型、真实OSS分别记录。费用未配置或usage缺失显示未知。

## 从空环境运行

需要PHP **8.5**、Composer、PostgreSQL17、Node24/pnpm10、FFmpeg和ffprobe。PHP开启pdo_pgsql、mbstring、openssl、curl、sockets、fileinfo、zip、bcmath、dom、xmlreader；Linux常驻进程另需pcntl/posix，生产缓存用Redis。本机锁文件在PHP8.5解析，不声称PHP8.3可直接安装。

```sh
cp .env.example .env
composer install
php artisan key:generate
# 填写数据库配置并创建空数据库 classroom
php artisan migrate --force
php artisan classroom:admin ADMIN_STUDENT_ID '管理员姓名'
# 命令秘密询问独有密码，不内置通用密码
cd frontend
pnpm install --frozen-lockfile
pnpm build
cd ..
```

在`.env`私下填写账号：

- Qwen业务地域是**北京**，`QWEN_REGION=cn-beijing`。填写`QWEN_WORKSPACE_ID`和`QWEN_API_KEY`；`QWEN_WS_ENDPOINT`可留空，由服务端生成`wss://{WorkspaceId}.cn-beijing.maas.aliyuncs.com/api-ws/v1/realtime`，不要带`?model=`。默认模型严格为`qwen3.8-livetranslate-flash-realtime`，输出text，源语言自动识别。
- OSS使用私有Bucket，`OSS_ENDPOINT=https://s3.oss-{region}.aliyuncs.com`，Endpoint不含Bucket；填写`OSS_REGION`、`OSS_BUCKET`、`OSS_ACCESS_KEY_ID`、`OSS_ACCESS_KEY_SECRET`、`OSS_PREFIX`。临时授权可设`OSS_SESSION_TOKEN`。S3使用虚拟托管与SigV4。
- `APP_KEY`及`APP_PREVIOUS_KEYS`须独立安全备份。`.env`被Git/Docker上下文排除，API和日志不返回密钥。生产`SESSION_SECURE_COOKIE=true`。

后台可加密版本化保存模型、OSS和政策，连接测试走独立任务。课堂首次开始冻结模型/术语/存储版本；新位置用于新课堂，旧录音按历史版本恢复。同OSS位置可轮换授权，失效密钥仍须修复。保存设置要最近15分钟身份验证，空密钥字段保持已有值。环境变量是初始引导；设置已版本化后通过后台修改，不能只改`.env`就假定所有旧课堂切换。

本地分别启动六个进程：

```sh
php -S 127.0.0.1:8080 -t public public/router.php
php bin/gateway.php start
php artisan queue:work --queue=recordings --timeout=150 --tries=6 --sleep=1
php artisan queue:work --queue=archives --timeout=7200 --tries=4 --sleep=1
php artisan queue:work --queue=exports,imports,maintenance --timeout=1800 --tries=3 --sleep=1
php artisan schedule:work
```

打开`http://localhost:8080`。管理员创建课程、添加用户或下载Excel模板并预检导入。普通用户初始密码严格为字符串学号后六字符，少于六字符拒绝，首次强制改密；管理员要及时安排改密。可用`php artisan classroom:user STUDENT_ID '姓名'`创建普通用户。创建课堂不会开麦；当前收音者点击开始、确认告知并获得浏览器授权后才采集。

本次Windows便携运行时在项目外`../../work/runtime`，PG在`127.0.0.1:55432`。`bin/start-local.ps1`启动上述六进程，PID保存在被忽略的`storage/local-processes.json`；重复启动检查端口/PID。默认隔离存储为`storage/main`，测试有独立目录；自定义持久路径可从启动环境设置`LARAVEL_STORAGE_PATH`。新电脑需自行准备依赖或使用Docker。本机六进程已启动，业务库为空，管理员由安全命令初始化。

## 部署、恢复与保留

步骤见[部署说明](docs/deployment.md)。提供Dockerfile、Compose、Nginx HTTPS/WSS及持久卷，但本机没有Docker，**未实际构建Linux镜像**。本地Nginx真实自签TLS测试与公网证书验收分别报告。

录音帧先fsync/PG提交再ACK，约10秒封块后异步云备份。ACK不代表云端或模型已完成；丢失本机卷时，尚未上传的帧需要本地独立备份。归档须实际FFmpeg编码、完整解码及OSS校验。默认长期保留；永久清理需要结束、软删除、精确名称与管理员再验证。只有显式启用保留期政策才自动清理，备份遵循自身周期。

备份/恢复：[数据库、主密钥与原声恢复](docs/disaster-recovery.md)、[存储与失败重试](docs/storage.md)。每五分钟自动reconcile处理遗漏待办，不无限重置failed预算。人工dry-run和重试：

```sh
php bin/reconcile-recordings.php
php bin/reconcile-recordings.php --apply --limit=100
php artisan classroom:archive ENDED_LESSON_ID
```

## 可重复测试

创建独立`classroom_test`数据库；PHPUnit固定库名并使用`storage/phpunit`，不要针对生产库测试。

```sh
APP_ENV=testing DB_DATABASE=classroom_test php artisan migrate --force
php vendor/bin/phpunit --log-junit reports/phpunit-phase-c-final.xml
cd frontend
pnpm test
pnpm build
pnpm exec playwright install chromium webkit
pnpm test:e2e
```

Windows实际使用已安装Edge：`$env:PLAYWRIGHT_CHROMIUM_CHANNEL='msedge'`；本机Playwright打包Chromium启动失败，WebKit没有AudioContext，其音频项明确跳过。实际服务/采集/恢复/负载脚本及fixture隔离见前端README、[进程恢复](docs/process-recovery-testing.md)、[并发验证](docs/final-concurrency-verification.md)、[真实音频轮换](docs/C-real-audio-rotation.md)、[长课测试](docs/load-testing.md)。

独立真实请求**会产生账号用量/随机测试对象**，不自动退回模拟：

```sh
php bin/check-config.php
php bin/probe-oss.php > reports/oss-real-new.json
ffmpeg -i speech.wav -ac 1 -ar 16000 -f s16le speech.pcm
php bin/probe-qwen.php speech.pcm zh > reports/qwen-real-new.json
```

探针exit0才成功，exit2为缺配置/输入未验证，exit1为真实失败；WebM/WAV字节不能当裸PCM。报告不包含凭据或签名URL。真实OSS403需核查RAM/Bucket授权。

官方链接：[Qwen3.8模型](https://www.alibabacloud.com/help/en/model-studio/qwen3-8-livetranslate-flash-realtime)、[实时指南3.8章节](https://www.alibabacloud.com/help/en/model-studio/qwen3-5-livetranslate-flash-realtime)、[OSS AWS SDK](https://help.aliyun.com/zh/oss/developer-reference/use-aws-sdks-to-access-oss)。版本差异和实测边界见[Qwen验证](docs/qwen-verification.md)。
