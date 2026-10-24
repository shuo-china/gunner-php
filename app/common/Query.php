<?php
declare(strict_types=1);

namespace app\common;

use think\db\Query as BaseQuery;
use think\Paginator;

class Query extends BaseQuery
{
    protected function resolveListRows($listRows)
    {
        if ($listRows === null) {
            $rows = request()->param('list_rows');
            if (filter_var($rows, FILTER_VALIDATE_INT) !== false && (int) $rows >= 1) {
                return (int) $rows;
            }
        }

        return $listRows;
    }

    public function paginate($listRows = null, $simple = false): Paginator
    {
        $listRows = $this->resolveListRows($listRows);

        return parent::paginate($listRows, $simple);
    }

    public function paginateX($listRows = null, string $key = null, string $sort = null): Paginator
    {
        $listRows = $this->resolveListRows($listRows);

        return parent::paginateX($listRows, $key, $sort);
    }
}