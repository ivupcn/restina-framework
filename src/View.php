<?php
// restina/View.php

namespace Restina;

/**
 * View
 */
class View
{
    /**
     * @var string
     */
    private string $templateDir;

    /**
     * @param string $templateDir
     */
    public function __construct(string $templateDir)
    {
        $this->templateDir = rtrim($templateDir, '/') . '/';
    }

    /**
     * Render a template with data
     *
     * @param string $template
     * @param array $data
     * @return string
     * @throws \InvalidArgumentException
     */
    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->templateDir . $template;

        if (!file_exists($templatePath)) {
            throw new \InvalidArgumentException("Template not found: {$templatePath}");
        }

        $content = file_get_contents($templatePath);

        // 替换变量占位符
        foreach ($data as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        return $content;
    }
}
