# Forest Gunner PHP 后端

当前锁定 ThinkPHP 8.1.4、ThinkORM 3.0.34、多应用扩展 1.1.1。要求 PHP 8.0+，本次使用 PHP 8.3 和 MySQL 5.7 验证。

小游戏接口位于 `/game/v1`。已实现微信小游戏登录、稳定玩家 UID、随机会话、账号查询、退出、[云存档快照同步](docs/云存档接入.md)以及[离线收益、每日签到、每日分享奖励和两类兑换码的后端核验](docs/奖励核验接入.md)。其他在线玩法仍由客户端结算，TapTap 登录尚未接入。

## 部署

先阅读 [手动部署说明](docs/手动部署说明.md)。站点根目录必须指向 `public`；将服务器自己的数据库和微信凭据写入根目录 `.env`，不要放入 `public`。

```sh
composer install --no-dev --prefer-dist --no-interaction
php think game:migrate
```

数据库必须先创建；迁移创建登录、云存档和奖励所需的表，不导入旧示例账号，不初始化旧后台的业务表。依赖按 `composer.lock` 安装，无需执行 `composer update`。离线结算另外需要 Node.js 20+，部署要求见奖励接入文档。

## 开发验证

```sh
php tests/game/run.php
php tests/game/save.php
php tests/game/rewards.php
php tests/game/share-state.php
php tests/game/share.php
php -S 127.0.0.1:8787 -t public public/router.php
```

Windows 本机可用 `tools/php.ps1` 代替 `php`，自动选择独立 PHP 8 运行时。完整 API、配置、验证范围见 [微信登录接入](docs/微信登录接入.md)。

## 版权信息

ThinkPHP遵循Apache2开源协议发布，并提供免费使用。

本项目包含的第三方源码和二进制文件之版权信息另行标注。

版权所有Copyright © 2006-2021 by ThinkPHP (http://thinkphp.cn)

All rights reserved。

ThinkPHP® 商标和著作权所有者为上海顶想信息科技有限公司。

更多细节参阅 [LICENSE.txt](LICENSE.txt)
