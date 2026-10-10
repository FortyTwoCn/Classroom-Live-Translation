# 长课与 100 观看者测试

tests/load 是显式测试 profile，应用生产进程不会导入这些文件。测试使用真实 PHP HTTP、Workerman WebSocket、PostgreSQL、逐帧 fsync/ACK、持久队列和 FFmpeg。输入是人工 440 Hz、16 kHz 单声道 PCM16，每份 100 ms；观看者是 100 个 Node ws 客户端，不能称为浏览器、真机或真实麦克风测试。

模型上游仅 localhost:28082 mock-Qwen，按实际收音每 50 帧产生一次源文、译文、VAD 及 final，并重复同一 response.done 测去重。存储 worker 明确安装 AWS SDK command handler，将对象保存至本地测试目录；不发 OSS 网络请求，不代表真实 Qwen/OSS 容量或配额。生产没有启用该 handler 的设置开关。

每次 run 创建新 classroom_load_<随机12位hex> 数据库和 work/classroom-load-<随机>/storage，避免共用 classroom_test 或让 lesson IDs 覆盖真实课堂原声目录。设置和测试密码只留在该私有 work 目录，报告没有密钥、签名 URL 或音频。现有数据库不清空、不覆盖，结束保留新数据库和 runtime 证据；进程清理只停止本次记录的 PID，并核验启动时间以防 PID 重用。

本地脚本假定本项目 portable 运行时位于 work/runtime：PHP、PostgreSQL 127.0.0.1:55432、pg-password.txt、FFmpeg/FFprobe，并已完成 frontend npm ci（使用 ws）。端口 28080/28081/28082 已使用时拒绝开始，不结束其他服务。

```powershell
# 60 秒真实墙钟 / 100 个 Node 观看者，缩短测试轮转阈值覆盖轮转
./tests/load/run.ps1 -DurationSeconds 60 -Viewers 100 -ReportName load-smoke -RotationSoftSeconds 30 -RotationHardSeconds 40

# 90 分钟真实墙钟，保持应用默认 600/720 秒轮转政策
./tests/load/run.ps1 -DurationSeconds 5400 -Viewers 100 -ReportName load-90min -AllowLongRun
```

第二条需要实际至少 5,400 秒墙钟收音，不能把压缩时间、快速灌帧或 60 秒测试称为已完成 90 分钟。服务建立和归档等待额外计时。运行进度在 work/<ReportName>-run.json 指向的 progress.json，正式报告写 reports/<ReportName>.json；负载退出失败也保留报告，启动或环境失败可能只有私有 runtime 日志。

报告记录实际墙钟、帧/样本/ACK、重复重传、epoch 与模型 session、唯一源/译文在全部观看者的交付、模型重复帧、样本缺口、chunk 备份与队列、真实 FFmpeg 成品、ACK/转发/mock响应/完成字幕广播延迟，以及每 5 秒 CPU/RSS 和每 10 秒数据库/队列/磁盘采样。CPU 百分比按单核心计算，可超过100%；PostgreSQL 是共享本地 cluster，其 CPU/RSS 还包含其他库与测试，明确保留这个范围限制。延迟为同机器 Date.now/PHP microtime，未做外部时钟同步。

当前脚本完成和语法检查不等于测试通过。只有对应报告 status=passed、墙钟满足目标、实际连接观看者100、样本/ACK及所有交付一致，才可报告该档负载通过。即便模拟上游负载通过，真实 Qwen容量/配额、真实OSS吞吐、移动浏览器与 Linux/Docker 部署仍须分别测试。

## 当前实测

reports/load-smoke.json 已通过60秒真实墙钟、100 Node观看者：600帧/960000samples、601持久ACK（一次重传）、模拟上游重复音频0、模型session2、源译完成26个unit在101客户端一致、重复广播事件0、7chunks uploaded、实际FFmpeg归档playable、时间轴缺口0。ACK p95 32ms，字幕完成广播p95 124ms。首轮启动配置和随后心跳初始化的测试脚本问题已修正，失败attempt报告与私有日志保留。

reports/load-90min.json 的首次完整90分钟尝试为failed，失败项为 archive_not_playable：原测试脚本结束收音后只等180秒，Windows上54,000个逐帧文件仍在校验和拼接，控制器随后停止了本次worker。实际收音5400.014秒、54,000帧、86,400,000样本、54,001次ACK、9个模拟模型session和541个uploaded chunk，全部101客户端收到相同2,174个完成unit，音频转发与广播重复均为0、时间轴无缺口。收音与共享一致性成立，首次完整测试仍失败，不改成通过。

授权人工恢复保留完全相同的数据库和PCM，停止旧worker后仅释放本课堂归档锁，直接调用原ArchiveLesson及严格模拟SDK，继续真实FFmpeg归档；单独结果见reports/load-90min-archive-recovery.json。没有重复收音、没有真实云请求，不替代首次failed报告。后续脚本将归档等待上限改为1800秒，小课完成即提前返回；报告分别记录收音和归档等待耗时。原生Windows大量小文件归档性能仍须在实际Linux部署硬件上评估。

这次人工恢复passed，用时258.479秒，原54,000帧逐帧SHA及连续样本校验通过，完整PCM172,800,000字节，54,000行manifest，实际FFprobe测得5400.000秒AAC单声道16kHz，完整成品解码通过。成品36,811,054字节，文件SHA与持久化记录一致，原101客户端的2,174个完成unit与恢复后数据库一致；随后限定测试worker处理最后一个UploadChunk，队列0、失败队列0。旧Archive任务1081只在归档成功且payload SHA仍相同时确认完成并删除，其他数据不清理。

长测进程中已经加载的Gateway代码没有热重载，但PHP惰性autoload的依赖可能在收尾时才读取已更新的文件。启动时未保存源文件hash，所以不能宣称所有组件都属于长测启动版，也不能将该次结果宣称为最终提交的逐文件相同版本测试。运行期间交付源码补充了首启锁顺序、权限ready校验及清理后迟到回调保护。最终源码另做独立60秒、强制轮转的smoke（reports/load-final-smoke.json），和90分钟报告分别呈现，不把短测视为最终版本已经完整重跑90分钟。

后续run脚本会在服务启动前和收音结束后记录应用与测试profile源码SHA-256，保存`reports/<ReportName>-source-manifest.json`，并把开始/结束一致性和Gateway哈希写入对应报告。清单排除.env、配置缓存、用户数据和凭据。未提交工作区用实际文件hash核对，不能仅凭Git HEAD称测试覆盖某个提交。运行中源码有变化时列出变化文件；清单不存在的旧长测不会被补造启动哈希。

reports/load-final-smoke.json 已通过最终源码独立烟测：60.011秒真实墙钟、100 Node观看者加录音端、600帧/960000样本/601ACK、2个模拟模型session、6个uploaded chunk、实际FFmpeg成品playable，26个完成unit在101客户端一致，重复音频/重复广播/时间轴缺口均为0、队列与失败队列均为0。ACK p95 27ms、完成字幕广播p95 124ms。对应source-manifest开始/结束所有记录的源码hash一致、变化文件为空；这是当前源码60秒覆盖，90分钟原尝试仍failed。phase_timing.archive_wait_wall_seconds记录end后的固定3秒settle之后继续轮询的等待时间，不代表FFmpeg独立转码耗时。
