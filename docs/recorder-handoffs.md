# 设备级录音权与交接

业务权属于用户，有效音频输入属于一个连接与递增 epoch。HTTP 身份、一次性 WebSocket ticket、每条命令、每个音频包和广播前均重新检查账户、会话和课堂权限。浏览器许可由目标设备明确操作取得。

默认心跳 5 秒、租约 20 秒、离线接管宽限 30 秒、邀请有效期 60 秒；后三项由环境变量配置。断线立即安全暂停并保留业务持有者，宽限内不分配给其他人。恢复需要本人再次点击继续；不会自动打开麦克风。

私有 `handoffs` 消息仅发给参与连接。合格用户的 `presence.participants` 包含姓名、业务用户 ID 和非上传凭据的连接选择 ID；游客只收到在线人数。学号不出现在名单中。

| 命令 | 参数与效果 |
|---|---|
| `recording.invite` | `target_client_id`, `confirmed:true`, UUID `idempotency_key`；当前持有设备邀请目标 |
| `recording.request` | `confirmed:true`, UUID `idempotency_key`；目标向持有设备申请 |
| `recording.respond` | `id`, `action:accept/reject/cancel`；只允许对应设备/发起人 |
| `recording.prepared` | `id`, `ready:boolean`；只允许目标连接。false 不撤销旧权限 |
| `recording.takeover` | `confirmed:true`, `prepared:true`, 可选 `force:true`；普通接管须离线宽限结束，管理员强制接管须近期重新认证 |

接受邀请或批准申请后，目标收到 `recording.prepare`，通过用户操作准备麦克风。准备完成才原子更换持有者、撤销旧连接、递增 epoch，取消竞争邀请并审计。旧设备新音频立即拒收；旧模型先 `session.finish` 收尾，然后新模型建立，`recorder.ready` 携最新课堂快照。目标此时才启动采集上传。过渡期间明确暂停，不保证零间隙音频。

原课堂 ready/paused 时交接保持该状态；目标停止预备轨道，不自动建立模型。结束、账户禁用/会话撤销、连接关闭使邀请失效。同账户的另一个连接不因用户名相同而获得输入权。

验证见 `reports/handoffs-simulated.json`：真实 PHP/Workerman/PostgreSQL、显式模拟 Qwen，四客户端涵盖拒绝准备、成功 live 交接、旧 epoch 恶意发送、暂停申请交接、媒体连续及游客拒绝。实体双设备交接尚未验证。
