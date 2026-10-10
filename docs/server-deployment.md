# 宝塔服务器部署记录 · 更新于2026-10-10

2026-10-10追加发布[空白字幕过滤修复](empty-subtitles.md)：空白原文/译文不再新增记录，历史空行在查询及显示时过滤。239后端测试/26前端单元通过，公网两端HTTP/WSS和字幕卡片一致；本次不将模拟空白事件归为真实模型测试。最新文件核对与备份见[本次部署报告](../reports/server-empty-subtitles-deployment.json)，以下各阶段记录保留。

目标服务器 `211.159.166.184`，项目 `/www/wwwroot/t.xuanknow.cn`，Nginx 网站运行目录为其 `public` 子目录。公网 `https://t.xuanknow.cn` 经阿里云 ESA，源站 `https://esa.t.xuanknow.cn`。业务源码为阶段 C 提交 `474088c`；本次新增原生部署模板及实际部署证据，未修改业务逻辑。

## 2026-10-09暂停时的历史状态（已恢复部署）

用户明确将于明早填写数据库密码。服务器 `.env` 已创建，`DB_CONNECTION=pgsql`、`DB_HOST=127.0.0.1`、`DB_PORT=5432`，数据库及用户名都是 `clt`，`DB_PASSWORD` 留空。独立生产 APP_KEY 已在服务器生成，既有北京 Qwen 和 OSS 账号配置已通过加密 SSH 传到服务器，密钥不进入代码包或公开报告。

网站处于 Laravel 维护模式：主页返回 **503**，健康检查 `/up` 返回 **200**。`clt-fpm` 运行且开机启用；`clt-gateway`、`clt-recordings`、`clt-archives`、`clt-product`、`clt-scheduler` 的定义已安装，但均停止并暂时禁用开机启动。密码未配置期间不会不断重试数据库。没有初始化管理员、示例课堂或启动录音；`clt` 公开 schema 当前为零表，尚未执行11个迁移。

明天只需在宝塔编辑 `/www/wwwroot/t.xuanknow.cn/.env` 的 `DB_PASSWORD` 并告知配置完成；不要把密码发到聊天。继续部署时先校验密码连接与目录权限，再迁移、启用服务和验证公网应用。

## 已实施与实际测试

| 内容 | 结果与范围 |
|---|---|
| 服务器运行时 | Debian12、PHP8.5.11、PostgreSQL18.0；安装 FFmpeg5.1.9，按官方 PHP8.5.11源码 SHA256 编译 pdo_pgsql/fileinfo |
| 依赖 | 服务器原有 Composer 过旧，改用独立 Composer2.10.3；按原锁文件安装80个生产依赖，平台检查通过，锁文件 SHA一致 |
| 独立 PHP-FPM | 真实 FastCGI socket请求验证全部所需扩展及 proc_open/readlink/putenv；master root、worker cltweb，socket `/run/clt/php-fpm.sock` |
| Nginx | 目标服务器 `nginx -t` 和实际 reload通过；仅 public可公开，私有storage无公开软链接；动态 no-store/private、哈希资产长期缓存、精确 `/ws` 代理到127.0.0.1:8081 |
| 进程托管 | 六个systemd定义解析通过；现有无关 monitor/tat_agent单元的警告保留，未改它们。应用的五个后台单元待密码后启动 |
| 源站 HTTPS | 现有 esa域名证书的真实可信TLS请求、`/up`200、维护页503及安全响应头通过 |
| ESA HTTPS | `/up`200、`x-site-cache-status: DYNAMIC`、`Cache-Control: no-store, private`；短暂无URI/无Cookie诊断日志确认回源 Host=t、SNI=t、scheme=https，诊断配置已恢复 |
| **真实 Qwen** | 服务器真实北京3.8接口，271,437样本合成英语音频→中文；1条完成原文/1条译文、流式增量、finish与官方usage通过。不是实体麦克风或公网浏览器链路 |
| **真实 OSS** | 服务器真实私有写读/SHA/HEAD/签名/Range/匿名拒绝/分片上传/删除均通过；只新建并清理两个随机probe对象 |

证据：[部署状态](../reports/server-deployment-pending.json)、[服务器真实模型](../reports/server-qwen-real.json)、[服务器真实OSS](../reports/server-oss-real.json)、[ESA回源](../reports/server-esa-origin.json)。这些报告没有凭据、签名URL或Cookie值；本次没有用模拟上游冒充真实调用。

