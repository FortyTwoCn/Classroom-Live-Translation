# 原声持久化、OSS S3、历史版本与归档恢复

原始需求完整保存在 requirements.original.md。本文件说明实际实现和测试边界，不替代或删减原需求。

## 持久 ACK 与分块备份

音频为 16 kHz、单声道、16-bit little-endian PCM，正常帧为 100 ms（1,600 samples / 3,200 bytes）。AudioStore::accept 在课堂行锁内校验录音人、epoch、连续序号、容量和时长，将文件 fflush、fsync、原子改名并提交 audio_segments 后才返回 ACK。ACK 代表服务器本地持久化，不代表模型已处理或 OSS 已备份。重传相同 epoch/sequence/hash 不增加样本、文件或模型发送次数；内容冲突明确失败。

每个 epoch 的连续帧组成最多 100 帧 / 160,000 samples / 320,000 bytes 的无损 PCM chunk。第一帧派发延迟 10 秒的 UploadChunk，满 100 帧则封块并立即派发；后来的定时任务发现 uploaded 即返回。通常每 100 帧至多两个队列任务，90 分钟约 540 个满块，避免逐帧产生 54,000 个 OSS 对象和任务。短帧或排队期间可能产生更多小块，该数量并非硬保证。

ChunkPlanner::flushEpoch / AudioStore::flushEpoch 在暂停、epoch 结束时封尾，flushLesson 在课堂结束时处理尾块。它们只做短事务和派发任务，网关不拼音频、不访问 OSS、不运行 FFmpeg。分块不跨 epoch、序号或样本缺口，片段保存 chunk ID 和字节偏移。云备份滞后为封块触发的最多 10 秒窗口，再加队列等待、重试和传输时间，不能承诺精确 10 秒的云恢复点。

UploadChunk 在 recordings worker 中重新验证各帧 SHA-256 和连续样本，生成无损块、记录完整 hash/key，再使用 AWS SDK 上传并 HEAD 核对长度和 SHA 元数据。源帧和 chunk 均保留。旧的逐帧 UploadSegment 任务与对象继续兼容，并在PUT前持久化其object_key，避免网络成功后数据库发布前退出而丢失对象归属；新的帧不再逐帧派发上传。

## S3 兼容与网络边界

