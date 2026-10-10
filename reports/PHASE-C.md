# 阶段C交付与验证 · 2026-10-09

在阶段A真实模型与OSS闭环、阶段B完整产品之后，实现恢复、术语、回放定位、用量保护和部署测试。附件原文没有删改，首版独立代码已完成；缺少物理设备或生产环境的实测仍保留，不将首版需求降为二期。A提交`b77b334`，B提交`867eaaf`；C以Git第三个阶段提交为准。

## 可运行实现

- 观看端断线增量重放最多500事件，可靠快照/历史窗口合并保留草稿；音频ACK验证实际持久文件与SHA。轨道结束、AudioContext挂起和缓冲/网络故障停止采集、报告未知音频区间；网关重启暂停课堂、保留中断字幕、不自动收音/重复发送历史音频。
- 加密追加设置版本、真实异步模型/OSS连接测试；课堂固定历史模型/OSS版本。规范PCM逐帧持久，约10秒封块异步备份，恢复校验全块及每帧SHA；实际FFmpeg归档失败可重试。自动reconcile处理待办，人工重试failed受限；清理、上传、归档、导出共用课堂锁。
- 课程专业术语CRUD、方向/启用/软删除、乐观冲突和课堂不可变快照；3.8 phrases回显确认后才收音。连续模型连接顺序轮换不更换浏览器track/epoch/包序号；硬期限下切换明确标近似边界。
- 中英乌课程字幕搜索、早期单位深链、真实播放器拖动/倍速、按媒体范围回读和高亮、关闭跟随；过期签名重新鉴权。原声下载由系统与课程双开关控制，游客无下载权。
- UTC样本账本、单课/并发/日额度、提前告警与尾句有界收尾，重复帧不重复计量。官方usage去重保存；缺项未知，配置地域/币种/计费项/生效价格后用精确小数估算，不编造官方账单。
- 敏感设置再验证、默认长期保存、受控永久清理和显式保留政策。清理保留用量/元数据/审计，删除正文/修订/录音/导出快照和对应对象，备份另有周期。私有导出过期不能被旧任务重新发布。
- 六进程启动、Compose持久卷、HTTPS/WSS vhost、独立新库/主密钥/原声恢复脚本和文档。三语言覆盖新增后台、故障和限制信息。

设计与操作：[设置/恢复/清理](../docs/C-settings-recovery.md)、[迟到模型与清理](../docs/late-model-purge-verification.md)、[存储](../docs/storage.md)、[灾难恢复](../docs/disaster-recovery.md)、[术语/用量](../docs/C-glossary-usage-qwen.md)、[架构](../docs/architecture.md)、[部署](../docs/deployment.md)。

## 最终回归与明确模拟

| 测试 | 结果 | 范围 |
|---|---|---|
| 最终PHPUnit | **231 tests / 1303 assertions PASS** | 真PG17.11，provider/SDK fixture；新迁移、权限、恢复、政策、并发等，无模型/OSS调用 |
| PHP/依赖 | 130 PHP文件语法、Composer platform requirements通过 | PHP8.5.11，含bcmath/dom/xmlreader |
| 前端 | **24单元、严格TS、生产构建通过** | DSP、三语言、历史合并、媒体定位、精确价格等 |
| 浏览器模拟 | Edge/WebKit **5通过、1跳过** | WebKit缺AudioContext；不是物理Safari/手机 |
| 实际C产品UI | **9组PASS** | 实际PHP/WS/PG及队列；历史字幕明确人工fixture，回放读取真实既有OSS |
| 独立重连/权威暂停 | **2项PASS** | 实际PHP/PG/Gateway；明确补回1条字幕事件、草稿保留/DOM不重复；同holder/epoch的真实outbox暂停事件直接停止所有track，450ms后无新包；provider模拟 |
| 实际采集故障 | **3组PASS** | 真实AudioWorklet＋合成440Hz＋mockQwen；13包20,800samples/13ACK，两次手动准备；轨道结束与Context挂起均停止并保存未知区间 |
| 实际进程故障恢复 | **PASS** | 强杀/重启真实Gateway，3客户端、7帧11,200samples；旧epoch/游客音频拒绝，账户禁用撤销，PCM全SHA一致，无自动重启模型 |
| 四项并发回归 | **4 tests / 58 assertions PASS** | 实际两个PHP进程/PG advisory等待；实际queue.worker持共享锁六次释放超过旧tries，解锁后导出/空课堂清理完成；模拟时钟advance非实际长等待 |
| 本地六服务 | **PASS** | HTTP/Gateway/上传/归档/产品worker/scheduler均运行；11迁移，主业务库0用户/课程/课堂/模型会话 |
| 原生HTTPS/WSS | **PASS** | Nginx1.30.5/PHP FastCGI/Edge，3客户端、6帧9600samples；实际Secure Cookie/重定向/headers/WSS，证书自签/provider模拟 |
| 60秒100观看者 | **PASS** | 实际墙钟/NodeWS/PG/FFmpeg；600帧960,000samples，601ACK含1重传，26最终units所有101客户端一致，2模型peer；Qwen/S3明确mock |
| 最终源码60秒/100观看者 | **PASS** | 60.011秒、600帧960,000samples、601ACK、2模拟peer、6上传块、26最终units全101客户端一致、归档playable/队列0/缺口0；启动/结束源码hash完全一致，非真实模型/OSS |
| 完整90分钟100观看者 | **首次整体FAILED：archive_not_playable** | 实际5400.014秒、54,000帧/86,400,000samples、54,001ACK含重传、2174最终units在101客户端一致，无重复/缺口；180秒归档等待到期时仍在拼接，生产job未超时。原报告保留；同一录音人工归档恢复258.479秒PASS，FFprobe5400秒/AAC mono16k、完整解码/PCM与DB SHA、541块/manifest54k行/队列0通过；不是原attemptPASS或最终commit同版本90分钟 |

