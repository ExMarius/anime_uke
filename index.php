<?php
session_start();

if (!isset($_SESSION['initiated'])) {
    $_SESSION['initiated'] = true;
    $_SESSION['log'] = [];
}

// Handle clear log request
if (isset($_GET['action']) && $_GET['action'] === 'clear_log') {
    $_SESSION['log'] = [];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); // Redirect to remove GET parameter
    exit();
}


// ==== CONFIG ==== //
$host = "127.0.0.1"; // Server IP
$port = 25575;
$password = "Zpoaskqw456";
$timeout = 3;
// ================ //

class Rcon {
    private $socket, $requestId;
    private $connected = false;
    const PACKET_LOGIN = 3;
    const PACKET_COMMAND = 2;

    public function __construct($host, $port, $password, $timeout = 3) {
        $this->socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$this->socket) {
            $this->connected = false;
            if ($errno === 111) { // Connection refused
                throw new Exception("Connection failed: Server is offline or RCON port is incorrect. ($errstr)");
            }
            throw new Exception("Connection failed: $errstr ($errno)");
        }
        stream_set_timeout($this->socket, $timeout);
        $this->requestId = 0;
        if (!$this->login($password)) {
            $this->connected = false;
            fclose($this->socket);
            throw new Exception("RCON login failed. Check password. (Possible incorrect password)"); // More specific login error
        }
        $this->connected = true;
    }

    public function isConnected() {
        return $this->connected;
    }

    private function login($password) {
        $res = $this->send(self::PACKET_LOGIN, $password);
        return $res['id'] !== -1;
    }

    public function sendCommand($command) {
        $res = $this->send(self::PACKET_COMMAND, $command);
        return $res['body'];
    }

    private function send($type, $body) {
        $id = ++$this->requestId;
        $packet = pack("VV", $id, $type) . $body . "\x00\x00";
        $packet = pack("V", strlen($packet)) . $packet;

        fwrite($this->socket, $packet);

        $size_data = fread($this->socket, 4);
        if (strlen($size_data) < 4) {
            return ['id' => -1, 'body' => 'Error: Incomplete response size. Server might have closed the connection.'];
        }
        $size = unpack("V", $size_data)[1];

        $packet_data = fread($this->socket, $size);
        if (strlen($packet_data) < $size) {
            return ['id' => -1, 'body' => 'Error: Incomplete response data. Server might have closed the connection.'];
        }

        $response = unpack("Vid/Vtype/a*body", $packet_data);
        $response['body'] = preg_replace('/\x00+$/', '', $response['body']);
        return $response;
    }

    public function close() {
        if ($this->socket && is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->connected = false;
    }
}

