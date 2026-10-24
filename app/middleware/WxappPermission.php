<?php

declare(strict_types=1);

namespace app\middleware;

use app\common\Send;
use app\middleware\annotation\WxappPermission as WxappPermissionAnnotation;
use Closure;
use ReflectionMethod;
use think\App;
use think\Config;
use think\Request;

class WxappPermission
{
    use Send;

    protected $app;

    protected $config;

    public function __construct(App $app, Config $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    public function handle(Request $request, Closure $next)
    {
        $requiredRoles = $this->getRequiredRoles($request);
        if (!$requiredRoles) {
            return $next($request);
        }

        $userRoles = $this->normalizeRoles($request->clientInfo['user_info']['roles'] ?? null);

        if (!array_intersect($requiredRoles, $userRoles)) {
            $this->error(403, '没有权限', 'NO_AUTH');
        }

        return $next($request);
    }

    protected function getRequiredRoles(Request $request): array
    {
        $controller = $this->getFullController($request);
        $action = $request->action();

        if (!$controller || !$action || !method_exists($controller, $action)) {
            return [];
        }

        $reflectionMethod = new ReflectionMethod($controller, $action);

        if (!method_exists($reflectionMethod, 'getAttributes')) {
            return [];
        }

        $roles = [];
        $attributes = $reflectionMethod->getAttributes(WxappPermissionAnnotation::class);

        foreach ($attributes as $attribute) {
            $annotation = $attribute->newInstance();
            $roles = array_merge($roles, $this->normalizeRoles($annotation->roles));
        }

        return array_values(array_unique($roles));
    }

    protected function getFullController(Request $request): string
    {
        $controller = $request->controller();

        if (!$controller) {
            return '';
        }

        $suffix = $this->config->get('route.controller_suffix') ? 'Controller' : '';
        $layer = $this->config->get('route.controller_layer') ?: 'controller';

        return $this->app->parseClass($layer, $controller . $suffix);
    }

    protected function normalizeRoles($roles): array
    {
        if (is_null($roles)) {
            return [];
        }

        if (is_string($roles)) {
            $roles = explode(',', $roles);
        } elseif (!is_array($roles)) {
            $roles = [$roles];
        }

        return array_values(array_unique(array_filter(array_map(function ($role) {
            return trim((string) $role);
        }, $roles), function ($role) {
            return $role !== '';
        })));
    }
}
