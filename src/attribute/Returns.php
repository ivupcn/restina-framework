<?php
// Restina/attribute/Returns.php

namespace Restina\attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Returns
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
     * 是否动态返回参数（即根据实际返回数据动态生成文档）
     */
    public bool $dynamic = false;

    /**
     * 动态返回参数描述（仅在dynamic=true时有效）
     */
    public string $dynamicDescription;

    /**
     * 子参数列表
     */
    public array $children;

    /**
     * 构造函数
     *
     * @param string $field 参数字段
     * @param string $title 参数标题
     * @param string $type 参数类型
     * @param bool $dynamic 是否动态返回参数
     * @param string $dynamicDescription 动态返回参数描述
     * @param array $children 子参数列表
     */
    public function __construct(string $field = '', string $title = '', string $type = '', bool $dynamic = false, string $dynamicDescription = '', array $children = [])
    {
        $this->field = $field;
        $this->title = $title;
        $this->type = $type;
        $this->dynamic = $dynamic;
        $this->dynamicDescription = $dynamicDescription;
        $this->children = $children;
    }
}
