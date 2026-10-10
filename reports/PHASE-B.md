# 阶段 B 交付与验证 · 2026-10-09

阶段 A 的真实北京 Qwen、真实 OSS 和三客户端原声回放已先行验证；详情与未验证的物理设备见 [PHASE-A.md](PHASE-A.md)。本阶段实现首版产品功能，没有开发附件真正二期的摘要、私人笔记或合成译音。

## 可运行代码

- 管理员、普通用户、游客的 HTTP / WebSocket / 音频授权；强制改密、90 天设备级哈希令牌轮换、撤销、退出全部设备及敏感操作重新验证。并发旧请求不能复活已退出的短会话。
- 课程、课堂、用户与句子后台；搜索、排序、分页、同课程活动课堂唯一；普通用户可重命名和校对其他用户创建的可访问课堂。软删除保留审计，原分享令牌在恢复后仍撤销。
- 按设备而非仅按账号交接；邀请、申请、拒绝、取消、超时、麦克风准备失败、在线交接、掉线接管与管理员强制接管。旧 epoch 音频在服务端失效；暂停课堂交接不会自动开麦。
- 独立原文和译文校对、完整追加历史、恢复为新修订、乐观锁与幂等键；流式和中断文本不可伪装为完成结果。
- 实际 XLSX 模板、预检、异步确认、错误 CSV；学号字符串保留前导零，重复导入不重置密码或提升角色。限制文件、行数、公式、宏与异常 ZIP。
- 每课堂稳定二维码、真实保存 PNG 与精确落点、公开/链接权限、撤销；本地生成不把课堂链接发送第三方。大字幕支持 24/32/40/48/64 px、三种布局和用户本地偏好。
- TXT、Markdown、SRT、WebVTT × 原文/译文/双语 × 原始/校对版本，共 24 种组合；固定数据库快照、异步队列、受控文件领取、到期清理和权限复查。

实现文档：[账户与导入](../docs/accounts.md)、[录音权交接](../docs/recorder-handoffs.md)、[修订、分享与导出](../docs/B-content-sharing-exports.md)。全部接口与界面支持中文、英语、乌克兰语；界面语言不改变课堂目标语言。

## 测试分类

| 类别 | 结果 | 边界 |
|---|---|---|
| PHP / PostgreSQL | **145 测试、711 断言通过** | 真 PostgreSQL；provider 为 fixture，不能当真实模型 |
| 前端单元 / 构建 | **18 测试通过、严格 TS 与生产构建通过** | 重采样、队列、三语言键和 QR 解码 |
| 产品浏览器 | **12 组通过** | 实际 PHP、WS、PG、队列与 4 种下载文件；显式合成字幕，0 模型/OSS调用 |
| 录音交接后端 E2E | **通过** | 4 客户端，实际 PHP/WS/PG、模拟 Qwen；14400 样本，epoch 1/3/5 连续，旧音频拒绝 |
| 录音交接浏览器 E2E | **6 组通过** | 3 客户端，实际 AudioWorklet/PHP/WS/PG、模拟音源/Qwen；43 PCM 包全持久 ACK，样本与数据库一致 |
| 真实模型 / OSS | 沿用阶段 A 独立真实报告 | 本阶段账户、编辑、分享和导出均不请求模型 |

原始记录：[PHPUnit](phpunit-phase-b.xml)、[权限](phpunit-phase-b-permissions.xml)、[账户导入](phpunit-accounts-imports.xml)、[内容与导出](phpunit-content-sharing-exports.xml)、[交接](handoffs-simulated.json)、[产品 UI](../frontend/tests/artifacts/actual-phase-b/phase-b-results.json)、[音频交接 UI](../frontend/tests/artifacts/actual-phase-b-audio/phase-b-audio-results.json)。24 个实际 UTF-8 文件示例在 [docs/examples](../docs/examples)，明确为模拟内容。

音频浏览器测试验证同账号两设备、接受/拒绝、暂停交接后仅手动继续才收音、旧轨道停止及旧 epoch 拒绝、结束尾包持久 ACK。大字幕、语言、字号、二维码与页面内全屏回退没有重启麦克风或模型。已修复 `recorder.ready` 与迟到课堂状态的顺序竞争，独立课堂状态序号不会跳过字幕补齐。游客全程没有麦克风调用。

一次 SRT/VTT 浏览器测试失败来自测试 fixture 的时间精度标记不合法，后端正确拒绝生成带时间文件并解释可先导出 TXT/Markdown。修正显式 fixture 的标记后重新建立导出任务，四种文件实际生成、读取通过；旧失败任务快照没有被修改。

## 部署与剩余问题

在阶段 A HTTP、网关、上传、归档进程之外，增加 `queue:work --queue=exports,imports,maintenance --timeout=1800 --tries=3` 和 `schedule:work`。Compose 与本地启动脚本均已包含；PHP 开启 dom、xmlreader、zip；Nginx 限制请求大小、关闭分享路径日志并设 no-referrer。管理员通过秘密询问独立密码的 CLI 创建，没有内置管理员密码。完整启动见 [README](../README.md)。

未验证：实际物理麦克风、Android/iPhone/iPad/Safari、真实 90 分钟与 100 观众、Docker 镜像和公网 HTTPS 部署。90 天令牌的时间、轮换和过期边界用自动化覆盖，尚未真实等待 90 天。阶段 C 继续实现历史存储配置恢复、录音重建、术语、字幕回放联动、用量和长课异常验收。原需求完整保留，没有把缺项降为二期。
