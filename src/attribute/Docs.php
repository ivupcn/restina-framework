<?php
// Restina/attribute/Docs.php

namespace Restina\attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Docs
{
    /**
     * 标题
     * 
     * @var string
     */
    public string $title;

    /**
     * 描述
     * 
     * @var string
     */
    public string $description;

    /**
     * 分类
     * 
     * @var string
     */
    public string $category;

    /**
     * 构造函数
     * 
     * @param string $title 标题
     * @param string $description 描述
     * @param string $category 分类
     */
    public function __construct(
        string $title = '',
        string $description = '',
        string $category = ''
    ) {
        $this->title = $title;
        $this->description = $description;
        $this->category = $category;
    }

    /**
     * 获取标题
     * 
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * 获取描述
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 获取分类
     * 
     * @return string
     */
    public function getCategory(): string
    {
        return $this->category;
    }
}
