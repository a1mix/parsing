<?php
require __DIR__ . '/../vendor/autoload.php'; // Подключение Composer autoload (если используются библиотеки)

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;

// Настройки CSV-файлов
$csv_files = [
    'photo' => 'photo.csv',
    'video' => 'video.csv',
    'news' => 'news.csv'
];

// Заголовки для CSV-файлов
$headers = [
    'photo' => ['shortTitle', 'previewText', 'previewImage', 'tags', 'gameId', 'playerId', 'staffId', 'datePublication', 'fullTitle', 'images'],
    'video' => ['shortTitle', 'previewText', 'previewImage', 'tags', 'gameId', 'playerId', 'staffId', 'datePublication', 'fullTitle', 'link'],
    'news' => ['shortTitle', 'previewText', 'previewImage', 'tags', 'gameId', 'playerId', 'staffId', 'datePublication', 'fullTitle', 'text', 'images']
];

// Создание CSV-файлов с заголовками
foreach ($csv_files as $key => $file) {
    $fp = fopen($file, 'w');
    fputcsv($fp, $headers[$key]);
    fclose($fp);
}

// Словарь для перевода месяцев с русского на английский
$months_ru = [
    'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6,
    'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12
];

// Функция для преобразования даты из русского формата в DateTime
function parse_date($date_str) {
    global $months_ru;
    try {
        $date_str = trim($date_str);
        if (strpos($date_str, ' в ') !== false) {
            list($date_part, $time_part) = explode(' в ', $date_str);
        } else {
            $date_part = $date_str;
            $time_part = '00:00';
        }
        $day_month_year = explode(' ', $date_part);
        $day = (int)$day_month_year[0];
        $month_ru = $day_month_year[1];
        $year = (int)$day_month_year[2];
        $month = $months_ru[$month_ru];
        $time_obj = DateTime::createFromFormat('H:i', $time_part);
        return (new DateTime())->setDate($year, $month, $day)->setTime($time_obj->format('H'), $time_obj->format('i'));
    } catch (Exception $e) {
        echo "Ошибка при парсинге даты $date_str: " . $e->getMessage() . "\n";
        return null;
    }
}

// Функция для извлечения gameId из ссылки
function get_game_id($linked_post_urls) {
    $matches = [];
    foreach ($linked_post_urls as $url) {
        if (!$url) {
            continue;
        }
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        if(isset($params['match'])) $matches[] =  $params['match'];
    }
    return $matches ?? null;
}

