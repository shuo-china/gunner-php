<?php

namespace app\admin\controller;

use app\admin\model\ExceptionLog;

class ExceptionController extends BaseController
{
    public function pagination()
    {
        $param = $this->request->param();

        $query = ExceptionLog::order('id', 'desc');

        if (!empty($param['keyword'])) {
            $keyword = '%' . $param['keyword'] . '%';
            $query->where(function ($query) use ($keyword) {
                $query->whereLike('issue|file|request_url', $keyword);
            });
        }

        if (!empty($param['request_ip'])) {
            $query->whereLike('request_ip', '%' . $param['request_ip'] . '%');
        }

        if (!empty($param['request_method'])) {
            $query->where('request_method', strtoupper($param['request_method']));
        }

        if (!empty($param['date_range']) && is_array($param['date_range']) && count($param['date_range']) === 2) {
            $startTime = strtotime($param['date_range'][0] . ' 00:00:00');
            $endTime = strtotime($param['date_range'][1] . ' 23:59:59');
            $query->whereBetween('request_time', [$startTime, $endTime]);
        }

        $this->success(200, $query->paginate());
    }

    public function detail()
    {
        $id = $this->request->param('id');
        $info = ExceptionLog::where('id', $id)->find();

        $this->success(200, $info);
    }

    public function delete()
    {
        $id = $this->request->param('id');
        ExceptionLog::destroy($id);

        $this->success(204);
    }

    public function clear()
    {
        ExceptionLog::destroy(function ($query) {
            $query->where('id', '>', 0);
        });

        $this->success(204);
    }
}
