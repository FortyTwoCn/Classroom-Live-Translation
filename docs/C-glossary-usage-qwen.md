# 阶段 C：术语、用量与 Qwen 会话观察接口

本文件是该子模块的交付与验收记录。原始需求仍完整保存在 `requirements.original.md`，没有被删减。阶段 C 总体的恢复、OSS、网关轮换、部署与浏览器验收另见项目阶段报告；本文件不替代那些验收。

## 已实现

课程术语维护源词、译词、源/目标语言、说明和启用状态。创建、编辑、软删除和恢复均增加课程版本；写入在课程锁内重新检查管理员身份和账号撤销版本。冲突返回最新版本，失败不会部分提交。列表每页 30 条，支持语言方向、关键词、启用状态和已删除状态筛选。所有写接口要求近期密码验证；删除和恢复还要求 `confirmed:true`。

课堂首次开始时保存术语原始条目、版本、映射、字节数和 SHA-256 快照。暂停恢复、录音权交接和会话轮换复用该快照，课程的新术语对下一课堂首次开始生效。快照读取使用同一 SQL 的 MVCC 视图，避免课程锁/课堂锁反向等待。人工字幕校对不会改写术语表。

Qwen 的 `session.translation.corpus.phrases` 是源词到译词的 JSON 对象。协议没有语言方向字段，因此同一目标语言下，同一源词的不同译词会被拒绝；同词同译词可去重。源语言方向作为课程管理信息保留，不宣称服务端能够根据这个字段区分同形词。[官方客户端事件](https://www.alibabacloud.com/help/en/model-studio/live-translator-client-events)

本站资源限制为：单课程最多 1000 条未删除记录、源词最多 256 UTF-8 字节、译词最多 64 UTF-8 字节、整个 `session.update` JSON（含 `event_id`）最多 65536 字节。这些是本站策略，**不是供应商公布的最大值，也不保证任意内容都能运行成功**。中文字母、乌克兰文字母与 ASCII 字符的字节数不同。

模型仍固定使用 3.8 的 `output_modalities:["text"]`。确认 `session.updated` 的文本模式、目标语言和术语回显后才允许发送音频；配置接受不等于翻译成功。结束仍发送 `session.finish` 并等候 `session.finished`，尾句超时有界且不会被标成完成。[官方客户端事件](https://www.alibabacloud.com/help/en/model-studio/live-translator-client-events)、[官方服务端事件](https://www.alibabacloud.com/help/en/model-studio/live-translator-server-events)

## 真实模型结果与限制

输入为 Windows SAPI 合成的公开英语句子，提到 airfoil、angle of attack 和 lift coefficient，规范化为 16 kHz 单声道 PCM16，时长 8.775 秒。**这不是实体麦克风测试，也不是语言质量人工验收**。报告只保存计数、匹配布尔值、usage 和安全错误码，不输出密钥、Workspace 或原始转写。

初探 9 次，墙钟 95.21 秒：

| 用例 | 配置接受与回显 | 完整收音、原文、译文及结束 | 指定译词观察 |
|---|---|---|---|
| 无术语 baseline | 通过 | 通过 | 不适用 |
| 1、3、16、128、512、1000 条短映射 | 通过 | 通过 | 通过 |
| 1000 字节 ASCII 源词，未在语音中朗读该长词 | 通过 | 通过 | **长源词匹配未验证** |
| 1000 字节 `LONGTARGET_` 加重复 A 的译词压力串 | 通过 | **失败：COMMON_ERROR** | 未验证 |

完整初探保存在 `reports/qwen-phrases-real-beijing.json`，总体 `PARTIAL`。它记录的是收紧本站资源限制之前的测试值，没有将接受值当作供应商最大值。

必要补测分三份保留，不覆盖失败报告：128/256 字节同类 ASCII 译词压力串共 2 次、9.21 秒，64 字节同类串 1 次，均先获配置确认，之后运行 `COMMON_ERROR`；不能据此推断通用字节长度限制。自然中文解释 **54 UTF-8 字节**和自然乌克兰专业词 **43 字节**共 2 次、21.54 秒，均完成配置接受、回显、原文识别、指定译词观察、全音频与 `session.finished`。后两条为 `PASS`。

证据：`reports/qwen-phrases-target-followup-real-beijing.json`、`reports/qwen-phrases-target64-real-beijing.json`、`reports/qwen-phrases-natural-real-beijing.json`。共 14 次必要模型调用，其中 10 个用例运行通过、4 个压力串失败；每轮有请求数量、180 秒总超时和 6.3 秒起始间隔。账号地域为北京。已测值不能外推到所有术语、声学条件或供应商上限。压力串失败的服务端根因仍未验证，`COMMON_ERROR` 只能归入供应商通用错误。

当前脚本默认执行与收紧后的本站资源限制相容的用例；旧压力串失败证据保持原样。重复执行会产生真实模型费用，输入必须是原始语音 PCM，缺账号或缺输入时报告 `SKIP`。

```sh
php bin/probe-qwen-phrases.php /private/synthetic-airfoil.pcm
php bin/probe-qwen-phrases.php /private/synthetic-airfoil.pcm --natural
```

## 用量保护与费用估算

`Usage::assertStart()` 检查课堂和 UTC 全账户日额度；`acceptSamples()` 在音频事务内按实际样本精确计数，先做重复包/顺序校验，随后计量并写入音频。单包超过额度会返回 `duration_limit` 或 `daily_audio_limit`，不部分接收。事务失败时样本、账本与告警 outbox 一起回滚。日额度重置不重置已有课堂的累计时长。一个包使用同一个 UTC 日锚点，避免跨午夜少计。

达到告警阈值后发布 `usage.warning`，包括代码、范围、占比、剩余秒数及日额度重置时间。告警唯一键防止每个包重复通知。硬额度之后的结束处理由网关执行有界尾句 drain；不继续接收新录音包。

`recordEvent()` 保存原始官方 usage，以模型会话与响应 ID 去重，缺响应 ID 时使用供应商事件 ID。历史重复原始行保留，统计也去重；按日期筛选不能让后来重复的响应再次计费。缺失的官方 usage、缺少 token 分类或不一致的总数都显示未知，不能补造精确数据。

价格没有产品默认数值。每条配置包括 `region`、`currency`、`item`、十进制字符串 `unit_price`（每百万 token）和带时区的 `effective_at`。可选 `source_url` 仅允许 HTTPS，无 URL 用户名/密码；可选 `verified_at` 接受日期或带时区时间，返回 UTC ISO。项目为 `audio_input/text_input/image_input/text_output/audio_output`。按每条 usage 的地域和实际记录时间选生效价格；不同币种分别列出，不做汇率换算。使用 bcmath 精确累加，最后以 12 位小数输出。缺价保留已计价小计，但完整估算为 `null`；官方最终账单始终单独对待。

统计 API 不返回账号姓名、学号、源译文、密钥或内部连接信息。正常 HTTP 统计使用 PostgreSQL 的只读可重复读事务，避免读到不一致计数。模型调用秒数基于连接创建/结束时间，明确标记 `call_seconds_estimate`，不宣称它就是供应商计费时长。

## 会话轮换挂接点

`QwenSession` 提供 `canRotateAtBoundary()`、`elapsedSeconds()`、`sentSamplesCount()`。已收到语音开始但尚未完成 ASR、临时字幕或待完成响应时，不建议切换；未完成工作超出有界窗口时失败，不为了报告安静边界而忘记它。该接口只能判断已收到的事件，供应商事件可能迟到，**不是可靠静音保证**。

网关负责 active/preparing/draining 连接、同一 epoch 的音频包边界切换和独立旧连接尾句。冻结模型配置通过 `QwenConfig::fromArray()` 加课堂术语快照传入。前端应消费 `translation.rotation`，不复用 `recorder.ready`，避免重建麦克风或重置序号。

本站默认 soft 600 秒/hard 720 秒为可配置运营策略。已查官方 3.8 模型页列出上下文/token 和速率限制，本次没有找到明确的单连接时长最大值；不能套用其他 Realtime 模型的分钟限制。短连接术语测试不等于长课堂轮换验收。[官方 3.8 模型页](https://www.alibabacloud.com/help/en/model-studio/qwen3-8-livetranslate-flash-realtime)

阶段 C 随后完成独立真实浏览器验证：66.894 秒合成语音、本站 30/40 秒阈值，两个模型音频 peer、一次硬阈值近似边界切换、同一收音 track/epoch/连续序号、三客户端一致、真实 OSS 块与原声归档回放均通过。计数只证明本站未重复转发，不保证供应商恰好一次接收；拆段翻译质量未验证。完整证据与复现见 `C-real-audio-rotation.md`，没有以该短测试替代 90 分钟实测。

## API 与测试

| 接口 | 权限与用途 |
|---|---|
| GET `/api/admin/courses/{course}/glossary` | 管理员，分页与筛选 |
| POST 同一路径、PATCH `/{term}` | 管理员近期密码验证，`expected_glossary_version` |
| DELETE `/{term}`、POST `/{term}/restore` | 管理员近期验证，版本与确认 |
| GET `/api/lessons/{lesson}/usage` | 已改密成员、课堂授权 |
| GET `/api/admin/usage` | 管理员，`course_id/lesson_id/from/to` 筛选 |

实际 JSON 示例在 `docs/examples/C-api-fixtures.json`，由 `bin/generate-c-api-fixtures.php` 使用真实 PostgreSQL 生成后回滚。**示例账号、官方形状 usage、价格数值都是明确 fixture，未调用 Qwen/OSS，价格不是官方价或默认配置。**

2026-10-09 模拟协议/资源限制 + PostgreSQL/HTTP/网关持久化回归测试为 **51 tests / 287 assertions，PASS**，JUnit：`reports/phpunit-glossary-usage.xml`。覆盖版本冲突、快照不可变、方向冲突、软删除恢复、权限撤销、近期验证、UTF-8/JSON 资源限制、配额边界、告警回滚、UTC 日重置、raw 去重、日期去重、缺价/缺 usage、多币种、生效价格、精确小数和轮换观察。协议模拟从不访问阿里云。

部署：先备份 PostgreSQL，再执行 `php artisan migrate --force`；需要 `ext-bcmath`，PHP 和网关/worker 使用相同代码与持久配置。迁移 9 会按已有音频段的 UTC 创建日回填历史账本，保留原始 usage 与历史重复行。原始模型数据没有因为迁移而删除。本站不可执行破坏性 down 回滚，恢复应进入空库。

未验证项：实体麦克风与噪声环境、1000 字节源词实际匹配、任意术语内容、供应商未公开的数量/长度/时长最大值、ASCII 压力串根因、长连接 90 分钟持续课堂和独立容器重启后的完整轮换。网关整合和真实 OSS/恢复测试应在阶段 C 总报告中独立列出，本子模块不将它们冒充已通过。
