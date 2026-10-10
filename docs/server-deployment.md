# 宝塔服务器部署记录 · 2026-10-09

目标服务器 `211.159.166.184`，项目 `/www/wwwroot/t.xuanknow.cn`，Nginx 网站运行目录为其 `public` 子目录。公网 `https://t.xuanknow.cn` 经阿里云 ESA，源站 `https://esa.t.xuanknow.cn`。业务源码为阶段 C 提交 `474088c`；本次新增原生部署模板及实际部署证据，未修改业务逻辑。

## 当前停留状态

用户明确将于明早填写数据库密码。服务器 `.env` 已创建，`DB_CONNECTION=pgsql`、`DB_HOST=127.0.0.1`、`DB_PORT=5432`，数据库及用户名都是 `clt`，`DB_PASSWORD` 留空。独立生产 APP_KEY 已在服务器生成，既有北京 Qwen 和 OSS 账号配置已通过加密 SSH 传到服务器，密钥不进入代码包或公开报告。

网站处于 Laravel 维护模式：主页返回 **503**，健康检查 `/up` 返回 **200**。`clt-fpm` 运行且开机启用；`clt-gateway`、`clt-recordings`、`clt-archives`、`clt-product`、`clt-scheduler` 的定义已安装，但均停止并暂时禁用开机启动。密码未配置期间不会不断重试数据库。没有初始化管理员、示例课堂或启动录音；`clt` 公开 schema 当前为零表，尚未执行11个迁移。

明天只需在宝塔编辑 `/www/wwwroot/t.xuanknow.cn/.env` 的 `DB_PASSWORD` 并告知配置完成；不要把密码发到聊天。继续部署时先校验密码连接与目录权限，再迁移、启用服务和验证公网应用。

## 已实施与实际测试

| 内容 | 结果与范围 |
|---|---|
| 服务器运行时 | Debian12、PHP8.5.11、PostgreSQL18.0；安装 FFmpeg5.1.9，按官方 PHP8.5.11源码 SHA256 编译 pdo_pgsql/fileinfo |
| 依赖 | 服务器原有 Composer 过旧，改用独立 Composer2.10.3；按原锁文件安装80个生产依赖，平台检查通过，锁文件 SHA一致 |
| 独立 PHP-FPM | 真实 FastCGI socket请求验证全部所需扩展及 proc_open/readlink/putenv；master root、worker cltweb，socket `/run/clt/php-fpm.sock` |
| Nginx | 目标服务器 `nginx -t` 和实际 reload通过；仅 public可公开，私有storage无公开软链接；动态 no-store/private、哈希资产长期缓存、精确 `/ws` 代理到127.0.0.1:8081 |
| 进程托管 | 六个systemd定义解析通过；现有无关 monitor/tat_agent单元的警告保留，未改它们。应用的五个后台单元待密码后启动 |
| 源站 HTTPS | 现有 esa域名证书的真实可信TLS请求、`/up`200、维护页503及安全响应头通过 |
| ESA HTTPS | `/up`200、`x-site-cache-status: DYNAMIC`、`Cache-Control: no-store, private`；短暂无URI/无Cookie诊断日志确认回源 Host=t、SNI=t、scheme=https，诊断配置已恢复 |
| **真实 Qwen** | 服务器真实北京3.8接口，271,437样本合成英语音频→中文；1条完成原文/1条译文、流式增量、finish与官方usage通过。不是实体麦克风或公网浏览器链路 |
| **真实 OSS** | 服务器真实私有写读/SHA/HEAD/签名/Range/匿名拒绝/分片上传/删除均通过；只新建并清理两个随机probe对象 |

证据：[部署状态](../reports/server-deployment-pending.json)、[服务器真实模型](../reports/server-qwen-real.json)、[服务器真实OSS](../reports/server-oss-real.json)、[ESA回源](../reports/server-esa-origin.json)。这些报告没有凭据、签名URL或Cookie值；本次没有用模拟上游冒充真实调用。

## 继续部署

应用服务用户为 `cltweb`。使用 `/www/server/php/85/bin/php`，CLI服务及其模型测试子进程继承 `PHP_INI_SCAN_DIR=:/www/wwwroot/t.xuanknow.cn/deploy/baota/php-cli.d`。独立FPM使用本站ini，避免继承宝塔全局禁用的进程函数；未修改共享FPM池。PHP8.5的两个全局ini只追加所需扩展，原配置已备份。

先重新检查 `.env` 所有权为 root:cltweb、权限640；确认 PostgreSQL密码认证成功。清除旧配置缓存后，在项目目录执行：

```sh
runuser -u cltweb -- /www/server/php/85/bin/php artisan config:clear
runuser -u cltweb -- /www/server/php/85/bin/php artisan migrate --force
runuser -u cltweb -- /www/server/php/85/bin/php artisan config:cache
runuser -u cltweb -- /www/server/php/85/bin/php artisan view:cache
systemctl enable --now clt-gateway clt-recordings clt-archives clt-product clt-scheduler
systemctl restart clt-fpm
runuser -u cltweb -- /www/server/php/85/bin/php artisan up
```

再检查六服务、11迁移、主域名页面与API、Secure/HttpOnly/SameSite登录Cookie、一次性WSS票据、超过300秒双向心跳、三客户端及队列/归档。管理员使用 `classroom:admin 学号 '姓名'` 的秘密密码提示初始化，无默认账户。实际实体麦克风测试要由浏览器用户授予权限并手动开始。

## ESA及恢复注意事项

已确认实际回源Host保留主域名且为HTTPS，当前不需要在应用盲目相信外部Forwarded头。动态API、分享路径、HTML及 `/ws` 应绕过ESA缓存；只缓存哈希 `/assets/`，浏览器TTL遵循源站。保持精确Origin与现有5秒心跳，`/ws` 回源超时建议300秒，并用真实WSS实测。[ESA缓存文档](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/cache-bypass)、[回源超时](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/configuring-the-back-to-origin-request-timeout-period)

源站证书SAN**只覆盖 esa.t.xuanknow.cn**，实际ESA回源SNI为t.xuanknow.cn。当前公网200不证明严格源站域名校验通过；ESA默认不校验源站证书。建议将ESA回源SNI设为esa.t.xuanknow.cn，再验证并启用源站证书校验，或安装同时覆盖两域名的源站证书。[证书校验](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/back-to-source-protocols-and-ports/)、[回源SNI](https://help.aliyun.com/zh/edge-security-acceleration/esa/user-guide/back-to-source-sni/)

本次未修改OSS Bucket CORS。普通原生audio不设置crossorigin；公网实际播放尚待验证。若后续JavaScript跨域读取需要CORS，应先用原生OSS API读取并保留既有规则，再合并两个精确Origin。Get/PutBucketCors不在官方S3兼容操作清单中，不能盲用AWS同名接口覆盖配置。[OSS S3兼容清单](https://help.aliyun.com/zh/oss/developer-reference/compatibility-with-amazon-s3)

原站点默认文件、Nginx、PHP配置和独立生产主密钥环境的备份保存在服务器root私有目录 `/www/server/clt-deploy-private/20261009-474088c`（700）。它只是同机回退备份，尚未证明离机灾备。宝塔读取实际Nginx root显示 `/public`；以后修改站点PHP/SSL/伪静态时保留本站独立FPM socket与精确路由配置。网关、PHP、队列必须保持同一storage与主密钥。

**仍未验证**：密码认证、生产数据库迁移、登录与有效票据WSS、公网浏览器真实麦克风闭环、正式归档播放、ESA严格回源证书验证；Docker构建和异地主密钥恢复的既有未验证项仍保留。
