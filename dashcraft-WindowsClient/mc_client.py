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
import threading
import queue
import re

# Python 3.8 兼容性补丁：使用 backports.zoneinfo
import sys
if sys.version_info < (3, 9):
    import backports.zoneinfo
    sys.modules['zoneinfo'] = backports.zoneinfo

from paddleocr import PaddleOCR

# ===================== 全局配置（需根据实际情况修改）=====================
# 公网PHP服务器配置
SERVER_HOST = "你的服务器IP，可以填内网的"  # 替换为实际服务器地址
SERVER_PORT = 9501              # 与PHP端config.php一致
SECRET_KEY = "123456"  # 与PHP端一致

# MC窗口配置（网易版默认名称，可根据实际调整）
MC_WINDOW_NAME = "Minecraft"

# 定时查询配置（可通过网页端动态修改）
LIST_QUERY_INTERVAL = 600  # 默认600秒执行一次/list查询（秒）
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
    返回：dict - 执行结果（code: 0成功/1失败，msg: 提示信息，raw_text: 聊天框原始文本）
    """
    # 互斥锁：确保同一时间只执行一个键鼠操作
    with executing_lock:
        # 第一步：激活MC窗口
        success, msg = activate_mc_window()
        if not success:
            return {"code": 1, "msg": msg, "raw_text": ""}
        
        try:
            # 第二步：打开聊天框（直接按T，避免ESC调出暂停菜单）
            pyautogui.press('t')     # 按T打开聊天框
            time.sleep(3)         # 增加等待时间，确保聊天框完全加载（2-3秒）
            
            # 第三步：等待聊天框真正打开（最多等待2秒）
            start_time = time.time()
            while time.time() - start_time < 2:
                if is_chat_box_open():
                    break
                time.sleep(0.2)
            else:
                print("[警告] 聊天框可能未正常打开，继续执行...")
            
            # 第四步：处理指令前缀
            if not cmd.startswith('/'):
                cmd = '/' + cmd
            
            # 第五步：输入指令
            pyautogui.typewrite(cmd, interval=0.08)  # 增加输入间隔，确保输入完整
            time.sleep(0.3)         # 等待输入完成
            
            # 第六步：发送指令
            pyautogui.press('enter')  # 按Enter发送指令
            
            # 第七步：等待指令执行结果（根据网络情况调整）
            time.sleep(1.5)         # 等待MC返回结果
            
            # 第八步：使用OCR解析聊天框内容作为返回结果
            chat_info = parse_chat_box_text()
            
            return {
                "code": 0, 
                "msg": f"指令执行成功：{cmd}",
                "raw_text": chat_info["raw_text"]
            }
        except Exception as e:
            return {"code": 1, "msg": f"键鼠模拟失败：{str(e)}", "raw_text": ""}

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

def is_chat_box_open():
    """
    检测MC聊天框是否打开
    返回：bool - 聊天框是否打开
    """
    mc_window = get_mc_window()
    if not mc_window:
        return False
    
    # 截取聊天框顶部区域（包含“聊天和命令”文字的区域）
    window_rect = (mc_window.left, mc_window.top, mc_window.right, mc_window.bottom)
    # 聊天框顶部区域（聊天框标题栏位置）
    chat_title_rect = (
        window_rect[0] + 100,         # 左边界
        window_rect[3] - 230,         # 上边界（聊天框标题栏）
        window_rect[2] - 100,         # 右边界
        window_rect[3] - 200          # 下边界
    )
    
    try:
        # 截取截图
        screenshot = ImageGrab.grab(chat_title_rect)
        # 预处理截图
        processed_img = preprocess_chat_image(screenshot)
        # OCR识别
        result = ocr.ocr(processed_img, cls=True)
        
        # 检查是否包含“聊天和命令”文字
        if result and result[0]:
            for line in result[0]:
                text = line[1][0].strip()
                if "聊天和命令" in text:
                    return True
        return False
    except Exception as e:
        print(f"[错误] 检测聊天框状态失败：{str(e)}")
        return False

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
