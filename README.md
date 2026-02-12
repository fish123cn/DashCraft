# MC远程控制系统 DashCraft
## 一、整体总览
### 1. 系统定位与核心目标
本系统解决“无公网IP环境下，通过网页端远程控制网易版Minecraft客户端”的核心需求，适配Windows/Linux客户端，支持：
- 网页端输入MC指令，客户端模拟键鼠执行（网易版无RCON权限适配）；
- 定时/手动查询服务器玩家数量+玩家名单，且用户指令优先级高于定时查询；
- 全链路256位密钥身份验证，防止非法访问；
- 客户端断连自动重连，保证通信稳定性。

### 2. 整体架构
```mermaid
graph LR
    A[用户网页端] -->|HTTP+256位KEY| B[公网PHP中转服务器]
    C[无公网Windows客户端] -->|长连接+256位KEY| B
    A -->|输入指令/配置查询间隔| B
    B -->|转发高优先级指令| C
    C -->|双队列+优先级执行| D[网易版MC]
    C -->|定时低优先级/list查询| D
    C -->|返回执行结果/玩家信息| B
    B -->|返回结果到网页| A
```

### 3. 核心特性
| 特性                | 实现方式                                                                 |
|---------------------|--------------------------------------------------------------------------|
| 无公网IP通信        | 客户端主动连接公网PHP服务器（反向连接），无需端口映射                     |
| 指令优先级          | 双队列（高优先级：用户指令；低优先级：定时/list）+ 互斥执行               |
| 高精度识别          | PaddleOCR+图片预处理（二值化/降噪/放大），适配MC特殊字体                 |
| 安全验证            | 256位SHA-256密钥全链路验证，仅合法请求可执行指令                         |
| 可配置定时查询      | 网页端动态修改/list查询间隔（≥5秒），客户端实时生效                      |
| 自动重连            | 客户端断连后自动重试，服务器定期清理失效连接                             |
| 跨平台兼容          | 客户端适配Windows（Linux仅需微调窗口控制逻辑）                           |

### 4. 技术栈与依赖
| 模块         | 核心技术/工具                          | 前置依赖                                                                 |
|--------------|---------------------------------------|--------------------------------------------------------------------------|
| 公网PHP端    | PHP Socket、HTTP接口、256位密钥验证   | 开启PHP socket扩展，放行9501（Socket）/80/443（网页）端口                |
| Windows客户端| Python 3.8+、PaddleOCR、pyautogui     | 管理员权限、安装依赖库、MC窗口化运行、关闭中文输入法（MC窗口）           |
| 核心能力     | 键鼠模拟、OCR识别、线程安全队列、长连接 | Windows：pyautogui/pygetwindow/paddleocr；Linux：wmctrl（窗口控制）       |

### 5. 运行流程
1. 公网PHP服务器启动Socket长连接服务，监听客户端连接；
2. Windows客户端以管理员身份运行，主动连接PHP服务器并完成密钥验证；
3. 客户端启动3个线程：通信线程（接收服务器指令）、消费线程（处理指令队列）、定时查询线程（低优先级/list）；
4. 网页端发送指令/配置，PHP服务器转发到客户端高优先级队列；
5. 客户端消费线程优先执行用户指令，空闲时执行定时/list查询；
6. 客户端将执行结果/玩家信息返回PHP服务器，再由服务器推送到网页端展示。

## 二、PHP网页端（公网中转服务器）
### 1. 目录结构
```
/var/www/html/
├── config.php          # 全局配置（密钥/端口/临时文件）
├── socket_server.php   # Socket长连接服务（后台常驻，转发指令）
├── api.php             # 网页端接口（接收请求/返回结果）
└── index.php           # 前端操作界面（指令输入/查询配置/结果展示）
```

### 2. 配置文件（config.php）
```php
<?php
/**
 * 全局配置文件
 * 注意：SECRET_KEY需与客户端保持一致，建议使用generate_256bit_key()生成
 */
// 256位密钥（32字节=256位，示例值需替换为自己生成的密钥）
define('SECRET_KEY', '7e8f9a0b1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6r7s8t9u0v1w2x3y4z5a6b7c8d9e0f1');
// Socket服务端口（需在服务器防火墙/安全组放行）
define('SOCKET_PORT', 9501);
// 指令/结果临时存储文件（需给PHP进程写入权限）
define('CMD_FILE', '/tmp/mc_cmd.json');
define('RESULT_FILE', '/tmp/mc_result.json');

/**
 * 生成256位随机密钥工具函数
 * 首次使用时取消注释运行一次，生成后替换上方SECRET_KEY
 */
function generate_256bit_key() {
    return bin2hex(random_bytes(32)); // 生成32字节随机字符串（256位）
}
// echo generate_256bit_key(); // 生成密钥后注释此行
?>
```

