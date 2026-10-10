# 阶段 A 架构与协议

Laravel 13.35 / PHP 8.5 提供 HTTP 与授权；Workerman 5.2.3 常驻进程维护每课堂单条上游；PostgreSQL 17 保存权利、音频索引、字幕、模型配置、usage、审计和事务 outbox。Vue 3/TS/Vite 只承担客户端。Redis 可用于生产缓存，最终内容始终在 PostgreSQL。FFmpeg 和 AWS SDK 在独立队列运行。

## 权限

| 操作 | guest | must_change_password 用户 | 有效 user | admin |
|---|---|---|---|---|
| 公开课程/课堂/订阅/回放 | 是 | 按 guest | 是 | 是 |
| 非公开课堂 | 否（分享令牌待 B） | 否 | 是 | 是 |
| 建课程 | 否 | 否 | 否 | 是 |
| 建课堂 | 否 | 否 | 是 | 是 |
| 开始/暂停/音频输入 | 否 | 否 | 仅当前业务持有者和有效唯一连接 | 同样约束 |
| 结束 | 否 | 否 | 当前持有者或创建者，必须确认 | 必须确认 |
| 管理、校对、交接、导出、90 天登录 | 待 B 全部验收 | 禁止正式操作 | 待 B | 待 B |

所有身份从服务器读回；HTTP 有 CSRF，WS 先检查精确 Origin 再验证 30 秒一次性 ticket。ticket 只保存 SHA256，绑定当前 HTTP 设备 Session；每次命令/音频/广播重新检查账号和访问权。禁用和退出后 WS 不再有操作权。客户端没有上游 Key、OSS Key 或长期 JWT。

## 状态

课堂 ready→live→paused→live→ended；只在 upstream session.updated 已确认 text/目标语言后转 live，开始录音凭据由 recorder.ready 返回。暂停/结束先停采集和尾包 ACK，再送 session.finish，有界等待。课堂 ended 立即拒收新音频，与 archive pending/processing/playable/failed 分离。模型 connecting/configuring/ready/finishing/finished/failed/interrupted 单独保存。

同课程未结束课堂由 PostgreSQL 部分唯一索引保证；每课堂一个 recorder_connection 与 recorder_epoch；重开增 epoch、包序号归零、media_samples 继续。Worker count=1，数据库 session advisory lock 只允许一个网关；DB reconnect 导致 backend PID 改变时停止全部上游，需监督重启。不承诺水平扩容容量。

## 音频与时间轴

AudioWorklet 依据真实 AudioContext.sampleRate，用抗混叠 FIR 和连续重采样，混为单声道 PCM16LE/16kHz。标准包 100ms / 1600样本，尾包可 1–1599样本。二进制前8字节是 uint32 BE epoch、sequence；后面是小端 PCM，不是容器文件。

服务器在行锁下检查连接/持有者/epoch/租约/预期序号、容量和时长，临时文件 fsync→rename→音频索引与累计样本事务→commit 后 ACK；随后发送 Qwen Base64 audio append。ACK 只说明本站持久接收，不代表翻译或 OSS 已成功。重复序号须同 SHA256，否则冲突；不会重送重复音频给模型。不承诺供应商恰好一次。翻译传送状态 sent 仅表示交给 Socket，结果未知仍保留原声。

暂停不占 media_samples，时长=media_samples/16000。源文/译文各自 unit，不假设 item相同；仅 assistant.previous_item_id 建真实关系。提供时间时把模型 session offset 加上 base_sample；没有时间就 null/unavailable，不用文字到达时刻伪造语音时间，不做字词级对齐。

本次每个100ms帧独立持久化和上传任务便于审计；长课会产生较多对象/任务。同步本地盘与 PDO 对事件循环的延迟、断电目录持久性、损坏分片、90分钟100观众容量尚未测，必须在 C 聚合分片/压测后评估部署规模。

## 可靠字幕与 outbox

provider event ID+unit ID 按模型 session 幂等。text.done 不代表成功，译文 completed 仅来自 response.done.status=completed；failed/incomplete/interrupted 保留状态。源文 completed 独立完成。模型原始 final 和人工 current 分开，late metadata 不覆盖原始/已校对文本。

字幕和 lesson_events 同事务提交，统一先锁 lesson；snapshot 锁同一行，在可靠 cutoff 下读取最新200单位。HTTP before_id 可回读更早内容。已提交但未 broadcasted_at 的事件由网关循环读取，避免跨事务 bigserial 提交顺序产生跳过；广播成功后标记，重启可能重复广播，客户端按 seq 幂等。

观看者自动重连当前阶段重新取可靠快照，不承诺按 last_seq 的完整历史增量重放；长期分页阅读状态与临时状态补齐待 C。传入 last_seq 不会改变授权，也不能开启模型。

## HTTP API

- GET /api/me：用户安全字段、CSRF、WS URL。
- POST /api/login：字符串学号/密码，设备 Session，限流。POST /api/password：强制改密或持 current_password 改密。POST /api/logout：设备 Session 失效。
- GET /api/courses；POST /api/courses 仅 admin；GET /api/courses/{id}/lessons；POST 同路径：name+UUID idempotency_key。
- GET /api/lessons/{id}：lesson、units、last_seq，before_id 可选。
- POST /api/lessons/{id}/ticket：短期一次性 ticket，guest公开查看可用。
- GET /api/lessons/{id}/playback：权限和可播放状态、配置指纹检查后返回短期 URL。

错误为稳定 code + params，不返回堆栈/私有正文。尚未实现管理写设置、90天设备令牌、分享令牌与全部导出 API，不能拿404当成对应功能完成。

## WebSocket

- 首包 subscribe {ticket,last_seq}；返回 snapshot {lesson,units,last_seq}，未订阅10秒关闭。
- heartbeat 每5秒，默认20秒宽限，服务器刷新 recorder lease。
- start/resume 开启当前 holder；ready {epoch,next_sequence,media_samples} 后上传。
- pause {epoch}；end {confirmed:true,epoch}。
- audio.ack {sequence,media_samples}；可靠 event {seq,event_type,payload}。
- subtitle.updated payload={unit}；lesson.updated payload={lesson}。
- error {code,params}；无模拟静默回退。

## 进程/归档

上传 queue=recordings，归档 queue=archives，超时和retry_after分离。归档只处理 ended，验证每个同格式分片SHA、样本连续，流式拼 PCM而不是拼不同容器；FFmpeg封AAC/M4A faststart，ffprobe核时间及全文件解码，再上传和HEAD验证，最后数据库+状态事件标 playable。原始输入不自动删除。

进程重启把活动课堂暂停/中断，不自动开麦。突然崩溃后的孤儿文件与 ended-but-unarchived 自动 reconciliation、从 OSS 重建丢失本地片段、滚动模型session、安全交接与灾难恢复仍待 C，不声称这些已通过。
