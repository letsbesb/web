<?php
// 최종 실행 코드

// 만약의 경우를 대비해 오류 표시 기능을 남겨둡니다.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 응답 헤더를 JSON으로 설정
header('Content-Type: application/json; charset=utf-8');

// --- 1. 환경 변수 로드 ---
$envFilePath = __DIR__ . '/.env.php';
if (!file_exists($envFilePath)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Configuration file (.env.php) is missing.']);
    exit;
}
$env = require $envFilePath;

if (!is_array($env) || !isset($env['API_KEY'])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Invalid configuration format in .env.php. It must return an array with an API_KEY.']);
    exit;
}
$apiKey = $env['API_KEY'];

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'API key is not set in .env.php.']);
    exit;
}

// --- 2. 요청 파라미터 확인 ---
$ym = $_GET['ym'] ?? '';
$lawd_cd = $_GET['lawd_cd'] ?? '';

if (empty($ym) || empty($lawd_cd)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters: ym, lawd_cd']);
    exit;
}

// --- 3. API 호출 URL 생성 ---
$url = "http://openapi.molit.go.kr/OpenAPI_ToolInstallPackage/service/rest/RTMSOBJSvc/getRTMSDataSvcAptTradeDev";
$queryParams = http_build_query([
    'serviceKey' => $apiKey,
    'pageNo'     => '1',
    'numOfRows'  => '100',
    'LAWD_CD'    => $lawd_cd,
    'DEAL_YMD'   => $ym
]);
$requestUrl = "{$url}?{$queryParams}";

// --- 4. cURL을 이용한 API 호출 ---
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $requestUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FAILONERROR, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10초 이상 응답 없으면 타임아웃
$response = curl_exec($ch);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'API request failed: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}
curl_close($ch);

// --- 5. XML 응답 파싱 및 JSON 변환 ---
libxml_use_internal_errors(true);
$xml = simplexml_load_string($response);
$items = [];

if ($xml === false) {
    http_response_code(500);
    // 응답 내용이 비어있거나 XML이 아닌 경우를 확인하기 위해 원본 응답을 일부 보여줍니다.
    echo json_encode(['status' => 'error', 'message' => 'Failed to parse XML response.', 'response_body' => substr($response, 0, 500)]);
    exit;
}

$resultCode = (string)$xml->header->resultCode;
if ($resultCode !== '00') {
    $resultMsg = (string)$xml->header->resultMsg;
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "API Error: {$resultMsg} (Code: {$resultCode})"]);
    exit;
}

if (isset($xml->body->items->item)) {
    foreach ($xml->body->items->item as $item) {
        $price = (int)str_replace(',', '', trim((string)$item->거래금액));
        $items[] = [
            'apt'      => trim((string)$item->아파트),
            'bjd'      => trim((string)$item->법정동),
            'dealY'    => (int)$item->년,
            'dealM'    => (int)$item->월,
            'dealD'    => (int)$item->일,
            'area'     => (float)$item->전용면적,
            'floor'    => (int)$item->층,
            'buildY'   => (int)$item->건축년도,
            'priceMan' => $price
        ];
    }
}

// --- 6. 최종 결과 출력 ---
echo json_encode(['status' => 'OK', 'items' => $items]);
?>