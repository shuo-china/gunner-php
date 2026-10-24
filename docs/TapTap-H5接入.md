# TapTap 创意工坊 H5 后端接入

本轮目标应用为 `997859`，MiniApp ID 为 `tapmcg8evaubisjmzc`。登录接口为 `POST https://nurse.kylinsit.com/game/v1/auth/taptap`。H5 通过宿主 `tap.login` 获取 code，然后发送到 PHP；PHP 调用 [官方 code2Session](https://developer.taptap.cn/minigameapidoc/dev/server/login/code2Session/) 验证身份，并签发现有 Bearer 会话。

**状态：代码与本地隔离测试完成；尚未使用该 H5 应用的真实宿主 code 完成登录交换。** 上线前必须确认实际 H5 宿主提供 `tap.login` 且生成的 code 能用当前 MiniApp 配置验证。若宿主没有此能力，应停止此登录链路联调并报告，不替换成扫码登录，不更换包体类型。

## 服务器配置与部署

在服务器原有 `.env` 中追加以下配置，保留其他内容：

```ini
[GAME_TAPTAP]
APP_ID = tapmcg8evaubisjmzc
APP_SECRET = "fill-on-server"
```

`APP_SECRET` 必须填写该 MiniApp 对应的 Secret。示例占位符不能用于登录；通用 TapTap Client ID / Server Secret 不用于本链路。真实密钥只放后端环境中，不写入 H5、文档、ZIP 或日志。本次未修改本机 `.env`，也未连接或部署生产服务器。

先备份服务器要覆盖的源文件，再覆盖 PHP 增量包。生产变更文件为：

- `app/common/game/platform/TapTapMiniProvider.php`
- `app/common/game/service/LoginService.php`
- `app/common/game/service/RewardService.php`
- `app/game/controller/AccessController.php`
- `app/game/route/app.php`
- `config/game.php`

`.env.example` 和本说明供配置参考；测试程序不需要上传。增量包依赖已有登录、云存档和奖励后端、生产 `vendor`、`certs/cacert.pem` 以及 Node 奖励运行文件。本次没有新表或新 SQL 迁移；如果服务器还未完成已有四个迁移，先按原部署说明执行 `php think game:migrate`。

服务器需能通过 HTTPS 访问 `cloud-miniapp.tapapis.cn`；沿用 PHP cURL、CA 文件、TLS 校验和连接超时。更新后清理已有配置/路由缓存及 PHP OPcache，或重新启动站点 PHP 进程，然后上传 H5 包。回滚时恢复已备份的上述源文件与原 H5 版本，保留现有玩家、存档、奖励表；不要删除新创建的 TapTap 身份数据。

## 接口与账号规则

请求 Content-Type 为 `application/json`，正文仅包含 `{"code":"宿主返回的临时凭证"}`。不接受 openid、unionid、App ID、密钥、平台名称或测试开关。code 为不超过 256 字节、非空且不含空白的字符串；凭证只在平台交换一次，数据库重试不会再次使用 code。

成功响应沿用微信登录结构：`player`、`is_new_player`、`token_info`、`server_time_ms`。`player.provider` 为 `taptap_mini`，`player.app_id` 为当前 MiniApp ID。响应不包含 openid、unionid、平台 session_key 或 MiniApp Secret。数据库只保存游戏 Bearer token 的 SHA-256 摘要。

身份键为 `(taptap_mini, MiniApp ID, openid)`。同一用户重复登录保持玩家 ID，微信与 TapTap、不同 MiniApp 及不同 TapTap 账号分别拥有独立玩家 ID 和进度，不做自动关联或迁移。云存档、签到、兑换码和离线收益沿用已有接口、版本冲突处理及请求幂等规则。

TapTap 分享入口由客户端隐藏，服务端还会检查已验证身份：`POST /rewards/share` 返回 `SHARE_UNSUPPORTED`，不会改变钱包、存档版本或奖励记录。奖励状态中的 `daily_share.canClaim` 固定为 false；原 `dailyShare` 存档字段保留，其他渠道行为不变。

| HTTP | 错误码 | 客户端处理 |
| --- | --- | --- |
| 422 | `PARAM_ERROR` | 检查 code 与 JSON 请求结构 |
| 401 | `TAPTAP_CODE_INVALID` | 重新调用宿主登录获取新 code |
| 503 | `TAPTAP_NOT_CONFIGURED` | 检查服务器 MiniApp 配置 |
| 502 | `TAPTAP_UNAVAILABLE` | 显示重试；检查网络、平台状态及配置 |
| 429 | `TOO_MANY_REQUESTS` | 按 `Retry-After` 等待后重试 |
| 403 | `ACCOUNT_DISABLED` | 停留登录页，联系管理员 |
| 401 | `SESSION_INVALID` | 清理失效会话后重新登录 |
| 403 | `SHARE_UNSUPPORTED` | 禁用本渠道分享领奖 |

平台文档错误表的 `1040029` 和同页示例的 `40029` 均映射为 `TAPTAP_CODE_INVALID`；其他未文档化错误统一返回 `TAPTAP_UNAVAILABLE`，不猜测其意义或把上游正文透传给客户端。

## H5 跨域与本地验证

继续使用现有全局 `AllowCrossDomain` 中间件，新增 `/game/v1/*` 的 OPTIONS 204 预检响应。已有 JSON 和 Authorization 允许头保留；成功、凭证失效、格式错误、账号禁用及限流响应均携带跨域头。反向代理须放行 OPTIONS，保留 Origin 和 Authorization，不把错误响应改写成 HTML。

提供两个新测试：`tests/game/taptap-provider.php` 为纯协议测试，不读取真实密钥或访问平台；`tests/game/taptap.php` 使用显式指定的本机隔离数据库和随机测试表前缀。HTTP 测试专用入口位于 `tests/game/taptap-router.php`，只允许 CLI 测试服务器，并且不在 `public/` 下。生产代码没有请求可开启的模拟登录开关。

集成测试前设置 `GAME_TEST_DB_PORT` 为隔离实例端口、`GAME_TEST_DB_NAME` 为 `test_` 开头的专用数据库，必要时设置 `GAME_TEST_DB_PASSWORD`；测试强制连接 `127.0.0.1`，只清理本次随机 `test_login_*` 表。不得把测试环境指向生产库。

```sh
php tests/game/taptap-provider.php
php tests/game/taptap.php
php tests/game/run.php
php tests/game/save.php
php tests/game/rewards.php
php tests/game/share.php
php tests/game/share-state.php
```

覆盖官方协议映射及敏感内容隔离、首次/重复/并发登录、错误凭证、账号禁用、会话到期与续期、账号切换、微信隔离、跨域预检与错误响应、分享拒绝、签到/兑换码/离线结算、断网补传幂等、双设备版本冲突，以及原微信存档与奖励回归。实际运行结果以本轮交付验证记录为准；模拟平台通过不等于 TapTap 真机通过。

## 真机验收

1. 在目标应用 H5 宿主调用 `tap.login`，将真实 code 交给当前 PHP，确认返回 `taptap_mini` 和稳定玩家 ID。
2. 首次登录、重启、重新授权及切换 TapTap 账号，确认进度恢复和账号隔离；账号 A 不能读到账号 B 或微信进度。
3. 签到、兑换码及离线收益领取成功；断网重试不重复领奖，双设备冲突能重新同步。
4. 检查分享/广告入口隐藏；直接调用分享奖励接口也应拒绝。
5. 在实际 H5 Origin 下确认预检、正常请求及 401/403/429 错误可读取，验证切后台保存与返回同步。
