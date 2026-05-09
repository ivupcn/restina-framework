<?php
// Restina/attribute/Params.php

namespace Restina\attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Params
{
    /**
     * 参数字段
     */
    public string $field;

    /**
     * 参数标题
     */
    public string $title;

    /**
     * 参数类型
     */
    public string $type;

    /**
     * 参数默认值
     */
    public mixed $default;

    /**
     * 参数验证规则
     */
    public string $rules;

    /**
     * 构造函数
     *
     * @param string $field 参数字段
     * @param string $title 参数标题
     * @param string $type 参数类型
     * @param mixed $default 参数默认值
     * @param string $rules 参数验证规则
     */
    public function __construct(string $field = '', string $title = '', string $type = '', mixed $default = null, string $rules = '')
    {
        $this->field = $field;
        $this->title = $title;
        $this->type = $type;
        $this->default = $default;
        $this->rules = $rules;
    }
}
