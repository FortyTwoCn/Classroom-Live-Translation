# 部署与启动验证

本机实际验证PHP8.5.11/PostgreSQL17.11/FFmpeg、六个独立本地进程，以及原生Nginx1.30.5/PHP FastCGI/Edge的自签HTTPS/WSS。没有Docker，提供的Linux镜像与公网证书流程尚未实跑。根README有空环境安装步骤，真实账号不得进入Git或镜像层。

## Compose

`.env`配置数据库密码、应用主密钥、北京Qwen及私有OSS。Compose覆盖DB_HOST=db、DB_PORT=5432、Redis缓存及容器内FFmpeg；全部PHP进程共享`/var/www/classroom/storage`，不使用Windows测试绝对路径。

```sh
docker compose build
docker compose up -d db redis
docker compose run --rm app php artisan migrate --force
docker compose run --rm app php artisan classroom:admin ADMIN_STUDENT_ID '管理员姓名'
docker compose up -d
docker compose ps
```

HTTP开发入口绑定127.0.0.1:8080。app为PHP-FPM，gateway唯一Workerman，upload-worker处理recordings，archive-worker处理archives，product-worker处理exports/imports/maintenance，scheduler负责导出过期、每日保留及每五分钟录音reconcile。`unless-stopped`监督进程；数据库session advisory lock拒绝第二网关。同课程活动课堂唯一由PostgreSQL约束保证。

`postgres_data`保存数据库，`recordings`保存规范PCM/manifest/私有导出/日志，`redis_data`为缓存持久数据，`bootstrap_cache`为配置缓存。原声不能放容器临时层。升级保留卷，不使用`down -v`。上传/归档/导出/清理共用课堂数据mutex；固定24小时重试截止和maxExceptions区别于正常锁等待，重复排队不能复活failed或已清理课堂。

队列retry_after=7500秒大于归档timeout7200；独立worker保证转码/OSS不阻塞音频事件循环。升级先在UI暂停或结束课堂，等模型finished和归档状态，再更换镜像。旧序列化队列任务保留旧任务字段；改变重试政策时先处理/排空旧任务，勿以重启worker推断旧payload变成新政策。重启中断课堂会paused并标不确定区间，不自动收音或补送已存音频。

## HTTPS/WSS

使用`deploy/nginx-https.conf.example`替换域名、证书路径，并挂载受信任证书；Compose覆盖Nginx配置并发布443。生产主配置：

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://classroom.example.edu
SESSION_SECURE_COOKIE=true
WS_PUBLIC_URL=wss://classroom.example.edu/ws
WS_ALLOWED_ORIGINS=https://classroom.example.edu
QWEN_ALLOW_TEST_ENDPOINT=false
```

精确Origin校验，`/ws`升级到独立gateway。游客订阅不会新建模型连接。Nginx仅index.php进入FastCGI，隐藏文件拒绝，分享令牌路径不写access log；真实凭据只保存在服务端。公共HTTP入口301至HTTPS。Secure、HttpOnly、SameSite=Lax设备令牌固定90天失效；token哈希轮换和撤销在服务端，未实际等待90天。

OSS CORS只允许网站Origin，GET/HEAD、Range；暴露Content-Range、Accept-Ranges、Content-Length、ETag。私有播放默认120秒签名，过期重新鉴权。已签URL在余下有效期可能仍可用；没有即时撤销媒体代理。系统/课程双开关控制正式用户原声下载，不从字幕导出权推导。

## 启动与恢复检查

检查迁移、HTTP `/up`、JS资源、网关监听、三个worker和scheduler；确认同一DB和同一持久storage，再初始化独有管理员。新库没有示例账户、假课堂或模型会话。实际用户登录后手动检测麦克风、开始，检查ACK/三端字幕/结束收尾/归档播放；物理设备验证仍待现场环境。

故障后按`disaster-recovery.md`恢复到全新数据库和存储目录，验证历史加密配置可解密、OSS读权限及全帧SHA。先dry-runreconcile，再人工恢复failed任务。主密钥托管须能在目标主机取回；本次DPAPI同主机恢复不证明异地或原主机丢失后可解密。默认长期保留，清理策略须显式启用；数据库/对象备份单独治理。

## 实际证据与复现

`reports/local-startup.json`：六进程均运行、HTTP/健康/资产/Gateway通过，11迁移，主业务库零用户/课程/课堂/模型会话。`bin/start-local.ps1`默认`storage/main`，重复运行检查PID/端口；关闭前核对PID仍属于本项目进程，避免PID复用误停。

`reports/local-https-wss.json`：交付HTTPS vhost只替换本地host/path/port/cert，native Nginx -t和实际TLS/WSS通过；3客户端、6帧9600样本、Secure Cookie及安全headers。Qwen明确mock、不调用OSS；浏览器允许本地自签证书，不能称公网可信TLS、LinuxFPM或Docker通过。

```powershell
./tests/e2e/run-local-tls.ps1
./tests/e2e/run-recovery.ps1
```

脚本各建新PG测试库和work私有目录，停止的仅本次启动并核对启动时间的进程；不覆盖生产库。TLS脚本需要work/runtime下官方Windows Nginx1.30.5、PHP、PG和已安装Edge。初次TLS测试缺prefix目录的环境错误保留work日志；修正helper后重新独立运行通过，未削弱交付vhost校验。
