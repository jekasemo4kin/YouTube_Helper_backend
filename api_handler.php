<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/vendor/autoload.php';


header('Content-Type: application/json'); 
header("Access-Control-Allow-Origin: " . $_ENV['FRONTEND_REDIRECT_URL_BASE']); // Разрешаем CORS для вашего фронтенда. Разные порты - это разные источники. Без этого заголовка брауз блокировал бы все AJAX запросы от фронта к бэку 
header("Access-Control-Allow-Methods: POST, GET, OPTIONS"); // Разрешаем методы, которые будет использовать фронтенд
header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization"); // Разрешаем необходимые заголовки
header("Access-Control-Allow-Credentials: true"); // Разрешаем отправку куков (сессий), важно для авторизации через сессию

// Обработка OPTIONS-запросов (CORS preflight requests)
// Браузер отправляет OPTIONS-запрос перед реальным POST-запросом, чтобы проверить разрешения
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(); // Завершаем выполнение скрипта после отправки заголовков OPTIONS
}
$response = ['success' => false, 'message' => 'An unknown error occurred.']; // Инициализация ответа

// Инициализация Google Client
$client = new Google_Client();
$client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
$client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);

// Установка HTTP-клиента Guzzle с CA-сертификатами
$httpClient = new GuzzleHttp\Client([
    'verify' => true // Использовать системный магазин CA
]);
$client->setHttpClient($httpClient);

// Установка необходимых областей видимости (scopes)
// Они должны быть согласованы с теми, что вы запрашивали при авторизации
$client->addScope("https://www.googleapis.com/auth/youtube.force-ssl");
$client->addScope("https://www.googleapis.com/auth/userinfo.email");
$client->addScope("https://www.googleapis.com/auth/userinfo.profile");
$client->addScope("https://www.googleapis.com/auth/youtube.readonly"); // Для получения информации о видео

/**
 * Функция для проверки и обновления Access Token.
 * Если токен истек, пытается обновить его с помощью Refresh Token.
 * Если Refresh Token отсутствует или недействителен, перенаправляет на страницу авторизации.
 *
 * @param Google_Client $client Объект Google Client.
 * @return bool Возвращает true, если Access Token валиден или успешно обновлен, false в случае ошибки.
 */
function ensureAccessTokenIsValid(Google_Client $client): bool
{
    if (!isset($_SESSION['google_access_token']) || empty($_SESSION['google_access_token'])) {
        error_log("DEBUG: ensureAccessTokenIsValid - No access token found in session.");
        // Возвращаем false, App.php отправит 401
        return false;
    }

    $client->setAccessToken($_SESSION['google_access_token']);

    if ($client->isAccessTokenExpired()) { // проверяет, истек ли срок действия текущего Access Token. Истёк срок жизни ?
        error_log("DEBUG: ensureAccessTokenIsValid - Access token expired. Attempting to refresh.");   // ДА
        if (isset($_SESSION['google_access_token']['refresh_token'])) { // проверяет, если рефреш токен
            try {
                $client->fetchAccessTokenWithRefreshToken($_SESSION['google_access_token']['refresh_token']); // обновляем Access Token рефреш токеном
                $_SESSION['google_access_token'] = $client->getAccessToken(); // заносим аксес токен в сессию, шоб можно было изи до него достучаться где-либо
                error_log("DEBUG: ensureAccessTokenIsValid - Access token successfully refreshed.");
                return true;
            } catch (Exception $e) {
                error_log("ERROR: ensureAccessTokenIsValid - Failed to refresh access token: " . $e->getMessage());
                unset($_SESSION['google_access_token']);
                return false; // Ошибка обновления, отправит 401
            }
        } else {
            error_log("DEBUG: ensureAccessTokenIsValid - Access token expired, but no refresh token found.");
            unset($_SESSION['google_access_token']);
            return false; // Нет refresh токена, A отправит 401
        }
    }
    error_log("DEBUG: ensureAccessTokenIsValid - Access token is valid.");
    return true; // Access Token валиден
}

// Попытка обеспечить валидность Access Token
if (!ensureAccessTokenIsValid($client)) { // токен не валидный(скорее и, а не или, тк в if-е будет выполнен процесс обновления аксес 
// токена, а значит if пустит дальше даже при старом аксес токене, но нормальном рефреш токене) или не авторизован?
    $response['message'] = 'Authentication required. Please log in again.';
    http_response_code(401); // Unauthorized
    echo json_encode($response);
    session_write_close();
    exit();
}

