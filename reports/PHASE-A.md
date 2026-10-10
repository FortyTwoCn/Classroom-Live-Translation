# 阶段 A 验证记录 · 2026-10-09（Asia/Shanghai）

源码、Composer/pnpm锁、PostgreSQL迁移、部署配置与探针已交付。原始需求文件SHA256与用户附件一致；核心生产路径无模拟字幕回退。此报告只对应阶段 A，不称阶段 B/C 已完成。

## 真实外部服务

- Qwen最初使用新加坡地域，真实连接返回401鉴权失败，见 [`qwen-real.json`](qwen-real.json)。用户明确业务地域为北京后改为cn-beijing，默认 Endpoint由 Workspace/地域严格生成。
- 北京真实模型 **中文、英文、乌克兰文三种目标均通过**：实际收到source delta/completed、translation delta/response.done completed、text模式确认及session.finished，见 [`qwen-real-beijing.json`](qwen-real-beijing.json)、[`qwen-real-beijing-en.json`](qwen-real-beijing-en.json)、[`qwen-real-beijing-uk.json`](qwen-real-beijing-uk.json)。
- 输入为Windows SAPI人工英语语音，单声道16kHz PCM、271437样本、约16.96秒；不是物理麦克风采集。只验证接口事件/终结，不声称人工听辨质量、乌克兰语源语、俄语口音英文或长会话限制已经通过。
- OSS首次PutObject返回403 AccessDenied，历史见 [`oss-real.json`](oss-real.json)。用户授权重试后 **全部真实兼容探针通过**，见 [`oss-real-retry.json`](oss-real-retry.json)：PUT、GET/SHA256、HEAD、签名GET、签名Range、匿名访问拒绝、multipart+HEAD、multipart读取校验、删除及删除后不存在。随机临时对象已清理；未接触课堂对象。
- 实际 Vue AudioWorklet → PHP/Workerman → 北京 Qwen → 三客户端同步 → PostgreSQL → 私有 OSS 原声归档/播放 **通过**，见 [`真实联合报告`](../frontend/tests/artifacts/actual-real-services/real-services-results.json)。175 个 PCM 包含 1 个尾包，总 278571 样本全部持久 ACK；两个观看端最终 ID/文本与数据库一致；结束后轨道停止；归档 playable，浏览器播放推进，签名 Range 206/1024 字节。合成英语语音输入，不是实际教室/物理麦克风测试。一次原生播放器正常取消请求单列为 expectedMediaCancellations，实际播放及 Range 验证通过。

## 模拟与本地真实组件

- PHPunit：**48测试、164断言通过**，[`phpunit.xml`](phpunit.xml)。PostgreSQL为真实17.11，provider/传输失败用显式fixture；SDK请求测试用模拟处理器；人工PCM封装/ffprobe/完整解码使用实际FFmpeg。
- 三浏览器客户端 + 实际HTTP/WS/PG + 显式模拟Qwen：**通过**，[`gateway-simulated.json`](gateway-simulated.json)。7包含512样本尾包，总10112样本；最终独立source/translation、重复完成事件去重，guest伪造start被拒绝。
- 实际Vue/AudioWorklet→PHP/WS/PG + 模拟源/模拟Qwen的UI闭环：**通过**；开始、暂停、继续、结束、2epoch、25包含2尾包、39040样本、3客户端同步；归档当时pending而没有伪播放器。见 [`前端报告`](../frontend/TEST-RESULTS.md)。
- 前端严格TS/生产构建通过；13项重采样/抗混叠/PCM/队列/三语言单元通过。浏览器fixture5通过/1跳过；Windows WebKit音频能力缺失，不算收音通过。
- 真实PHP空库页面9种语言/视口组合通过，guest零麦克风调用，无JS/HTTP错误和横向溢出。截图标明actual-empty，模拟字幕截图标明simulated，两者分开。
- PostgreSQL真实snapshot pg_dump→独立新库pg_restore通过，18表行数、51约束、31索引一致，见 [`postgresql-backup-restore.json`](postgresql-backup-restore.json)。当前源库无业务课堂，覆盖结构/会话；不是原声/OSS/主密钥完整灾难恢复。

## 部署与剩余问题

启动、HTTPS、账号、队列、存储、重试、备份步骤见 [`README`](../README.md)。本机Windows便携PHP8.5.11、PostgreSQL17.11、FFmpeg实际运行；Docker Compose/Nginx配置完成静态审查，未构建镜像或公网部署，不能称部署验收通过。

未验证：实际物理麦克风、真实Android/iPhone/iPad/Safari、90分钟100观众、容量/延迟测量、模型长会话轮换、突然断电/本地盘损坏和原声对象灾难恢复。同步逐帧磁盘与PDO开销仍需压测；当前较细分片会产生较多队列任务/OSS对象。

阶段 B/C 所有首版功能仍以 [`acceptance.md`](../docs/acceptance.md) 为跟踪，原文没有删除或降级；真正二期的摘要、私人笔记、视觉、合成译音等没有实现。
