# Qwen3.8 接入与验证记录

核对时间：2026-10-09（Asia/Shanghai）。此文件描述阶段 A 的适配层；不将官方说明或模拟事件测试视为项目账号实测。

## 官方依据

- [模型页面](https://www.alibabacloud.com/help/en/model-studio/qwen3-8-livetranslate-flash-realtime)
- [实时翻译指南：独立的 Qwen3.8 章节与快速开始](https://www.alibabacloud.com/help/en/model-studio/qwen3-5-livetranslate-flash-realtime)
- [客户端事件](https://www.alibabacloud.com/help/en/model-studio/live-translator-client-events)
- [服务端事件](https://www.alibabacloud.com/help/en/model-studio/live-translator-server-events)
- [Workerman WS/WSS 客户端](https://manual.workerman.net/doc/en/faq/as-wss-client.html)

实现按 3.8 的 `output_modalities` 与 `translation.language` 配置文字输出，并在 `session.updated` 确认后才接受 PCM。源文和译文分别保存；仅依赖助手消息 `previous_item_id` 建立对应关系。`response.text.done` 暂存文本，最终译文状态依据 `response.done`。正常关闭发送 `session.finish`，超时则明确标记未完成文本。

## 配置和运行

在项目 `.env` 配置 `QWEN_API_KEY`、`QWEN_WORKSPACE_ID`、`QWEN_REGION`、`QWEN_WS_ENDPOINT`。Endpoint 必须匹配 Workspace 和地域，例如：

```dotenv
QWEN_REGION=ap-southeast-1
QWEN_WS_ENDPOINT=wss://实际WorkspaceID.ap-southeast-1.maas.aliyuncs.com/api-ws/v1/realtime
```

中国北京使用 `cn-beijing`；不得在前端放置 Key。Endpoint 不附加查询参数，适配层负责添加可配置模型名称。更改 `.env` 后清理 Laravel 配置缓存并重启网关。

准备一段含清晰讲话、已经获得使用许可的音频，转换为**无文件头、单声道、16 kHz、16-bit little-endian PCM**：

```sh
ffmpeg -i input.wav -ar 16000 -ac 1 -f s16le speech.pcm
php bin/probe-qwen.php speech.pcm zh
php bin/probe-qwen.php speech.pcm en
php bin/probe-qwen.php speech.pcm uk
```

每次探测可能产生模型费用。探测限制输入 0.1 至 120 秒，每 100 ms 发送最多 3200 字节。它只联系配置的真实云 Endpoint；本地测试 Endpoint 无法产生 `real_model PASS`。输出只含计数、状态、输入校验和与官方 usage，不输出原始字幕或密钥。退出码：0 表示该音频样本的协议闭环通过；1 表示失败；2 表示缺少配置、依赖或音频而跳过。静音、仅成功连接、缺失原文、缺失译文、缺失增量、未确认尾句均不能判定成功。

自动协议测试：

```sh
vendor/bin/phpunit tests/Unit/QwenEventsTest.php tests/Unit/QwenSessionTest.php
```

## 测试结果：分别报告

| 类别 | 状态 | 依据 |
| --- | --- | --- |
| 模拟事件与配置 | 通过 | 16 个 PHPUnit 用例；去重、独立 IDs、显式迟到关联、合法 Unicode、完成/失败/中断、时间信息缺失、Endpoint 限制 |
| 模拟上游 WebSocket 传输与回调故障 | 通过 | 4 个用例；真实 Workerman 本地连接、等待 `session.updated`、尾句保留、1 秒有界收尾超时、业务回调异常脱敏并关闭、拒绝握手不输出原始头；上游文字为测试构造内容 |
| 网关真实 PostgreSQL 持久化 | 通过 | 3 个用例；构造模型事件、分别保存源文/译文、完成事件幂等、原始和人工修订值不被迟到事件覆盖、媒体偏移转换、未完成状态 |
| 真实 Qwen 模型 | **未验证 / SKIP** | 探测运行时缺 `workspace_id`、`endpoint`；没有发起真实模型音频调用 |
| 真实 OSS | 不由此探测验证 | 使用独立 OSS 探测和报告，不能从 Qwen 测试推断存储兼容性 |
| 真实麦克风、真机、中文/英语/乌克兰语质量 | **未验证** | 本地协议测试没有真人收音、口音、课堂噪声或移动设备证据 |

本机运行：PHP 8.5.11、PHPUnit 12.5.38。适配层 20 个用例、74 个断言；增加网关 PostgreSQL 测试后共 23 个用例、95 个断言通过。完整 Unit 测试组（含存储适配层）34 个用例、120 个断言通过。配置缺失探测已输出 `test_kind=real_model, status=SKIP`；项目账号配置完成后必须重新执行真实探测和浏览器纵向测试。

## 数据契约与限制

- 字幕回调为 `subtitle.upsert`，每个字段独立 `unit_id`；含 `kind`、`item_id`、可空 `response_id`、`source_item_id`、`content_index`、`text`、`status`、`language`、可空 `start_ms/end_ms`、`timing_precision`、`mapping`、`provider_event_id` 与适配层 `sequence`。一次上游事件可更新多个单元，业务落库不能仅按上游事件 ID 丢弃其他单元；会话轮换后需使用课堂与上游会话命名空间。
- 仅使用供应商音频偏移建立 `server_segment` 段级定位。未提供时间则返回 `null/unavailable`；不使用文本返回时刻伪造音频起止，不宣称字词级精度。课堂累计媒体偏移由网关叠加。
- 译文 `text_done` 不能校对或导出为确认完成。`failed/incomplete/interrupted` 保留真实文本和状态；源文独立完成，不强制等待译文。
- `usage` 为官方返回内容，未返回时为 `null`。没有价格配置便不生成精确账单。
- 不设 3.5 的源语言、`modalities`、同语言跳过、旧版 ASR 设置；不启用图片、翻译音频或声音克隆。源语言自动识别。
- 术语字段能够按 `translation.corpus.phrases` 配置，但数量、长度、实际效果与账号接受情况**未实测**。代码的 1000 条和 1000 字节每词为本站资源上限，不是供应商限制的验证结论。
- 会话真实最长时长、限流额度、90 分钟连续课堂、账号地域/权限、俄语口音英语、乌克兰语音质、长课会话轮换均**未验证**。不承诺供应商断点续传或任意历史上下文重放。
- 内存只保留有界事件和已完成单元窗口；持久化和长期幂等由 PostgreSQL 业务层负责。任何故障不会静默转用别的模型或模拟字幕。
- 上游 HTTP 401/403/429 和错误事件归一为稳定类别；拒绝的握手头不直接进入日志。密钥只在受验证的服务端目的地使用。本地测试目的地必须同时开启显式标志和 `APP_ENV=testing`。

## 2026-10-09 16:47 实测更新
用户确认北京地域后，真实中文、英语、乌克兰文目标探针均PASS（reports/qwen-real-beijing*.json）。输入为Windows SAPI合成英语语音，未验证真实物理麦克风或源语质量；此前新加坡401记录保留。Endpoint为空时按官方Workspace地域格式生成，配置可显式覆盖。
