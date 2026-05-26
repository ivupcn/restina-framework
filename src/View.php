<?php
// restina/View.php

namespace Restina;

/**
 * 视图类
 * @package Restina
 */
class View
{
    /**
     * @var string
     */
    private string $templateDir;

    /**
     * @var array
     */
    private array $cache = [];

    /**
     * @var bool
     */
    private bool $enableCache;

    /**
     * 构造函数
     * 
     * @param string $templateDir 模板目录
     * @param bool $enableCache 是否启用缓存
     */
    public function __construct(string $templateDir, bool $enableCache = true)
    {
        $this->templateDir = rtrim($templateDir, '/') . '/';
        $this->enableCache = $enableCache;
    }

    /**
     * 渲染模板并填充数据
     *
     * @param string $template 模板文件名
     * @param array $data 数据数组
     * @param bool $escapeHtml 是否对变量进行HTML转义（默认：true）
     * @return string 渲染后的内容
     * @throws \InvalidArgumentException 当模板不存在时抛出异常
     */
    public function render(string $template, array $data = [], bool $escapeHtml = true): string
    {
        // 验证模板路径，防止路径遍历
        $template = $this->validateTemplateName($template);
        $templatePath = $this->templateDir . $template;

        if (!file_exists($templatePath)) {
            throw new \InvalidArgumentException("模板文件不存在: {$templatePath}");
        }

        if (!is_readable($templatePath)) {
            throw new \InvalidArgumentException("模板文件不可读: {$templatePath}");
        }

        // 使用缓存（如果启用）
        $cacheKey = $templatePath . md5(serialize($data));

        if ($this->enableCache && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $content = file_get_contents($templatePath);

        if ($content === false) {
            throw new \RuntimeException("无法读取模板文件: {$templatePath}");
        }

        // 替换变量占位符
        foreach ($data as $key => $value) {
            if ($escapeHtml) {
                $escapedValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            } else {
                $escapedValue = (string)$value;
            }

            $content = str_replace('{{' . $key . '}}', $escapedValue, $content);
        }

        // 缓存结果（如果启用）
        if ($this->enableCache) {
            $this->cache[$cacheKey] = $content;
        }

        return $content;
    }

    /**
     * 清除模板缓存
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * 验证模板名称，防止路径遍历攻击
     *
     * @param string $template 模板名称
     * @return string 验证后的模板名称
     * @throws \InvalidArgumentException 当模板名称无效时抛出异常
     */
    private function validateTemplateName(string $template): string
    {
        // 只允许字母、数字、下划线、连字符和点号
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $template)) {
            throw new \InvalidArgumentException('模板名称包含非法字符');
        }

        // 使用realpath防止路径遍历
        $fullPath = $this->templateDir . $template;
        $resolvedPath = realpath(dirname($fullPath)) . '/' . basename($fullPath);

        if (strpos($resolvedPath, $this->templateDir) !== 0) {
            throw new \InvalidArgumentException('模板路径超出允许的目录范围');
        }

        return basename($template);
    }

    /**
     * 设置是否启用缓存
     *
     * @param bool $enableCache 是否启用缓存
     * @return self
     */
    public function setCacheEnabled(bool $enableCache): self
    {
        $this->enableCache = $enableCache;
        return $this;
    }

    /**
     * 获取模板目录
     *
     * @return string 模板目录
     */
    public function getTemplateDir(): string
    {
        return $this->templateDir;
    }
}
