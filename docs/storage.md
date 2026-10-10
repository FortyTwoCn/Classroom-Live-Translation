# 原声持久化、OSS S3 与归档（阶段 A）

原始需求完整保存在 `requirements.original.md`。本文件说明当前实现及实测边界，不替代或删减原需求。

## 当前实现

- 网关确认接收前须将 16 kHz、单声道、16-bit little-endian PCM 写入持久目录，并保存 `audio_segments` 的采样数、全局起始样本、epoch、序号、SHA-256、路径和存储配置版本。只在数据库和本地文件可靠写入后发送持久化 ACK。采集 ACK 不表示 OSS 上传或翻译完成。
- `UploadSegment` 在 `recordings` 队列中上传同一份 PCM。AWS SDK for PHP 3 负责 SigV4；使用虚拟托管寻址、HTTPS、已知 Content-Length。关闭可选 AWS 校验和扩展，不使用私有 OSS SDK、自制签名或 path-style。小对象直接 PUT，大对象采用最多 8 MiB 的有界分片，每片大小明确；失败尝试中止分片上传。
- 上传后必须 HEAD 核对对象长度和 SHA-256 元数据。`storage_state` 依次为 `pending → uploading → uploaded`，可重试失败恢复到 pending，耗尽重试后 failed。输入文件保留。
- 收音停止、模型尾句有界收尾完成、课堂已 ended 后才派发 `ArchiveLesson`。归档在独立 `archives` 队列处理；状态 `pending → processing → playable/failed` 与课堂结束独立。
- 归档按全局 start_sample 读取片段，重新核对每份文件长度及 SHA-256，只拼接相同 PCM 格式，不直接拼接 M4A/WebM 等容器。epoch 切换保持采样时间连续；暂停不补入音频。存在缺失、重叠、校验损坏或样本总数不符时明确失败，不伪造完整录音。
- 归档写出 PCM 和 NDJSON 清单后调用 FFmpeg 生成 AAC/M4A `faststart`，用 FFprobe 校验唯一 AAC 音轨、16 kHz、单声道和时长误差，并完整解码验证文件。只有成品上传及 HEAD 验证成功后，在同一事务中设置 recordings 与 lesson 为 playable。磁盘容量不足则失败，源片段、输入 PCM 和清单继续保留。
- 播放接口须先执行课堂权限检查、确认 playable 与配置版本，再调用 `presignedGet`。数据库保存对象 key，不保存过期 URL。默认签名 120 秒，可配置范围 30–900 秒。已签发 URL 在到期前存在撤销窗口；需要即时撤销时应实现受控代理。

## 设置及网络边界