## 暂停后的继续部署步骤（2026-10-10已执行）

应用服务用户为 `cltweb`。使用 `/www/server/php/85/bin/php`，CLI服务及其模型测试子进程继承 `PHP_INI_SCAN_DIR=:/www/wwwroot/t.xuanknow.cn/deploy/baota/php-cli.d`。独立FPM使用本站ini，避免继承宝塔全局禁用的进程函数；未修改共享FPM池。PHP8.5的两个全局ini只追加所需扩展，原配置已备份。

先重新检查 `.env` 所有权为 root:cltweb、权限640；确认 PostgreSQL密码认证成功。清除旧配置缓存后，在项目目录执行：

```sh
runuser -u cltweb -- /www/server/php/85/bin/php artisan config:clear
runuser -u cltweb -- /www/server/php/85/bin/php artisan migrate --force
runuser -u cltweb -- /www/server/php/85/bin/php artisan config:cache
runuser -u cltweb -- /www/server/php/85/bin/php artisan view:cache
systemctl enable --now clt-gateway clt-recordings clt-archives clt-product clt-scheduler
systemctl restart clt-fpm
runuser -u cltweb -- /www/server/php/85/bin/php artisan up
```

再检查六服务、11迁移、主域名页面与API、Secure/HttpOnly/SameSite登录Cookie、一次性WSS票据、超过300秒双向心跳、三客户端及队列/归档。管理员使用 `classroom:admin 学号 '姓名'` 的秘密密码提示初始化，无默认账户。实际实体麦克风测试要由浏览器用户授予权限并手动开始。

## ESA及恢复注意事项

已确认实际回源Host保留主域名且为HTTPS，当前不需要在应用盲目相信外部Forwarded头。动态API、分享路径、HTML及 `/ws` 应绕过ESA缓存；只缓存哈希 `/assets/`，浏览器TTL遵循源站。保持精确Origin与现有5秒心跳，`/ws` 回源超时建议300秒，并用真实WSS实测。[ESA缓存文档](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/cache-bypass)、[回源超时](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/configuring-the-back-to-origin-request-timeout-period)

源站证书SAN**只覆盖 esa.t.xuanknow.cn**，实际ESA回源SNI为t.xuanknow.cn。当前公网200不证明严格源站域名校验通过；ESA默认不校验源站证书。建议将ESA回源SNI设为esa.t.xuanknow.cn，再验证并启用源站证书校验，或安装同时覆盖两域名的源站证书。[证书校验](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/back-to-source-protocols-and-ports/)、[回源SNI](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/back-to-source-sni/)

