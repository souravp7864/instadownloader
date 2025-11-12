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
        
        // Download using yt-dlp with better error handling
        $command = "yt-dlp -f 'best[ext=mp4]/best' -o " . escapeshellarg($filename) . " " . escapeshellarg($url) . " 2>&1";
        
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Executing command: " . $command . "\n", FILE_APPEND);
        
        $output = shell_exec($command);
        $exitCode = 0;
        
        if ($output === null) {
            $exitCode = 1;
        }
        
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Download output: " . $output . "\n", FILE_APPEND);
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Exit code: " . $exitCode . "\n", FILE_APPEND);
        
        // Check if file was created successfully
        if (file_exists($filename) && filesize($filename) > 1024) { // At least 1KB
            // Get file size
            $fileSize = filesize($filename);
            file_put_contents('error.log', date('Y-m-d H:i:s') . " - File downloaded successfully: " . $filename . " (" . $fileSize . " bytes)\n", FILE_APPEND);
            
            // Check if file size is within Telegram limits (50MB)
            if ($fileSize > 50 * 1024 * 1024) {
                sendMessage($chatId, "❌ The video is too large (over 50MB). Telegram cannot send files larger than 50MB.");
                unlink($filename);
                return;
            }
            
            // Send video to user
            sendVideo($chatId, $filename);
            
            // Clean up
            unlink($filename);
            file_put_contents('error.log', date('Y-m-d H:i:s') . " - Temporary file cleaned up: " . $filename . "\n", FILE_APPEND);
            
        } else {
            sendMessage($chatId, "❌ Failed to download the reel. Please make sure:\n- The link is correct\n- The reel is public\n- The reel is accessible\n- Try again later");
            file_put_contents('error.log', date('Y-m-d H:i:s') . " - Download failed, file not created or too small: " . $filename . "\n", FILE_APPEND);
            
            if (file_exists($filename)) {
                $size = filesize($filename);
                file_put_contents('error.log', date('Y-m-d H:i:s') . " - File exists but size is: " . $size . " bytes\n", FILE_APPEND);
                unlink($filename);
            }
        }
        
    } catch (Exception $e) {
        sendMessage($chatId, "⚠️ An error occurred while processing your request. Please try again later.");
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Error handling Instagram URL: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents('error.log', date('Y-m-d H:i:s') . " - Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    }
}