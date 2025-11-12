<?php
// Enable error reporting and display errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Set content type
header('Content-Type: text/plain');

// Simple error logging function
function log_error($message) {
    file_put_contents('error.log', date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

// Test basic functionality
log_error("=== Application Started ===");
log_error("PHP Version: " . PHP_VERSION);
log_error("Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

// Check if yt-dlp is available
$ytdlp_test = shell_exec('which yt-dlp');
log_error("yt-dlp available: " . ($ytdlp_test ? 'YES' : 'NO'));

// Check if ffmpeg is available  
$ffmpeg_test = shell_exec('which ffmpeg');
log_error("ffmpeg available: " . ($ffmpeg_test ? 'YES' : 'NO'));

// Get request method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST') {
        // Handle POST request (Telegram webhook)
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if ($data) {
            processUpdate($data);
            echo "OK - Update processed";
        } else {
            http_response_code(400);
            echo "ERROR - Invalid JSON";
        }
    } else {
        // Handle GET request
        if (isset($_GET['setwebhook'])) {
            setupWebhook();
        } elseif (isset($_GET['removewebhook'])) {
            removeWebhook();
        } elseif (isset($_GET['info'])) {
            showBotInfo();
        } else {
            // Health check
            echo "Telegram Bot Service - Online\n";
            echo "Time: " . date('Y-m-d H:i:s') . "\n";
            echo "Environment: " . (getenv('RENDER') ? 'Render' : 'Local') . "\n";
            
            // Check bot token
            $botToken = getenv('BOT_TOKEN');
            if ($botToken && $botToken !== 'your_bot_token_here') {
                echo "Bot Token: SET\n";
            } else {
                echo "Bot Token: NOT SET - Please set BOT_TOKEN environment variable\n";
            }
        }
    }
} catch (Exception $e) {
    log_error("Top level error: " . $e->getMessage());
    http_response_code(500);
    echo "Internal Server Error - Check logs";
}

function processUpdate($update) {
    log_error("Processing update");
    
    $chatId = $update['message']['chat']['id'] ?? null;
    $text = $update['message']['text'] ?? '';
    
    if (!$chatId) {
        log_error("No chat ID found");
        return;
    }
    
    log_error("Message from chat $chatId: $text");
    
    if (strpos($text, '/start') === 0) {
        sendMessage($chatId, "👋 Send me an Instagram Reels link and I'll download it for you!");
        logUser($chatId, $update['message']['chat']);
    } elseif (strpos($text, '/help') === 0) {
        sendMessage($chatId, "🤖 Instagram Reels Downloader Bot\n\nSimply send me any Instagram Reels link and I'll download it for you!\n\nNote: Make sure the reel is public and accessible.");
    } elseif (filter_var($text, FILTER_VALIDATE_URL) && strpos($text, 'instagram.com') !== false) {
        handleInstagramUrl($chatId, $text);
    } elseif ($text) {
        sendMessage($chatId, "❌ Please send a valid Instagram Reels link.");
    }
}

function handleInstagramUrl($chatId, $url) {
    log_error("Handling Instagram URL: $url");
    
    sendMessage($chatId, "📥 Downloading your reel, please wait...");
    
    $downloadDir = sys_get_temp_dir() . '/insta_reels_bot';
    if (!is_dir($downloadDir)) {
        mkdir($downloadDir, 0777, true);
    }
    
    $filename = $downloadDir . '/reel_' . md5($url . time()) . '.mp4';
    
    // Download using yt-dlp
    $command = "yt-dlp -f 'best[ext=mp4]/best' -o " . escapeshellarg($filename) . " " . escapeshellarg($url) . " 2>&1";
    log_error("Executing: $command");
    
    $output = shell_exec($command);
    log_error("Download output: $output");
    
    if (file_exists($filename) && filesize($filename) > 10000) {
        $fileSize = filesize($filename);
        log_error("Download successful: " . round($fileSize/1024/1024, 2) . "MB");
        
        if ($fileSize > 50 * 1024 * 1024) {
            sendMessage($chatId, "❌ Video is too large (" . round($fileSize/1024/1024, 2) . "MB). Telegram limit is 50MB.");
            unlink($filename);
            return;
        }
        
        sendVideo($chatId, $filename);
        unlink($filename);
        log_error("Video sent successfully");
    } else {
        sendMessage($chatId, "❌ Download failed. Please check:\n• Link is correct\n• Reel is public\n• Try again later");
        log_error("Download failed - file doesn't exist or too small");
        
        if (file_exists($filename)) {
            unlink($filename);
        }
    }
}

function sendMessage($chatId, $text) {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken || $botToken === 'your_bot_token_here') {
        log_error("BOT_TOKEN not properly set");
        return;
    }
    
    $data = [
        'chat_id' => $chatId,
        'text' => $text
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        log_error("Failed to send message. HTTP: $httpCode, Response: $response");
    }
}

function sendVideo($chatId, $videoPath) {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken || $botToken === 'your_bot_token_here') {
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
        log_error("Failed to send video. HTTP: $httpCode");
    }
}

function setupWebhook() {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken || $botToken === 'your_bot_token_here') {
        echo "ERROR: BOT_TOKEN not set\n";
        return;
    }
    
    $protocol = isset($_SERVER['HTTPS']) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $webhookUrl = $protocol . '://' . $host . '/';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$botToken}/setWebhook");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['url' => $webhookUrl]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['ok']) {
        echo "SUCCESS: Webhook set to: $webhookUrl\n";
    } else {
        echo "ERROR: Failed to set webhook: " . ($result['description'] ?? 'Unknown error') . "\n";
    }
}

function removeWebhook() {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken || $botToken === 'your_bot_token_here') {
        echo "ERROR: BOT_TOKEN not set\n";
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
        echo "SUCCESS: Webhook removed\n";
    } else {
        echo "ERROR: Failed to remove webhook\n";
    }
}

function showBotInfo() {
    $botToken = getenv('BOT_TOKEN');
    if (!$botToken || $botToken === 'your_bot_token_here') {
        echo "ERROR: BOT_TOKEN not set\n";
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
        echo "Bot Info:\n";
        echo "Name: " . $result['result']['first_name'] . "\n";
        echo "Username: @" . $result['result']['username'] . "\n";
        echo "ID: " . $result['result']['id'] . "\n";
    } else {
        echo "ERROR: Failed to get bot info\n";
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
        'username' => $chatInfo['username'] ?? '',
        'first_seen' => date('Y-m-d H:i:s')
    ];
    
    file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}
?>