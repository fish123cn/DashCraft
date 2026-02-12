<?php
/**
 * 全局配置文件
 * 注意：SECRET_KEY需与客户端保持一致，建议使用generate_256bit_key()生成
 */
// 256位密钥（32字节=256位，示例值需替换为自己生成的密钥）
define('SECRET_KEY', '7e8f9a0b1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6r7s8t9u0v1w2x3y4z5a6b7c8d9e0f1');
// Socket服务端口（需在服务器防火墙/安全组放行）
define('SOCKET_PORT', 9501);

// 数据库配置
define('DB_HOST', 'localhost');
define('DB_NAME', 'dashcraft_funton');
define('DB_USER', 'dashcraft_funton');
define('DB_PASS', 'SrYxS1PsGS8RHNTP');
define('DB_CHARSET', 'utf8mb4');
define('DB_SOCKET', '/tmp/mysql.sock');

/**
 * 生成256位随机密钥工具函数
 * 首次使用时取消注释运行一次，生成后替换上方SECRET_KEY
 */
function generate_256bit_key() {
    return bin2hex(random_bytes(32)); // 生成32字节随机字符串（256位）
}
// echo generate_256bit_key(); // 生成密钥后注释此行

/**
 * 数据库连接函数
 * @return PDO|null 数据库连接对象，失败返回null
 */
function get_db_connection() {
    try {
        // 记录连接尝试
        error_log("尝试连接数据库: " . DB_USER . "@" . DB_HOST . ", 数据库: " . DB_NAME);
        
        // 定义连接选项
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        // 尝试使用TCP连接
        $dsn = "mysql:host=" . DB_HOST . ";port=3306;dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        error_log("TCP连接成功");
        return $db;
    } catch (PDOException $e) {
        error_log("TCP连接失败: " . $e->getMessage());
        
        try {
            // 定义连接选项
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            // 尝试使用socket连接
            $dsn = "mysql:unix_socket=" . DB_SOCKET . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $db = new PDO($dsn, DB_USER, DB_PASS, $options);
            error_log("Socket连接成功");
            return $db;
        } catch (PDOException $e2) {
            error_log("Socket连接失败: " . $e2->getMessage());
            return null;
        }
    }
}

/**
 * 初始化数据库表结构
 * @return bool 是否初始化成功
 */
function init_database() {
    $db = get_db_connection();
    if (!$db) {
        error_log("数据库连接失败，无法初始化表结构");
        return false;
    }
    
    try {
        // 创建指令表
        $db->exec("
            CREATE TABLE IF NOT EXISTS mc_commands (
                id INT AUTO_INCREMENT PRIMARY KEY,
                command TEXT NOT NULL COMMENT 'MC指令内容',
                action VARCHAR(50) NOT NULL COMMENT '操作类型：exec_cmd, get_players, set_query_interval',
                status ENUM('pending', 'executing', 'completed', 'failed') DEFAULT 'pending' COMMENT '执行状态',
                key_hash VARCHAR(64) NOT NULL COMMENT '密钥哈希值',
                interval_value INT DEFAULT NULL COMMENT '查询间隔（仅set_query_interval使用）',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MC指令表'
        ");
        
        // 创建结果表
        $db->exec("
            CREATE TABLE IF NOT EXISTS mc_results (
                id INT AUTO_INCREMENT PRIMARY KEY,
                command_id INT NOT NULL COMMENT '关联的指令ID',
                status ENUM('success', 'error') NOT NULL COMMENT '执行状态',
                message TEXT NOT NULL COMMENT '执行结果消息',
                player_count VARCHAR(10) DEFAULT NULL COMMENT '玩家数量',
                player_list TEXT DEFAULT NULL COMMENT '玩家名单（JSON格式）',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
                FOREIGN KEY (command_id) REFERENCES mc_commands(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MC执行结果表'
        ");
        
        // 创建玩家信息表
        $db->exec("
            CREATE TABLE IF NOT EXISTS mc_players (
                id INT AUTO_INCREMENT PRIMARY KEY,
                player_count VARCHAR(10) NOT NULL COMMENT '玩家数量',
                player_list TEXT NOT NULL COMMENT '玩家名单（JSON格式）',
                is_auto_query BOOLEAN DEFAULT FALSE COMMENT '是否为自动查询',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '查询时间'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MC玩家信息表'
        ");
        
        // 创建客户端表
        $db->exec("
            CREATE TABLE IF NOT EXISTS mc_clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_ip VARCHAR(50) NOT NULL COMMENT '客户端IP',
                connect_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '连接时间',
                last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后活跃时间',
                status ENUM('connected', 'disconnected') DEFAULT 'connected' COMMENT '连接状态',
                KEY idx_status (status),
                KEY idx_last_active (last_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MC客户端连接表'
        ");
        
        // 添加索引
        try {
            $db->exec("CREATE INDEX idx_commands_status ON mc_commands(status)");
        } catch (Exception $e) {
            // 索引已存在，忽略错误
        }
        try {
            $db->exec("CREATE INDEX idx_commands_created_at ON mc_commands(created_at)");
        } catch (Exception $e) {
            // 索引已存在，忽略错误
        }
        try {
            $db->exec("CREATE INDEX idx_results_command_id ON mc_results(command_id)");
        } catch (Exception $e) {
            // 索引已存在，忽略错误
        }
        try {
            $db->exec("CREATE INDEX idx_players_created_at ON mc_players(created_at)");
        } catch (Exception $e) {
            // 索引已存在，忽略错误
        }
        
        return true;
    } catch (Exception $e) {
        error_log("数据库初始化失败: " . $e->getMessage());
        return false;
    }
}
?>