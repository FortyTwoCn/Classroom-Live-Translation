# 阶段 B：字幕修订、课堂分享与四格式导出

本模块以 `requirements.original.md` 的角色权限、字幕校对、课堂分享和首版导出条款为依据。以下测试中的字幕是明确构造的模拟内容；测试确实使用 PostgreSQL、HTTP 会话、数据库队列和实际导出文件，但不能将这些结果称为新增真实 Qwen 或真实 OSS 测试。阶段 A 的真实外部接口结果由阶段 A 报告单独记录。

## 已交付行为

- 所有完成强制改密的登录用户可校对自己有权访问的课堂，无课堂创建者限制。仅 `completed` 字段可修改。批量修改在同一事务内检查所有版本；冲突返回 `409 subtitle_version_conflict` 和最新字段，全部修改一起失败。幂等键不会重复增加版本。
- 模型 `original_text` 不变；人工修订写 `current_text`，每次变更追加 before/after、用户引用、时间及版本。恢复原始或某个历史版本也追加记录。历史响应仅展示姓名，不提供学号；有真实关联时同时返回源文、译文的原始内容，关联缺失时不伪造对应。
- 管理员可经近期密码验证与明确确认冻结编辑、软删除与恢复字段。操作写入审计及课堂事件。管理员字幕列表可按课程、课堂、原文/译文、关键词、是否校对及删除状态筛选，每页 30 字段；修订历史每页 50 条。
- 创建课堂的授权流程预置随机课堂令牌；观看与分享 GET 不创建、延期或重置授权。令牌只以 SHA-256 索引和 Laravel Crypt 密文存储，`APP_KEY` 必须持久保存。公开课堂展示固定课堂直达链接，链接模式展示稳定 `/s/{token}`。
- 扫码始终落到原课堂 ID，不跟随课程后来创建的课堂。访客授权限定该课堂，并同时校验令牌版本、有效期与现存会话中的授权标记。清空、过期或撤销会话后，孤立授权不能通过复用旧 Cookie 复活。登录会话更新由 `SharingService::migrateGrants(old,new)` 迁移；认证模块负责主动撤销时删除对应授权。
- 分享仅授权观看；不授权校对、收音、管理或导出。HTTP 详情、字幕、播放与 WebSocket 使用统一 `Access::lesson(..., sessionId)` 检查；根模块已挂接。已存在的 OSS 短期播放链接仍存在阶段 A 文档说明的签名有效期窗口。
- 导出支持 TXT、Markdown、SRT、WebVTT，原文/译文/双语，以及当前校对/原始模型两个版本。请求接受时锁定课堂并写入不可变 JSONB 行快照、截止事件、选项与元数据；之后新增句子或校对不会混入文件。后台生成只读取快照，不请求模型、不调用 OSS、不授予原声下载权限。
- 双语只按同模型会话、已保存的明确 source item 关联组织。未关联字段保持独立段落。时间使用课堂绝对媒体 sample / 16000；暂停、交接和模型连接切换不使用墙上时钟补造时间。`server_segment` 是段落近似时间，不能解释为逐词精度。
- 带时间格式跳过缺失时间、未经验证精度、非正时长或超出快照录音范围的段落，响应明确返回问题计数。全部无法计时则 `failed/export_empty_timed`，可改用 TXT/Markdown。未完成、空白字段和软删除字段不被伪装成确认字幕。TXT/Markdown 可保留无时间文本并标记 `time unavailable`。
- SRT 不插入伪标题字幕；WebVTT 元数据使用合法 NOTE；带时间格式规整空行并转义字幕 HTML，防止用户文本成为可执行字幕标记。
- 创建、状态、重试和下载都重查账户与课堂权限，任务只归发起者领取。账户禁用、强制改密或课程/课堂不可用即阻止后续签发。下载使用私有服务器文件和受控 HTTP 响应，`Cache-Control: no-store, private`，没有永久公开 URL。

## API

| 操作 | 接口 | 主要参数 |
| --- | --- | --- |
| 修改字段 | `PATCH /api/lessons/{lesson}/subtitles` | `changes[{unit_id,expected_version,text}],idempotency_key` |
| 修订历史 | `GET /api/lessons/{lesson}/subtitles/{unit}/history` | 可选 `before_id` |
| 恢复文本版本 | `POST /api/lessons/{lesson}/subtitles/{unit}/restore` | `revision_id:null` 为原始版本；`expected_version,idempotency_key` |
| 冻结编辑 | `PATCH /api/admin/lessons/{lesson}/editing` | `frozen,confirmed:true`；近期密码验证 |
| 软删除/恢复字段 | `DELETE .../subtitles/{unit}` / `POST .../subtitles/{unit}/restore-deleted` | 管理员路径，`confirmed:true`；近期密码验证 |
| 字幕筛选 | `GET /api/admin/subtitles` | `course_id,lesson_id,search,edited:all/yes/no,deleted:active/only/all,kind:all/source/translation,page` |
| 已获权分享链接 | `GET /api/lessons/{lesson}/share` | 现有身份/会话 |
| 解析课堂令牌 | `GET /s/{token}` | 成功 302 到固定 `/lessons/{id}`；失败到明确不可用提示 |
| 重置/撤销令牌 | `POST /api/admin/lessons/{lesson}/share/reset` / `revoke` | `confirmed:true`，可选 `expires_at`；近期密码验证 |
| 创建导出 | `POST /api/lessons/{lesson}/exports` | `format:txt/md/srt/vtt,layout:source/translation/bilingual,text_version:current/original,idempotency_key` |
| 查询/重试/领取 | `GET /api/exports/{id}` / `POST .../retry` / `GET .../file` | 发起用户；每次重查权限 |

