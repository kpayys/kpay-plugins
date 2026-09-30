<?php
declare (strict_types=1);

// 3.5.9 及以后版本的配置存在后台「支付配置」里，这个文件只用来存插件排序（top）。
// 3.5.9 之前的版本直接从这里读配置，所以保留全部键，方便老版本在后台填写。
return [
    'url' => 'https://api.kaipay.cn',
    'pid' => '',
    'key' => '',
    'mode' => 'mapi',
    'subject' => '',
];
