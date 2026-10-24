<?php

namespace app\admin\model;

use app\admin\model\File;
use app\admin\model\ManagerRole;
use app\common\Password;
use think\model\concern\SoftDelete;

class Manager extends BaseModel
{
    use SoftDelete;

    protected $type = [
        'id' => 'integer',
        'last_login_time' => 'timestamp',
    ];

    public function setPasswordAttr($value)
    {
        return Password::make($value);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function avatar()
    {
        return $this->hasOne(File::class, 'key', 'avatar_key');
    }

    public function createManager($data)
    {
        $this->save($data);

        $roleIds = $this->normalizeRoleIds($data['role_ids'] ?? []);

        if (!empty($roleIds)) {
            $this->roles()->saveAll($roleIds);
        }
    }

    public function updateManager($data)
    {
        if (isset($data['password']) && empty($data['password'])) {
            unset($data['password']);
        }

        $this->exists(true)->save($data);

        $roleIds = $this->normalizeRoleIds($data['role_ids'] ?? []);
        $dbRoleIds = ManagerRole::where('manager_id', $this->getData('id'))->column('role_id');
        $insertRoleIds = array_diff($roleIds, $dbRoleIds);
        $removeRoleIds = array_diff($dbRoleIds, $roleIds);

        if (!empty($insertRoleIds)) {
            $this->roles()->saveAll($insertRoleIds);
        }

        if (!empty($removeRoleIds)) {
            $this->roles()->detach($removeRoleIds);
        }
    }

    public function deleteManager()
    {
        $this->delete();

        ManagerRole::destroy(function ($query) {
            $query->where('manager_id', $this->getData('id'));
        });
    }

    protected function normalizeRoleIds($roleIds)
    {
        if (!is_array($roleIds)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($roleIds))));
    }
}
