<?php

namespace app\admin\controller;

use app\admin\model\Manager;
use app\admin\model\Menu;
use app\admin\model\MenuApi;
use app\admin\model\RoleMenu;
use think\facade\Db;

class MenuController extends BaseController
{
    protected $middleware = [
        'admin_auth',
        'admin_permission' => [
            'except' => ['currentMenus']
        ]
    ];

    public function tree()
    {
        $list = $this->attachApiKeys(Menu::order('order asc')->select()->toArray());
        $this->success(200, $this->buildTree($list));
    }

    public function currentMenus()
    {
        $manager = Manager::with([
            'roles' => function ($query) {
                $query->where('status', 1);
            }
        ])->where('id', $this->request->clientId)->find();

        if (!$manager) {
            $this->error(404, 'Manager not found');
        }

        if ($manager->is_top) {
            $list = $this->attachApiKeys(Menu::order('order asc')->select()->toArray());
            $this->success(200, $this->buildTree($list));
        }

        $roleIds = array_map(function ($role) {
            return (int) $role['id'];
        }, $manager->roles->toArray());

        if (!$roleIds) {
            $this->success(200, []);
        }

        $menuIds = RoleMenu::whereIn('role_id', $roleIds)->column('menu_id');
        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));

        if (!$menuIds) {
            $this->success(200, []);
        }

        $menuIds = $this->withAncestorIds($menuIds);
        $list = $this->attachApiKeys(Menu::whereIn('id', $menuIds)->order('order asc')->select()->toArray());
        $this->success(200, $this->buildTree($list));
    }

    public function create()
    {
        $post = $this->request->post();
        $this->validate($post, 'Menu');
        $apiKeys = $this->normalizeApiKeys($post['api_keys'] ?? []);
        unset($post['api_keys']);

        Db::transaction(function () use ($post, $apiKeys) {
            $menu = Menu::create($post);
            $this->syncApiKeys((int) $menu->id, $apiKeys);
        });
        $this->success(201);
    }

    public function update()
    {
        $post = $this->request->post();
        $this->validate($post, 'Menu');

        if ((int) $post['parent_id'] === (int) $post['id']) {
            $this->error(400, '上级菜单不能选择自身');
        }

        if ($this->isDescendant((int) $post['id'], (int) $post['parent_id'])) {
            $this->error(400, '上级菜单不能选择当前菜单的子菜单');
        }

        $apiKeys = $this->normalizeApiKeys($post['api_keys'] ?? []);
        unset($post['api_keys']);

        Db::transaction(function () use ($post, $apiKeys) {
            Menu::update($post);
            $this->syncApiKeys((int) $post['id'], $apiKeys);
        });
        $this->success(201);
    }

    public function sort()
    {
        $menus = $this->request->post('menus', []);

        if (!is_array($menus)) {
            $this->error(400, '菜单排序数据格式错误');
        }

        Db::transaction(function () use ($menus) {
            foreach ($menus as $menu) {
                Menu::where('id', (int) $menu['id'])->update([
                    'parent_id' => (int) $menu['parent_id'],
                    'order' => (int) $menu['order'],
                ]);
            }
        });

        $this->success(201);
    }

    public function delete()
    {
        $id = (int) $this->request->param('id');
        $menu = Menu::find($id);
        if (!$menu) {
            $this->error(404, '菜单不存在');
        }

        $ids = $this->getDescendantIds($id);
        Db::transaction(function () use ($ids) {
            MenuApi::destroy(function ($query) use ($ids) {
                $query->whereIn('menu_id', $ids);
            });
            Menu::destroy(function ($query) use ($ids) {
                $query->whereIn('id', $ids);
            });
        });
        $this->success(204);
    }

    private function attachApiKeys(array $list): array
    {
        if (!$list) {
            return [];
        }

        $menuIds = array_map(function ($item) {
            return (int) $item['id'];
        }, $list);

        $apiRows = MenuApi::whereIn('menu_id', $menuIds)
            ->order('id asc')
            ->select()
            ->toArray();
        $apiKeysByMenuId = [];

        foreach ($apiRows as $row) {
            $menuId = (int) $row['menu_id'];
            if (!isset($apiKeysByMenuId[$menuId])) {
                $apiKeysByMenuId[$menuId] = [];
            }

            $apiKeysByMenuId[$menuId][] = $row['api_key'];
        }

        return array_map(function ($item) use ($apiKeysByMenuId) {
            $item['api_keys'] = $apiKeysByMenuId[(int) $item['id']] ?? [];
            return $item;
        }, $list);
    }

    private function normalizeApiKeys($apiKeys): array
    {
        if (!is_array($apiKeys)) {
            return [];
        }

        $apiKeys = array_map(function ($apiKey) {
            return trim((string) $apiKey);
        }, $apiKeys);
        $apiKeys = array_filter($apiKeys, function ($apiKey) {
            return $apiKey !== '';
        });
        $invalidApiKeys = array_filter($apiKeys, function ($apiKey) {
            return !preg_match('/^[A-Za-z][A-Za-z0-9_]*\/[A-Za-z][A-Za-z0-9_]*\/[A-Za-z][A-Za-z0-9_]*$/', $apiKey);
        });

        if ($invalidApiKeys) {
            $this->error(400, '接口标识格式错误，请使用 app/controller/action');
        }

        return array_values(array_unique($apiKeys));
    }

    private function syncApiKeys(int $menuId, array $apiKeys): void
    {
        MenuApi::destroy(function ($query) use ($menuId) {
            $query->where('menu_id', $menuId);
        });

        if (!$apiKeys) {
            return;
        }

        $rows = array_map(function ($apiKey) use ($menuId) {
            return [
                'menu_id' => $menuId,
                'api_key' => $apiKey,
            ];
        }, $apiKeys);

        (new MenuApi())->saveAll($rows);
    }

    private function buildTree(array $list, int $parentId = 0): array
    {
        $tree = [];

        foreach ($list as $item) {
            if ((int) $item['parent_id'] !== $parentId) {
                continue;
            }

            $children = $this->buildTree($list, (int) $item['id']);
            if ($children) {
                $item['children'] = $children;
            }

            $tree[] = $item;
        }

        return $tree;
    }

    private function isDescendant(int $id, int $parentId): bool
    {
        if ($parentId === 0) {
            return false;
        }

        return in_array($parentId, $this->getDescendantIds($id), true);
    }

    private function getDescendantIds(int $id): array
    {
        $ids = [$id];
        $children = Menu::where('parent_id', $id)->column('id');

        foreach ($children as $childId) {
            $ids = array_merge($ids, $this->getDescendantIds((int) $childId));
        }

        return $ids;
    }

    private function withAncestorIds(array $ids): array
    {
        $allIds = $ids;

        foreach ($ids as $id) {
            $parentId = (int) Menu::where('id', $id)->value('parent_id');
            while ($parentId > 0) {
                $allIds[] = $parentId;
                $parentId = (int) Menu::where('id', $parentId)->value('parent_id');
            }
        }

        return array_values(array_unique($allIds));
    }
}
