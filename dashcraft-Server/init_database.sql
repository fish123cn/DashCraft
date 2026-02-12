-- 数据库初始化脚本
-- 注意：请确保已经创建了数据库 dashcraft_funtong_cn

-- 切换到目标数据库
USE dashcraft_funtong_cn;

-- 1. 指令表：存储用户输入的MC指令
CREATE TABLE IF NOT EXISTS mc_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    command TEXT NOT NULL COMMENT 'MC指令内容',
    action VARCHAR(50) NOT NULL COMMENT '操作类型：exec_cmd, get_players, set_query_interval',
    status ENUM('pending', 'executing', 'completed', 'failed') DEFAULT 'pending' COMMENT '执行状态',
    key_hash VARCHAR(64) NOT NULL COMMENT '密钥哈希值',
    interval_value INT DEFAULT NULL COMMENT '查询间隔（仅set_query_interval使用）',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MC指令表';

-- 2. 结果表：存储指令执行结果
CREATE TABLE IF NOT EXISTS mc_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    command_id INT NOT NULL COMMENT '关联的指令ID',
    status ENUM('success', 'error') NOT NULL COMMENT '执行状态',
    message TEXT NOT NULL COMMENT '执行结果消息',
    player_count VARCHAR(10) DEFAULT NULL COMMENT '玩家数量',
    player_list TEXT DEFAULT NULL COMMENT '玩家名单（JSON格式）',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    FOREIGN KEY (command_id) REFERENCES mc_commands(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MC执行结果表';

-- 3. 玩家信息表：存储定时查询的玩家信息
CREATE TABLE IF NOT EXISTS mc_players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    player_count VARCHAR(10) NOT NULL COMMENT '玩家数量',
    player_list TEXT NOT NULL COMMENT '玩家名单（JSON格式）',
    is_auto_query BOOLEAN DEFAULT FALSE COMMENT '是否为自动查询',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '查询时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MC玩家信息表';

-- 4. 客户端表：存储客户端连接状态
CREATE TABLE IF NOT EXISTS mc_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_ip VARCHAR(50) NOT NULL COMMENT '客户端IP',
    connect_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '连接时间',
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后活跃时间',
    status ENUM('connected', 'disconnected') DEFAULT 'connected' COMMENT '连接状态',
    KEY idx_status (status),
    KEY idx_last_active (last_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='MC客户端连接表';

-- 5. 添加索引以提高查询性能
CREATE INDEX idx_commands_status ON mc_commands(status);
CREATE INDEX idx_commands_created_at ON mc_commands(created_at);
CREATE INDEX idx_results_command_id ON mc_results(command_id);
CREATE INDEX idx_players_created_at ON mc_players(created_at);

-- 6. 插入初始数据（可选）
-- 插入一条测试指令
INSERT INTO mc_commands (command, action, status, key_hash) 
VALUES ('list', 'get_players', 'completed', 'test') 
ON DUPLICATE KEY UPDATE status = 'completed';

-- 插入测试结果
INSERT INTO mc_results (command_id, status, message, player_count, player_list) 
VALUES (LAST_INSERT_ID(), 'success', '测试查询成功', '0', '[]') 
ON DUPLICATE KEY UPDATE status = 'success';

-- 插入测试玩家信息
INSERT INTO mc_players (player_count, player_list, is_auto_query) 
VALUES ('0', '[]', TRUE) 
ON DUPLICATE KEY UPDATE player_count = '0';

-- 初始化完成
SELECT '数据库初始化完成' AS message;