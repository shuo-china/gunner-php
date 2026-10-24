<?php

namespace app\admin\controller;

use app\admin\model\Menu;
use app\admin\model\Role;
use app\admin\model\RoleMenu;
use think\facade\Db;

class RoleController extends BaseController
{
    public function options()
    {
        $list = Role::column('name as label,id as value');
        $this->success(200, $list);
    }

    public function pagination()
    {
        $list = Role::paginate();
        $this->success(200, $list);
    }

    public function detail()
    {
        $id = $this->request->param('id');
        $role = Role::find($id);
        $this->success(200, $role);
    }

    public function create()
    {
        $post = $this->request->post();
        Role::create($post);
        $this->success(201);
    }

    public function update()
    {
        $post = $this->request->post();
        Role::update($post);
        $this->success(201);
    }

    public function delete()
    {
        $id = $this->request->param('id');
        Db::transaction(function () use ($id) {
            RoleMenu::destroy(function ($query) use ($id) {
                $query->where('role_id', (int) $id);
            });
            Role::destroy($id);
        });
        $this->success(204);
    }

    public function menus()
    {
        $id = (int) $this->request->param('id');

        if (!Role::find($id)) {
            $this->error(404, '角色不存在');
        }

        $menuIds = RoleMenu::where('role_id', $id)->column('menu_id');
        $this->success(200, array_map('intval', $menuIds));
    }

    public function updateMenus()
    {
        $post = $this->request->post();
        $id = (int) ($post['id'] ?? 0);
        $menuIds = $post['menu_ids'] ?? [];

        if (!Role::find($id)) {
            $this->error(404, '角色不存在');
        }

        if (!is_array($menuIds)) {
            $this->error(400, '菜单权限数据格式错误');
        }

        $menuIds = array_values(array_unique(array_map('intval', $menuIds)));
        if ($menuIds) {
            $menuIds = array_map('intval', Menu::whereIn('id', $menuIds)->column('id'));
        }

        Db::transaction(function () use ($id, $menuIds) {
            RoleMenu::destroy(function ($query) use ($id) {
                $query->where('role_id', $id);
            });

            if (!$menuIds) {
                return;
            }

            $rows = array_map(function ($menuId) use ($id) {
                return [
                    'role_id' => $id,
                    'menu_id' => $menuId,
                ];
            }, $menuIds);

            (new RoleMenu())->saveAll($rows);
        });

        $this->success(201);
    }

    public function update_menus()
    {
        return $this->updateMenus();
    }
}