证据：[最终PHPUnit](phpunit-phase-c-final.xml)、[产品UI](../frontend/tests/artifacts/actual-phase-c/phase-c-results.json)、[采集故障](../frontend/tests/artifacts/actual-phase-c-capture/phase-c-capture-results.json)、[进程恢复](process-recovery-simulated.json)、[并发](phpunit-final-concurrency.xml)、[独立重放/暂停](frontend-replay-simulated.json)、[本地启动](local-startup.json)、[本地TLS](local-https-wss.json)、[烟测](load-smoke.json)、[90分钟原失败报告](load-90min.json)、[同录音归档恢复](load-90min-archive-recovery.json)、[最终烟测](load-final-smoke.json)、[最终源码hash](load-final-smoke-source-manifest.json)。独立SQL/锁等待证据：[start-settings-postgresql-concurrency.json](start-settings-postgresql-concurrency.json)。

## 真实模型

账号业务地域**北京**。保留早期错误地域401，不把它覆盖成成功。A中文、英文、乌克兰语目标及真实三浏览器服务链已通过；音源始终为明确合成英语语音，尚未验证实体麦克风、乌克兰源语及俄语口音英语。

C短闭环以及**66.894秒合成英语语音、30/40秒运营阈值强制轮换**通过实际浏览器、PHP/Workerman、北京Qwen、PG、OSS和原生播放器。674包、1,077,589样本（67.349313秒录音媒体），1次麦克风准备、1个epoch、连续序号；两模型peer分别转发667,200及410,389samples，base_sample准确衔接。三个完成原文和三个完成译文在三端与DB一致，三个官方usage行、7已上传块、343,092bytes真实归档，实际播放及Range通过。本地转发样本守恒不证明供应商恰好一次，近似边界拆段质量未人工验证。

术语测试共**14次真实请求**：10个用例完整运行通过，包括1/3/16/128/512/1000短映射、自然中文54 UTF-8字节及乌克兰专业词43字节。4个重复ASCII压力译词先配置接受后`COMMON_ERROR`，原报告保留，根因未知。1000字节源词配置/运行接受，但该长词未被朗读，匹配未验证。当前1000条/源256字节/译64字节/session.update64KiB是本站资源政策，不是供应商最大值或任意内容成功保证。

后台模型连接测试走实际DB任务＋独立PHP子进程，真实北京ready/零音频finish通过（1.493秒）。

证据：[轮换浏览器](phase-c-real-browser-rotation.json)、[逐会话DB核对](phase-c-real-browser-rotation-database.json)、[正常短闭环](phase-c-real-browser-normal.json)、[连接测试](phase-c-real-model-connection-queue.json)、[术语初探PARTIAL](qwen-phrases-real-beijing.json)、[自然术语PASS](qwen-phrases-natural-real-beijing.json)、[压力串补测](qwen-phrases-target-followup-real-beijing.json)、[64字节压力串](qwen-phrases-target64-real-beijing.json)。[真实轮换复现与限制](../docs/C-real-audio-rotation.md)。

