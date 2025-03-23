<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error_log.txt');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);
error_reporting(E_ALL);

require 'vendor/autoload.php';
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;

function transcribe($audio) {
    $apiUrl = 'https://api-inference.huggingface.co/models/openai/whisper-large-v3-turbo';
    $apiToken = 'REPLACE_WITH_HUGGINGFACE_API_KEY';

    $data = [
        'inputs' => new CURLFile($audio),
    ];

    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer $apiToken",
            'method' => 'POST',
            'content' => json_encode($data),
        ]
    ];

    $ch = curl_init();  
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiToken"
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
        error_log(curl_errno($ch) . "\n", 3, __DIR__ . '/log.txt');
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    $transcription = json_decode($response, true);
    if (!$transcription) {
        echo 'Error decoding response: ' . $response;
        return null;
    }

    if (isset($transcription['error'])) {
        echo 'Error from Hugging Face API: ' . $transcription['error'];
        return null;
    }

    return $transcription;
}

function summarizeGPT($text, $summaryLength) {

    switch ($summaryLength) {
        case 'Short':
            $maxLength = 100;
            break;
        case 'Medium':
            $maxLength = 200;
            break;
        case 'Long':
            $maxLength = 300;
            break;
        default:
            $maxLength = 200;
            break;
    }

    $content = "Summarize this text abstractively in $maxLength words (DO NOT SAY ANYTHING EXCEPT THE SUMMARY, THIS IS AN INTEGRATION IN A SOFTWARE WITH AN API) - $text";

    $apiUrl = 'https://models.inference.ai.azure.com/chat/completions';
    $apiToken = 'REPLACE_WITH_GITHUB_PERSONAL_ACCESS_TOKEN';

    log_error("Starting GPT-4o Summary - Prompt is $content");

    $data = [
        "messages" => [
            [
                "role" => "system",
                "content" => "DO NOT SAY ANYTHING EXCEPT THE SUMMARY, THIS IS AN INTEGRATION IN A SOFTWARE WITH AN API"
            ],
            [
                "role" => "user",
                "content" => $content
            ]
        ],
        "temperature" => 1.0,
        "top_p" => 1.0,
        "max_tokens" => 1000,
        "model" => "gpt-4o"
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $apiToken"
    ]);

    $response = curl_exec($ch);
    $jsonResponse = json_decode($response, true);

    error_log("Executed GPT-4o Summary - Response is $response");

    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
    } else {
        if (isset($jsonResponse['choices'][0]['message']['content'])) {
            echo $jsonResponse['choices'][0]['message']['content'];
        } else {
            echo 'No content found in response.';
        }
    }
    
    curl_close($ch);
}

function summarize($text, $summaryLength) {
    $apiUrl = 'https://api-inference.huggingface.co/models/facebook/bart-large-cnn';
    $apiToken = 'REPLACE_WITH_HUGGINGFACE_API_KEY';
    $maxRetries = 3;
    $retryDelay = 2;

    switch ($summaryLength) {
        case 'Short':
            $maxLength = 100;
            break;
        case 'Medium':
            $maxLength = 200;
            break;
        case 'Long':
            $maxLength = 300;
            break;
        default:
            $maxLength = 200;
            break;
    }

    $data = [
        'inputs' => $text,
        'parameters' => [
            'max_length' => $maxLength + 20,
            'min_length' => $maxLength - 50,
            'length_penalty' => 3.0,
            'num_beams' => 4,
            'early_stopping' => true
        ]
    ];

    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer $apiToken",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];

    $attempt = 0;
    $response = null;
    while ($attempt < $maxRetries) {
        $context = stream_context_create($options);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiToken",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);

        if ($response !== false) {
            break;
        }

        if (curl_errno($ch)) {
            $errorCode = curl_errno($ch);
            $errorMessage = curl_error($ch);
            error_log("cURL error ($errorCode): $errorMessage", 3, __DIR__ . '/log.txt');
        }
        curl_close($ch);
        $attempt++;
        sleep($retryDelay * $attempt);
    }

    if ($response === false) {
        exit('Error: API request failed after multiple retries.');
    }

    $summary = json_decode($response, true);

    if (isset($summary['error'])) {
        error_log("Hugging Face API Error: " . $summary['error'], 3, __DIR__ . '/log.txt');
        exit('Error: ' . $summary['error']);
    }

    if (isset($summary[0]['summary_text'])) {
        return $summary[0]['summary_text'];
    } else {
        exit('Error: Summary not returned by Hugging Face.');
    }
}

