<?php
ini_set('session.save_path', '/tmp');
ini_set('session.cookie_secure', 1);
session_set_cookie_params([
    'samesite' => 'None',
    'secure' => true
]);
session_start(); // 1. Запуск сессии - всегда в начале!
// 2. Настройки отображения ошибок (ОБЯЗАТЕЛЬНО УДАЛИТЬ В ПРОДАКШЕНЕ!)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// тк этот файл (oauth2callback) указал в .env, то после авторизации гугл отправляет именно сюда
// в этом url гугл добавляет временный авториз код (code) 

require_once __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Oauth2;
use Google\Service\YouTube;
use GuzzleHttp\Client as GuzzleClient;


$client = new Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
$client->setRedirectUri($_ENV['REDIRECT_URI']);

// Установка HTTP-клиента Guzzle с CA-сертификатами
$httpClient = new GuzzleHttp\Client([
    'verify' => true // Использовать системный магазин CA
]);
$client->setHttpClient($httpClient);

$client->setAccessType('offline'); // Запрос оффлайн доступа для получения refresh_token

// Опционально: setPrompt('consent') для принудительного запроса согласия.
// Это полезно только для разработки, чтобы всегда получать refresh_token. В продакшене лучше убрать,
// чтобы не надоедать пользователю.
// $client->setPrompt('consent');

// Установка необходимых областей видимости (scopes)
$client->addScope("https://www.googleapis.com/auth/youtube.force-ssl");
$client->addScope("https://www.googleapis.com/auth/userinfo.email");
$client->addScope("https://www.googleapis.com/auth/userinfo.profile");
$client->addScope("https://www.googleapis.com/auth/youtube.readonly");
$client->addScope('https://www.googleapis.com/auth/gmail.send');



// Основная логика: обработка полученного кода авторизации
if (isset($_GET['code']) && !empty($_GET['code'])) { // проверка наличия code в url и выдирание его для обмена на токен далее
    try {
        error_log("DEBUG: oauth2callback.php - Attempting to fetch access token with code: " . $_GET['code']);
        // Обмениваем код авторизации на токены (access_token и refresh_token)
        // Библиотека Google_Client здесь также проверит 'state' автоматически
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        error_log("DEBUG: oauth2callback.php - Access token fetched successfully.");

        // Обработка ошибок, возвращаемых Google в токене
        // (хотя fetchAccessTokenWithAuthCode обычно выбрасывает исключение при ошибках)
        if (isset($token['error'])) {
            error_log("ERROR: OAuth error from Google: " . $token['error_description']);
            session_write_close();
            header('Location: ' . $_ENV['FRONTEND_REDIRECT_URL_BASE'] . '?error=oauth_error&error_description=' . urlencode($token['error_description']));
            exit();
        }

        // Устанавливаем полученный токен в клиент
        $client->setAccessToken($token);

        // Сохраняем ВЕСЬ объект токена в сессии.
        $_SESSION['google_access_token'] = $token;
        
        // Получаем информацию о пользователе и сохраняем в сессии
        $userName = 'Неизвестный пользователь';
        $userEmail = 'Неизвестный email';
        $userProfilePicture = '';
        try {
            $oauth2 = new Oauth2($client);
            $userInfo = $oauth2->userinfo->get();
            $userName = $userInfo->name ?? 'Неизвестный пользователь';
            $userEmail = $userInfo->email ?? 'Неизвестный email';
            $userProfilePicture = $userInfo->picture ?? ''; 
            error_log("DEBUG: oauth2callback.php - User info fetched: " . $userName);
        } catch (Exception $e) {
            error_log("ERROR: Ошибка при получении информации о пользователе: " . $e->getMessage());
        }

        // Также трай получить информацию о YouTube канале для картинки профиля
        try {
            $youtubeService = new YouTube($client);
            $channelsResponse = $youtubeService->channels->listChannels('snippet', array(
                'mine' => true,
            ));

            if (!empty($channelsResponse['items'])) {
                $channel = $channelsResponse['items'][0];
                $userProfilePicture = $channel['snippet']['thumbnails']['default']['url'] ?? $userProfilePicture;
                error_log("DEBUG: oauth2callback.php - YouTube channel info fetched. Profile picture URL: " . $userProfilePicture);
            }
        } catch (Google\Service\Exception $e) {
            error_log("ERROR: Ошибка при получении информации о YouTube канале: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("ERROR: Общая ошибка при получении информации о YouTube канале: " . $e->getMessage());
        }

        // Сохраняем информацию в сессии для последующего использования на бэкенде
        $_SESSION['user_name'] = $userName;
        $_SESSION['user_email'] = $userEmail;
        $_SESSION['user_profile_picture'] = $userProfilePicture;

        // Перенаправление на React-фронтенд после успешной авторизации.
        session_write_close();
        header('Location: ' . $_ENV['FRONTEND_REDIRECT_URL_BASE'] .
                       '?status=success' .
                       '&user_name=' . urlencode($userName) .
                       '&user_email=' . urlencode($userEmail) .
                       '&user_profile_picture=' . urlencode($userProfilePicture)
        );
        exit();

    } catch (Google\Service\Exception $e) {
        error_log("ERROR: Google Service Exception during token exchange or state validation: " . $e->getMessage());
        session_write_close();
        // Библиотека Google Client бросит исключение, если state не совпадает или если код недействителен
        header('Location: ' . $_ENV['FRONTEND_REDIRECT_URL_BASE'] . '?error=google_oauth_error&error_description=' . urlencode($e->getMessage()));
        exit();
    } catch (Exception $e) {
        error_log("ERROR: Общая ошибка при обмене кода на токены или другой критической ошибке: " . $e->getMessage());
        session_write_close();
        header('Location: ' . $_ENV['FRONTEND_REDIRECT_URL_BASE'] . '?error=server_error&error_description=' . urlencode($e->getMessage()));
        exit();
    }
} else {
    $error_msg = $_GET['error_description'] ?? $_GET['error'] ?? "Неизвестная ошибка авторизации.";
    error_log("ERROR: Authorization code not received or user denied: " . $error_msg);
    session_write_close();
    header('Location: ' . $_ENV['FRONTEND_REDIRECT_URL_BASE'] . '?error=auth_denied&error_description=' . urlencode($error_msg));
    exit();
}
?>