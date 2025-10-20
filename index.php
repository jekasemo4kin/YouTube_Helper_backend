<?php
//session_start();
//session_destroy(); // Эта строка уничтожит текущую сессию
session_start();
error_log("DEBUG: Current session.save_path: " . session_save_path());


require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Google\Client; 
use Google\Service\Gmail; 
use GuzzleHttp\Client as GuzzleClient; 


$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$client = new Google_Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['REDIRECT_URI']);

// 3. Установка HTTP-клиента Guzzle с CA-сертификатами (РЕКОМЕНДУЕТСЯ ТАКЖЕ ЗДЕСЬ)
// Это поможет избежать SSL-проблем при начальном взаимодействии, если они возникнут
// Хотя здесь прямого вызова API Google нет, но это хорошая практика для консистентности
$httpClient = new GuzzleHttp\Client([
    'verify' => true // Использовать системный магазин CA
]);
$client->setHttpClient($httpClient);


// добавление скоупов
$client->addScope("https://www.googleapis.com/auth/youtube.force-ssl");
$client->addScope("https://www.googleapis.com/auth/userinfo.email");
$client->addScope("https://www.googleapis.com/auth/userinfo.profile");
$client->addScope("https://www.googleapis.com/auth/youtube.readonly"); // Для получения информации о видео
$client->addScope('https://www.googleapis.com/auth/gmail.send');
// Запрос оффлайн доступа, чтобы получить refresh_token
$client->setAccessType('offline'); 
//$client->setPrompt('consent');
// Если нужно принудительно получать refresh_token каждый раз для отладки (не для продакшна)


$auth_url = $client->createAuthUrl();

//error_log("DEBUG: index.php - Generated and stored SESSION state: " . $state);
error_log("DEBUG: index.php - чекаю auth_url ===  " . $auth_url);
session_write_close(); // Сохраняет и разблокирует сессию
header('Location: ' . $auth_url); // так сервер указывает браузеру, куда (url) нужно перейти
exit(); // Это необходимо, чтобы предотвратить 
// дальнейшую отправку содержимого PHP-скрипта, что может 
// привести к ошибкам "Headers already sent" или нежелательному 
// поведению, так как браузер уже получил инструкцию на перенаправление
?>