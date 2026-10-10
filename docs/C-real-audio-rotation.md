# 阶段 C：真实浏览器音频闭环与模型轮换

2026-10-09，在独立 PostgreSQL 数据库、独立本地录音目录、独立私有 OSS 对象前缀及本机 19080/19081 端口上通过以下验收。浏览器运行生产 Vue、AudioWorklet、PHP、Workerman 和 PostgreSQL；没有 HTTP/WebSocket mock。音频输入是明确的英语合成语音替代 `getUserMedia`，不是实体麦克风。

| 验收 | 结果与证据 |
|---|---|
| 正常真实闭环 | PASS，17.4 秒持久录音，174 包全部确认，真实 Qwen 原文与译文各 1 条完成，3 客户端内容与数据库一致；`reports/phase-c-real-browser-normal.json` |
| 正常数据库读回 | PASS，174 音频段连续无缺口/重叠，2 个 OSS 块上传，归档可播放；`reports/phase-c-real-browser-normal-database.json`。此轮早于增加 peer 计数，`sent_samples` 及本地去重证明为 `null`，没有补造 |
| 真实模型轮换 | PASS，66.8944375 秒合成输入，67.3493125 秒持久录音，674 包全部确认；原文/译文各 3 条完成，3 客户端内容与数据库一致；`reports/phase-c-real-browser-rotation.json` |
| 两个模型 peer 覆盖 | 初始 peer base 0 / 667200 样本；轮换 peer base 667200 / 410389 样本；合计 1077589，等于音频段、课堂及归档的样本数，0 缺口/重叠，全部结束；`reports/phase-c-real-browser-rotation-database.json` |
| 收音权与连续序号 | 只申请 1 次收音，只收到 1 次 `recorder.ready`，epoch 始终 1，包序号持续递增；轮换事件发生时仍是同一个 live 录音 track |
| 真实 OSS 与原声 | 轮换课堂 7 个块全部上传，归档可播放，浏览器解码及播放进度通过，私有签名 Range 返回 206 / 1024 字节。报告不保存签名 URL |
| 官方用量和字幕状态 | 保存 3 条官方 usage 原始记录，保留的 interrupted 单位为 0；不据此声称不存在语义误差或缺词 |
| 异步连接测试 | PASS，实际 database queue → `TestConnection` worker → `ConnectionProbe` PHP 子进程 → Select event loop，确认文本配置及 `session.finished`，0 音频样本；`reports/phase-c-real-model-connection-queue.json` |

本次将**本站**轮换 soft/hard 配置为 30/40 秒，仅在隔离测试库生效，不是供应商连接时长限制。已记录 `preparing` 和 `switched`，切换 base 为 667200，`approximate_boundary:true`。这是一条真实硬阈值近似边界路径；安全静音切换的具体真实样例仍未验证。每个 peer 的计数是本站调用 `sendAudio` 的样本数与连续 base 的证明，不能证明供应商恰好接收一次。

首次长音频尝试因测试脚本过早读取异步解码时长，读到 0 并错误地使用 25 秒等待超时，发生在轮换前。失败报告及实际留存数据分别保存在 `reports/phase-c-real-browser-rotation-harness-failed.json`、`reports/phase-c-real-browser-rotation-aborted-database.json`。脚本改为等待解码时长及首次 `recorder.ready` 后，在新课堂重跑；没有更改生产 UI/网关来绕过问题。旧 A 阶段报告和新 C 失败证据均保留。

## 本地复现

需要已配置的私有 `.env`、有权使用 Beijing Qwen 模型/Workspace 和私有 OSS、真实 PostgreSQL，以及项目部署说明列出的 PHP/Node 依赖。以下脚本使用项目本地测试运行时约定；正常部署仍按主部署说明。执行会产生真实模型与 OSS 用量。助手没有把凭据写入报告。

```powershell
# 从项目根目录执行。helper 创建新的隔离数据库，已有库不重置。
# 如 work/real-processes.json 仍需保留，先复制备份再启动；端口应空闲。
./bin/start-real-integration.ps1

# 本地 CLI 后续进程同样需要隔离数据库和录音路径。
$metadata = Get-Content ../../work/real-processes.json -Raw | ConvertFrom-Json
$env:APP_ENV='testing'
$env:DB_DATABASE=$metadata.database
$env:LARAVEL_STORAGE_PATH=$metadata.storage
$env:OSS_PREFIX=$metadata.prefix
$env:QUEUE_CONNECTION='database'

# 使用包含公开合成语音的 fixture；实体麦克风没有参与。
./bin/make-rotation-speech.ps1 -WavePath tests/fixtures/synthetic-english-16k.wav -OutputPath ../../work/synthetic-rotation-speech.wav
php bin/prepare-real-rotation.php | Set-Content ../../work/real-rotation-fixture.json -Encoding utf8
cd frontend
$env:REAL_RUN_LABEL='rotation-reproduction'
node tests/e2e/actual-phase-c-real-services.mjs ../../../work/real-rotation-fixture.json ../../../work/synthetic-rotation-speech.wav
cd ..

# 将上一条 fixture 的 lesson_id 传入；不要使用任意生产课堂 ID。
$fixture = Get-Content ../../work/real-rotation-fixture.json -Raw | ConvertFrom-Json
php bin/report-real-integration.php $fixture.lesson_id
php bin/test-real-model-connection.php
```

`tests/fixtures/synthetic-english-16k.wav` 是 16 kHz、mono、PCM16 合成英语。轮换输入由该音频重复三遍，间隔两段 8 秒静音生成，未调用语音模型。正常 17 秒初次测试使用原 22.05 kHz SAPI WAV，经浏览器真实 AudioWorklet 重采样；轮换测试使用附带 16 kHz 版本。不同机器启动或结束的包尾时间可能略有差异，验收以实际样本数和连续性为准。

截图保存在 `frontend/tests/artifacts/actual-phase-c-real-services/normal/` 和 `rotation-final/`，仅包含公开合成内容和 fixture 账号。`bin/restart-real-gateway.ps1` 仅允许重启 metadata 中经脚本名/可执行文件检查的独立 19080 服务网关，用于测试时加载新代码；不要用于生产。

仍未验证：实体麦克风及环境噪声、实体 Android/iOS 设备、轮换拆段翻译质量与语义完整性、供应商恰好一次收音、真实模型持续 90 分钟/100 观看者、生产 HTTPS/WSS/反向代理/容器重启。模拟压力测试与真实 OSS 恢复报告分别列出，不能以它们冒充以上未验证项。
