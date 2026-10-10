# 数据库、主密钥与原声恢复

数据库、原声对象和配置解密密钥是三个分别备份、分别核验的部分。数据库中保存原声的历史 storage_version、对象 key、长度和 SHA-256，配置表保存加密凭据。只有数据库而没有相应 APP_KEY/APP_PREVIOUS_KEYS，不能解密历史配置；只有对象也不能恢复原来的字幕、权限和校对历史。

## 备份范围

1. PostgreSQL 使用一致性快照的 custom-format pg_dump，保留表、约束、索引及完整数据。备份文件属于私有数据，保存在访问受控的备份目录，记录文件SHA和完成时间。
2. 通过当前部署的安全配置系统单独备份 APP_KEY 和 APP_PREVIOUS_KEYS。密钥不写进公开部署日志、命令参数、Git或数据库明文字段。数据库密码、OSS授权和备份解密密钥的保管方式也须能在恢复环境使用。
3. 保留 OSS 原声对象及其历史位置授权。需要对象独立备份/跨地域复制时由部署账号配置；本次测试没有验证账号外的备份副本。源对象删除和备份保留按管理员策略处理。
4. 持久化本地 storage 原声目录及其备份包含已ACK、尚未上传的帧。逐帧ACK只保证服务器本地fsync+数据库提交；完整本机卷丢失时，云备份可能落后封块窗口最多10秒，加队列、传输和重试时间。不能承诺这部分未上传音频已经在OSS。

参考命令中的账号和密码应由私有PGPASSFILE或安全环境注入，勿把密码加到命令行：

```sh
pg_dump --format=custom --file=/secure-backups/classroom.dump --dbname=classroom
```

持续写入时须使用同一导出快照核验备份，而不是把备份后的实时行数当作快照行数。演练脚本使用REPEATABLE READ只读事务导出snapshot，再让pg_dump使用该snapshot。

## 恢复到独立环境

先创建全新、明确命名的数据库和空原声目录。不要覆盖现有课堂数据库，不使用drop/clean参数删除源环境。下面的恢复库名只是示例，应改为本次新库：

```sh
createdb classroom_restore_20261009_example
pg_restore --exit-on-error --no-owner --no-privileges \
  --dbname=classroom_restore_20261009_example /secure-backups/classroom.dump
```

先核验快照完整行内容、表/约束/索引和备份SHA，再迁移新库。配置新的DB_DATABASE和本地持久存储目录，并从安全备份恢复原APP_KEY/APP_PREVIOUS_KEYS；先清除旧配置缓存，确认目标是新库，再执行迁移：

```sh
php artisan config:clear
php artisan migrate --force
php bin/reconcile-recordings.php
```

核验storage_versions、settings_versions仍能解密。恢复环境必须能访问原Bucket/Prefix，并且授权覆盖历史对象。原声始终按历史version解析；换成新的活动Bucket不会自动搬迁或补齐旧对象。历史版本缺失时明确失败，需要提供与该fingerprint相符的原配置后注册；不能把当前.env新位置当作旧位置。

OSS凭据轮换但位置不变时，通过管理员设置或受控注册更新该位置的加密授权。主密钥轮换需要保留旧解密密钥；从环境移除旧密钥前，应完成相应加密数据重加密与备份核验。

启动恢复环境网关时，未结束的课堂进入应用恢复流程；不得自动开启任何浏览器麦克风。备份中的会话、设备令牌、分享和撤销状态只代表备份时点，应由恢复管理员核查并按恢复范围撤销旧登录/分享授权，再让用户重新登录。

先以reconcile dry-run核查，再明确执行人工恢复。上传过的缺失/坏本地帧会通过历史OSS GET恢复，核验完整chunk及每帧SHA后原子发布到新节点目录；原机器绝对路径不作为新节点写入地址。未上传且本地/备份都缺失的帧不能凭空恢复，归档应明确失败。

```sh
php bin/reconcile-recordings.php --apply --limit=100
php artisan classroom:archive <ended-lesson-id>
```

人工恢复可重新派发failed任务；自动每五分钟扫描不会反复重置failed异常预算。课堂处于purge流程时，CLI和扫描均拒绝复活归档。已playable归档的显式强制重建使用后台授权操作。

新环境先核验恢复原声和字幕权限，再开放流量。本地恢复演练不会证明Linux/Docker重启、异地网络、真机麦克风或完整原主机丢失后密钥托管可用。

## 已执行的独立演练

`bin/probe-disaster-recovery.php`仅允许指定`classroom_integration_<12hex>`synthetic测试库，并创建全新`classroom_disaster_<16hex>`库及work私有目录。它不删除源数据库、本地源文件或旧OSS对象，不覆盖旧云归档key。

`reports/disaster-recovery-real.json`通过40表全部行内容SHA、139约束、83索引的一致性恢复；同用户Windows DPAPI保护的密钥备份/恢复可解密原配置，错误密钥被拒绝。真实OSS恢复674帧/7块/1,077,589 samples，实际FFmpeg本地重建M4A与原云归档字节SHA一致。额外创建并清理的随机证明对象只验证恢复授权可写，不修改原对象。该演练没有调用Qwen或真实麦克风。

这是同一Windows用户/主机上的DPAPI恢复验证。受保护密钥备份不能据此声称在原机器已丢失、另一用户或Linux环境可解密；异地密钥托管与完整机器损失恢复仍未验证。私有备份二进制和受保护密钥只留work目录，不作为公开交付文件。
