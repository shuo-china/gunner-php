<?php

declare(strict_types=1);

namespace app\middleware;

use app\admin\model\Manager;
use app\admin\model\MenuApi;
use app\admin\model\RoleMenu;
use app\common\Send;

class AdminPermission
{
    use Send;

    public function handle($request, \Closure $next)
    {   
        $clientInfo = $request->clientInfo ?? null;

        if (!$clientInfo) {
            $this->error(401, 'Token client info missing', 'TOKEN_INVALID');
        }

        $manager = Manager::with([
            'roles' => function ($query) {
                $query->where('status', 1);
            }
        ])->where('id', $request->clientId)->find();

        if (!$manager) {
            $this->error(404, 'Manager not found');
        }

        if ((int) $manager->is_top === 1) {
            return $next($request);
        }

        $roleIds = array_map(function ($role) {
            return (int) $role['id'];
        }, $manager->roles->toArray());

        if (!$roleIds) {
            $this->error(403, '无权访问该接口', 'NO_API_PERMISSION');
        }

        $menuIds = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));

        if (!$menuIds) {
            $this->error(403, '无权访问该接口', 'NO_API_PERMISSION');
        }

        $apiKeys = MenuApi::whereIn('menu_id', $menuIds)->column('api_key');
        $apiKeys = array_map(function ($apiKey) {
            return strtolower(trim((string) $apiKey));
        }, $apiKeys);

        if (!in_array($this->getCurrentApiKey($request), $apiKeys, true)) {
            $this->error(403, '无权访问该接口', 'NO_API_PERMISSION');
        }

        return $next($request);
    }

    private function getCurrentApiKey($request): string
    {
        $app = app('http')->getName();
        $controller = str_replace('.', '/', $request->controller());
        $action = $request->action();

        return strtolower($app . '/' . $controller . '/' . $action);
    }
}
