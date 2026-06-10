<?php

/**
 * 将蛇形命名转换为驼峰命名
 * 
 * @param string $value 蛇形命名的字符串
 * @return string 驼峰命名的字符串
 */
if (!function_exists('snakeToCamel')) {
    function snakeToCamel(string $value): string
    {
        return lcfirst(str_replace('_', '', ucwords($value, '_')));
    }
}

/**
 * 将数组的键从蛇形命名转换为驼峰命名（递归处理嵌套数组）
 * 
 * @param array $array 需要转换的数组
 * @return array 转换后的数组
 */
if (!function_exists('convertKeysToCamelCase')) {
    function convertKeysToCamelCase(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            // 转换键名为驼峰命名
            $camelKey = snakeToCamel($key);

            // 如果值是数组，递归转换
            if (is_array($value)) {
                $result[$camelKey] = convertKeysToCamelCase($value);
            } else {
                $result[$camelKey] = $value;
            }
        }

        return $result;
    }
}
