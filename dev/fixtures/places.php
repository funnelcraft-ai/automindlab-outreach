<?php
/**
 * Mock Google Places API (New) for local testing (dev only).
 * Run:  php -S 127.0.0.1:8090 dev/fixtures/places.php
 * Config: 'places_endpoint' => 'http://127.0.0.1:8090/', 'places_details_endpoint' => 'http://127.0.0.1:8090/places/'
 * Every search returns the same 9 Dallas businesses (page 1), then an empty page 2. Requests are logged to <tmp>/places_mock.log.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('~/places/(p\d+)$~', $path, $mm)) {
  $sites = ['p1'=>'http://coolair.test/','p2'=>'http://brightsmile.test/','p3'=>'http://quickfix.test/','p4'=>'http://nosolicit.test/','p5'=>'http://bounceplumb.test/','p9'=>'http://inject.test/'];
  file_put_contents(sys_get_temp_dir() . '/places_mock.log', json_encode(['details'=>$mm[1],'mask'=>$_SERVER['HTTP_X_GOOG_FIELDMASK'] ?? ''])."\n", FILE_APPEND);
  header('Content-Type: application/json'); echo json_encode(['id'=>$mm[1],'websiteUri'=>$sites[$mm[1]] ?? null,'businessStatus'=>'OPERATIONAL']); exit;
}
$in = json_decode(file_get_contents('php://input'), true);
file_put_contents(sys_get_temp_dir() . '/places_mock.log', json_encode(['q'=>$in, 'mask'=>$_SERVER['HTTP_X_GOOG_FIELDMASK'] ?? '', 'key'=>$_SERVER['HTTP_X_GOOG_API_KEY'] ?? '']) . "\n", FILE_APPEND);
$mk = function($id,$name,$site,$city,$st,$reviews,$rating=4.6,$status='OPERATIONAL') { return ['id'=>$id,'displayName'=>['text'=>$name],'formattedAddress'=>"1 Main St, $city, $st 75001, USA",
 'addressComponents'=>[['longText'=>$city,'shortText'=>$city,'types'=>['locality','political']],['longText'=>'Texas','shortText'=>$st,'types'=>['administrative_area_level_1','political']],['longText'=>'United States','shortText'=>'US','types'=>['country','political']]],
 'websiteUri'=>$site,'nationalPhoneNumber'=>'(214) 555-0101','rating'=>$rating,'userRatingCount'=>$reviews,'primaryTypeDisplayName'=>['text'=>'HVAC contractor'],'googleMapsUri'=>"https://maps.google.com/?cid=$id",'businessStatus'=>$status]; };
$places = [
 $mk('p1','CoolAir Heating & Air','http://coolair.test/','Dallas','TX',120),
 $mk('p2','BrightSmile Family Dentistry','http://brightsmile.test/','Dallas','TX',85),
 $mk('p3','QuickFix Auto','http://quickfix.test/','Dallas','TX',40),
 $mk('p4','No Solicit Plumbing','http://nosolicit.test/','Dallas','TX',30),
 $mk('p5','Bounce Plumbing','http://bounceplumb.test/','Dallas','TX',30),
 $mk('p6','Big Franchise HQ','https://www.facebook.com/bigfranchise','Dallas','TX',50),
 $mk('p7','Too Big Corp','http://toobig.test/','Dallas','TX',5000),
 $mk('p9','Inject Roofing','http://inject.test/','Dallas','TX',60),
 $mk('p8','Closed Co','http://closed.test/','Dallas','TX',20,4.0,'CLOSED_PERMANENTLY'),
];
header('Content-Type: application/json');
echo json_encode(empty($in['pageToken']) ? ['places'=>$places,'nextPageToken'=>'tok2'] : ['places'=>[]]);