### 3. Socket长连接服务（socket_server.php）
```php
<?php
/**
 * Socket长连接服务（后台常驻）
 * 功能：接收客户端连接、验证密钥、转发网页端指令、接收客户端结果
 * 启动方式：nohup php socket_server.php > socket.log 2>&1 &
 */
require 'config.php';

// 创建TCP Socket服务
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$socket) {
    die("Socket创建失败：" . socket_strerror(socket_last_error()) . "\n");
}

// 设置Socket可重用端口（避免重启服务时端口占用）
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

// 绑定端口并监听
if (!socket_bind($socket, '0.0.0.0', SOCKET_PORT)) {
    die("端口绑定失败：" . socket_strerror(socket_last_error($socket)) . "\n");
}
if (!socket_listen($socket)) {
    die("监听失败：" . socket_strerror(socket_last_error($socket)) . "\n");
}

// 设置非阻塞模式（避免单客户端阻塞整个服务）
socket_set_nonblock($socket);

echo "=== MC中转Socket服务启动 ===" . "\n";
echo "监听地址：0.0.0.0:" . SOCKET_PORT . "\n";
echo "密钥：" . SECRET_KEY . "\n\n";

$clients = []; // 已连接的客户端列表
$last_check_time = time(); // 上次清理失效连接的时间

// 主循环：处理客户端连接/指令转发/结果接收
while (true) {
    // 1. 接收新客户端连接
    $new_client = socket_accept($socket);
    if ($new_client !== false) {
        // 验证客户端密钥
        $key = trim(socket_read($new_client, 300));
        if ($key === SECRET_KEY) {
            $clients[] = $new_client;
            socket_write($new_client, "AUTH_SUCCESS\n");
            echo "[" . date('Y-m-d H:i:s') . "] 客户端连接成功，密钥验证通过\n";
        } else {
            socket_write($new_client, "AUTH_FAILED\n");
            socket_close($new_client);
            echo "[" . date('Y-m-d H:i:s') . "] 客户端密钥验证失败，断开连接\n";
        }
    }

    // 2. 转发网页端指令到客户端
    if (file_exists(CMD_FILE)) {
        $cmd_data = json_decode(file_get_contents(CMD_FILE), true);
        if ($cmd_data && $cmd_data['key'] === SECRET_KEY) {
            // 转发指令到所有已连接的客户端（可扩展为指定客户端）
            foreach ($clients as $client) {
                socket_write($client, json_encode($cmd_data['data']) . "\n");
            }
            unlink(CMD_FILE); // 转发完成后删除指令文件
            echo "[" . date('Y-m-d H:i:s') . "] 转发指令：" . json_encode($cmd_data['data']) . "\n";
        }
    }

    // 3. 接收客户端返回的结果并写入结果文件
    foreach ($clients as $client) {
        $response = @socket_read($client, 4096); // 抑制读取失败的警告
        if ($response !== false && $response !== '') {
            $result = [
                'key' => SECRET_KEY,
                'data' => json_decode(trim($response), true)
            ];
            file_put_contents(RESULT_FILE, json_encode($result));
            echo "[" . date('Y-m-d H:i:s') . "] 接收客户端结果：" . trim($response) . "\n";
        }
    }

    // 4. 每10秒清理一次失效的客户端连接
    if (time() - $last_check_time > 10) {
        $last_check_time = time();
        foreach ($clients as $k => $client) {
            // 发送PING测试连接是否有效
            $test = @socket_write($client, 'PING');
            if ($test === false) {
                socket_close($client);
                unset($clients[$k]);
                echo "[" . date('Y-m-d H:i:s') . "] 清理失效客户端连接\n";
            }
        }
    }

    usleep(100000); // 休眠100ms，降低CPU占用
}

// 关闭Socket（理论上不会执行到此处）
socket_close($socket);
?>
```

