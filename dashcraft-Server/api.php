<?php
/**
 * 网页端接口文件
 * 功能：接收网页端POST请求、验证密钥、写入指令到数据库、返回客户端执行结果
 * 支持操作：exec_cmd（执行MC指令）、get_players（手动查询玩家信息）、set_query_interval（修改定时查询间隔）
 */
require 'config.php';

// 初始化数据库（静默执行，不输出错误信息）
try {
    $db_init_result = init_database();
} catch (Exception $e) {
    error_log("数据库初始化异常: " . $e->getMessage());
}

// 临时文件路径（作为数据库失败时的备份）
define('CMD_FILE', '/tmp/mc_cmd.json');
define('RESULT_FILE', '/tmp/mc_result.json');

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
        
        $db_available = false;
        $command_id = null;
        
        // 尝试写入指令到数据库
        $db = get_db_connection();
        if ($db) {
            try {
                $stmt = $db->prepare("INSERT INTO mc_commands (command, action, status, key_hash) VALUES (:command, 'exec_cmd', 'pending', :key_hash)");
                $stmt->execute([
                    ':command' => $cmd,
                    ':key_hash' => hash('sha256', SECRET_KEY)
                ]);
                $command_id = $db->lastInsertId();
                $db_available = true;
            } catch (Exception $e) {
                error_log("数据库写入指令失败: " . $e->getMessage());
            }
        }
        
        // 如果数据库写入失败，写入临时文件
        if (!$db_available) {
            $data = [
                'key' => SECRET_KEY,
                'data' => [
                    'action' => 'exec_cmd',
                    'cmd' => $cmd
                ]
            ];
            file_put_contents(CMD_FILE, json_encode($data));
        }
        
        // 等待客户端返回结果（最多等待5秒）
        $timeout = 5;
        $start_time = time();
        $result = null;
        
        while ((time() - $start_time) < $timeout) {
            // 尝试从数据库查询结果
            if ($db_available && $command_id) {
                try {
                    $stmt = $db->prepare("SELECT status, message, player_count, player_list FROM mc_results WHERE command_id = :command_id LIMIT 1");
                    $stmt->execute([':command_id' => $command_id]);
                    $result_data = $stmt->fetch();
                    
                    if ($result_data) {
                        $result = [
                            'code' => $result_data['status'] === 'success' ? 0 : 1,
                            'msg' => $result_data['message'],
                            'player_count' => $result_data['player_count'],
                            'player_list' => $result_data['player_list'] ? json_decode($result_data['player_list'], true) : []
                        ];
                        break;
                    }
                } catch (Exception $e) {
                    error_log("数据库查询结果失败: " . $e->getMessage());
                }
            }
            
            // 如果数据库查询失败，尝试从临时文件读取
            if (!$result && file_exists(RESULT_FILE)) {
                $result_data = json_decode(file_get_contents(RESULT_FILE), true);
                if ($result_data && $result_data['key'] === SECRET_KEY) {
                    $result = $result_data['data'];
                    @unlink(RESULT_FILE); // 删除结果文件（静默处理错误）
                    break;
                }
            }
            
            usleep(100000); // 每100ms检查一次
        }
        
        // 返回结果
        if ($result) {
            exit(json_encode($result));
        } else {
            exit(json_encode([
                'code' => 1,
                'msg' => '客户端无响应，请求超时（5秒）'
            ]));
        }
        break;

    // 操作2：手动查询玩家数量+名单
    case 'get_players':
        $db_available = false;
        $command_id = null;
        
        // 尝试写入查询指令到数据库
        $db = get_db_connection();
        if ($db) {
            try {
                $stmt = $db->prepare("INSERT INTO mc_commands (command, action, status, key_hash) VALUES ('list', 'get_players', 'pending', :key_hash)");
                $stmt->execute([':key_hash' => hash('sha256', SECRET_KEY)]);
                $command_id = $db->lastInsertId();
                $db_available = true;
            } catch (Exception $e) {
                error_log("数据库写入查询指令失败: " . $e->getMessage());
            }
        }
        
        // 如果数据库写入失败，写入临时文件
        if (!$db_available) {
            $data = [
                'key' => SECRET_KEY,
                'data' => [
                    'action' => 'get_players'
                ]
            ];
            file_put_contents(CMD_FILE, json_encode($data));
        }
        
        // 等待结果（最多5秒）
        $timeout = 5;
        $start_time = time();
        $result = null;
        
        while ((time() - $start_time) < $timeout) {
            // 尝试从数据库查询结果
            if ($db_available && $command_id) {
                try {
                    $stmt = $db->prepare("SELECT status, message, player_count, player_list FROM mc_results WHERE command_id = :command_id LIMIT 1");
                    $stmt->execute([':command_id' => $command_id]);
                    $result_data = $stmt->fetch();
                    
                    if ($result_data) {
                        $result = [
                            'code' => $result_data['status'] === 'success' ? 0 : 1,
                            'msg' => $result_data['message'],
                            'player_count' => $result_data['player_count'],
                            'player_list' => $result_data['player_list'] ? json_decode($result_data['player_list'], true) : []
                        ];
                        break;
                    }
                } catch (Exception $e) {
                    error_log("数据库查询结果失败: " . $e->getMessage());
                }
            }
            
            // 如果数据库查询失败，尝试从临时文件读取
            if (!$result && file_exists(RESULT_FILE)) {
                $result_data = json_decode(file_get_contents(RESULT_FILE), true);
                if ($result_data && $result_data['key'] === SECRET_KEY) {
                    $result = $result_data['data'];
                    @unlink(RESULT_FILE); // 删除结果文件（静默处理错误）
                    break;
                }
            }
            
            usleep(100000);
        }
        
        if ($result) {
            exit(json_encode($result));
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
        
        $db_available = false;
        $command_id = null;
        
        // 尝试写入设置指令到数据库
        $db = get_db_connection();
        if ($db) {
            try {
                $stmt = $db->prepare("INSERT INTO mc_commands (command, action, status, key_hash, interval_value) VALUES ('set_interval', 'set_query_interval', 'pending', :key_hash, :interval)");
                $stmt->execute([
                    ':key_hash' => hash('sha256', SECRET_KEY),
                    ':interval' => (int)$interval
                ]);
                $command_id = $db->lastInsertId();
                $db_available = true;
            } catch (Exception $e) {
                error_log("数据库写入设置指令失败: " . $e->getMessage());
            }
        }
        
        // 如果数据库写入失败，写入临时文件
        if (!$db_available) {
            $data = [
                'key' => SECRET_KEY,
                'data' => [
                    'action' => 'set_query_interval',
                    'interval' => (int)$interval
                ]
            ];
            file_put_contents(CMD_FILE, json_encode($data));
        }
        
        // 等待结果（最多5秒）
        $timeout = 5;
        $start_time = time();
        $result = null;
        
        while ((time() - $start_time) < $timeout) {
            // 尝试从数据库查询结果
            if ($db_available && $command_id) {
                try {
                    $stmt = $db->prepare("SELECT status, message FROM mc_results WHERE command_id = :command_id LIMIT 1");
                    $stmt->execute([':command_id' => $command_id]);
                    $result_data = $stmt->fetch();
                    
                    if ($result_data) {
                        $result = [
                            'code' => $result_data['status'] === 'success' ? 0 : 1,
                            'msg' => $result_data['message']
                        ];
                        break;
                    }
                } catch (Exception $e) {
                    error_log("数据库查询结果失败: " . $e->getMessage());
                }
            }
            
            // 如果数据库查询失败，尝试从临时文件读取
            if (!$result && file_exists(RESULT_FILE)) {
                $result_data = json_decode(file_get_contents(RESULT_FILE), true);
                if ($result_data && $result_data['key'] === SECRET_KEY) {
                    $result = $result_data['data'];
                    @unlink(RESULT_FILE); // 删除结果文件（静默处理错误）
                    break;
                }
            }
            
            usleep(100000);
        }
        
        if ($result) {
            exit(json_encode($result));
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