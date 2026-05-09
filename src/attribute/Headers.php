<?php
// Restina/attribute/Headers.php

namespace Restina\attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Headers
{
    /**
     * 请求头参数字段
     */
    public string $field;

    /**
     * 请求头参数标题
     */
    public string $title;

    /**
     * 请求头参数类型
     */
    public string $type;

    /**
     * 请求头参数是否必填（默认 true）
     */
    public bool $required;

    /**
     * 构造函数
     *
     * @param string $field 参数字段
     * @param string $title 参数标题
     * @param string $type 参数类型
     * @param bool $required 参数是否必填
     */
    public function __construct(string $field = '', string $title = '', string $type = '', bool $required = true)
    {
        $this->field = $field;
        $this->title = $title;
        $this->type = $type;
        $this->required = $required;
    }
}