// Теперь $client гарантированно имеет валидный Access Token
$youtubeService = new Google_Service_YouTube($client);
error_log("DEBUG: Client is ready for YouTube API calls.");

// Чтение входных данных из POST-запроса (ожидаем JSON)
$input = json_decode(file_get_contents('php://input'), true); // file_get_contents читает все необработанные данные из php://input (то есть, из тела входящего HTTP-запроса) и возвращает их как одну большую строку.
// 1 арг функции json_decode - это строка JSON, которую мы получили из file_get_contents('php://input'), второй арг говорит декодировать объект json в ассоциативный массив.

if (!is_array($input)) { // если не массив, то пустит в тело, а значит JSON пришедший с фронта поломанный
    $response['message'] = 'Invalid JSON input.';
    http_response_code(400); // Bad Request
    echo json_encode($response); // Массив $response (теперь содержащий ошибку) кодируется в JSON-строку и отправляется обратно фронтенду
    session_write_close();
    exit();
}

$action = $input['action'] ?? null; // Например, 'likeVideo'
$videoUrl = $input['videoUrl'] ?? null; // URL видео
$videoId = $input['videoId'] ?? null; // Добавляем videoId в качестве отдельного параметра для actions

if ($videoUrl) {// Если videoUrl предоставлен, извлекаем videoId
    // Извлечение ID видео из URL
    // Улучшенная регулярка для различных форматов YouTube URL, работает с шортс
    if (preg_match('/(?:youtube\.com\/(?:[^\/\n\s]+\/\S+\/|(?:v|e(?:mbed)?)\/|\S*?[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $videoUrl, $matches)) { // функция выполняющая поиск 
    // совпадений по регулярному выражению. сперва регулярка, затем строка (где ищем), затем необязательный массив, который 
    // будет заполнен всеми найденными совпадениями
    
        $videoId = $matches[1];
    } else {
        $response['message'] = 'Invalid YouTube video URL provided.';
        http_response_code(400);
        echo json_encode($response);
        session_write_close();
        exit();
    }
}


if (!$action) {
    $response['message'] = 'Action not specified.';
    http_response_code(400);
    echo json_encode($response);
    session_write_close();
    exit();
}

try {
    switch ($action) {
        case 'likeVideo':
            if (!$videoId) {
                $response['message'] = 'Video ID is missing for like operation.';
                http_response_code(400);
                break; // Выход из switch, чтобы далее сработало echo json_encode($response);
            }

            // 1. Лайк видео
            $youtubeService->videos->rate($videoId, 'like');
            $response['message'] = 'Video liked successfully.';

            // 2. Добавление комментария (рандомный из пула)
            $commentsPool = [
                "Отличное видео, продолжайте в том же духе!",
                "Очень полезный контент, спасибо!",
                "Супер! Лайк и подписка!",
                "Классный ролик, мне очень понравилось!",
                "Просто блестяще! 👍",
                "Спасибо за такой качественный контент!",
                "Узнал много нового, круто!",
                "Жду новых видео!"
            ];
            $randomComment = $commentsPool[array_rand($commentsPool)];

            $commentThread = new Google_Service_YouTube_CommentThread();
            $commentThreadSnippet = new Google_Service_YouTube_CommentThreadSnippet();
            $commentThreadSnippet->setVideoId($videoId);
            $topLevelComment = new Google_Service_YouTube_Comment();
            $topLevelCommentSnippet = new Google_Service_YouTube_CommentSnippet();
            $topLevelCommentSnippet->setTextOriginal($randomComment);
            $topLevelComment->setSnippet($topLevelCommentSnippet);
            $commentThreadSnippet->setTopLevelComment($topLevelComment);
            
            $commentThread->setSnippet($commentThreadSnippet);

            $commentResponse = $youtubeService->commentThreads->insert('snippet', $commentThread);

            $commentId = $commentResponse->getSnippet()->getTopLevelComment()->getId(); // Получаем commentId
            $response['commentId'] = $commentId; // Добавляем ID комментария в ответ

            $response['commentText'] = $randomComment;
            $response['message'] .= ' Comment added successfully.';

            // 3. Получение информации о видео для фронтенда
            $videoInfo = $youtubeService->videos->listVideos('snippet,contentDetails', ['id' => $videoId]);
            if (!empty($videoInfo['items'])) {
                $item = $videoInfo['items'][0];
                $response['success'] = true;
                $response['videoData'] = [
                    'id' => $videoId,
                    'title' => $item['snippet']['title'],
                    // Выбираем самую большую доступную миниатюру
                    'thumbnail' => $item['snippet']['thumbnails']['maxres']['url'] ??
                                   $item['snippet']['thumbnails']['standard']['url'] ??
                                   $item['snippet']['thumbnails']['high']['url'] ??
                                   $item['snippet']['thumbnails']['medium']['url'] ??
                                   $item['snippet']['thumbnails']['default']['url'] ?? '',
                    'comment' => $randomComment, // Добавляем оставленный комментарий
                ];
            } else {
                $response['success'] = false;
                $response['message'] = 'Video liked and commented, but could not retrieve video information.';
                http_response_code(404); // Not Found for video info
            }

            break;

        case 'deleteVideoCard': // Новый экшен для удаления карточки
            if (!$videoId) {
                $response['message'] = 'Video ID is missing for delete operation.';
                http_response_code(400);
                break;
            }

            $commentIdToDelete = $input['commentId'] ?? null; // Получаем commentId из запроса

            // 1. Отмена лайка видео (unrate)
            try {
                $youtubeService->videos->rate($videoId, 'none'); // Устанавливаем рейтинг 'none'
                $response['message'] = 'Video unliked successfully.';
            } catch (Google_Service_Exception $e) {
                // Если видео уже не лайкнуто или ошибка API, просто логируем
                error_log("WARNING: Failed to unrate video ID " . $videoId . ": " . $e->getMessage());
                $response['message'] = 'Could not unrate video (may already be unrated).';
            }

            // 2. Удаление комментария (если commentId предоставлен)
            if ($commentIdToDelete) {
                try {
                    // Для удаления комментария используется метод comments->delete
                    $youtubeService->comments->delete($commentIdToDelete);
                    $response['message'] .= ' Comment deleted successfully.';
                } catch (Google_Service_Exception $e) {
                    error_log("WARNING: Failed to delete comment ID " . $commentIdToDelete . ": " . $e->getMessage());
                    $response['message'] .= ' Could not delete comment (may already be deleted or permission denied).';
                }
            } else {
                $response['message'] .= ' No comment ID provided for deletion.';
            }

            $response['success'] = true; // Считаем операцию успешной, даже если что-то не удалось удалить, но не было критических ошибок
            http_response_code(200); // OK
            break;

        case 'getVideoInfo': // Отдельный action для получения инфо о видео, если нужно без лайка/комментария
            if (!$videoId) {
                $response['message'] = 'Video ID is missing for getting video info.';
                http_response_code(400);
                break;
            }
            $videoInfo = $youtubeService->videos->listVideos('snippet,contentDetails', ['id' => $videoId]);
            if (!empty($videoInfo['items'])) {
                $item = $videoInfo['items'][0];
                $response['success'] = true;
                $response['message'] = 'Video information retrieved successfully.';
                $response['videoData'] = [
                    'id' => $videoId,
                    'title' => $item['snippet']['title'],
                    'thumbnail' => $item['snippet']['thumbnails']['maxres']['url'] ??
                                   $item['snippet']['thumbnails']['standard']['url'] ??
                                   $item['snippet']['thumbnails']['high']['url'] ??
                                   $item['snippet']['thumbnails']['medium']['url'] ??
                                   $item['snippet']['thumbnails']['default']['url'] ?? '',
                ];
            } else {
                $response['message'] = 'Video not found with the provided ID.';
                http_response_code(404);
            }
            break;

        default:
            $response['message'] = 'Unknown action specified.';
            http_response_code(400); // Bad Request
            break;
    }
} catch (Google_Service_Exception $e) {
    $errorMessage = 'YouTube API Error: ' . $e->getMessage();
    error_log("ERROR: " . $errorMessage . " (Code: " . $e->getCode() . ")");
    $response['message'] = $errorMessage;
    $response['code'] = $e->getCode();
    http_response_code(500); // Internal Server Error
} catch (Exception $e) {
    $errorMessage = 'Server Error: ' . $e->getMessage();
    error_log("ERROR: " . $errorMessage);
    $response['message'] = $errorMessage;
    http_response_code(500); // Internal Server Error
}

// Завершаем работу с сессией перед отправкой JSON-ответа
session_write_close();
echo json_encode($response);
exit();

?>