AWS SDK for PHP 3 负责标准 SigV4，使用 HTTPS 和虚拟托管寻址。小对象 PUT，大对象使用 8 MiB 的有界 multipart，每次请求明确长度，关闭可选 AWS 校验和扩展，失败尝试中止 multipart。没有私有 OSS SDK、自制签名或 path-style 回退。[阿里云 AWS SDK 接入文档](https://help.aliyun.com/zh/oss/developer-reference/use-aws-sdks-to-access-oss)介绍标准 https://s3.oss-{region}.aliyuncs.com 地址；实际账号兼容性以真实探针为准。

OssConfiguration 只允许与 region 一致的阿里云公开 regional endpoint（s3.oss- 或旧 oss- 形式）。拒绝 HTTP、自定义端口、userinfo、路径、query、自定义域、IP、内网 endpoint 和重定向。对象 prefix 不允许路径穿越，管理员不能用探针访问任意 URL。凭据仅在服务端 .env 或加密数据库中存在，不进入前端、公开设置或错误日志。AWS 异常可能包含签名 URL，因此任务只记录稳定错误码和异常类型。

Bucket 必须私有，RAM 权限应覆盖项目前缀和随机探针前缀所需 PUT/GET/DELETE、HEAD 和 multipart 操作。建议给未完成 multipart 配置生命周期。浏览器 HTTPS origin 需要 GET/HEAD/Range CORS，暴露 Content-Range、Accept-Ranges、Content-Length；CLI Range 探针不能替代浏览器 CORS/设备验证。

## 加密历史配置

StorageRegistry::register(array $oss): string 将验证后的完整配置使用 Laravel Crypt / APP_KEY 加密写入 storage_versions.payload_encrypted，返回 endpoint、region、bucket、prefix 的 SHA-256 fingerprint。configuration($version) 解密并重新核验身份；resolve($version) 返回该版本 AwsS3OssStorage。

fingerprint 不含凭据，同一位置轮换 key/secret/token 会更新同一版本的加密 payload，让历史对象和待处理任务使用新的授权。新 Bucket 或 prefix 产生新版本，旧版本仍可解析。课堂第一次收音固定 lessons.storage_version，直到结束所有 epoch、片段、chunk、归档保持该位置。变更后台活动设置不会将已经开始的课堂迁移到新位置。

历史版本不存在、不能解密或身份不一致时明确报 storage_configuration_version_unavailable，绝不回退到当前 .env。旧课堂没有 lesson version 时只允许从该课堂唯一且已注册的 segment version 推导；多个版本或未知版本需人工核查。测试环境旧 fixture 允许无音频新课堂注册 .env，生产不使用该兼容入口。

从阶段 A 升级时，在改变原 OSS 位置前迁移数据库并执行 php bin/register-oss-version.php 注册原配置。主密钥与 PostgreSQL 和原声数据一同备份，轮换主密钥保留 APP_PREVIOUS_KEYS。丢失主密钥不能通过换 .env 恢复历史凭据。

## 恢复与可播放归档

SegmentRecovery 核对本地帧的范围、长度和 SHA；丢失/损坏时从其历史版本 OSS 对象恢复。chunk 模式完整 HEAD+GET 下载一次至当前节点 canonical chunk cache，再按偏移提取各帧并验证各自 SHA。旧单对象 segment 也可直接恢复。旧机器绝对路径不会控制新节点写入位置。

下载有长度上限、完整 hash、临时文件、容量检查、fsync 和原子改名；坏 canonical 文件进入同目录隔离副本，拒绝符号链接和越界路径。每段/块有 cache lock，避免并发发布。每个恢复实例只读取一次当前磁盘政策，逐帧不读取设置。已验证的 chunk 在同实例内复用时仍检查范围、长度和文件时间；逐帧 hash 失败会清除缓存记忆，下次重试重新校验完整块并按需下载。

课堂 ended、模型尾句完成后 ArchiveLesson 在 archives 队列处理。状态 pending → processing → playable/failed，经 lesson.updated 通知观看者。按全局 start_sample 核验同一 PCM 时间线，epoch 切换连续，暂停不补音频；缺失、重叠、坏 hash 或总样本数不符均失败。先保存 input.pcm 和 manifest.ndjson，再由 FFmpeg 生成 AAC/M4A faststart，FFprobe 校验单声道/16 kHz/AAC 和时长误差并完整解码。成品上传+HEAD 成功后才设置 playable。new ArchiveLesson($id, true) 可强制从历史片段重建。

UploadChunk、UploadSegment、ArchiveLesson 和 PurgeLesson 共用 lesson-data:<id> 跨 job 互斥锁。有 purge_status 的课堂不会开始上传或归档；已经传输中的任务先完成，清理再取得锁并依据持久清单删除对象。清单还补入该课堂已注册历史位置的精确canonical归档key，覆盖归档PUT成功而recordings尚未发布的退出边界；旧legacy逐帧nullkey按其自身已注册version、epoch和sequence补精确key。空课堂没有version时不猜.env，未知历史明确失败。没有列举或删除其它课堂/序号对象，不提前降级旧playable记录。源音频默认保留，清理由后台明确政策、审计和清单控制，不由上传/归档顺带删除。

播放/下载先核验课堂权限和 playable，再按 recording.storage_version 解析签名。数据库保存 key，不保存 URL。签名默认 120 秒，允许 30–900 秒，已签发 URL 到期前存在撤销窗口。前端不得把私有签名 URL 展示为后台设置。

## 队列、迁移与恢复命令

```sh
php artisan migrate --force
php bin/register-oss-version.php
php artisan queue:work --queue=recordings --tries=6 --timeout=120
php artisan queue:work --queue=archives --tries=4 --timeout=7200
php bin/reconcile-recordings.php
php bin/reconcile-recordings.php --apply --limit=100
php artisan classroom:archive <ended-lesson-id>
php bin/probe-oss.php
php bin/probe-oss-recovery.php
# 仅独立 synthetic testing 数据库；真实后台加密设置+队列子进程
php bin/test-real-oss-connection.php
# 只备份指定 synthetic integration 库，恢复至全新库/本地目录
php bin/probe-disaster-recovery.php --source=classroom_integration_<12hex> --lesson=<id>
```

reconcile 默认只报告 IDs；CLI 的 --apply 为明确的人工恢复，会封闭超时/结束 open chunks、重新派发失败/待处理/过期上传和 ended 课堂失败归档，保留全部输入。routes/console.php 每五分钟派发 maintenance 队列的 ReconcileRecordings，自动扫描总计最多100项，只处理open、pending、失联uploading/processing和待归档的ended课堂；自动 retryFailed=false 不重投已经failed的任务，避免无限重置异常预算。人工 CLI 使用 retryFailed=true；classroom:archive 在事务中锁定ended且未purge的课堂，将failed改pending、记录无私有路径的审计后提交派发，仍可明确重试失败归档。直接 queue:retry 不能复活数据库failed状态，先人工恢复或后台强制重建。旧重复job读到failed立即返回，ArchiveLesson显式force除外。

自动和人工扫描共用 cache lock recording-reconciliation-scan，300秒到期；同时扫描者跳过，不重复派发。同一共享cache后端供所有进程使用；scheduler的withoutOverlapping只保护派发调度，不代替扫描锁。每项应用事务先锁课堂，再锁/读取最新音频行，重验purge_status、状态和过期时间；状态已变化则跳过，任务在事务提交后进入队列。--limit是所有类别合计上限（人工允许1–1000），不是每类各一批。

不能用 queue:flush 代替恢复。retry_after 为7500秒，大于7200秒归档timeout，共享课堂数据锁保留7500秒。上传退避5/15/45/120/300秒，归档30/120/600秒。新任务固定24小时retryUntil，tries=0使锁等待不会耗尽尝试，实际异常分别最多6次/4次；超时或异常上限后进入failed。旧序列化队列payload沿用其原始重试政策，升级时先排空或人工重派，不能把旧payload当作已获得新期限。

生产采用有 pcntl 的 Linux worker 执行超时和进程退出。Windows portable PHP 不提供等价的 pcntl 强制超时，本地结果不能证明 Linux worker 崩溃/终止或 Docker 行为。持久卷、数据库、OSS 和 APP_KEY/历史主密钥一并备份。

## 测试结果与未验证边界

- **真实 OSS：通过。** reports/oss-real-retry.json 验证 PUT/GET+SHA/HEAD/签名 GET/Range/匿名拒绝/multipart/删除。最初缺凭据 unverified 和后来 403 AccessDenied 的 oss-real.json 保留为历史，最新重试全部通过。
- **真实 OSS 历史版本恢复：通过。** reports/oss-recovery-real.json 使用随机独立前缀、101 个人工 PCM 帧 / 10.1 秒，真实上传两个 chunk，删除本次 fixture 本地输入，更换活动 prefix 后通过历史版本 GET 恢复全部 PCM，实际 FFmpeg 生成 M4A、真实上传+HEAD 校验，最后只清理本次三个已知对象。数据库事务回滚，原课堂/对象未删除；未使用真实模型或麦克风。
- **真实 OSS 后台异步连接测试：通过。** reports/phase-c-real-oss-connection-queue.json 验证管理员 owner、数据库加密设置版本、实际 TestConnection 数据库队列和独立 queue:work 子进程。真实 PUT/GET/SHA/HEAD/签名 GET/Range/匿名拒绝/10MB multipart 上传与读回/删除后 HEAD 404 全部通过，finally 清理无失败。仅两个新 `_connection-tests` 随机对象，无模型请求；不能替代浏览器 CORS 测试。
- **组件/模拟 SDK：通过。** reports/phpunit-storage-recovery.xml 验证加密版本、凭据轮换、未知版本失败、100帧聚合、定时封尾、epoch、重复ACK、坏云对象拒绝、旧节点恢复、清理并发保护、三epoch暂停及换录音人后精确恢复、上传PUT后崩溃状态恢复、同实例缓存损坏恢复、恢复扫描purge竞态/全局锁/总上限/失败预算与实际FFmpeg编码/解码。确切测试数与断言以最新XML报告为准。S3 handler显式模拟，没有云请求。
- **真实 PostgreSQL 备份/恢复：通过但范围有限。** reports/postgresql-backup-restore.json 在导出快照上 pg_dump，恢复至全新随机数据库，40 表行数及逐表行内容SHA-256、137 约束和79索引一致。最新执行时业务表为空，验证当时结构、会话/迁移记录和加密存储版本数据，不能代表完整课堂/OSS/主密钥灾难恢复通过。
- **有真实课堂数据的灾难恢复演练：通过，跨机器范围未验证。** reports/disaster-recovery-real.json 从独立真实 Qwen/OSS 集成库只读导出快照，恢复至全新随机库；40 表完整行内容SHA-256、139约束和83索引一致。保存并恢复同用户 Windows DPAPI 保护的主密钥备份，错误主密钥被拒绝，原 OSS/模型加密配置可解密。空的新本地目录经真实历史 OSS GET 恢复674帧/7块/1,077,589 samples，所有帧SHA及PCM时间线一致；实际 FFmpeg 本地重建M4A与原云归档字节hash一致。源数据库、源本地文件和旧云对象未更改；仅额外新建一个随机证明对象后清理。备份二进制与受保护密钥仅留work私有目录；异地密钥托管、完整原机器丢失和Linux恢复未验证。
- **模拟上游负载短测：通过。** reports/load-smoke.json 为100个Node观看者、60秒真实墙钟、600帧/601 ACK（1次重传）、2个模拟模型session、全部客户端交付一致、7个chunk备份与真实FFmpeg归档。ACK p95 32ms，字幕完成广播p95 124ms。S3和Qwen均明确模拟；失败的前次客户端心跳测试报告保留。90分钟真实墙钟测试正在独立数据库/目录后台进行，未结束前不标通过。
- **尚未验证：** 90 分钟完整真实墙钟、真实云服务100观看者容量；真实 Linux/Docker 重启与部署；iOS Safari、Android 等实物设备和真实长课音频。真实模型、三目标语言、浏览器 CORS 的其他测试由模型/前端报告分别记录，组件或人工 PCM 不当作真实模型/真机结果。

本地为 PHP 8.5.11、PostgreSQL 17.11 portable、FFmpeg N-127252-ga25ba44c0c-20261008（官方发布 digest 核验）。运行时在 work/runtime，不是系统安装；二进制路径通过 .env 配置，生产采用部署说明的持久卷与服务管理。