历史响应包括 `original:{version:1,text,kind}`、最新 `unit`、`related_units`、`revisions`、`next_before_id`。`related_units` 只包含有依据的关联，不承诺一一对应。

导出状态为 `queued/processing/ready/failed/expired`。`counts` 有 `snapshot_units,selected_units,exported_segments,skipped_incomplete,skipped_empty,missing_source,missing_translation,unpaired_segments,missing_timing,invalid_timing,skipped_timing,paragraph_timing_segments,normalized_blank_lines`；`warnings` 是 `{code,count}` 数组，并包含段落计时说明 `paragraph_timing_approximate`。

## 运行与保护

按主部署文档配置 PHP/PostgreSQL 并执行 `php artisan migrate --force`。迁移 000004 只做附加建表；禁止破坏性 down。生产 Web 和导出 worker 必须共享 `storage` 私有卷，不能把 `storage/app/private/subtitle-exports` 放进 Web 静态根目录。

导出任务队列名 `exports`，例如：

```sh
php artisan queue:work --queue=exports --timeout=300 --tries=3 --sleep=1
php artisan schedule:work
```

Compose 的管理 worker 已包含 `exports,imports,maintenance`。数据库队列 `retry_after=7500` 大于任务超时；网关单独进程，不执行导出。每位用户最多 2 个未到期 queued/processing 任务，每分钟最多 5 个新任务；同幂等键返回同一任务。每任务最多人工重试 3 次，连续人工重试至少间隔 10 秒；队列的传输/写入异常另有最多 3 次有界尝试。

快照最多 20,000 字段、8 MiB 文本，超过上限明确返回 `413 export_snapshot_limit`，不会偷偷截断。文件和快照保留 24 小时。每小时 `private-export-cleanup` 定时任务删除到期文件与快照；下载/查询也即时识别到期。故障日志仅记录任务 ID 和错误类型，不记录字幕、令牌、用户资料或密钥。`/s/` 访问日志与 referrer 控制由 Nginx 部署配置处理。

## 已验证结果与剩余验证

2026-10-09，本模块 PHPUnit：**46 tests / 314 assertions，全部通过**（PHP 8.5.11，PostgreSQL）。JUnit 原始记录：`reports/phpunit-content-sharing-exports.xml`。测试范围：

- 24 个格式/布局/版本组合，中文、英语、乌克兰字符、特殊字符；源文/译文确实先后组织；SRT/WebVTT 时间语法及 metadata 位置。
- 实际私有文件生成和读取，HTTP 创建/查询/领取，UTF-8 文件名及 ASCII fallback，私有缓存头。
- 实际 PostgreSQL 数据库队列序列化和 Laravel Worker 消费生成 WebVTT；该测试在同一个 PHP 测试进程执行 Worker，不代表已完成独立容器的重启/超时部署验收。
- 修订幂等与原始不变、多字段冲突全部回滚、原始恢复形成新版本、冻结/未完成/软删除/游客拒绝修改；管理员筛选。写事务持有课堂与操作账户锁，重新检查禁用、强制改密、角色与初始 auth_version；陈旧 Service 用户对象不能绕过账户撤销或管理员降权。
- 课堂稳定分享、仅原课堂授权、课程新开课不劫持旧链接、重置/撤销/过期/删除、会话迁移、过期/清空会话不复活授权、真实 HTTP Cookie 跨请求授权。
- 快照后新句与校对不污染文件、未完成/空白剔除、当前/原始确实不同；无内容、全部不能计时、并发上限、到期清理、他人任务/禁用账号/强制改密/课程停用拒绝领取；权限恢复后显式重试。

`docs/examples/` 保存 **24 份明确模拟字幕的真实导出样例**，可用 `php bin/generate-fixture-exports.php` 重建；`fixture-report.json` 明确 `model_called:false`。这些文件不含用户学号、校对者资料、账号凭据或私密分享令牌。

本模块尚未单独验证：真实手机摄像头扫二维码、三设备全屏/大字幕体验、独立 Linux 容器导出进程强杀与重启、最大容量长文件压力与并发性能。这些不能由 PHPUnit 或样例文件代替，归阶段 B 浏览器验收及阶段 C 部署/压力验收继续完成。阶段 A 已完成的真实模型与 OSS 结果不在本报告重复充数。