官方当前介绍的标准 S3 兼容地址是 `https://s3.oss-{region}.aliyuncs.com`；[阿里云 AWS SDK 接入文档](https://help.aliyun.com/zh/oss/developer-reference/use-aws-sdks-to-access-oss)要求按实际 Bucket 地域配置。具体 SDK 与项目账号兼容性以本项目真实探针结果为准。

`.env` 的 `OSS_ENDPOINT`、`OSS_REGION`、`OSS_BUCKET`、`OSS_ACCESS_KEY_ID`、`OSS_ACCESS_KEY_SECRET`、可选 `OSS_SESSION_TOKEN`、`OSS_PREFIX` 与 `OSS_PRESIGN_SECONDS` 仅供服务器使用。允许的目的地址严格限制为与配置 region 一致的阿里云公开 regional endpoint（标准 `s3.oss-` 与旧 `oss-` 格式）。拒绝 HTTP、端口绕过、用户信息、任意路径、query、自定义域、IP、内网 endpoint 和重定向；不允许管理员将任意 URL 交给探针。

Bucket 必须私有。RAM 授权仅覆盖项目对象前缀与专用测试前缀的 PutObject/GetObject/DeleteObject、multipart 相关动作。建议设置 incomplete multipart upload 生命周期，处理进程崩溃后的未完成上传。对象前缀不允许路径穿越。不能将凭据输出到前端、GET 响应或日志。上传错误只持久记录稳定错误码和异常类型，禁止原样记录 AWS exception（可能包含签名地址）。

浏览器回放需在 OSS 控制台针对部署的 HTTPS origin 配置 CORS，允许 GET/HEAD 和 Range，暴露 Content-Range、Accept-Ranges、Content-Length；不开放 Bucket 列表或永久公共访问。CLI Range 探针不能代替实际浏览器 CORS 验证。

## 配置版本限制

当前阶段 A 是单一不可变 `.env` 配置快照。`OssConfiguration::version()` 用 endpoint、region、bucket、prefix 的 SHA-256 标识存储位置，片段及录音记录保留该标识。凭据正常轮换且对象位置相同时版本不变；改变目标位置后旧任务明确报错，不上传到错误 Bucket，也不签发错误回放链接。

在阶段 B 实现加密、版本化数据库配置和旧版本解析前，不能直接改 Bucket/Endpoint/prefix 来迁移生产数据。需保留旧设置和服务器主密钥备份。`OSS_STORAGE_VERSION` 环境变量目前仅为预留字段，实际配置身份以计算出的哈希为准。当前未提供可任意切换存储配置的后台，也未声称已完成该需求。

## 启动及验证

安装锁定依赖并配置 PostgreSQL 后，由独立 worker 处理归档，不能在音频网关事件循环直接调用 FFmpeg 或 OSS。生产分别启动：

```sh
php artisan queue:work --queue=recordings --tries=6 --timeout=120
php artisan queue:work --queue=archives --tries=4 --timeout=7200
php bin/probe-oss.php
```

队列 retry_after 必须大于最长任务 timeout，防止未完成任务被另一 worker 重取；对象任务另有互斥锁。有界退避为片段 5/15/45/120/300 秒，归档 30/120/600 秒。管理员确认问题解决后可使用 Laravel failed_jobs 与 `php artisan queue:retry <uuid>` 重试。重试不删除本地输入。禁止使用 queue:flush 作为恢复操作。

真实 OSS 探针只写入随机 `_connection-tests/<128-bit-random>/` 子路径，验证 PUT、GET+SHA-256、HEAD、签名 GET、签名 Range、匿名访问拒绝、超过 8 MiB 的 multipart、删除和删除后不存在。finally 只清理本次预先指定的两个 key，不列出或接触课堂对象。报告不含凭据、签名 URL 或音频。缺配置返回 exit 2 和 `unverified`，真实失败 exit 1，通过 exit 0。不会自动切换到模拟存储。

`StorageConfigurationTest` 的 SDK 本地签名测试与 `StorageArchiveTest` 的生成 PCM fixture 测试均属于模拟/本地组件测试，不代表真实 OSS、真实模型或真实设备。FFmpeg 测试使用人工生成的 440 Hz 两秒音频，实际执行编码、FFprobe 和解码；缺二进制时明确 skipped。

本轮 Windows 本地执行结果：PHP 8.5.11，存储测试 14 项、46 断言全部通过（包含真实 FFmpeg 编码/解码人工样本）。本地 FFmpeg 来自官网列出的 BtbN Windows build，版本 `N-127252-ga25ba44c0c-20261008`；下载 SHA-256 与 GitHub release 的官方 digest 一致。PostgreSQL 17.11 portable 已启动在 `127.0.0.1:55432`，`classroom` 与 `classroom_test` 两数据库默认 UTC；这些本地运行时位于工作目录的 `work/runtime`，不是系统安装或常驻开机服务。

真实 PostgreSQL 备份/恢复检查已通过：从 `classroom` 的同一 REPEATABLE READ 事务导出快照并交给 pg_dump，在全新、随机命名的本地数据库中 pg_restore；18 个表的行数、51 项约束与 31 个索引数量一致，报告见 `reports/postgresql-backup-restore.json`。检查没有写入或覆盖源数据库，私有备份只保存在 `work/`，临时恢复库保留并标记。当前源库业务表为空，仅包含会话及迁移记录，因此该项证明数据库结构和这些现有数据可恢复；不代表完整课堂、OSS 原声对象和服务器主密钥的灾难恢复已验证。

## 尚未验证和待完成项

最初缺凭据时探针明确返回 `unverified`。配置项目账号后，2026-10-09 16:29（Asia/Shanghai）真实探针已发送请求：配置校验通过，PUT 返回 HTTP 403 / `AccessDenied`，专用路径清理请求亦被拒绝，报告见 `reports/oss-real.json`。没有成功写入、读取或 Range 验证，不能声称已通过真实 OSS 接入。应优先核对 RAM/STS 权限、Bucket policy、对象前缀授权及账号配置；[阿里云 403 排查文档](https://help.aliyun.com/en/oss/user-guide/http-403-error-code)区分授权拒绝、签名错误和地域错误。仅凭 403 不能证明所有签名及兼容选项正确，后续必须以完整探针通过为准。浏览器 CORS、iOS Safari/Android/桌面真机回放及 90 分钟录音仍未验证。

当前网关每 100 ms 一个 durable frame，逐帧上传会产生较多对象/任务。90 分钟约 54,000 个片段，此设计尚未通过目标容量测试。核心闭环稳定后应实现有界聚合上传与 manifest/索引，并保证持久 ACK、重连去重和时间轴保持不变。

尚未实现从 OSS 下载原始片段后在全新节点恢复归档、自动扫描进程崩溃留下的上传状态、多版本加密设置、自动保留周期清理、音频对象删除审计。持久卷不可清空。部署备份应同时覆盖 PostgreSQL、recordings 持久目录、OSS 对象和配置/主密钥。仅保留当前代码输入不能代替已经验证的灾难恢复。

## 2026-10-09 16:41 重试更新
用户授权再次测试后，真实OSS全部探针PASS：PUT/GET校验/HEAD/签名GET/Range/匿名拒绝/multipart/删除，见reports/oss-real-retry.json。此前403历史报告保留，不影响最新通过结果。
