<?php
ini_set('session.save_path', '/tmp');
ini_set('session.cookie_secure', 1);
session_set_cookie_params([
    'samesite' => 'None',
    'secure' => true
]);
session_start(); // обеспечивает единую область видимости сессионных данных для одного пользователя на протяжении его взаимодействия с сайтом

ini_set('display_errors', 1); // Она указывает PHP выводить любые ошибки, предупреждения и уведомления непосредственно в вывод скрипта (т.е., прямо в браузер пользователя).
ini_set('display_startup_errors', 1); // Она указывает PHP выводить ошибки, которые происходят во время запуска скрипта (например, ошибки синтаксиса или проблемы с загрузкой расширений). Эти ошибки могут не отображаться при обычном display_errors.
error_reporting(E_ALL); // Эта функция PHP устанавливает уровень отчетов об ошибках.E_ALL: Это константа, которая указывает PHP сообщать обо всех возможных ошибках, предупреждениях и уведомлениях.


require_once __DIR__ . '/vendor/autoload.php'; //все классы из установленных зависимостей будут доступны "по требованию", уменьшает количество строк require .....


// Устанавливаем заголовки для обработки CORS-запросов от фронтенда
// Это важно, так как фронтенд будет делать запросы к этому бэкенду
// В продакшне заменить '*' на домен фронтенда (например, 'http://localhost:3000' или 'https://yourfrontend.com').
header("Access-Control-Allow-Origin: " . $_ENV['FRONTEND_REDIRECT_URL_BASE']);; // ДЛЯ ПРОДАКШЕНА НУЖНО УКАЗЫВАТЬ НЕ ЗВЁЗДОЧКУ * , а КОНКРЕТНЫЙ(Е) УРЛЫ, ИНАЧЕ ЭТО УЯЗВИМОСТЬ БЕЗОПАСНОСТИ
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Обработка OPTIONS-запросов (preflight requests) для CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { // чекается, options ли это запрос. Для разведочного запроса, перед отправлением трушных запросов
    session_write_close();
    http_response_code(200);
    exit(); // это функция PHP, которая завершает выполнение текущего скрипта. Завершение скрипта немедленно экономит ресурсы сервера, избегая ненужной обработки.
}

// 1. Проверяем, есть ли токены в сессии.
// Мы больше не передаем токен через GET-параметр для безопасности.
if (!isset($_SESSION['google_access_token'])) {  // возвращает true, если существует
    session_write_close();
    http_response_code(401); // Unauthorized
    exit(json_encode(["error" => "Пользователь не авторизован. Токен отсутствует в сессии."]));
}

// 2. Получаем ID видео из GET-параметра.
// В идеале для POST-запросов лучше использовать $_POST, но для простоты пока оставим GET.
if (!isset($_GET['videoId']) || empty($_GET['videoId'])) {  // $_GET - это суперглобальный массив PHP, который содержит все переменные, переданные скрипту через URL-строку (т.е. параметры запроса GET)
// empty() - это функция PHP, которая проверяет, пуста ли переменная. Она возвращает true, если переменная считается пустой
    session_write_close();    
    http_response_code(400); // Bad Request
    exit(json_encode(["error" => "Не передан ID видео."]));
}
$videoId = $_GET['videoId']; // если видос запрошен, то его Id вытягиваем из массива $_GET в переменную $videoId

// 3. Инициализируем Google_Client
$client = new Google_Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
// Redirect URI здесь не нужен для API-запросов, но он используется при обновлении токена.
$client->setRedirectUri($_ENV['REDIRECT_URI']); 

// Настройка cURL для Google_Client (для решения проблемы с SSL-сертификатом).
// Убедитесь, что 'cacert.pem' находится по указанному пути.
$httpClient = new GuzzleHttp\Client([
    'verify' => true // Использовать системный магазин CA
]);
$client->setHttpClient($httpClient);
// Устанавливаем токены из сессии.
// Важно: $token должен быть массивом (включая access_token, expires_in и refresh_token)
$token = $_SESSION['google_access_token'];
$client->setAccessToken($token);

// 4. Проверяем срок действия токена доступа и обновляем его, если он просрочен.
// Это критически важно, так как access_token действует всего около часа.
if ($client->isAccessTokenExpired()) {
    // Проверяем, есть ли refresh_token для обновления.
    if (!isset($token['refresh_token'])) {
        session_write_close(); // ХЗ - МОЖЕТ УДАЛИТЬ - ЧЕКНУТЬ
        http_response_code(401); // Unauthorized
        // Удаляем устаревший access_token из сессии, чтобы пользователь мог авторизоваться заново
        unset($_SESSION['google_access_token']); 
        exit(json_encode(["error" => "Access token просрочен и refresh token отсутствует. Пожалуйста, авторизуйтесь заново."]));
    }

    // Обновляем access_token с использованием refresh_token
    try {
        $refreshToken = $token['refresh_token'];
        $client->fetchAccessTokenWithRefreshToken($refreshToken);
        $newAccessToken = $client->getAccessToken();
        
        // Важно: Если refresh_token был перевыпущен (редко, но бывает), сохраняем его.
        // PHP-клиентская библиотека сама позаботится об этом.
        $_SESSION['google_access_token'] = $newAccessToken;
        
        // Устанавливаем новый токен в клиент
        $client->setAccessToken($newAccessToken); 

    } catch (Exception $e) {
        session_write_close(); // ХЗ - МОЖЕТ УДАЛИТЬ - ЧЕКНУТЬ
        http_response_code(401); // Unauthorized
        unset($_SESSION['google_access_token']); // Удаляем устаревший токен
        exit(json_encode(["error" => "Не удалось обновить access token: " . $e->getMessage() . ". Пожалуйста, авторизуйтесь заново."]));
    }
}

// 5. Создаем экземпляр сервиса YouTube.
$youtube = new Google_Service_YouTube($client);

try {
    // Пробуем поставить лайк.
    // 'like' - поставить лайк, 'dislike' - поставить дизлайк, 'none' - убрать лайк/дизлайк.
    $youtube->videos->rate($videoId, 'like');
    session_write_close(); // ХЗ - МОЖЕТ УДАЛИТЬ - ЧЕКНУТЬ

    // В случае успеха возвращаем JSON-ответ.
    http_response_code(200); // OK
    echo json_encode(["status" => "success", "message" => "✅ Видео успешно лайкнуто!"]);

} catch (Google_Service_Exception $e) {
    // Обработка ошибок, специфичных для Google API
    $errors = $e->getErrors();
    $firstError = $errors[0]['message'] ?? 'Неизвестная ошибка Google API';
    session_write_close(); // ХЗ - МОЖЕТ УДАЛИТЬ - ЧЕКНУТЬ
    http_response_code($e->getCode() ?: 500); // Используем HTTP-код ошибки от Google, если есть
    echo json_encode(["status" => "error", "message" => "❌ Ошибка Google API: " . $firstError]);
} catch (Exception $e) {
    session_write_close(); // ХЗ - МОЖЕТ УДАЛИТЬ - ЧЕКНУТЬ
    // Обработка любых других PHP-ошибок
    http_response_code(500); // Internal Server Error
    echo json_encode(["status" => "error", "message" => "❌ Произошла непредвиденная ошибка: " . $e->getMessage()]);
}
?>