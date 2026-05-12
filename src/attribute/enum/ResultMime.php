<?php
// Restina/attribute/enum/ResultMime.php

namespace Restina\attribute\enum;

enum ResultMime: string
{
    case JSON = 'application/json'; // JSON格式数据
    case XML = 'application/xml'; // XML格式数据
    case HTML = 'text/html'; // HTML格式数据
    case TEXT = 'text/plain'; // 纯文本数据
}
