<?php
// app/hooks.php

return [
    // 动作钩子配置
    'actions' => [
        // 应用启动时执行
        'app.started' => [],

        // 请求处理前执行
        'request.before_handle' => [],

        // 请求处理后执行
        'request.after_handle' => [],

        // 参数验证错误时执行
        'parameter.validate_error' => [],

        // 控制器执行前执行
        'controller.before_execute' => [],

        // 控制器执行后执行
        'controller.after_execute' => []
    ],

    // 过滤器钩子配置
    'filters' => [
        // 控制器结果过滤
        'controller.result' => []
    ]
];