function get_person_info($linked_post_urls) {
    $persons = [];

    foreach ($linked_post_urls as $url) {
        if (!$url) {
            continue;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $type = null;
        if (strpos($path, 'igrok') !== false) {
            $type = 'player';
        } elseif (strpos($path, 'trener') !== false) {
            $type = 'staff';
        }

        if ($type && isset($params['person'])) {
            $persons[] = [
                'type' => $type,
                'person' => $params['person']
            ];
        }
    }

    return $persons;
}
// Основной URL для парсинга
$base_url = "https://fcakron.ru/novosti/";

// Общее количество страниц
$total_pages = 362;

// Клиент для HTTP-запросов
$client = new Client(['verify' => __DIR__ . '/cacert.pem']);

// Проход по всем страницам
for ($page = 1; $page <= $total_pages; $page++) {
    echo "Парсинг страницы $page...\n";
    $url = $page > 1 ? "$base_url/page/$page" : $base_url;
    $response = $client->get($url);
    if ($response->getStatusCode() != 200) {
        echo "Ошибка при загрузке страницы $url: " . $response->getStatusCode() . "\n";
        continue;
    }

    $crawler = new Crawler($response->getBody()->getContents());
    $items = $crawler->filter('div.item');

    foreach ($items as $item) {
        $item_crawler = new Crawler($item);
        $short_title = $item_crawler->filter('strong.title')->text('');
        $preview_text = $item_crawler->filter('div.description')->text('');
        $preview_image = $item_crawler->filter('img.cover')->attr('src', '');
        $tags = $item_crawler->filter('ul.tag-cloud a.btn')->each(function (Crawler $node) {
            return $node->text();
        });
        $tags_str = implode(',', $tags);
        $csv_type = 'news';
        if (in_array('Фото', $tags)) {
            $csv_type = 'photo';
        } elseif (in_array('Видео', $tags)) {
            $csv_type = 'video';
        }
        $linked_posts = $item_crawler->filter('a.linked_post')->each(function (Crawler $node) {
            return $node->attr('href');
        });
        $game_ids = get_game_id($linked_posts);
        $game_id = implode(',', $game_ids);
        $persons_info = get_person_info($linked_posts);

        $staff_ids = [];
        $player_ids = [];

        foreach ($persons_info as $person) {
            if ($person['type'] == 'staff') {
                $staff_ids[] = $person['person'];
            } elseif ($person['type'] == 'player') {
                $player_ids[] = $person['person'];
            }
        }

        $staff_id = implode(',', $staff_ids);
        $player_id = implode(',', $player_ids);
        
        $date_span = $item_crawler->filter('span.date')->text('');
        $date_publication = parse_date($date_span);
        $date_str = $date_publication ? $date_publication->format('Y-m-d H:i:s') : '';
        $data_href = $item_crawler->attr('data-href', '');
        if (!$data_href) {
            continue;
        }
        $detail_url = urljoin($base_url, $data_href);
        $detail_response = $client->get($detail_url);
        if ($detail_response->getStatusCode() != 200) {
            echo "Ошибка при загрузке страницы $detail_url: " . $detail_response->getStatusCode() . "\n";
            continue;
        }
        $detail_crawler = new Crawler($detail_response->getBody()->getContents());
        $full_title = $detail_crawler->filter('section.news_header div.h2')->text('');
        $row_data = [];
        if ($csv_type == 'photo') {
            $images = $detail_crawler->filter('img.owl-lazy')->each(function (Crawler $node) {
                return $node->attr('data-src');
            });
            $row_data = [$short_title, $preview_text, $preview_image, $tags_str, $game_id, $player_id, $staff_id, $date_str, $full_title, implode(',', $images)];
        } elseif ($csv_type == 'video') {
            $iframe = $detail_crawler->filter('iframe')->attr('src', '');
            $row_data = [$short_title, $preview_text, $preview_image, $tags_str, $game_id, $player_id, $staff_id, $date_str, $full_title, $iframe];
        } else {
            $text = $detail_crawler->filter('main')->html('');
            $text = preg_replace('/\s+/', ' ', $text); // Заменяем множественные пробелы на один
            $text = preg_replace('/>\s+</', '><', $text); // Удаляем пробелы между тегами
            $text = trim($text); // Удаляем пробелы в начале и конце
            $images = $detail_crawler->filter('main img')->each(function (Crawler $node) {
                return $node->attr('data-src') ?? $node->attr('src');
            });
            $row_data = [$short_title, $preview_text, $preview_image, $tags_str, $game_id, $player_id, $staff_id,  $date_str, $full_title, $text, implode(',', $images)];
        }
        $fp = fopen($csv_files[$csv_type], 'a');
        fputcsv($fp, $row_data);
        fclose($fp);
    }
}

echo "Парсинг завершен. Данные сохранены в файлы: photo.csv, video.csv, news.csv\n";



function urljoin($base, $rel) {
    $pbase = parse_url($base);
    $prel = parse_url($rel);

    $merged = array_merge($pbase, $prel);
    if ($prel['path'][0] != '/') {
        // Relative path
        $dir = preg_replace('@/[^/]*$@', '', $pbase['path']);
        $merged['path'] = $dir . '/' . $prel['path'];
    }

    // Get the path components, and remove the initial empty one
    $pathParts = explode('/', $merged['path']);
    array_shift($pathParts);

    $path = [];
    $prevPart = '';
    foreach ($pathParts as $part) {
        if ($part == '..' && count($path) > 0) {
            // Cancel out the parent directory (if there's a parent to cancel)
            $parent = array_pop($path);
            // But if it was also a parent directory, leave it in
            if ($parent == '..') {
                array_push($path, $parent);
                array_push($path, $part);
            }
        } else if ($prevPart != '' || ($part != '.' && $part != '')) {
            // Don't include empty or current-directory components
            if ($part == '.') {
                $part = '';
            }
            array_push($path, $part);
        }
        $prevPart = $part;
    }
    $merged['path'] = '/' . implode('/', $path);

    $ret = '';
    if (isset($merged['scheme'])) {
        $ret .= $merged['scheme'] . ':';
    }

    if (isset($merged['scheme']) || isset($merged['host'])) {
        $ret .= '//';
    }

    if (isset($prel['host'])) {
        $hostSource = $prel;
    } else {
        $hostSource = $pbase;
    }

    // username, password, and port are associated with the hostname, not merged
    if (isset($hostSource['host'])) {
        if (isset($hostSource['user'])) {
            $ret .= $hostSource['user'];
            if (isset($hostSource['pass'])) {
                $ret .= ':' . $hostSource['pass'];
            }
            $ret .= '@';
        }
        $ret .= $hostSource['host'];
        if (isset($hostSource['port'])) {
            $ret .= ':' . $hostSource['port'];
        }
    }

    if (isset($merged['path'])) {
        $ret .= $merged['path'];
    }

    if (isset($prel['query'])) {
        $ret .= '?' . $prel['query'];
    }

    if (isset($prel['fragment'])) {
        $ret .= '#' . $prel['fragment'];
    }


    return $ret;
}