本次未修改OSS Bucket CORS。普通原生audio不设置crossorigin；2026-10-10公网实际解码、播放及独立签名Range已通过。若后续JavaScript跨域读取需要CORS，应先用原生OSS API读取并保留既有规则，再合并两个精确Origin。Get/PutBucketCors不在官方S3兼容操作清单中，不能盲用AWS同名接口覆盖配置。[OSS S3兼容清单](https://help.aliyun.com/zh/oss/developer-reference/compatibility-with-amazon-s3)

原站点默认文件、Nginx、PHP配置和独立生产主密钥环境的备份保存在服务器root私有目录 `/www/server/clt-deploy-private/20261009-474088c`（700）。它只是同机回退备份，尚未证明离机灾备。宝塔读取实际Nginx root显示 `/public`；以后修改站点PHP/SSL/伪静态时保留本站独立FPM socket与精确路由配置。网关、PHP、队列必须保持同一storage与主密钥。

上述503、空数据库和停止服务是2026-10-09暂停时的记录，原始[待部署报告](../reports/server-deployment-pending.json)保留，不改写为通过。

## 2026-10-10恢复部署与当前运行状态

用户填写数据库密码后，已验证生产`clt`密码认证，执行全部11个迁移，构建配置/视图缓存并解除维护模式。正式学号登录ID为`000000000000`（用户允许的12位零），姓名“管理员”。密码按用户指定值bcrypt保存，`must_change_password=true`；未代替用户修改密码。公网实际登录成功，服务端正式操作返回`password_change_required`，界面展示不可跳过的改密弹窗。管理员通用初始化命令仍要求独有秘密密码，不内置本次账户或密码。

首次部署的`view:cache`发现空的`resources/views`目录未进入Git包；增加`.gitkeep`并补建服务器目录后缓存成功。第一次迁移已经完整执行；第二次部署确认11迁移均Ran，未重复建表。该失败日志保存在服务器私有备份目录，未隐藏失败。

`clt-fpm`、`clt-gateway`、`clt-recordings`、`clt-archives`、`clt-product`、`clt-scheduler`六服务均active并enabled；仅本站服务使用独立FPM和持久storage。主域名与源站首页、`/up`均真实可信TLS返回200。公网HTML/API为no-store/private，ESA观察为DYNAMIC；`.env`与`.git/config`返回403。不存在的`/storage/...`路径返回SPA HTML壳200，没有返回私有fixture文件，没有公开storage软链接。

当前生产缓存、队列和HTTP session使用PostgreSQL database驱动，而非Redis；数据库是最终字幕及权限历史来源。推荐的Redis不是强制技术边界，当前六进程与事件outbox已实际通过部署链路验证。后续引入Redis必须保留数据库权威记录，不会改变生产数据库为PostgreSQL。

真实模型与OSS、模拟回归的证据分别保留：[服务器真实Qwen](../reports/server-qwen-real.json)、[服务器真实OSS](../reports/server-oss-real.json)、[公网HTTP](../reports/server-http-live.json)、[阶段C模拟及实际服务回归](../reports/PHASE-C.md)。本次公网测试不使用模拟字幕或模型替代。

公网Edge154真实闭环通过：16.9648125秒合成英语经AudioWorklet采集为175包、278,570samples；全部持久ACK，1次收音准备、1个epoch、序号连续。三个客户端接收的完成原文/译文ID和文本与数据库一致，游客没有申请麦克风。仅1条模型会话，正常finish并保存官方usage（173输入token、50输出token）；2个原声块上传到私有OSS，123,250bytes归档，原生解码播放17.411秒及独立Range206/1024bytes通过。三条原始公网WSS连接保持330.106秒，分别71/70/70次心跳、零关闭与重连。不是实体麦克风、90分钟或100真人测试。

初始自动化协议测试通过后，截图复核发现收音端开始回放时，`at_sample=0`返回空时间窗，将已显示字幕切成空的历史列表。追加真实只读回归复现“2条→0条”，修复为空时间窗保留现有阅读列表后，“2条→2条”、文字不变、零麦克风请求通过。修复只涉及显示状态，未编造时间戳或字幕，没有新增模型调用；重新运行24前端单元、严格TypeScript与生产构建均通过。新哈希JS`index-BAGilSTY.js`及HTML已原子发布，服务器/本地SHA一致。初始协议报告、失败截图和失败回归保留；330秒整套音频测试是在该一行显示修复前执行，修复后针对同一归档做回放回归，没有重跑整套模型链路。

证据：[当前部署](../reports/server-deployment-live.json)、[公网真实闭环](../reports/server-public-real-loop.json)、[生产DB核对](../reports/server-public-database.json)、[回放修复前失败](../reports/server-playback-before-fix.json)、[修复后通过](../reports/server-playback-after-fix.json)、[当前哈希资产](../reports/server-assets-live.json)。截图位于`frontend/tests/artifacts/server-deployment`，标明真实Qwen/OSS与合成音源。

已结束测试课堂、撤销分享、停用测试课程和临时账号；临时设备、session、WSS票据均为0，正式管理员保持启用及首次改密标记。随后在无活动课堂条件下实际重启本站六服务，全部恢复active/enabled，没有自动创建录音或模型会话。最终活动模型/课堂、待处理/失败队列、未解决录音问题均为0。

本次迁移前空库备份、部署后备份和最终回归后备份在root私有目录`/www/server/clt-deploy-private/20261010-resume`（700）。最终`clt.after-playback-fix.dump`为241,358bytes，`.env.production`含生产APP_KEY和配置，两文件权限600；`pg_restore --list`通过，仅证明归档可读取，未在新的生产库或异地执行恢复。原始2026-10-09备份仍保留。

## 手机及全局排版优化 · 2026-10-10

按用户追加要求，修正旧移动端样式的身份栏`flex-basis:100%`及整个操作区`width:100%`造成的多行顶部。900px及以下使用紧凑同一行页头：语言、游客/姓名和登录/账户入口保持同行，长姓名省略但可在账户弹窗完整查看；管理员、账户与退出操作收进可访问的账户入口。课堂右上角二维码和语言仍直接可见，大字幕退出使用带可访问名称的图标。首页显示本地化短名称，完整网站名仍在可访问名称与提示中。

全局缩小面板/标题/状态卡间距，字幕区域增加可用高度；课堂工具、后台九个标签及管理操作横向滑动，避免挤成数行。后台表格在自身容器横向滚动，学号不折行，操作行不再撑高整个用户表。回放和保存说明折叠为可展开说明，真实播放器与授权入口保留。弹窗标题/语言/关闭按钮紧凑排列，输入字体16px，主要按钮与图标触控高度44px。三语言新文案完整，没有修改音频或模型调用流程。

公网Edge浏览器模拟320/360/390/430/768/1280px与三语言，共85组检查通过，包含游客/登录首页、登录弹窗、历史列表、课堂、大字幕、账户菜单和九个后台标签。没有全页面横向溢出；390px首页页头从217.6875px降为57px，释放约161px给正文。此前旧选择器/切换语言后硬编码中文选择器的测试脚本失败保留，并与最终通过结果区分。该测试是浏览器视口模拟，不是手机真机。

最终前端24单元、严格TypeScript、生产构建通过；公网新JS`index-DWlnvILI.js`及CSS`index-6nDD7F1h.css`已发布，HTML原子切换，当前资产真实响应JavaScript/immutable。更新后再次用既有真实OSS归档和Qwen字幕回放，空时间窗仍保持2条字幕且内容不变，通过；未新建模型会话。临时账号及课程再次停用、分享保持撤销、会话/票据清零。最后备份`clt.after-mobile-layout.dump`为242,369bytes、600 root:root，原有各次备份保留。

证据：[排版前](../reports/server-mobile-layout-before.json)、[85组排版通过](../reports/server-mobile-layout.json)、[最终回放回归](../reports/server-playback-after-mobile.json)。实际截图在`frontend/tests/artifacts/server-deployment/layout-after`。服务器公开产物与Laravel/六服务日志按实际配置凭据值扫描，结果另见[服务器密钥检查](../reports/server-secret-scan.json)；这是精确值检查，不是一般安全审计。

## 公网复现与剩余验证

公网验收脚本为[server-deployment.mjs](../frontend/tests/e2e/server-deployment.mjs)，运行需要独立临时管理员fixture JSON（student_id、password、user_id、run_id），合成英语WAV，以及可用项目账号。临时账号只用于验收，应设独有随机密码、隔离测试课程，结束后停用并撤销会话。fixture放在私有目录，不提交源码、不置于public。脚本创建链接访问课程，验证真实前端AudioWorklet、PHP/Gateway、北京Qwen、PG、私有OSS及三个观看客户端；自动结束该课堂、撤销其分享并停用课程。测试账本与原声作为私有验收记录保留，不永久删除。

```sh
node frontend/tests/e2e/server-deployment.mjs /private/fixture.json tests/fixtures/synthetic-english-16k.wav
```

默认使用本机Edge；可通过`PLAYWRIGHT_CHROMIUM_CHANNEL`配置已安装浏览器，通过`ACTUAL_CAPTURE_URL`选择HTTPS地址、`REAL_RUN_LABEL`隔离重试报告。可选通过stdin传入正式管理员初始密码以验证强制改密；无此输入时跳过该项，不生成通过标记。脚本不会改变正式管理员密码。禁止把密码写入命令历史或公开fixture。报告只记计数、状态与协议，不包含Cookie、分享令牌或签名URL。

仅回放回归使用`node frontend/tests/e2e/server-playback-regression.mjs /private/fixture.json /private/scope.json`；scope包含该测试课程/课堂/临时用户ID和run_id。需先由操作员仅临时启用自己已结束的私有测试课程及临时账户，分享保持撤销；通过后脚本停用课程，操作员再停用账户并撤销其会话。它读取既有真实字幕和OSS录音，不重新识别音频，不伪造空时间窗。

**仍未验证**：实体麦克风/现场噪声/拔插/锁屏及手机真机；真实供应商连续90分钟与100真人观看；实际90天墙钟保持登录；ESA严格回源证书域名验证；Docker镜像构建；异地主密钥恢复与整台生产服务器重启。原生systemd启用不能当作整机重启或Docker通过。此前90分钟模拟provider/S3长测首次归档超时和独立恢复通过的记录仍分别保留，不能当作真实模型长课通过。