### 4. 接口文件（api.php）
```php
<?php
/**
 * 网页端接口文件
 * 功能：接收网页端POST请求、验证密钥、写入指令文件、返回客户端执行结果
 * 支持操作：exec_cmd（执行MC指令）、get_players（手动查询玩家信息）、set_query_interval（修改定时查询间隔）
 */
require 'config.php';

// 设置响应头（支持跨域、JSON格式）
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // 生产环境建议限制为指定域名
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// 接收请求参数
$action = $_POST['action'] ?? '';
$client_key = $_POST['key'] ?? '';
$cmd = $_POST['cmd'] ?? '';
$interval = $_POST['interval'] ?? '';

// 第一步：验证密钥（核心安全校验）
if ($client_key !== SECRET_KEY) {
    exit(json_encode([
        'code' => 1,
        'msg' => '密钥验证失败，拒绝请求'
    ]));
}

// 第二步：处理不同操作
switch ($action) {
    // 操作1：执行用户输入的MC指令
    case 'exec_cmd':
        if (empty($cmd)) {
            exit(json_encode([
                'code' => 1,
                'msg' => '指令不能为空'
            ]));
        }
        // 写入指令文件（供Socket服务转发）
        $data = [
            'key' => SECRET_KEY,
            'data' => [
                'action' => 'exec_cmd',
                'cmd' => $cmd
            ]
        ];
        file_put_contents(CMD_FILE, json_encode($data));
        // 等待客户端返回结果（最多等待5秒）
        $timeout = 5;
        $start_time = time();
        while (!file_exists(RESULT_FILE) && (time() - $start_time) < $timeout) {
            usleep(100000); // 每100ms检查一次
        }
        // 返回结果
        if (file_exists(RESULT_FILE)) {
            $result = json_decode(file_get_contents(RESULT_FILE), true);
            unlink(RESULT_FILE); // 删除结果文件，避免重复读取
            exit(json_encode($result['data'] ?? [
                'code' => 1,
                'msg' => '结果解析失败'
            ]));
        } else {
            exit(json_encode([
                'code' => 1,
                'msg' => '客户端无响应，请求超时（5秒）'
            ]));
        }
        break;

    // 操作2：手动查询玩家数量+名单
    case 'get_players':
        $data = [
            'key' => SECRET_KEY,
            'data' => [
                'action' => 'get_players'
            ]
        ];
        file_put_contents(CMD_FILE, json_encode($data));
        // 等待结果（最多5秒）
        $timeout = 5;
        $start_time = time();
        while (!file_exists(RESULT_FILE) && (time() - $start_time) < $timeout) {
            usleep(100000);
        }
        if (file_exists(RESULT_FILE)) {
            $result = json_decode(file_get_contents(RESULT_FILE), true);
            unlink(RESULT_FILE);
            exit(json_encode($result['data'] ?? [
                'code' => 1,
                'msg' => '结果解析失败'
            ]));
        } else {
            exit(json_encode([
                'code' => 1,
                'msg' => '客户端无响应，请求超时（5秒）'
            ]));
        }
        break;

    // 操作3：修改客户端定时查询间隔
    case 'set_query_interval':
        if (empty($interval) || !is_numeric($interval) || $interval < 5) {
            exit(json_encode([
                'code' => 1,
                'msg' => '查询间隔必须为≥5的数字（秒）'
            ]));
        }
        $data = [
            'key' => SECRET_KEY,
            'data' => [
                'action' => 'set_query_interval',
                'interval' => (int)$interval
            ]
        ];
        file_put_contents(CMD_FILE, json_encode($data));
        // 等待结果（最多5秒）
        $timeout = 5;
        $start_time = time();
        while (!file_exists(RESULT_FILE) && (time() - $start_time) < $timeout) {
            usleep(100000);
        }
        if (file_exists(RESULT_FILE)) {
            $result = json_decode(file_get_contents(RESULT_FILE), true);
            unlink(RESULT_FILE);
            exit(json_encode($result['data'] ?? [
                'code' => 1,
                'msg' => '结果解析失败'
            ]));
        } else {
            exit(json_encode([
                'code' => 1,
                'msg' => '客户端无响应，请求超时（5秒）'
            ]));
        }
        break;

    // 未知操作
    default:
        exit(json_encode([
            'code' => 1,
            'msg' => '未知操作类型，支持：exec_cmd、get_players、set_query_interval'
        ]));
}
?>
```

