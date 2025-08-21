<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL);
ini_set('display_errors', '0');

try {
  $env = __DIR__.'/.env.php';
  if (!file_exists($env)) throw new Exception('.env.php가 /api 폴더에 없음');
  require_once $env;
  if (!defined('DATAGO_SERVICE_KEY') || !DATAGO_SERVICE_KEY) {
    throw new Exception('DATAGO_SERVICE_KEY가 비어 있음');
  }
  if (!extension_loaded('curl')) throw new Exception('PHP cURL 확장 미활성');

  $ym   = isset($_GET['ym']) ? preg_replace('/\D/','', $_GET['ym']) : '';
  $lawd = isset($_GET['lawd_cd']) ? preg_replace('/\D/','', $_GET['lawd_cd']) : '';
  if (strlen($ym) !== 6 || strlen($lawd) < 5) throw new Exception('ym(YYYYMM), lawd_cd 파라미터 확인');

  $base = 'https://apis.data.go.kr/1613000/RTMSDataSvcAptTrade/getRTMSDataSvcAptTrade';

  $items = [];
  $page = 1; $guard = 0;

  while ($guard++ < 50) {
    // type, _type 둘 다 시도 (서비스마다 차이)
    $tried = false; $ok = false; $data = null;

    foreach ( [ ['type'=>'json'], ['_type'=>'json'] ] as $extra ) {
      $qs = http_build_query(array_merge([
        'serviceKey' => DATAGO_SERVICE_KEY,
        'LAWD_CD'    => $lawd,
        'DEAL_YMD'   => $ym,
        'pageNo'     => $page,
        'numOfRows'  => 1000
      ], $extra), '', '&', PHP_QUERY_RFC3986);

      $raw = http_get($base.'?'.$qs);
      $data = parse_response($raw);
      $tried = true;
      if (!empty($data['response']['body'])) { $ok = true; break; }
    }

    if (!$tried || !$ok) break;

    $body = $data['response']['body'];
    $rows = $body['items']['item'] ?? [];
    if (isset($rows['거래금액'])) $rows = [ $rows ];

    foreach ($rows as $r) {
      $items[] = [
        'apt'      => $r['아파트'] ?? '',
        'bjd'      => $r['법정동'] ?? '',
        'dealY'    => (int)($r['년'] ?? 0),
        'dealM'    => (int)($r['월'] ?? 0),
        'dealD'    => (int)($r['일'] ?? 0),
        'area'     => isset($r['전용면적']) ? (float)$r['전용면적'] : null,
        'floor'    => isset($r['층']) ? (int)$r['층'] : null,
        'buildY'   => isset($r['건축년도']) ? (int)$r['건축년도'] : null,
        'priceMan' => (int)preg_replace('/\D/','', (string)($r['거래금액'] ?? '0'))
      ];
    }

    $total = (int)($body['totalCount'] ?? 0);
    $num   = (int)($body['numOfRows']  ?? 0);
    if ($num <= 0 || count($items) >= $total) break;
    $page++;
  }

  echo json_encode(['status'=>'OK','items'=>$items], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status'=>'ERROR','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

function http_get(string $url): string {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 30
  ]);
  $out  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($out === false) throw new Exception('cURL 실패: '.$err);
  if ($code >= 400)    throw new Exception('HTTP '.$code.' from upstream');
  return $out;
}

function parse_response(string $raw): ?array {
  $json = json_decode($raw, true);
  if ($json !== null) return $json;
  libxml_use_internal_errors(true);
  $xml = simplexml_load_string($raw);
  if ($xml === false) return null;
  return json_decode(json_encode($xml), true);
}