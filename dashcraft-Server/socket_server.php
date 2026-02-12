<?php
/**
 * Socket长连接服务（后台常驻）
 * 功能：接收客户端连接、验证密钥、转发网页端指令、接收客户端结果
 * 启动方式：nohup php socket_server.php > socket.log 2>&1 &
 */
require 'config.php';

// 初始化数据库
$db_init_result = init_database();
echo "数据库初始化结果: " . ($db_init_result ? "成功" : "失败（将使用临时文件存储）") . "\n";

// 临时文件路径（作为数据库失败时的备份）
define('CMD_FILE', '/tmp/mc_cmd.json');
define('RESULT_FILE', '/tmp/mc_result.json');

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
        // 获取客户端IP
        socket_getpeername($new_client, $client_ip);
        
        // 验证客户端密钥
        $key = trim(socket_read($new_client, 300));
        if ($key === SECRET_KEY) {
            $clients[] = $new_client;
            socket_write($new_client, "AUTH_SUCCESS\n");
            echo "[" . date('Y-m-d H:i:s') . "] 客户端连接成功，密钥验证通过，IP: {$client_ip}\n";
            
            // 记录客户端连接到数据库
            $db = get_db_connection();
            $stmt = $db->prepare("INSERT INTO mc_clients (client_ip, status) VALUES (:client_ip, 'connected')");
            $stmt->execute([':client_ip' => $client_ip]);
        } else {
            socket_write($new_client, "AUTH_FAILED\n");
            socket_close($new_client);
            echo "[" . date('Y-m-d H:i:s') . "] 客户端密钥验证失败，断开连接，IP: {$client_ip}\n";
        }
    }

    // 2. 转发网页端指令到客户端
    $command_data = null;
    $command_id = null;
    
    // 尝试从数据库读取指令
    $db = get_db_connection();
    if ($db) {
        try {
            $stmt = $db->prepare("SELECT id, command, action, interval_value FROM mc_commands WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
            $stmt->execute();
            $cmd_data = $stmt->fetch();
            
            if ($cmd_data) {
                // 准备指令数据
                $command_data = [
                    'action' => $cmd_data['action'],
                    'cmd' => $cmd_data['command']
                ];
                
                // 如果是设置查询间隔的指令，添加interval参数
                if ($cmd_data['action'] === 'set_query_interval' && $cmd_data['interval_value'] !== null) {
                    $command_data['interval'] = $cmd_data['interval_value'];
                }
                
                $command_id = $cmd_data['id'];
            }
        } catch (Exception $e) {
            error_log("数据库读取指令失败: " . $e->getMessage());
        }
    }
    
    // 如果数据库读取失败，尝试从临时文件读取
    if (!$command_data && file_exists(CMD_FILE)) {
        $cmd_data = json_decode(file_get_contents(CMD_FILE), true);
        if ($cmd_data && $cmd_data['key'] === SECRET_KEY) {
            $command_data = $cmd_data['data'];
        }
    }
    
    if ($command_data) {
        // 转发指令到所有已连接的客户端
        foreach ($clients as $client) {
            socket_write($client, json_encode($command_data) . "\n");
        }
        
        // 更新指令状态为执行中（如果有command_id）
        if ($command_id && $db) {
            try {
                $stmt = $db->prepare("UPDATE mc_commands SET status = 'executing' WHERE id = :id");
                $stmt->execute([':id' => $command_id]);
            } catch (Exception $e) {
                error_log("数据库更新指令状态失败: " . $e->getMessage());
            }
        } else if (file_exists(CMD_FILE)) {
            // 如果使用的是临时文件，删除它
            unlink(CMD_FILE);
        }
        
        echo "[" . date('Y-m-d H:i:s') . "] 转发指令：" . json_encode($command_data) . "\n";
    }

    // 3. 接收客户端返回的结果并写入数据库或临时文件
    foreach ($clients as $client) {
        $response = @socket_read($client, 4096); // 抑制读取失败的警告
        if ($response !== false && $response !== '') {
            try {
                $result_data = json_decode(trim($response), true);
                if ($result_data) {
                    $db_available = false;
                    
                    // 尝试写入数据库
                    $db = get_db_connection();
                    if ($db) {
                        try {
                            // 查找最近的执行中指令
                            $stmt = $db->prepare("SELECT id FROM mc_commands WHERE status = 'executing' ORDER BY created_at DESC LIMIT 1");
                            $stmt->execute();
                            $cmd_info = $stmt->fetch();
                            
                            if ($cmd_info) {
                                $command_id = $cmd_info['id'];
                                
                                // 插入结果
                                $stmt = $db->prepare("INSERT INTO mc_results (command_id, status, message, player_count, player_list) VALUES (:command_id, :status, :message, :player_count, :player_list)");
                                $stmt->execute([
                                    ':command_id' => $command_id,
                                    ':status' => $result_data['code'] == 0 ? 'success' : 'error',
                                    ':message' => $result_data['msg'] ?? '无消息',
                                    ':player_count' => $result_data['player_count'] ?? null,
                                    ':player_list' => isset($result_data['player_list']) ? json_encode($result_data['player_list']) : null
                                ]);
                                
                                // 更新指令状态为已完成
                                $stmt = $db->prepare("UPDATE mc_commands SET status = 'completed' WHERE id = :id");
                                $stmt->execute([':id' => $command_id]);
                                
                                // 如果返回了玩家信息，也更新玩家信息表
                                if (isset($result_data['player_count']) && isset($result_data['player_list'])) {
                                    $stmt = $db->prepare("INSERT INTO mc_players (player_count, player_list, is_auto_query) VALUES (:player_count, :player_list, :is_auto_query)");
                                    $stmt->execute([
                                        ':player_count' => $result_data['player_count'],
                                        ':player_list' => json_encode($result_data['player_list']),
                                        ':is_auto_query' => isset($result_data['type']) && $result_data['type'] === 'auto_query'
                                    ]);
                                }
                                
                                $db_available = true;
                            }
                        } catch (Exception $e) {
                            error_log("数据库写入结果失败: " . $e->getMessage());
                        }
                    }
                    
                    // 如果数据库写入失败，写入临时文件
                    if (!$db_available) {
                        $result = [
                            'key' => SECRET_KEY,
                            'data' => $result_data
                        ];
                        file_put_contents(RESULT_FILE, json_encode($result));
                    }
                    
                    echo "[" . date('Y-m-d H:i:s') . "] 接收客户端结果：" . trim($response) . "\n";
                }
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] 处理结果失败：" . $e->getMessage() . "\n";
            }
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
        
        // 同时清理数据库中过期的客户端连接（超过30秒未活跃）
        $db = get_db_connection();
        if ($db) {
            try {
                $stmt = $db->prepare("UPDATE mc_clients SET status = 'disconnected' WHERE status = 'connected' AND last_active < DATE_SUB(NOW(), INTERVAL 30 SECOND)");
                $stmt->execute();
            } catch (Exception $e) {
                error_log("数据库清理过期连接失败: " . $e->getMessage());
            }
        }
    }

    usleep(100000); // 休眠100ms，降低CPU占用
}

// 关闭Socket（理论上不会执行到此处）
socket_close($socket);
?>