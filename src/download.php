<?php
require __DIR__ . '/../vendor/autoload.php';  // Подключение Composer autoload (если используются библиотеки)

use GuzzleHttp\Client;

// Папка для сохранения медиафайлов
$output_folder = "downloaded_media";
if (!file_exists($output_folder)) {
    mkdir($output_folder, 0777, true);
}

// Функция для скачивания файла по URL с сохранением иерархии папок
function download_file($url, $base_folder) {
    $client = new Client();
    try {
        $response = $client->get($url, ['stream' => true]);
        if ($response->getStatusCode() == 200) {
            // Извлекаем путь из URL
            $parsed_url = parse_url($url);
            $path_parts = explode('/', trim($parsed_url['path'], '/'));
            
            // Определяем путь для сохранения файла
            $save_folder = $base_folder . '/' . implode('/', array_slice($path_parts, 0, -1));
            if (!file_exists($save_folder)) {
                mkdir($save_folder, 0777, true);
            }
            
            // Имя файла
            $filename = end($path_parts);
            $filepath = $save_folder . '/' . $filename;
            
            // Сохраняем файл
            file_put_contents($filepath, $response->getBody());
            echo "Скачан файл: $filepath\n";
        } else {
            echo "Ошибка при скачивании $url: статус " . $response->getStatusCode() . "\n";
        }
    } catch (Exception $e) {
        echo "Ошибка при скачивании $url: " . $e->getMessage() . "\n";
    }
}

// Функция для обработки CSV-файла
function process_csv($file_path, $media_columns, $base_folder) {
    if (!file_exists($file_path)) {
        echo "Файл $file_path не найден.\n";
        return;
    }
    $file = fopen($file_path, 'r');
    $headers = fgetcsv($file);
    while (($row = fgetcsv($file)) !== false) {
        $row_data = array_combine($headers, $row);
        foreach ($media_columns as $column) {
            if (isset($row_data[$column]) && $row_data[$column]) {
                $urls = explode(',', $row_data[$column]);
                foreach ($urls as $url) {
                    if (trim($url)) {
                        download_file(trim($url), $base_folder);
                    }
                }
            }
        }
    }
    fclose($file);
}

// Обработка файла photo.csv
$photo_folder = $output_folder . "/photos";
process_csv("photo.csv", ["previewImage", "images"], $photo_folder);

// Обработка файла news.csv
$news_folder = $output_folder . "/news";
process_csv("news.csv", ["previewImage", "images"], $news_folder);

echo "Скачивание медиаконтента завершено.\n";
?>