function formatLogLineWithColor($logEntry) {
    $text = htmlspecialchars($logEntry['message'], ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/§[0-9a-fk-or]/i', '', $text);

    $class = 'info-log';
    switch ($logEntry['type']) {
        case 'error':
            $class = 'error-log';
            break;
        case 'warning':
            $class = 'warning-log';
            break;
        case 'info':
        default:
            $class = 'info-log';
            break;
    }
    return "<span class=\"$class\">" . $text . "</span>";
}

$rconStatus = 'Disconnected';
$statusClass = 'disconnected';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['command'])) {
    $cmd = trim($_POST['command']);
    if ($cmd !== '') {
        try {
            $rcon = new Rcon($host, $port, $password, $timeout);
            $rconStatus = $rcon->isConnected() ? 'Connected' : 'Disconnected';
            $statusClass = $rcon->isConnected() ? 'connected' : 'disconnected';

            if ($rcon->isConnected()) {
                $response = $rcon->sendCommand($cmd);
                $rcon->close();

                $t = gmdate("H:i:s") . " UTC";
                $_SESSION['log'][] = ['type' => 'info', 'message' => "[$t INFO]: [RCON] $cmd"];
                
                $responseLines = explode("\n", $response);
                if (empty(trim($response))) { // Check for empty response
                    $_SESSION['log'][] = ['type' => 'info', 'message' => "[$t INFO]: Command sent. No specific output received from server."];
                } else {
                    foreach ($responseLines as $line) {
                        $line = trim($line);
                        if ($line !== '') {
                            if (preg_match('/^\[ERROR\]/i', $line) || preg_match('/^\[FAIL\]/i', $line)) {
                                $_SESSION['log'][] = ['type' => 'error', 'message' => "[$t ERROR]: " . $line];
                            } elseif (preg_match('/^\[WARN\]/i', $line) || preg_match('/^\[WARNING\]/i', $line)) {
                                $_SESSION['log'][] = ['type' => 'warning', 'message' => "[$t WARNING]: " . $line];
                            } else {
                                $_SESSION['log'][] = ['type' => 'info', 'message' => "[$t INFO]: " . $line];
                            }
                        }
                    }
                }
            } else {
                $t = gmdate("H:i:s") . " UTC";
                $_SESSION['log'][] = ['type' => 'error', 'message' => "[$t ERROR]: RCON connection failed or lost."];
                $rconStatus = 'Disconnected';
                $statusClass = 'disconnected';
            }

        } catch (Exception $e) {
            $t = gmdate("H:i:s") . " UTC";
            $_SESSION['log'][] = ['type' => 'error', 'message' => "[$t ERROR]: " . $e->getMessage()];
            $rconStatus = 'Disconnected';
            $statusClass = 'disconnected';
        }
    }
} else {
    try {
        $rcon = new Rcon($host, $port, $password, $timeout);
        $rconStatus = $rcon->isConnected() ? 'Connected' : 'Disconnected';
        $statusClass = $rcon->isConnected() ? 'connected' : 'disconnected';
        $rcon->close();
    } catch (Exception $e) {
        $rconStatus = 'Disconnected';
        $statusClass = 'disconnected';
        // Only log initial connection errors if the log is empty to avoid clutter on page load
        if (empty($_SESSION['log'])) {
             $t = gmdate("H:i:s") . " UTC";
            $_SESSION['log'][] = ['type' => 'error', 'message' => "[$t ERROR]: " . $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Minecraft RCON Console</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: linear-gradient(135deg, #0d1421 0%, #1a2332 50%, #0f1419 100%);
            --card-bg: rgba(13, 20, 33, 0.8);
            --glass-bg: rgba(255, 255, 255, 0.05);
            --border-color: rgba(0, 255, 136, 0.3);
            --glow-color: #00ff88;
            --glow-secondary: #00d4ff;
            --text-primary: #e8f4fd;
            --text-secondary: #a3b8cc;
            --success-color: #00ff88;
            --error-color: #ff4757;
            --warning-color: #ffa502;
            --shadow-glow: 0 0 30px rgba(0, 255, 136, 0.2);
            --shadow-strong: 0 20px 40px rgba(0, 0, 0, 0.4);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--primary-bg);
            color: var(--text-primary);
            font-family: 'JetBrains Mono', 'Consolas', monospace;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 20% 80%, rgba(0, 255, 136, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(0, 212, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(147, 51, 234, 0.05) 0%, transparent 50%);
            pointer-events: none;
            z-index: -1;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
            display: flex;
            flex-direction: column;
            height: 100vh;
            gap: 1.5rem;
        }

        .header {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem 2rem;
            box-shadow: var(--shadow-glow);
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(0, 255, 136, 0.05), transparent);
            transform: rotate(45deg);
            animation: shimmer 3s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            50% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--glow-color), var(--glow-secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .logo {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--glow-color), var(--glow-secondary));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 0 20px rgba(0, 255, 136, 0.4);
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            position: relative;
            animation: pulse 2s infinite;
        }

        .status-dot.connected {
            background: var(--success-color);
            box-shadow: 0 0 15px var(--success-color);
        }

        .status-dot.disconnected {
            background: var(--error-color);
            box-shadow: 0 0 15px var(--error-color);
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.1); }
        }

        .console-container {
            flex: 1;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--shadow-strong);
            position: relative;
            overflow: hidden;
        }

        .console-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--glow-color), transparent);
            animation: scanline 2s linear infinite;
        }

        @keyframes scanline {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        #console {
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(0, 255, 136, 0.2);
            border-radius: 15px;
            padding: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.6;
            overflow-y: auto;
            white-space: pre-wrap;
            font-weight: 500;
            color: var(--text-primary);
            position: relative;
        }

        #console::-webkit-scrollbar {
            width: 8px;
        }

        #console::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
        }

        #console::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--glow-color), var(--glow-secondary));
            border-radius: 4px;
            box-shadow: 0 0 10px rgba(0, 255, 136, 0.3);
        }

        .input-section {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--shadow-glow);
        }

        .input-container {
            position: relative;
            margin-bottom: 1rem;
        }

        #command {
            width: 100%;
            background: rgba(0, 0, 0, 0.4);
            border: 2px solid var(--border-color);
            border-radius: 15px;
            padding: 1rem 1.5rem;
            font-size: 1rem;
            font-family: inherit;
            font-weight: 500;
            color: var(--text-primary);
            outline: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            caret-color: var(--glow-color);
        }

        #command:focus {
            border-color: var(--glow-color);
            box-shadow: 0 0 25px rgba(0, 255, 136, 0.3);
            background: rgba(0, 0, 0, 0.6);
        }

        #command::placeholder {
            color: var(--text-secondary);
            opacity: 0.7;
        }

        .quick-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            background: linear-gradient(135deg, rgba(0, 255, 136, 0.1), rgba(0, 212, 255, 0.1));
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 255, 136, 0.2);
            border-color: var(--glow-color);
        }

        .btn.clear-btn {
            background: linear-gradient(135deg, rgba(255, 71, 87, 0.1), rgba(255, 165, 2, 0.1));
            border-color: rgba(255, 71, 87, 0.3);
            margin-left: auto;
        }

        .btn.clear-btn:hover {
            border-color: var(--error-color);
            box-shadow: 0 10px 25px rgba(255, 71, 87, 0.2);
        }

        /* Log styling */
        .info-log {
            color: var(--success-color);
            text-shadow: 0 0 5px rgba(0, 255, 136, 0.3);
        }

        .error-log {
            color: var(--error-color);
            text-shadow: 0 0 5px rgba(255, 71, 87, 0.3);
            font-weight: 600;
        }

        .warning-log {
            color: var(--warning-color);
            text-shadow: 0 0 5px rgba(255, 165, 2, 0.3);
            font-weight: 500;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
                gap: 1rem;
            }

            .header h1 {
                font-size: 1.4rem;
            }

            .header-content {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .quick-buttons {
                justify-content: center;
            }

            .btn.clear-btn {
                margin-left: 0;
                margin-top: 0.5rem;
            }
        }

        /* Glitch effect for error messages */
        .error-log {
            animation: glitch 0.3s ease-in-out;
        }

        @keyframes glitch {
            0% { transform: translateX(0); }
            20% { transform: translateX(-2px); }
            40% { transform: translateX(2px); }
            60% { transform: translateX(-1px); }
            80% { transform: translateX(1px); }
            100% { transform: translateX(0); }
        }

        /* Loading animation */
        .loading::after {
            content: '';
            animation: loading 1s infinite;
        }

        @keyframes loading {
            0%, 100% { content: ''; }
            25% { content: '.'; }
            50% { content: '..'; }
            75% { content: '...'; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-content">
                <h1>
                    <div class="logo">⚡</div>
                    Minecraft RCON Console
                </h1>
                <div class="status-indicator">
                    <div class="status-dot <?php echo $statusClass; ?>"></div>
                    <span>Status: <?php echo $rconStatus; ?></span>
                </div>
            </div>
        </div>

        <div class="console-container">
            <div id="console"><?php foreach ($_SESSION['log'] as $line) echo formatLogLineWithColor($line) . "\n"; ?></div>
        </div>

        <div class="input-section">
            <form method="post" onsubmit="return sendCmd();">
                <div class="input-container">
                    <input
                        type="text"
                        name="command"
                        id="command"
                        placeholder="Type a command, e.g., say Hello World"
                        autocomplete="off"
                        autofocus
                        spellcheck="false"
                        autocorrect="off"
                        autocapitalize="off"
                    />
                </div>
            </form>

            <div class="quick-buttons">
                <button type="button" class="btn" onclick="quickCmd('say Hello world')">🗨️ Say Hello</button>
                <button type="button" class="btn" onclick="quickCmd('time set day')">☀️ Set Day</button>
                <button type="button" class="btn" onclick="quickCmd('list')">👥 List Players</button>
                <button type="button" class="btn clear-btn" onclick="clearConsole()">🗑️ Clear Console</button>
            </div>
        </div>
    </div>

    <script>
        function sendCmd() {
            const form = document.forms[0];
            const data = new FormData(form);
            const commandInput = document.getElementById('command');
            
            // Add loading state
            commandInput.classList.add('loading');
            commandInput.disabled = true;
            
            fetch("", {
                method: "POST",
                body: data
            })
            .then(res => res.text())
            .then(html => {
                document.open();
                document.write(html);
                document.close();
            })
            .catch(error => {
                console.error('Error sending command:', error);
                const consoleBox = document.getElementById('console');
                const errorMessage = `<span class="error-log">[${new Date().toLocaleTimeString()}] UI Error: Failed to send command. Check console.</span>\n`;
                consoleBox.innerHTML += errorMessage;
                consoleBox.scrollTop = consoleBox.scrollHeight;
            })
            .finally(() => {
                commandInput.classList.remove('loading');
                commandInput.disabled = false;
            });
            return false;
        }

        function quickCmd(cmd) {
            const input = document.getElementById('command');
            input.value = cmd;
            sendCmd();
        }

        function clearConsole() {
            window.location.href = window.location.pathname + '?action=clear_log';
        }

        window.onload = () => {
            const input = document.getElementById('command');
            input.focus();
            const consoleBox = document.getElementById('console');
            consoleBox.scrollTop = consoleBox.scrollHeight;

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    sendCmd();
                }
            });
        };
    </script>
</body>
</html>