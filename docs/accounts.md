# 账户、设备登录、管理与 Excel 导入

此模块对应原始需求的登录与密码、用户和课程管理、Excel 预检确认。普通账户仅由管理员或初始化命令创建，没有自助注册。学号作为字符串存储，保留前导零；普通用户的初始密码严格为学号最后 6 个字符，使用密码哈希保存并设置独立的 `must_change_password`。姓名和学号先去除首尾 Unicode 空白再验证；学号为 6–100 个字符，禁止内部空白和控制字符。初始密码可预测，管理员应督促新用户首次登录立即更改。

应用使用 PHP 8.5、Laravel 13、PostgreSQL；XLSX 读写使用锁定的 [OpenSpout 5.12](https://github.com/openspout/openspout/tree/v5.12.0)，其 [官方依赖声明](https://github.com/openspout/openspout/blob/v5.12.0/composer.json)包含 PHP 8.5、dom、xmlreader、zip，无需 GD。运行 `composer install` 安装锁定版本。该库不执行公式；本项目额外拒绝公式单元格、宏、外部关系、实体和异常压缩内容。即使单元格被库识别为公式，错误报告仍保留原始文本并防止 CSV 公式注入。

## 登录和立即撤销

短期 HTTP session 默认 120 分钟，Cookie 为 Secure、HttpOnly、SameSite=Lax。开启“90 天保持登录”另发 `classroom_remember` Cookie：随机设备 UUID + 256 位随机验证值，数据库只保存 SHA-256。固定有效期为最初登录后的 90 天，恢复短会话时轮换验证值；30 秒前值宽限支持同时打开的标签页，其后旧验证值触发该设备撤销。自动恢复不会取得敏感操作的近期重认证权限。

敏感操作需最近 15 分钟内验证密码。`POST /api/reauth` 使用安全哈希验证并限流；登录、改密也取得该短期权限。`reauth_at` 和 `reauth_until` 与 WebSocket 录音权强制接管检查一致。强制改密状态下，管理、创建、校对、导出和录音上传等操作均由服务器拒绝。

`sessions.user_id/auth_version/device_id` 与 session payload 对应，HTTP 中间件验证账户版本、禁用状态和设备撤销状态。数据库 session handler 在用户/设备行锁事务内保存；已撤销身份不会被并发旧请求重新保存为有效权限。退出全部设备、禁用、重置密码或修改敏感账户资料会增加 `users.auth_version`，撤销设备令牌、HTTP sessions、WebSocket tickets。网关持续按相同数据库状态校验权限。

短会话退出当前设备和所有 session ID 更换额外建立 `session_revocations` 标记，只保存旧 ID 的 SHA-256。session 写入与标记操作使用相同的 PostgreSQL 事务锁；旧请求即使已捕获原 uid/payload，晚到的写入也被拒绝。标记保留短会话寿命加 30 分钟，定时任务每小时清理过期项。单设备撤销会清理对应分享授权；正常登录/改密时 session 更换会迁移已取得的课堂分享授权。

## HTTP 契约

所有修改请求使用 Laravel session 和 CSRF；API 错误形状为 `{code,params}`，不返回密码、验证值或内部异常。普通身份与游客的 `/api/me` 只返回 id、姓名、角色和强制改密状态，学号仅在管理员用户管理响应中出现。

| 请求 | 输入/结果 |
| --- | --- |
| GET `/api/me` | `{user,csrf_token,ws_url}` |
| POST `/api/login` | `{student_id,password,remember:boolean}`；返回 `{user,csrf_token}` |
| POST `/api/password` | `{password,current_password?}`；新密码 10–200 字符，不能为学号或初始密码；普通改密需旧密码，首次强制改密使用已验证的登录会话 |
| POST `/api/logout` | 退出当前设备，返回新 CSRF |
| POST `/api/logout-all` | `{current_password}`，撤销全部设备并退出 |
| POST `/api/reauth` | `{password}`，返回 15 分钟有效截止时间 |
| GET `/api/devices` | `{devices:[{id,current,user_agent,created_at,last_used_at,expires_at}]}`；只显示本人有效设备 |
| DELETE `/api/devices/{uuid}` | 撤销本人设备，拒绝跨用户设备 ID |
| GET `/api/admin/users?q=&page=` | `{users:{data,current_page,last_page,total,per_page,...}}`，每页 20，姓名/学号搜索 |
| POST `/api/admin/users` | `{name,student_id}`，固定创建普通用户、初始密码与强制改密 |
| PATCH `/api/admin/users/{id}` | 姓名/学号/disabled/role；敏感字段需近期重认证；禁止停用或降级最后一位有效管理员 |
| POST `/api/admin/users/{id}/reset-password` | `{confirm:true}` + 近期重认证；恢复学号后 6 位并强制改密、撤销会话 |
| POST `/api/admin/users/{id}/revoke-sessions` | `{confirm:true}` + 近期重认证 |
| GET `/api/admin/courses?page=&deleted=1` | 每页 30；默认排除软删除；deleted=1 显示全部记录供恢复操作 |
| POST/PATCH `/api/admin/courses[/{id}]` | name、description、target_language、guest_access、enabled、sort_order、archived；需近期重认证 |
| DELETE `/api/admin/courses/{id}` | `{confirm:true}` + 近期重认证；拒绝有未结束课堂的课程 |

课程归档保留历史查看与回放，禁止创建新课堂；软删除保留数据库和媒体对象，隐藏课程/课堂；恢复由课堂管理接口提供。设置更新向已有课堂发布持久事件，网关重新检查订阅权限。这里没有删除 OSS、备份或外部缓存，也未声称软删除会彻底抹除数据。

## Excel 预检和执行

| 请求 | 用途 |
| --- | --- |
| GET `/api/admin/users/template.xlsx?locale=zh-CN\|en\|uk` | 真实 XLSX 下载，三种语言模板都可由同一导入器识别，示例学号为文本 `000123456` |
| POST `/api/admin/imports` | multipart `file` 和 `duplicate_policy=skip\|update_name`，仅预检，不创建用户 |
| GET `/api/admin/imports/{uuid}?page=` | `{import:{id,status,counts,preview_hash,expires_at,duplicate_policy},rows,pagination}`，每页 50 |
| POST `/api/admin/imports/{uuid}/confirm` | `{preview_hash,confirm:true}` + 近期重认证，转为 processing 并提交 imports 队列 |
| GET `/api/admin/imports/{uuid}/errors.csv` | 私有、不缓存的 UTF-8 BOM 错误清单，原始 Excel 行号及错误码 |

预检只接受单工作表 `.xlsx`，文件最多 4 MiB、2,000 条数据行、20 列、2,000 个压缩条目。每条解压大小最多 8 MiB、合计最多 32 MiB；较大条目压缩比超过 100 拒绝。名称必须为非空文本；学号必须为真实文本单元格，拒绝数值单元格以避免丢失前导零。空行跳过但原始行号保留。表头兼容“名字”“姓名”、英文及乌克兰语模板。

预检结果保存在受控数据库，默认 24 小时过期、绑定上传管理员、确认时核对不可变结果哈希。重复学号默认跳过；选择 update_name 只改姓名，不改变既有密码、角色或强制改密状态。文件内重复项标为错误；所有错误行在预检可见，其余有效行在确认后于单一数据库事务写入。错误行不会写入账户。数据库唯一约束处理并发新增，重复确认和重复队列执行不会重复创建用户。队列执行时重新验证管理员权限，失败事务回滚且任务最终标记 failed；没有不可解释的半批结果。

XLSX 源文件在请求结束后由上传机制清理；库临时目录和错误报告位于非公开 `storage/app/imports`。错误报告下载后删除，CSV 中 `= + - @` 等危险开头加文本前缀。日志不写入初始明文密码或整份导入资料。预检记录含个人资料，应按部署的用户数据保留政策清理，不能公开暴露 storage 目录。

## 部署与验证

```sh
php artisan migrate --force
php artisan classroom:admin <student_id> <name>
php artisan queue:work --queue=imports --tries=3 --timeout=1800
php artisan schedule:work
php vendor/bin/phpunit --filter 'AccountsManagementTest|UsersImportTest'
```

管理员初始化命令交互读取唯一安全密码，不提供通用默认密码或向日志输出密码。必须使用 HTTPS 和 Secure Cookies，PHP 的 multipart 上传限制需允许 4 MiB XLSX（部署 php.ini 的 upload_max_filesize/post_max_size 已覆盖）。队列 retry_after 为 7,500 秒，大于最长归档任务 7,200 秒；导入独立 worker 避免媒体队列阻塞。

本模块自动化结果见 `reports/phpunit-accounts-imports.xml`：真实本地 PostgreSQL 17.11、PHP 8.5.11、OpenSpout 5.12，33 项测试、183 断言通过。涵盖令牌哈希/90 天截止计算/轮换宽限与旧令牌撤销、短会话退出后的并发旧写入拒绝、分享授权迁移/撤销、真实 database session driver HTTP 登录、权限/强制改密/近期重认证、课程归档/软删除、真实三语言 XLSX 文件、原始行号/前导零、压缩炸弹/实体/宏拒绝、预检不写入、幂等导入及既有敏感资料保护。固定 90 天过期采用期限逻辑验证；实际连续 90 天使用、目标浏览器真机 Cookie 行为及部署 HTTPS 浏览器检查仍需独立记录。这里的组件测试不替代真实模型或真实 OSS 测试。