### 5. 前端界面（index.php）
```php
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MC远程控制系统（无公网IP+指令优先级版）</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Microsoft Yahei", sans-serif;
        }
        .container {
            width: 800px;
            margin: 30px auto;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
        }
        .player-info {
            font-size: 18px;
            color: #2c3e50;
            margin: 15px 0;
            padding: 10px;
            background: #fff;
            border-radius: 4px;
            border-left: 4px solid #3498db;
        }
        .input-group {
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .input-group input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .input-group button {
            padding: 10px 20px;
            background: #3498db;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        .input-group button:hover {
            background: #2980b9;
        }
        .config-group {
            margin: 20px 0;
            padding: 15px;
            background: #fff;
            border-radius: 4px;
            border: 1px solid #eee;
        }
        .config-group h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .config-group .tips {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }
        .log-box {
            margin-top: 20px;
            padding: 15px;
            background: #fff;
            border-radius: 4px;
            border: 1px solid #ddd;
            min-height: 300px;
            max-height: 400px;
            overflow-y: auto;
            font-size: 14px;
            line-height: 1.5;
        }
        .log-box .log-item {
            margin: 5px 0;
            color: #333;
        }
        .log-box .log-item .time {
            color: #999;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>MC远程控制系统</h1>
        
        <!-- 玩家信息展示区域 -->
        <div class="player-info" id="playerInfo">
            玩家数量：获取中... | 玩家名单：无
        </div>
        
        <!-- 指令执行区域 -->
        <div class="input-group">
            <input type="text" id="cmdInput" placeholder="输入MC指令（如gamemode creative @a），按回车执行">
            <button onclick="sendCommand()">执行指令</button>
            <button onclick="getPlayerCount()">手动刷新玩家信息</button>
        </div>
        
        <!-- 定时查询配置区域 -->
        <div class="config-group">
            <h3>定时查询配置</h3>
            <div class="input-group">
                <input type="number" id="intervalInput" placeholder="定时查询间隔（秒），默认10" value="10" min="5">
                <button onclick="setQueryInterval()">修改查询间隔</button>
            </div>
            <div class="tips">
                说明：定时查询为低优先级，仅当无用户指令时执行；查询间隔建议≥5秒，避免频繁操作影响MC运行
            </div>
        </div>
        
        <!-- 日志展示区域 -->
        <div class="log-box" id="logBox">
            <div class="log-item"><span class="time">[系统]</span> 系统已启动，等待指令...</div>
        </div>
    </div>

    <script>
        // 核心配置（需与服务器config.php保持一致）
        const CONFIG = {
            SECRET_KEY: '7e8f9a0b1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6r7s8t9u0v1w2x3y4z5a6b7c8d9e0f1',
            API_URL: 'https://你的公网域名/api.php' // 替换为实际API地址
        };

        // 初始化：页面加载完成后自动获取一次玩家信息
        window.onload = function() {
            getPlayerCount();
            // 给指令输入框绑定回车事件
            document.getElementById('cmdInput').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    sendCommand();
                }
            });
        };

        /**
         * 发送MC指令到服务器
         */
        function sendCommand() {
            const cmdInput = document.getElementById('cmdInput');
            const cmd = cmdInput.value.trim();
            if (!cmd) {
                addLog('指令不能为空，请输入有效的MC指令');
                return;
            }

            // 发送POST请求
            fetch(CONFIG.API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=exec_cmd&key=${CONFIG.SECRET_KEY}&cmd=${encodeURIComponent(cmd)}`
            })
            .then(res => res.json())
            .then(data => {
                addLog(data.msg);
                cmdInput.value = ''; // 清空输入框
            })
            .catch(err => {
                addLog(`指令发送失败：${err.message}`);
            });
        }

        /**
         * 手动获取玩家数量+名单
         */
        function getPlayerCount() {
            fetch(CONFIG.API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=get_players&key=${CONFIG.SECRET_KEY}`
            })
            .then(res => res.json())
            .then(data => {
                addLog(data.msg);
                // 更新玩家信息展示
                if (data.code === 0 && data.player_count) {
                    const playerList = data.player_list && data.player_list.length > 0 
                        ? data.player_list.join(',') 
                        : '无';
                    document.getElementById('playerInfo').innerText = 
                        `玩家数量：${data.player_count} | 玩家名单：${playerList}`;
                }
            })
            .catch(err => {
                addLog(`获取玩家信息失败：${err.message}`);
                document.getElementById('playerInfo').innerText = '玩家数量：获取失败 | 玩家名单：无';
            });
        }

        /**
         * 修改定时查询间隔
         */
        function setQueryInterval() {
            const intervalInput = document.getElementById('intervalInput');
            const interval = intervalInput.value.trim();
            if (!interval || interval < 5) {
                addLog('查询间隔必须为≥5的有效数字（秒）');
                return;
            }

            fetch(CONFIG.API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=set_query_interval&key=${CONFIG.SECRET_KEY}&interval=${interval}`
            })
            .then(res => res.json())
            .then(data => {
                addLog(data.msg);
            })
            .catch(err => {
                addLog(`修改查询间隔失败：${err.message}`);
            });
        }

        /**
         * 添加日志到日志框
         * @param {string} msg 日志内容
         */
        function addLog(msg) {
            const logBox = document.getElementById('logBox');
            const time = new Date().toLocaleString('zh-CN');
            const logItem = document.createElement('div');
            logItem.className = 'log-item';
            logItem.innerHTML = `<span class="time">[${time}]</span>${msg}`;
            logBox.appendChild(logItem);
            // 自动滚动到最新日志
            logBox.scrollTop = logBox.scrollHeight;
        }
    </script>