## 真实OSS与独立恢复

A真实OSS重试的PUT/GET SHA/HEAD/签名/Range/private/multipart/delete全部通过，初次403保留。C后台新加密设置异步测试8.6秒通过，实际维护worker从1任务到0，只操作并清理两个新的随机测试对象；不会触碰课堂对象。

历史版本恢复测试101帧/10.1秒人工440Hz、2块，清除仅该fixture本地输入并更换活动prefix后，从历史OSS恢复完全相同PCM、实际FFmpeg编码/云校验通过；3个随机对象清理完毕，不调用Qwen。

完整课堂恢复演练：从真实集成测试库只读一致性快照导出，恢复到**全新独立PG库**；40表全部行内容SHA、139约束、83索引一致。原加密配置用同主机/同Windows用户DPAPI保护的主密钥备份恢复解密，错误密钥被拒绝。新存储目录无旧本地输入，实际OSS恢复7块674帧1,077,589samples；真实FFmpeg重建M4A的SHA与原归档完全一致。原云对象不改写、不删除，只新建并清理一个随机写权限证明对象。此测试不证明原机器丢失后DPAPI可恢复到另一主机。

C产品回放组只读借用A真实原声，实际native17.410688秒/1.5倍速、Range206/1024bytes、权限拒绝均通过；440历史字幕单位及时间索引为显式合成，不能作为真实模型对齐精度证据。借用对象未清理，仅另一空课堂做了永久清理。

证据：[A重试](oss-real-retry.json)、[新设置异步测试](phase-c-real-oss-connection-queue.json)、[历史块恢复](oss-recovery-real.json)、[完整独立恢复](disaster-recovery-real.json)。备份二进制/受保护主密钥留私有work，不进入公开源码。

## 部署、复现与保留失败

运行步骤见根README和部署说明。新迁移7–11保留历史数据，需PG及bcmath；HTTP/Gateway/worker使用同代码与持久目录。本机六服务在localhost:8080运行；没有默认管理员密码，先用`classroom:admin`初始化。生产HTTPS/WSS及private OSS配置由项目账号准备。

测试失败与修正分开保留：真实术语压力串供应商错误仍未解决；首轮长测配置用户名/心跳初始化、轮换测试音源duration尚未就绪、恢复脚本BOM/readonly字段、导出并发fixture时间为0、首轮TLS缺prefix目录为测试环境/脚本问题，分别保留attempt或work日志。实际浏览器还发现并修复设置草稿初始GET竞态、后台空筛选422、历史分页/播放窗口和权威暂停状态停止采集。未删除失败证据或把原失败请求改成成功。

## 尚未验证与已知限制

- 实体麦克风及现场噪声，实际拔插/锁屏/后台挂起；物理Android、iPhone/iPad及桌面Safari/Chrome。已安装Edge实跑，Windows WebKit只验证页面能力，不能代表Safari收音。
- 首次90分钟录音的180秒归档等待超时，恢复同一录音需258.479秒；Windows逐帧小文件校验/拼接慢，生产任务预算7200秒，后续测试等待改1800秒。不能声称初次整闭环或最终相同源码90分钟重跑通过；90分钟native浏览器回放未测，成品已FFprobe/完整解码核验。
- 真实供应商连续90分钟及100真人浏览器、配额与网络条件；模拟上游长测只能验证本站路径。最终源码另做60秒强制轮换，不能代替同版本完整90分钟重跑。
- 公网可信TLS、Docker/Linux镜像/进程监督/重启、异地主密钥托管和完整原主机丢失恢复；没有对应环境，均未验证。
- 真实90天墙钟登录期未等待；固定到期、轮换、撤销与Secure Cookie已测试。费用是已配价usage估算，缺项未知，不能作官方最终账单。
- 已ACK未封块/上传的音频依赖本地卷/备份，云恢复有封块及队列延迟；外部签名URL余下寿命存在撤销窗口。缺失原声不伪造静音长度或完整回放。
- 供应商未公开的上限、任意术语内容、语种/口音质量及近似拆段效果不外推；不同客户端回放精度为段落级，字词级对齐不承诺。

这些是保留的验收限制，不是把首版需求移到二期。附件第十九章扩展功能未实现。
