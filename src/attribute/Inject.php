<?php
// Restina/attribute/Inject.php

namespace Restina\attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Inject
{
    /**
     * 构造函数
     *
     * @param string|null $id 可以指定绑定ID，例如 "UserService"
     * @param bool $required 是否为必填项
     */
    public function __construct(
        public ?string $id = null, // 可以指定绑定ID，例如 "UserService"
        public bool $required = true
    ) {}
}