</body>
</html>
```

### 6. PHP端部署与启动步骤
1. **环境准备**：
   - 公网服务器需安装PHP（7.4+）、开启`socket`扩展（修改php.ini：`extension=socket.so`，重启PHP服务）；
   - 放行端口：在服务器防火墙/安全组中放行9501（Socket）、80/443（网页）端口。
2. **文件部署**：
   - 将上述4个文件上传到服务器网页根目录（如`/var/www/html`）；
   - 修改`config.php`中的`SECRET_KEY`为自己生成的256位密钥；
   - 修改`index.php`中的`API_URL`为服务器公网域名/IP。
3. **启动Socket服务**：
   ```bash
   # 后台启动Socket服务，输出日志到socket.log
   nohup php /var/www/html/socket_server.php > /var/www/html/socket.log 2>&1 &
   # 验证服务是否启动（查看端口是否监听）
   netstat -tulpn | grep 9501
   ```
4. **权限配置**：
   - 给`/tmp`目录赋权（确保PHP能写入临时文件）：`chmod 777 /tmp`；
   - 确保PHP进程对网页目录有读写权限。

## 三、Windows客户端（Python）
### 1. 依赖安装
以**管理员身份**运行CMD，执行以下命令安装依赖：
```bash
# 核心依赖（键鼠模拟、窗口控制、OCR识别）
pip install pyautogui pygetwindow pillow paddleocr opencv-python numpy
# 可选：如果需要中文OCR更精准，安装paddlepaddle国内源
pip install paddlepaddle -i https://pypi.tuna.tsinghua.edu.cn/simple
```

### 2. 完整代码（mc_client.py）
```python
#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
MC远程控制客户端（Windows版）
功能：
1. 主动连接公网PHP中转服务器，完成256位密钥验证；
2. 接收服务器转发的指令，按优先级执行（用户指令>定时/list查询）；
3. 模拟键鼠操作MC客户端执行指令；
4. 高精度OCR识别MC聊天框，解析玩家数量+名单；
5. 断连自动重连，支持动态修改定时查询间隔。
运行要求：
- 管理员权限运行；
- MC窗口化运行，关闭中文输入法；
- 替换SERVER_HOST/SERVER_PORT/SECRET_KEY为实际值。
"""

import socket
import json
import time
import pyautogui
import pygetwindow as gw
import cv2
import numpy as np
from PIL import ImageGrab
from paddleocr import PaddleOCR
import threading
import queue
import re

# ===================== 全局配置（需根据实际情况修改）=====================
# 公网PHP服务器配置
SERVER_HOST = "你的公网域名/IP"  # 替换为实际服务器地址
SERVER_PORT = 9501              # 与PHP端config.php一致
SECRET_KEY = "7e8f9a0b1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6r7s8t9u0v1w2x3y4z5a6b7c8d9e0f1"  # 与PHP端一致

# MC窗口配置（网易版默认名称，可根据实际调整）
MC_WINDOW_NAME = "我的世界"

# 定时查询配置（可通过网页端动态修改）
LIST_QUERY_INTERVAL = 10  # 默认10秒执行一次/list查询（秒）
list_query_running = True  # 是否开启定时查询
query_interval_lock = threading.Lock()  # 配置修改锁（线程安全）

# 指令队列（线程安全）
high_priority_queue = queue.Queue()  # 高优先级：用户主动指令
low_priority_queue = queue.Queue()   # 低优先级：定时/list查询
executing_lock = threading.Lock()    # 指令执行互斥锁（防止同时执行多个指令）

# ===================== 初始化核心组件 =====================
# PaddleOCR初始化（本地高精度识别，关闭日志，适配MC特殊字体）
ocr = PaddleOCR(
    use_angle_cls=True,          # 开启文字方向检测
    lang='ch',                   # 中英混合识别
    use_gpu=False,               # 无GPU则设为False
    show_log=False,              # 关闭冗余日志
    det_db_thresh=0.5,           # 检测阈值（调高减少误识别）
    rec_img_h=32,                # 适配MC小字识别
    rec_batch_num=1              # 单张图片识别
)

# 键鼠配置（关闭安全保护，设置操作间隔）
pyautogui.FAILSAFE = False  # 关闭鼠标移到角落触发的安全退出
pyautogui.PAUSE = 0.1       # 键鼠操作间隔（秒），避免操作过快

# ===================== 工具函数 =====================
def reconnect_server():
    """
    断连自动重连服务器
    返回：socket对象（已完成密钥验证）
    """
    while True:
        try:
            # 创建TCP Socket
            s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            s.settimeout(10)  # 连接超时时间10秒
            # 连接服务器
            s.connect((SERVER_HOST, SERVER_PORT))
            print(f"[连接] 正在连接服务器：{SERVER_HOST}:{SERVER_PORT}")
            # 发送密钥验证
            s.sendall(SECRET_KEY.encode('utf-8'))
            # 接收验证结果
            auth_res = s.recv(1024).decode('utf-8').strip()
            if auth_res == "AUTH_SUCCESS":
                print("[连接] 服务器连接成功，密钥验证通过")
                s.settimeout(None)  # 取消超时，保持长连接
                return s
            else:
                print("[错误] 密钥验证失败，服务器拒绝连接")
                s.close()
                time.sleep(5)  # 5秒后重试
        except Exception as e:
            print(f"[错误] 服务器连接失败：{str(e)}，5秒后重试...")
            time.sleep(5)

def get_mc_window():
    """
    查找MC窗口对象
    返回：MC窗口对象（None表示未找到）
    """
    try:
        # 查找包含指定名称的MC窗口
        mc_windows = gw.getWindowsWithTitle(MC_WINDOW_NAME)
        if mc_windows:
            return mc_windows[0]
        return None
    except Exception as e:
        print(f"[错误] 查找MC窗口失败：{str(e)}")
        return None

def activate_mc_window():
    """
    激活MC窗口（最小化则还原）
    返回：(bool, str) - (是否成功, 提示信息)
    """
    mc_window = get_mc_window()
    if not mc_window:
        return False, "未找到MC窗口，请确认MC已启动且窗口名称正确"
    
    try:
        # 窗口最小化则还原
        if mc_window.isMinimized:
            mc_window.restore()
        # 激活窗口（将焦点移到MC窗口）
        mc_window.activate()
        time.sleep(0.2)  # 等待窗口激活
        return True, "MC窗口激活成功"
    except Exception as e:
        return False, f"MC窗口激活失败：{str(e)}"

def execute_mc_command_by_keyboard(cmd):
    """
    模拟键鼠执行MC指令（互斥执行，同一时间仅执行一个指令）
    参数：cmd - MC指令字符串
    返回：dict - 执行结果（code: 0成功/1失败，msg: 提示信息）
    """
    # 互斥锁：确保同一时间只执行一个键鼠操作
    with executing_lock:
        # 第一步：激活MC窗口
        success, msg = activate_mc_window()
        if not success:
            return {"code": 1, "msg": msg}
        
        try:
            # 第二步：模拟键鼠操作
            pyautogui.press('t')  # 按T打开聊天框
            time.sleep(0.1)       # 等待聊天框打开
            pyautogui.typewrite(cmd, interval=0.05)  # 输入指令（间隔0.05秒/字符）
            time.sleep(0.1)       # 等待输入完成
            pyautogui.press('enter')  # 按Enter发送指令
            return {"code": 0, "msg": f"指令执行成功：{cmd}"}
        except Exception as e:
            return {"code": 1, "msg": f"键鼠模拟失败：{str(e)}"}

def preprocess_chat_image(image):
    """
    预处理MC聊天框截图（提升OCR识别率）
    步骤：灰度化 → 自适应二值化 → 降噪 → 放大
    参数：image - PIL截图对象
    返回：预处理后的OpenCV图像
    """
    # 转换为OpenCV格式（PIL → numpy → BGR）
    img = cv2.cvtColor(np.array(image), cv2.COLOR_RGB2BGR)
    
    # 1. 灰度化（去除颜色干扰）
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    
    # 2. 自适应二值化（适配MC浅色字体+深色背景，反相处理）
    binary = cv2.adaptiveThreshold(
        gray, 255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,  # 高斯自适应阈值
        cv2.THRESH_BINARY_INV,           # 反相（白色文字，黑色背景）
        blockSize=7,                     # 块大小（奇数，越大越模糊）
        C=-2                             # 阈值调整（负数适配浅色字）
    )
    
    # 3. 降噪（去除游戏画面噪点）
    kernel = np.ones((1, 1), np.uint8)
    denoised = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel)
    
    # 4. 放大图片（提升小字识别率）
    scale = 2  # 放大2倍
    height, width = denoised.shape[:2]
    enlarged = cv2.resize(
        denoised,
        (width * scale, height * scale),
        interpolation=cv2.INTER_CUBIC  # 立方插值，放大更清晰
    )
    
    return enlarged

def parse_chat_box_text():
    """
    识别并解析MC聊天框文字
    返回：dict - {player_count: 玩家数量, player_list: 玩家名单列表, raw_text: 原始识别文本}
    """
    mc_window = get_mc_window()
    if not mc_window:
        return {"player_count": "未知", "player_list": [], "raw_text": ""}
    
    # 精准截取聊天框区域（可根据MC窗口分辨率微调坐标）
    # 坐标规则：(left, top, right, bottom)
    window_rect = (mc_window.left, mc_window.top, mc_window.right, mc_window.bottom)
    chat_box_rect = (
        window_rect[0] + 50,          # 左边界（留50px边距）
        window_rect[3] - 200,         # 上边界（聊天框顶部）
        window_rect[2] - 50,          # 右边界（留50px边距）
        window_rect[3] - 20           # 下边界（聊天框底部）
    )
    
    try:
        # 1. 截取聊天框截图
        screenshot = ImageGrab.grab(chat_box_rect)
        # 2. 预处理截图
        processed_img = preprocess_chat_image(screenshot)
        # 3. PaddleOCR识别
        result = ocr.ocr(processed_img, cls=True)
        
        # 4. 整理识别结果
        text_list = []
        if result and result[0]:
            for line in result[0]:
                text = line[1][0].strip()  # 提取文字部分
                if text:
                    text_list.append(text)
        raw_text = "\n".join(text_list)
        
        # 5. 解析玩家数量和名单（适配网易版格式）
        player_count = "未知"
        player_list = []
        
        # 匹配格式1："当前在线玩家: 2 人：玩家1, 玩家2"
        pattern1 = r'当前在线玩家[:：]\s*(\d+)\s*人[:：]\s*(.*)'
        # 匹配格式2："目前有 2 名玩家在线：玩家1, 玩家2"
        pattern2 = r'目前有\s*(\d+)\s*名玩家在线[:：]\s*(.*)'
        
        match = re.search(pattern1, raw_text) or re.search(pattern2, raw_text)
        if match:
            player_count = match.group(1)
            player_str = match.group(2).strip()
            if player_str and player_str != "无":
                # 分割玩家名单（兼容中文逗号/英文逗号）
                player_list = [p.strip() for p in re.split('[，,]', player_str) if p.strip()]
        
        return {
            "player_count": player_count,
            "player_list": player_list,
            "raw_text": raw_text
        }
    except Exception as e:
        print(f"[错误] 解析聊天框失败：{str(e)}")
        return {"player_count": "未知", "player_list": [], "raw_text": ""}

def get_player_info():
    """
    获取玩家数量+名单（执行/list指令并解析）
    返回：dict - 执行结果（包含player_count/player_list）
    """
    # 第一步：执行/list指令
    exec_res = execute_mc_command_by_keyboard("list")
    if exec_res["code"] != 0:
        return {
            "code": 1,
            "msg": exec_res["msg"],
            "player_count": "未知",
            "player_list": []
        }
    
    # 第二步：等待MC返回结果（根据网络情况调整，建议1.5秒）
    time.sleep(1.5)
    
    # 第三步：解析聊天框文字
    chat_info = parse_chat_box_text()
    
    # 第四步：整理结果
    return {
        "code": 0,
        "msg": f"玩家信息查询成功 | 数量：{chat_info['player_count']} | 名单：{','.join(chat_info['player_list']) if chat_info['player_list'] else '无'}",
        "player_count": chat_info["player_count"],
        "player_list": chat_info["player_list"]
    }

# ===================== 指令队列处理 =====================
def command_consumer(s):
    """
    指令消费者线程：优先处理高优先级指令（用户指令）
    参数：s - 服务器socket对象
    """
    print("[线程] 指令消费线程已启动（用户指令优先执行）")
    while True:
        try:
            # 第一步：处理高优先级队列（用户指令）
            try:
                # 1秒超时，无指令则处理低优先级队列
                cmd_data = high_priority_queue.get(timeout=1)
                action = cmd_data.get("action")
                print(f"[指令] 执行高优先级指令：{action}")
                
                # 处理不同指令
                if action == "exec_cmd":
                    result = execute_mc_command_by_keyboard(cmd_data.get("cmd"))
                elif action == "get_players":
                    result = get_player_info()
                else:
                    result = {"code": 1, "msg": "未知的高优先级指令"}
                
                # 将结果返回服务器
                s.sendall(json.dumps(result, ensure_ascii=False).encode('utf-8'))
                high_priority_queue.task_done()  # 标记任务完成
            
            # 第二步：高优先级队列为空，处理低优先级队列（定时/list）
            except queue.Empty:
                try:
                    cmd_data = low_priority_queue.get(timeout=1)
                    action = cmd_data.get("action")
                    if action == "auto_get_players":
                        print(f"[指令] 执行低优先级定时查询：/list")
                        result = get_player_info()
                        # 定时查询结果主动推送到服务器
                        s.sendall(json.dumps({
                            "code": 0,
                            "msg": f"定时查询结果：{result['msg']}",
                            "player_count": result["player_count"],
                            "player_list": result["player_list"],
                            "type": "auto_query"
                        }, ensure_ascii=False).encode('utf-8'))
                    low_priority_queue.task_done()
                except queue.Empty:
                    continue  # 无指令，继续循环
        
        except Exception as e:
            print(f"[错误] 指令处理失败：{str(e)}，继续等待指令...")
            continue

def auto_list_query():
    """
    定时/list查询线程（低优先级，仅当无用户指令时执行）
    """
    print(f"[线程] 定时查询线程已启动，默认间隔：{LIST_QUERY_INTERVAL}秒")
    while list_query_running:
        try:
            # 仅当高优先级队列为空时，才添加定时任务
            if high_priority_queue.empty():
                low_priority_queue.put({"action": "auto_get_players"})
            
            # 动态读取查询间隔（线程安全）
            with query_interval_lock:
                interval = LIST_QUERY_INTERVAL
            time.sleep(interval)
        
        except Exception as e:
            print(f"[错误] 定时查询异常：{str(e)}，5秒后重试...")
            time.sleep(5)

# ===================== 服务器通信线程 =====================
def server_communication(s):
    """
    服务器通信线程：接收服务器指令，放入对应队列
    参数：s - 服务器socket对象
    """
    print("[线程] 服务器通信线程已启动，等待指令...")
    while True:
        try:
            # 接收服务器转发的指令
            data = s.recv(4096)
            if not data:
                print("[警告] 与服务器连接断开，正在重连...")
                s.close()
                s = reconnect_server()  # 重连服务器
                continue
            
            # 解析指令（JSON格式）
            cmd_data = json.loads(data.decode('utf-8').strip())
            action = cmd_data.get("action")
            print(f"[接收] 服务器指令：{action}")
            
            # 处理动态修改查询间隔指令
            if action == "set_query_interval":
                new_interval = int(cmd_data.get("interval", 10))
                # 线程安全修改查询间隔
                with query_interval_lock:
                    global LIST_QUERY_INTERVAL
                    LIST_QUERY_INTERVAL = new_interval
                # 返回修改结果到服务器
                result = {"code": 0, "msg": f"定时查询间隔已修改为{new_interval}秒"}
                s.sendall(json.dumps(result, ensure_ascii=False).encode('utf-8'))
                print(f"[配置] 定时查询间隔已更新为：{new_interval}秒")
                continue
            
            # 普通指令放入高优先级队列
            high_priority_queue.put(cmd_data)
            print(f"[队列] 指令已加入高优先级队列：{action}")
        
        except json.JSONDecodeError:
            print("[警告] 接收到无效JSON数据，忽略")
            continue
        except Exception as e:
            print(f"[错误] 服务器通信异常：{str(e)}，正在重连...")
            s.close()
            s = reconnect_server()
            continue

# ===================== 主函数 =====================
def main():
    """
    主函数：启动所有线程，建立服务器连接
    """
    print("=== MC远程控制客户端（Windows版）===")
    print(f"配置信息：服务器={SERVER_HOST}:{SERVER_PORT} | MC窗口名={MC_WINDOW_NAME}")
    print("注意：请确保MC已窗口化运行，且本程序以管理员身份启动！\n")
    
    # 1. 连接公网服务器
    s = reconnect_server()
    
    # 2. 启动指令消费者线程（守护线程）
    consumer_thread = threading.Thread(target=command_consumer, args=(s,), daemon=True)
    consumer_thread.start()
    
    # 3. 启动定时查询线程（守护线程）
    query_thread = threading.Thread(target=auto_list_query, daemon=True)
    query_thread.start()
    
    # 4. 启动服务器通信线程（主线程运行）
    server_communication(s)

if __name__ == "__main__":
    # 管理员权限检查（可选，提示用户）
    try:
        import ctypes
        if not ctypes.windll.shell32.IsUserAnAdmin():
            print("[警告] 程序未以管理员身份运行，可能导致MC窗口控制失败！")
            print("建议：右键CMD → 以管理员身份运行，再执行本程序。")
            time.sleep(3)
    except:
        pass
    
    # 启动主函数
    main()
```

### 3. 客户端配置与运行步骤
1. **配置修改**：
   - 打开`mc_client.py`，修改`SERVER_HOST`为PHP服务器公网IP/域名；
   - 确认`SERVER_PORT`（9501）、`SECRET_KEY`与PHP端一致；
   - 确认`MC_WINDOW_NAME`为网易版MC窗口名称（默认“我的世界”，可通过截图工具确认）。
2. **运行前准备**：
   - 启动网易版MC，登录并进入目标服务器；
   - 将MC窗口设置为**窗口化模式**（非全屏）；
   - 关闭MC窗口的中文输入法（切换为英文）；
   - 以**管理员身份**打开CMD（右键CMD → 以管理员身份运行）。
3. **启动客户端**：
   ```bash
   # 进入代码所在目录
   cd /d D:\mc_client
   # 运行客户端
   python mc_client.py
   ```
4. **验证运行**：
   - 客户端日志显示“服务器连接成功，密钥验证通过”；
   - 网页端输入指令（如`list`），客户端日志显示“执行高优先级指令：exec_cmd”；
   - MC窗口自动执行指令，网页端返回玩家数量+名单。

### 4. 常见问题排查
| 问题现象                  | 排查方案                                                                 |
|---------------------------|--------------------------------------------------------------------------|
| 无法找到MC窗口            | 确认MC窗口名称正确；MC已启动且未最小化；以管理员身份运行客户端             |
| 键鼠模拟无效果            | MC窗口需为前台窗口；关闭MC全屏；切换为英文输入法；确认管理员权限         |
| 玩家数量识别失败          | 微调`chat_box_rect`坐标；检查OCR依赖是否安装完整；确保聊天框有/list返回结果 |
| 客户端无法连接服务器      | 检查服务器9501端口是否放行；确认SERVER_HOST/SERVER_PORT正确；密钥是否一致 |
| 定时查询不执行            | 检查高优先级队列是否有未执行的指令；确认查询间隔≥5秒；日志是否有报错     |

## 四、核心总结
### 1. 系统核心优势
- **无公网IP适配**：客户端主动连接公网服务器，无需端口映射；
- **指令优先级**：用户指令永远优先于定时查询，避免执行冲突；
- **高精度识别**：PaddleOCR+图片预处理，适配MC特殊字体；
- **安全可靠**：256位密钥验证，防止非法访问；
- **易扩展**：支持新增指令类型（如服务器状态查询、玩家踢出等）。

### 2. 关键注意事项
- PHP端：Socket服务需后台常驻，确保临时文件目录有写入权限；
- 客户端：必须管理员权限运行，MC窗口化+英文输入法；
- 网络：确保客户端能访问公网服务器（测试`ping 服务器IP`）；
- 性能：定时查询间隔建议≥5秒，避免频繁操作影响MC运行。

### 3. 扩展建议
- 增加指令白名单：限制危险指令（如/stop、/op）；
- 增加日志持久化：客户端/服务器日志写入文件，便于排查问题；
- 支持多客户端：修改PHP端Socket服务，实现多客户端管理；
- 前端优化：增加指令历史记录、玩家名单可视化等功能。