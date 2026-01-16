<?php
return [
  'db' => [
    'dsn'  => 'mysql:host=127.0.0.1;dbname=noserver_datatesting_ims;charset=utf8mb4',
    'user' => 'root',
    'pass' => 'root',
  ],

  // 输出目录
  'paths' => [
    'uploads' => __DIR__ . '/storage/uploads',
    'exports' => __DIR__ . '/storage/exports',
    'logs'    => __DIR__ . '/storage/logs',
  ],

  // 解析容错
  'tolerance' => [
    'abs' => 0.05,
    'rel' => 0.02,
  ],

  // 系统默认值
  'defaults' => [
    'user_id' => 1,
    'warehouse_id' => 1,
    'purchase_unit_id' => 1,
    'payment_status' => 'due',
    'status' => 1,
  ],
];
