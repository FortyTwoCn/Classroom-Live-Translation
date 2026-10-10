# 第三阶段设置、恢复、回放和数据清理

原始需求仍完整保存在 requirements.original.md；本说明描述最终实现，不替代需求。

## 设置与访问边界

管理员通过后台设置模型、OSS、默认语言、显示时区、保存策略、下载开关、容量和用量政策。模型/OSS/政策采用 settings_versions 的加密追加版本，保存须最近15分钟重新验证、confirmed和expected_version。冲突返回409并保留客户端草稿。API不返回API Key、Access Key或Secret；留空保持已有密钥，临时OSS token有单独清除选项。日志只记录修改字段、版本和错误类别。

模型Endpoint限定官方地域/Workspace地址，OSS限定与Region一致的阿里云公开S3地址，拒绝IP、内网、用户信息、路径、查询串和任意URL。测试环境loopback模型仅由服务端开关授权，生产不会回退模拟。后台连接测试是maintenance队列任务，模型使用独立有界PHP子进程；OSS只访问新的随机_connection-tests对象，验证真实存取、签名、Range、私有访问和multipart并清理。

课堂首次开始固定模型设置版本、术语快照和OSS位置；后续暂停、继续及模型轮换沿用。新设置用于新课堂。已有课堂密钥被供应商撤销时仍会失败，不能声称冻结旧配置可以绕过失效授权；结束/恢复需管理员修复该版本的授权。相同OSS位置可更新加密历史凭据，不改对象指纹。未知或无法解密的历史版本明确失败，不用当前环境变量猜测。

默认政策：保存周期0表示长期保留，auto_purge=false；单课堂5400秒、同时3课堂、UTC日音频32400秒、80%预警；模型软轮换600秒/硬720秒；磁盘余量512MiB。费用无默认价格，官方未返回的usage和未配置的计费项显示未知。管理员可降低Excel上传限制：max_import_bytes为64KiB–4MiB，max_import_rows为1–2000；协议、解压和Web服务器仍有固定硬上限，已接受的预检快照不会因以后更改限制而被重新解释。

## 故障与事件恢复

音频ACK只在规范PCM文件fsync及PostgreSQL索引提交后发送。重复epoch/sequence还需验证当前本地文件、长度和SHA，校验失败不会假确认。ACK不代表OSS备份或模型已经完成。云上传、转码和恢复在队列内执行。

观看端重连使用last_seq；最多补500条可靠事件，超限从可靠快照和分页历史恢复。最新200单位是首屏窗口；超出范围的早期句子通过before_id、around_id或at_sample查询，不能把窗口当全课堂。临时provider状态、原始最终文本和人工修订分开处理，迟到模型结果不能覆盖修订。

网关重启后暂停活动课堂，终止失效租约/交接，未完成字幕标interrupted，保留已保存原声和translation_uncertain区间，不自动开麦或重放历史给供应商。客户端轨道终止、AudioContext挂起、网络/缓冲失败停止采集并提交capture_uncertain；不知道缺失音频时长时end_sample=null，估计墙钟不是录音媒体。采用skip_unreceived统一策略，只有已接收PCM进入媒体时间轴，恢复不盲目补发或承诺外部恰好一次。

模型轮换预先建立下一连接，在观察到的段落边界切换；硬期限下明确标近似边界。新旧连接顺序消费音频，旧连接仅收尾，不重复发送同一PCM。base_sample继续累计，epoch和采集设备不因轮换改变。finalize记录本地sent_samples；异常进程退出时没有完整计数，不能据此证明供应商已收到了全部数据。

## 回放与下载

只有真实归档playable才签发短期URL；过期或媒体错误可重新授权并保留位置。native播放器支持拖动、倍速和时间；字幕使用已保存server_segment媒体范围定位并高亮，无法精确匹配时明确近似/未知。按at_sample取回早期窗口；unit_id深链只定位，不自动播放。中文、英文、乌克兰文课程搜索返回具体课堂/单位。

正式用户原声下载须同时满足系统audio_download和课程allow_audio_download，字幕导出不授予原声下载权。下载使用短期attachment签名，普通播放仍有独立访问校验。游客不获得永久密钥。已允许播放的音频不能声称绝对防复制；已签发URL的撤销窗口为其剩余30–900秒寿命。

## 删除与保留

默认只软删除，可恢复。不可恢复清理须已结束且软删除、管理员最近验证、confirmed和准确课堂名称；排队后不能恢复。PurgeLesson与上传、归档及字幕导出共用lesson-data锁。它先把每个历史存储版本/对象key加入durable purge_objects，逐项DELETE记录进度；失败可重试，不凭当前Bucket推断位置。

本地清理限定canonical numeric课堂目录和UUID导出目录，拒绝符号链接及越界路径。数据库清除字幕/修订/修订批次、课堂术语快照、音频索引/chunk/成品索引、事件和导出快照；私有导出正文及tmp删除，导出状态expired不得被旧任务重新发布。课堂元数据、用量、模型会话和管理审计保留以解释清理和计费；课程级术语与其他课堂不受影响。数据库/对象备份和外部缓存遵循各自保留周期，不声称立即抹除。

只有显式启用auto_purge且retention_days>0，日任务才为过期已结束课堂申请同一清理流程，先撤销分享并广播状态。不会结束活动课堂。恢复/清理步骤、主密钥备份、历史OSS版本和手工reconcile见storage.md；自动恢复不会无限重置已经耗尽的失败任务预算。

## API与事件补充

| 接口 | 权限/行为 |
|---|---|
| GET /api/public-settings | 只公开阅读/显示政策，无凭据或价格配置 |
| GET /api/admin/settings，PUT /api/admin/settings/{kind} | 管理员；kind=model/oss/policies，敏感保存重新验证 |
| POST /api/admin/settings/{kind}/test | 管理员；仅model/oss，异步、owner限定、限频 |
| GET /api/admin/connection-tests/{uuid} | 当前拥有者管理员，逐步骤脱敏结果 |
| GET /api/admin/diagnostics | 管理员，安全计数/故障/审计，无原始私有日志 |
| POST /api/lessons/{id}/capture-issue | 参与该epoch的正式成员；不授予音频上传权 |
| GET /api/lessons/{id}/issues | 有课堂阅读权，安全故障详情 |
| GET /api/courses/{id}/subtitles?q=… | 有课程阅读权；链接guest不能借此查整个私有课程 |
| GET /api/lessons/{id}/subtitles?at_sample=… | 按媒体范围取40+40单位窗口，没有依据则空结果 |
| GET /api/lessons/{id}/download | 正式成员+系统/课程双开关，短期attachment |
| POST /api/admin/lessons/{id}/purge | 管理员敏感清理，准确名称/idempotent状态 |

可靠事件包括recording.issue、usage.warning和translation.rotation；控制消息不作为观看者历史重放。recorder.ready携带权威lesson与独立lesson_seq，旧状态事件不会覆盖当前录音权，字幕仍按各自seq处理。

## 验证边界

参见PHASE-C报告、process-recovery-testing、C-real-audio-rotation、storage、load-testing及frontend/TEST-RESULTS。组件模拟、真实PHP/PG/网关、真实Qwen和真实OSS分别记录。实体麦克风、物理移动Safari/Android、真实供应商90分钟课堂、公网受信任证书及Docker/Linux实际部署仍需对应环境，不能由localhost或视口模拟替代。
