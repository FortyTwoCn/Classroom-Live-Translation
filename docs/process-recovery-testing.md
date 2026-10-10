# 阶段 C：强杀网关进程与账号撤销恢复验收

2026-10-09 验收 PASS，最终一轮 10.394 秒。结果保存在 `reports/process-recovery-simulated.json`。这是三个真实 Edge HTTP/WebSocket 客户端、真实 PHP/Workerman 进程和真实 PostgreSQL；Qwen 是明确的本机协议 fixture。未启动 OSS worker，未访问真实模型或 OSS，未使用实体麦克风。此报告必须列入模拟上游测试，不能冒充真实模型故障恢复。

完整流程：

1. 三客户端订阅，epoch 1 写入四帧并收到持久化确认，媒体样本为 6400；原文与译文均保持未完成。
2. 控制器强杀网关进程并启动新进程。三个旧连接关闭；重新授权订阅读到 paused、translation interrupted、两条 interrupted 单位、持久事件 replay 和 0..6400 的 translation_uncertain 记录。没有自动重启模型或收音。
3. 人工开始 epoch 2。epoch 1 的音频返回 recorder_stale，访客音频返回 login_required；成员的新帧确认后累计 8000 样本。
4. 禁用成员并提高账号授权版本，成员 WebSocket 关闭、HTTP 收音问题提交被拒绝；访客仍保持订阅并看到暂停。
5. 启用后重新登录，人工开始 epoch 3，写两帧并结束；共 7 帧、11200 样本，epoch 帧数为 4/1/2。

数据库读回验证每帧本地长度、SHA-256 与连续媒体位置；进一步将实际 PCM 与脚本发送的每帧固定样本值比较。完整流的实际/预期 SHA-256 均为 `d7d92bc4eb807f93da70315e58c52c61162487f77f8f5e03451fafabd0714085`。最终模型会话恰好三条，状态为 interrupted/finished/finished，无未结束会话；最初的两条 interrupted 字幕仍保留，没有被后续模型完成事件覆盖。

脚本加入有界阶段标记、异常栈和客户端安全事件诊断；记录事件类型、错误码及状态，不记录密钥、Cookie 或字幕内容。重启控制器等待新网关监听，录音开始只接受新增 recorder.ready。

已有失败证据保留：第一次 fixture 的 UTF-8 BOM 触发 JSON 解析错误；原始错误日志已复制到 `reports/process-recovery-failed-attempt1.stderr.txt`。第二次原超时报告为 `reports/process-recovery-failed-attempt2.json`。第三次带诊断的超时为 `reports/process-recovery-failed-attempt3.json`，显示三个 socket 实际 readyState=3、closeCode=1006，但脚本错误使用只读原生 Window.closed 保存 WebSocket 状态。改成自有 socketClosed 后通过。这里修正的是测试脚本，未更改生产恢复代码或放宽验收。

复现：在 Windows PowerShell 中，从项目根目录执行 `./tests/e2e/run-recovery.ps1`。需要部署说明中的本地 PHP、Node/Playwright/Edge 和 PostgreSQL 测试运行时。脚本仅使用空闲的 20080/20081/20082，创建全新隔离数据库与录音目录，使用明确的 mock 凭据及 loopback Qwen endpoint，没有 OSS 队列 worker。端口已被占用会停止测试，不终止其他服务；结束时仅清理记录且启动时间一致的测试子进程，保留隔离数据和诊断。整体 120 秒有界，原报告失败时在新一轮前自动复制保留。

未验证：真实 Qwen 会话断线后的供应商行为、真实 OSS worker 在强杀时的在途操作、实体麦克风缓存丢失、网络分区、整台机器断电、生产容器/反向代理重启及跨节点切换。已有真实模型短轮换、真实 OSS 归档和独立恢复探针应分别阅读对应报告。