function log_error($message) {
    $logFile = __DIR__ . '/debug_log.txt';
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function debug_upload_error($error_code) {
    $error_messages = [
        UPLOAD_ERR_OK => 'No error.',
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
    ];

    return isset($error_messages[$error_code]) ? $error_messages[$error_code] : 'Unknown error.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['video']['name'])) {
    $target_dir = 'uploads/';
    $targetFile = $target_dir . basename($_FILES['video']['name']);
    $uploadOk = 1;
    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $allowedTypes = ['mp4', 'avi', 'mov', 'wmv', 'flv', '3gp', 'webm'];
    $summaryLength = $_POST['length'];

    $allowed_video_mime_types = [
        'video/mp4', 'video/x-msvideo', 'video/quicktime', 'video/x-ms-wmv',
        'video/x-flv', 'video/webm', 'video/ogg', 'video/3gpp', 'video/3gpp2'
    ];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $_FILES['video']['tmp_name']);
    finfo_close($finfo);
    
    if (in_array($mime_type, $allowed_video_mime_types)) {
        echo "Valid video file.";
    } else {
        echo "Invalid file type.";
        exit();
    }
    
    if (!in_array($fileType, $allowedTypes)) {
        $uploadOk = 0;
        log_error('Invalid file type: ' . $fileType);
        echo 'Sorry, only mp4, avi, mov, wmv, flv, 3gp, webm files are allowed.';
    }

    if ($_FILES['video']['size'] > 20000000) { 
        $uploadOk = 0;
        log_error('File too large: ' . $_FILES['video']['size']);
        echo 'Sorry, your file is too large. Please upload a video of a maximum size of 20MB.';
    }

    if ($_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        $uploadOk = 0;
        $error_message = debug_upload_error($_FILES['video']['error']);
        log_error('Upload error: ' . $error_message);
        echo 'Sorry, there was an error uploading your file. Error: ' . $error_message;
    }

    log_error('File name: ' . $_FILES['video']['name']);
    log_error('File type: ' . $_FILES['video']['type']);
    log_error('File size: ' . $_FILES['video']['size']);
    log_error('Temporary file: ' . $_FILES['video']['tmp_name']);
    log_error('File error: ' . $_FILES['video']['error']);

    if ($uploadOk && move_uploaded_file($_FILES['video']['tmp_name'], $targetFile)) {
        // echo 'Uploaded file successfully.';

        log_error("----------------------FILE UPLOADED SUCCESSFULLY: $targetFile----------------------");

        try {
            $moveFile = move_uploaded_file($_FILES['video']['tmp_name'], $targetFile);
        } catch (Exception $e) {
            echo 'An error occurred: ' . $e->getMessage();
            $uploadOk = 0;
        }
        
            $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => __DIR__ . '/ffmpeg/bin/ffmpeg',
            'ffprobe.binaries' => __DIR__ . '/ffmpeg/bin/ffprobe',
            'timeout' => 3600,
            'ffmpeg.threads' => 4,
        ]);

        $video = $ffmpeg->open($targetFile);
        try {
            $audio1 = new Mp3();
            $audio1->setAudioKiloBitrate(128);
            $audioPath = 'uploads/' . pathinfo($targetFile, PATHINFO_FILENAME) . '.mp3';
            $video->save($audio1, $audioPath);

            // echo 'Audio saved - SUCCESSFUL'; 

            $transcription = transcribe($audioPath);

            $summary = summarizeGPT($transcription['text'], $summaryLength);
            if ($summary) {
                $decodedSummary = json_decode($summary, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decodedSummary['choices'][0]['message']['content'])) {
                    echo $decodedSummary['choices'][0]['message']['content'];
                } else {
                    echo 'No valid content found in GPT response.';
                }
            } else {
                log_error('No summary obtained from GPT.');
            }
            
            unlink($targetFile);
            unlink($audioPath);

            if (isset($transcription['text'])) {
                // echo $transcription['text'];
            } else {
                echo print_r($transcription);
            }
        } catch (Exception $e) {
            echo 'An error occurred: ' . $e->getMessage();
        } 
    } else {
        log_error('Failed to move uploaded file to target directory.');
        echo 'Sorry, there was an error uploading your file.';
    }
}
?>