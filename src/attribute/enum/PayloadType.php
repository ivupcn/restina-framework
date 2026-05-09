<?php
// Restina/attribute/enum/PayloadType.php

namespace Restina\attribute\enum;

class PayloadType
{
    const JSON = 'application/json'; // JSON格式数据
    const FORM = 'application/x-www-form-urlencoded'; // 键值对表单
    const MULTIPART = 'multipart/form-data'; // 复合表单数据
}
