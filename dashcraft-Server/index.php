<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MC远程控制系统</title>
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
            SECRET_KEY: '123456',
            API_URL: 'api.php' // 当前目录下的api.php
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