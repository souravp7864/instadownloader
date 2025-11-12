<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set proper headers
header('Content-Type: application/json');

// Log startup
file_put_contents('error.log', date('Y-m-d H:i:s') . " - Bot starting up...\n", FILE_APPEND);

// Get the request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Get the input data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if ($data) {
        // Process webhook update
        processUpdate($data);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    }
} else if ($method === 'GET') {
    // Handle GET requests (for webhook setup and health checks)
    if (isset($_GET['setwebhook'])) {
        setupWebhook();
    } else if (isset($_GET['removewebhook'])) {
        removeWebhook();
    } else if (isset($_GET['info'])) {
        showBotInfo();
    } else {
        // Health check
        echo json_encode([
            'status' => 'online',
            'service' => 'Telegram Bot',
            'timestamp' => date('c'),
            'environment' => getenv('RENDER') ? 'Render' : 'Local'
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}

function processUpdate($update) {
    try {
        // Log the update
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Received update: " . json_encode($update) . "\n", FILE_APPEND);
        
        // Extract chat ID and message text
        $chatId = $update['message']['chat']['id'] ?? null;
        $text = $update['message']['text'] ?? '';
        $messageId = $update['message']['message_id'] ?? null;
        
        if (!$chatId) {
            return;
        }
        
        // Handle commands
        if (strpos($text, '/start') === 0) {
            sendMessage($chatId, "👋 Send me an Instagram Reels link and I'll download it for you!");
            logUser($chatId, $update['message']['chat']);
        } else if (strpos($text, '/help') === 0) {
            sendMessage($chatId, "🤖 Instagram Reels Downloader Bot\n\nSimply send me any Instagram Reels link and I'll download it for you!\n\nNote: Make sure the reel is public and accessible.");
        } else if (filter_var($text, FILTER_VALIDATE_URL) && strpos($text, 'instagram.com') !== false) {
            // Handle Instagram URL
            handleInstagramUrl($chatId, $text);
        } else if ($text) {
            sendMessage($chatId, "❌ Please send a valid Instagram Reels link.");
        }
        
    } catch (Exception $e) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Error processing update: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

function handleInstagramUrl($chatId, $url) {
    try {
        // Send "processing" message
        sendMessage($chatId, "📥 Downloading your reel, please wait...");
        
        // Create download directory
        $downloadDir = sys_get_temp_dir() . '/insta_reels_bot';
        if (!is_dir($downloadDir)) {
            mkdir($downloadDir, 0777, true);
        }
        
        // Generate unique filename
        $filename = $downloadDir . '/reel_' . md5($url . time()) . '.mp4';
        
        // Download using yt-dlp - simplified command
        $command = "yt-dlp -f mp4 -o " . escapeshellarg($filename) . " " . escapeshellarg($url) . " 2>&1";
        
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Executing: " . $command . "\n", FILE_APPEND);
        
        $output = shell_exec($command);
        
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Output: " . $output . "\n", FILE_APPEND);
        
        // Check if file was created
        if (file_exists($filename) && filesize($filename) > 10000) { // At least 10KB
            $fileSize = filesize($filename);
            file_put_contents('error.log', date('Y-m-d H:i:s') . " - Success: " . $filename . " (" . round($fileSize/1024/1024, 2) . "MB)\n", FILE_APPEND);
            
            // Check Telegram file size limit (50MB)
            if ($fileSize > 50 * 1024 * 1024) {
                sendMessage($chatId, "❌ Video is too large (" . round($fileSize/1024/1024, 2) . "MB). Telegram limit is 50MB.");
                unlink($filename);
                return;
            }
            
            // Send video
            sendVideo($chatId, $filename);
            unlink($filename);
            
        } else {
            sendMessage($chatId, "❌ Download failed. Possible reasons:\n• Invalid/private reel\n• Network issue\n• Try again later");
            file_put_contents('error.log', date('Y-m-d H:i:s') . " - Download failed\n", FILE_APPEND);
            
            if (file_exists($filename)) {
                unlink($filename);
            }
        }
        
    } catch (Exception $e) {
        sendMessage($chatId, "⚠️ Server error. Please try again.");
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

function sendMessage($chatId, $text) {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - BOT_TOKEN not set\n", FILE_APPEND);
        return;
    }
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Failed to send message: " . $response . "\n", FILE_APPEND);
    }
}

function sendVideo($chatId, $videoPath) {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken) {
        return;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/sendVideo");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'chat_id' => $chatId,
        'video' => new CURLFile($videoPath),
        'supports_streaming' => true
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Failed to send video: " . $response . "\n", FILE_APPEND);
    }
}

function setupWebhook() {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken) {
        echo json_encode(['status' => 'error', 'message' => 'BOT_TOKEN not set']);
        return;
    }
    
    // Get current URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $webhookUrl = $protocol . '://' . $host . '/';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/setWebhook");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'url' => $webhookUrl
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['ok']) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Webhook set successfully',
            'webhook_url' => $webhookUrl
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to set webhook',
            'error' => $result['description']
        ]);
    }
}

function removeWebhook() {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken) {
        echo json_encode(['status' => 'error', 'message' => 'BOT_TOKEN not set']);
        return;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/deleteWebhook");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, []);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['ok']) {
        echo json_encode(['status' => 'success', 'message' => 'Webhook removed successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to remove webhook']);
    }
}

function showBotInfo() {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken) {
        echo json_encode(['status' => 'error', 'message' => 'BOT_TOKEN not set']);
        return;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/getMe");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['ok']) {
        echo json_encode([
            'status' => 'success',
            'bot_info' => $result['result'],
            'environment' => getenv('RENDER') ? 'Render' : 'Local'
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to get bot info']);
    }
}

function logUser($chatId, $chatInfo) {
    $usersFile = 'users.json';
    $users = [];
    
    if (file_exists($usersFile)) {
        $users = json_decode(file_get_contents($usersFile), true) ?? [];
    }
    
    $users[$chatId] = [
        'first_name' => $chatInfo['first_name'] ?? '',
        'last_name' => $chatInfo['last_name'] ?? '',
        'username' => $chatInfo['username'] ?? '',
        'type' => $chatInfo['type'] ?? '',
        'first_seen' => date('Y-m-d H:i:s'),
        'last_active' => date('Y-m-d H:i:s')
    ];
    
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
    
    // Update user count for statistics
    $statsFile = 'stats.json';
    $stats = [
        'total_users' => count($users),
        'last_updated' => date('Y-m-d H:i:s')
    ];
    
    file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT));
}

// Handle shutdown
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error) {
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Fatal error: " . json_encode($error) . "\n", FILE_APPEND);
    }
});
?>