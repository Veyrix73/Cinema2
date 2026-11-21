<?php
declare(strict_types=1);
session_name('dfc_sid');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require __DIR__ . '/config.php';

function out($data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $v=json_decode(file_get_contents('php://input'),true); return is_array($v)?$v:$_POST; }
function fail(string $message,int $status=400): never { out(['error'=>$message],$status); }
function uid(): string { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',random_int(0,65535),random_int(0,65535),random_int(0,65535),random_int(0,4095)|16384,random_int(0,16383)|32768,random_int(0,65535),random_int(0,65535),random_int(0,65535)); }
function db(): PDO {
 static $pdo=null; global $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS;
 if(!$pdo){$pdo=new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
 return $pdo;
}
function q(string $sql,array $args=[]): PDOStatement { $s=db()->prepare($sql);$s->execute($args);return $s; }
function admin(): void { if(empty($_SESSION['admin'])) fail('Administrator login required.',401); }
function customer(): void { if(empty($_SESSION['user'])) fail('Please sign in to your customer account.',401); }
function prices(): array { $p=['ticket'=>10,'custom'=>10,'office'=>20,'water'=>20,'popcorn'=>50];foreach(q('SELECT setting_key,value_cents FROM cinema_settings')->fetchAll() as $r)$p[$r['setting_key']]=(int)$r['value_cents']/100;return $p; }
function seatIds(): array { return ['BED-1','BED-2','BED-3','BED-4','BED-5','BED-6','CHAIR-L']; }
function initDb(): void {
 $sql=[
 "CREATE TABLE IF NOT EXISTS admin_users(id CHAR(36) PRIMARY KEY,username VARCHAR(80) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS customer_users(id CHAR(36) PRIMARY KEY,full_name VARCHAR(70) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,avatar_emoji VARCHAR(24) NOT NULL DEFAULT '🍿',avatar_color VARCHAR(7) NOT NULL DEFAULT '#9b5d43',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS movies(id CHAR(36) PRIMARY KEY,title VARCHAR(100) NOT NULL,genre VARCHAR(80) NOT NULL DEFAULT 'Cits',duration INT NOT NULL,age VARCHAR(12) NOT NULL DEFAULT 'U',year INT NOT NULL,emoji VARCHAR(24) NOT NULL DEFAULT '🎬',poster MEDIUMTEXT NOT NULL,description VARCHAR(1000) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS screenings(id CHAR(36) PRIMARY KEY,movie_id CHAR(36) NOT NULL,show_date DATE NOT NULL,show_time TIME NOT NULL,starts_at DATETIME NOT NULL,hall_name VARCHAR(80) NOT NULL DEFAULT '1. zāle',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_show(movie_id,show_date,show_time),FOREIGN KEY(movie_id) REFERENCES movies(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS bookings(id CHAR(36) PRIMARY KEY,booking_code VARCHAR(80) NOT NULL UNIQUE,screening_id CHAR(36) NOT NULL,user_id CHAR(36) NULL,customer_name VARCHAR(70) NOT NULL,customer_email VARCHAR(190) NOT NULL,ticket_count INT NOT NULL,custom_selection TINYINT(1) NOT NULL DEFAULT 0,water_count INT NOT NULL DEFAULT 0,popcorn_count INT NOT NULL DEFAULT 0,total_cents INT NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'confirmed',checked_in TINYINT(1) NOT NULL DEFAULT 0,checked_in_at DATETIME NULL,cancelled_at DATETIME NULL,cancelled_by VARCHAR(20) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(user_id),INDEX(screening_id),FOREIGN KEY(screening_id) REFERENCES screenings(id) ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS screening_seats(screening_id CHAR(36) NOT NULL,seat_number VARCHAR(20) NOT NULL,booking_id CHAR(36) NULL,office_chair TINYINT(1) NOT NULL DEFAULT 0,PRIMARY KEY(screening_id,seat_number),INDEX(booking_id),FOREIGN KEY(screening_id) REFERENCES screenings(id),FOREIGN KEY(booking_id) REFERENCES bookings(id) ON DELETE SET NULL ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS booking_seats(booking_id CHAR(36) NOT NULL,screening_id CHAR(36) NOT NULL,seat_number VARCHAR(20) NOT NULL,office_chair TINYINT(1) NOT NULL DEFAULT 0,PRIMARY KEY(booking_id,seat_number) ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS booking_passengers(booking_id CHAR(36) NOT NULL,seat_number VARCHAR(20) NOT NULL,passenger_name VARCHAR(70) NOT NULL,PRIMARY KEY(booking_id,seat_number) ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS cinema_settings(setting_key VARCHAR(30) PRIMARY KEY,value_cents INT NOT NULL ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
 ];foreach($sql as $s)db()->exec($s);
 foreach(['ticket'=>1000,'custom'=>1000,'office'=>2000,'water'=>2000,'popcorn'=>5000] as $k=>$v)q('INSERT IGNORE INTO cinema_settings(setting_key,value_cents) VALUES(?,?)',[$k,$v]);
 // Add the demo catalogue only on a brand-new database; never overwrite existing films.
 if((int)q('SELECT COUNT(*) FROM movies')->fetchColumn()===0){
  $defaults=[
   ["Ron's Gone Wrong",'Animācija',107,'7+',2021,'🤖','linear-gradient(135deg,#164e65,#172b46)','Sirsnīgs un jautrs piedzīvojums par zēnu un viņa neparasto robotu draugu.',2,['14:00','17:00','20:00']],
   ["The Queen's Corgi",'Komēdija',85,'7+',2019,'🐶','linear-gradient(135deg,#75502e,#a52c35)','Jautrs animācijas stāsts par karalisko korgiju un viņa piedzīvojumiem.',3,['15:00','18:00']],
   ['Augļu lielais piedzīvojums','Piedzīvojumi',96,'U',2026,'🍍','linear-gradient(135deg,#9b5623,#4c6a32)','Jautrs ģimenes piedzīvojums — Dancing Fruit Cinema demonstrācijas filma.',2,['15:00','18:00']]
  ];
  foreach($defaults as $d){$mid=uid();q('INSERT INTO movies(id,title,genre,duration,age,year,emoji,poster,description) VALUES(?,?,?,?,?,?,?,?,?)',[$mid,$d[0],$d[1],$d[2],$d[3],$d[4],$d[5],$d[6],$d[7]]);
   foreach($d[9] as $time){$date=date('Y-m-d',strtotime('+'.$d[8].' days'));$sid=uid();q("INSERT IGNORE INTO screenings(id,movie_id,show_date,show_time,starts_at,hall_name) VALUES(?,?,?,?,?,'1. zāle')",[$sid,$mid,$date,$time,$date.' '.$time.':00']);$screen=q('SELECT id FROM screenings WHERE movie_id=? AND show_date=? AND show_time=?',[$mid,$date,$time])->fetch();foreach(seatIds() as $seat)q('INSERT IGNORE INTO screening_seats(screening_id,seat_number,office_chair) VALUES(?,?,?)',[$screen['id'],$seat,$seat==='CHAIR-L'?1:0]);}
  }
 }
 // Repair the seven-seat layout for screenings already present in the database.
 foreach(q('SELECT id FROM screenings')->fetchAll(PDO::FETCH_COLUMN) as $sid)foreach(seatIds() as $seat)q('INSERT IGNORE INTO screening_seats(screening_id,seat_number,office_chair) VALUES(?,?,?)',[$sid,$seat,$seat==='CHAIR-L'?1:0]);

 $adminName=defined('ADMIN_USERNAME')?ADMIN_USERNAME:'admin';$adminPass=defined('ADMIN_PASSWORD')?ADMIN_PASSWORD:'';
 if(strlen($adminPass)>=12)q('INSERT IGNORE INTO admin_users(id,username,password_hash) VALUES(?,?,?)',[uid(),$adminName,password_hash($adminPass,PASSWORD_DEFAULT)]);
}
function cancelBooking(string $code,?string $email,bool $isAdmin): array {
 $pdo=db();$pdo->beginTransaction();try{
 $r=q('SELECT b.*,s.starts_at FROM bookings b JOIN screenings s ON s.id=b.screening_id WHERE UPPER(b.booking_code)=UPPER(?) FOR UPDATE',[$code])->fetch();
 if(!$r||(!$isAdmin&&strtolower($r['customer_email'])!==strtolower((string)$email))){$pdo->rollBack();return ['error'=>'Booking not found.','_status'=>404];}
 if($r['status']==='cancelled'){$pdo->rollBack();return ['error'=>'Booking already cancelled.','_status'=>409];}
 if(!$isAdmin&&$r['checked_in']){$pdo->rollBack();return ['error'=>'A checked-in booking cannot be cancelled by a customer.','_status'=>409];}
 if(!$isAdmin&&strtotime($r['starts_at'])-time()<86400){$pdo->rollBack();return ['error'=>'Customer cancellations close 24 hours before the screening.','_status'=>403];}
 q('UPDATE screening_seats SET booking_id=NULL,office_chair=(seat_number=?) WHERE booking_id=?',['CHAIR-L',$r['id']]);
 q("UPDATE bookings SET status='cancelled',cancelled_at=NOW(),cancelled_by=? WHERE id=?",[$isAdmin?'admin':'customer',$r['id']]);
 $pdo->commit();return ['ok'=>true];
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
try {
 initDb();
 $method=$_SERVER['REQUEST_METHOD'];$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$b=body();
 if($method==='GET'&&$path==='/api/health')out(['ok'=>true,'service'=>'Dancing Fruit Cinema PHP API']);
 if($method==='GET'&&$path==='/api/movies'){
 $movies=q("SELECT id,title,genre,duration,age,year,emoji,poster,description FROM movies WHERE active=1 ORDER BY created_at DESC")->fetchAll();
 foreach($movies as &$m){$m['duration']=(int)$m['duration'];$m['year']=(int)$m['year'];$m['screenings']=q("SELECT id,DATE_FORMAT(show_date,'%Y-%m-%d') AS date,DATE_FORMAT(show_time,'%H:%i') AS time,hall_name AS hall FROM screenings WHERE movie_id=? AND starts_at>NOW() ORDER BY starts_at",[$m['id']])->fetchAll();}out(['movies'=>$movies]);}
 if($method==='GET'&&$path==='/api/prices')out(['prices'=>prices()]);
 if($method==='GET'&&preg_match('#^/api/screenings/([^/]+)/seats$#',$path,$m)){
 $rows=q("SELECT ss.seat_number,IF(ss.booking_id IS NULL,0,1) AS occupied,ss.office_chair FROM screening_seats ss JOIN screenings s ON s.id=ss.screening_id JOIN movies m ON m.id=s.movie_id WHERE ss.screening_id=? AND m.active=1 ORDER BY ss.seat_number",[$m[1]])->fetchAll();
 if(!$rows)fail('Screening not found.',404);$n=count(array_filter($rows,fn($x)=>(int)$x['occupied']));out(['seats'=>$rows,'capacity'=>7,'occupiedCount'=>$n,'remainingCapacity'=>max(0,7-$n)]);}
 if($method==='POST'&&$path==='/api/account/register'){
 $name=trim($b['name']??'');$email=strtolower(trim($b['email']??''));$pass=$b['password']??'';
 if(strlen($name)<2||strlen($name)>70||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<8||strlen($pass)>100)fail('Enter a valid name and email. Password must be at least 8 characters.');
 if(q('SELECT id FROM customer_users WHERE email=?',[$email])->fetch())fail('An account with this email already exists. Sign in instead.',409);
 $id=uid();q('INSERT INTO customer_users(id,full_name,email,password_hash) VALUES(?,?,?,?)',[$id,$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);
 q('UPDATE bookings SET user_id=? WHERE LOWER(customer_email)=? AND user_id IS NULL',[$id,$email]);session_regenerate_id(true);
 $_SESSION['user']=['id'=>$id,'name'=>$name,'email'=>$email,'avatarEmoji'=>'🍿','avatarColor'=>'#9b5d43'];out(['ok'=>true,'user'=>$_SESSION['user']],201);}
 if($method==='POST'&&$path==='/api/account/login'){
 $email=strtolower(trim($b['email']??''));$r=q('SELECT * FROM customer_users WHERE email=?',[$email])->fetch();
 if(!$r||!password_verify($b['password']??'',$r['password_hash']))fail('Incorrect email or password.',401);
 session_regenerate_id(true);$_SESSION['user']=['id'=>$r['id'],'name'=>$r['full_name'],'email'=>$r['email'],'avatarEmoji'=>$r['avatar_emoji'],'avatarColor'=>$r['avatar_color']];out(['ok'=>true,'user'=>$_SESSION['user']]);}
 if($method==='GET'&&$path==='/api/account/me'){customer();$r=q('SELECT * FROM customer_users WHERE id=?',[$_SESSION['user']['id']])->fetch();if(!$r)fail('Account not found.',401);$_SESSION['user']=['id'=>$r['id'],'name'=>$r['full_name'],'email'=>$r['email'],'avatarEmoji'=>$r['avatar_emoji'],'avatarColor'=>$r['avatar_color']];out(['user'=>$_SESSION['user']]);}
 if($method==='PUT'&&$path==='/api/account/profile'){customer();$em=['🍿','🎬','🍓','🍉','🍒','🍍','🍊','⭐','🎟️','🐱'];$co=['#9b5d43','#b84a55','#c28b35','#438b78','#477da8','#8062a8','#777777'];$name=trim($b['name']??'');if(strlen($name)<2||strlen($name)>70||!in_array($b['avatarEmoji']??'',$em,true)||!in_array($b['avatarColor']??'',$co,true))fail('Check your display name, profile icon and colour.');
 q('UPDATE customer_users SET full_name=?,avatar_emoji=?,avatar_color=? WHERE id=?',[$name,$b['avatarEmoji'],$b['avatarColor'],$_SESSION['user']['id']]);$_SESSION['user']['name']=$name;$_SESSION['user']['avatarEmoji']=$b['avatarEmoji'];$_SESSION['user']['avatarColor']=$b['avatarColor'];out(['ok'=>true,'user'=>$_SESSION['user']]);}
 if($method==='POST'&&$path==='/api/account/logout'){customer();unset($_SESSION['user']);out(['ok'=>true]);}
 if($method==='GET'&&$path==='/api/account/bookings'){customer();$rows=q("SELECT b.*,DATE_FORMAT(s.show_date,'%Y-%m-%d') show_date,DATE_FORMAT(s.show_time,'%H:%i') show_time,s.starts_at,m.title movie_title FROM bookings b JOIN screenings s ON s.id=b.screening_id JOIN movies m ON m.id=s.movie_id WHERE b.user_id=? ORDER BY b.created_at DESC",[$_SESSION['user']['id']])->fetchAll();$out=[];foreach($rows as $r){$r['seats']=q('SELECT seat_number FROM booking_seats WHERE booking_id=?',[$r['id']])->fetchAll(PDO::FETCH_COLUMN);$r['passengers']=q('SELECT seat_number,name FROM (SELECT seat_number,passenger_name AS name FROM booking_passengers WHERE booking_id=?) p',[$r['id']])->fetchAll();$out[]=['code'=>$r['booking_code'],'name'=>$r['customer_name'],'email'=>$r['customer_email'],'count'=>(int)$r['ticket_count'],'total'=>$r['total_cents']/100,'status'=>$r['status'],'checkedIn'=>(bool)$r['checked_in'],'date'=>$r['show_date'],'time'=>$r['show_time'],'startsAt'=>$r['starts_at'],'movie'=>$r['movie_title'],'seats'=>$r['seats'],'passengers'=>$r['passengers']];}out(['bookings'=>$out]);}
 if($method==='POST'&&preg_match('#^/api/account/bookings/([^/]+)/cancel$#',$path,$m)){customer();$r=cancelBooking($m[1],$_SESSION['user']['email'],false);$s=$r['_status']??200;unset($r['_status']);out($r,$s);}
 if($method==='POST'&&$path==='/api/bookings/lookup'){
 $code=trim($b['bookingCode']??'');$email=strtolower(trim($b['customerEmail']??''));if(!$code||!$email)fail('Enter the booking code and email address.');
 $r=q("SELECT b.*,DATE_FORMAT(s.show_date,'%Y-%m-%d') show_date,DATE_FORMAT(s.show_time,'%H:%i') show_time,s.starts_at,m.title movie_title FROM bookings b JOIN screenings s ON s.id=b.screening_id JOIN movies m ON m.id=s.movie_id WHERE UPPER(b.booking_code)=UPPER(?) AND LOWER(b.customer_email)=?",[$code,$email])->fetch();if(!$r)fail('No booking found for that code and email.',404);
 $r['seats']=q('SELECT seat_number FROM booking_seats WHERE booking_id=?',[$r['id']])->fetchAll(PDO::FETCH_COLUMN);$r['officeSeats']=q('SELECT seat_number FROM booking_seats WHERE booking_id=? AND office_chair=1',[$r['id']])->fetchAll(PDO::FETCH_COLUMN);$r['passengers']=q('SELECT seat_number,passenger_name AS name FROM booking_passengers WHERE booking_id=?',[$r['id']])->fetchAll();out(['booking'=>['code'=>$r['booking_code'],'customerName'=>$r['customer_name'],'customerEmail'=>$r['customer_email'],'ticketCount'=>(int)$r['ticket_count'],'waterCount'=>(int)$r['water_count'],'popcornCount'=>(int)$r['popcorn_count'],'total'=>$r['total_cents']/100,'status'=>$r['status'],'date'=>$r['show_date'],'time'=>$r['show_time'],'startsAt'=>$r['starts_at'],'movieTitle'=>$r['movie_title'],'seats'=>$r['seats'],'officeSeats'=>$r['officeSeats'],'passengers'=>$r['passengers']]]);}
 if($method==='POST'&&preg_match('#^/api/bookings/([^/]+)/cancel$#',$path,$m)){if(empty($b['customerEmail']))fail('Enter the email address used for this booking.');$r=cancelBooking($m[1],$b['customerEmail'],false);$s=$r['_status']??200;unset($r['_status']);out($r,$s);}
 if($method==='POST'&&$path==='/api/bookings'){
 customer();$count=(int)($b['ticketCount']??0);$selected=$b['selectedSeats']??[];$office=$b['officeSeats']??[];$passengers=$b['passengers']??[];$custom=!empty($b['customSelection']);$water=(int)($b['waterCount']??0);$pop=(int)($b['popcornCount']??0);
 if($count<1||$count>7||$water<0||$water>20||$pop<0||$pop>20||count($passengers)!==$count)fail('Check your details. A booking can contain 1–7 tickets.');
 if($custom&&(count($selected)!==$count||count(array_unique($selected))!==$count||array_diff($selected,seatIds())))fail('Choose exactly the number of available seats in your booking.');
 if(!$custom&&(count($selected)||count($office)))fail('Custom seat details are not valid for automatic allocation.');
 if(array_diff($office,['CHAIR-L'])||($custom&&array_diff($office,$selected)))fail('Office chair upgrades must match selected seats.');
 $pdo=db();$pdo->beginTransaction();try{
 $scr=q('SELECT s.* FROM screenings s JOIN movies m ON m.id=s.movie_id WHERE s.id=? AND m.active=1 FOR UPDATE',[$b['screeningId']??''])->fetch();if(!$scr)throw new DomainException('Screening not found.');if(strtotime($scr['starts_at'])<=time())throw new DomainException('This screening has already started.');
 $occupied=(int)q('SELECT COUNT(*) FROM screening_seats WHERE screening_id=? AND booking_id IS NOT NULL',[$scr['id']])->fetchColumn();if($occupied+$count>7)throw new DomainException('This screening has reached its 7-seat capacity.');
 $available=q('SELECT seat_number FROM screening_seats WHERE screening_id=? AND booking_id IS NULL FOR UPDATE',[$scr['id']])->fetchAll(PDO::FETCH_COLUMN);$allocated=$custom?$selected:array_slice(array_values(array_intersect(seatIds(),$available)),0,$count);
 if(count($allocated)!==$count)throw new DomainException('One or more seats are no longer available. Refresh the seat map and try again.');
 $by=[];foreach($passengers as $p){if(empty($p['seat'])||empty(trim($p['name']??''))||strlen(trim($p['name']))>70)throw new DomainException('Enter a name for every seat.');$by[$p['seat']]=trim($p['name']);}
 if(count($by)!==$count||array_diff($allocated,array_keys($by))||array_diff(array_keys($by),$allocated))throw new DomainException('Seat assignments changed. Please review the guest names and try again.');
 $pr=prices();$officeAllocated=$custom?$office:array_values(array_intersect($allocated,['CHAIR-L']));$total=$count*$pr['ticket']+($custom?$pr['custom']:0)+count($officeAllocated)*$pr['office']+$water*$pr['water']+$pop*$pr['popcorn'];
 $id=uid();$code='Dncngfrt-'.strtoupper(substr(bin2hex(random_bytes(2)),0,3)).'-'.strtoupper(substr(bin2hex(random_bytes(2)),0,3)).'-'.strtoupper(substr(bin2hex(random_bytes(2)),0,3)).'-'.str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
 q('INSERT INTO bookings(id,booking_code,screening_id,user_id,customer_name,customer_email,ticket_count,custom_selection,water_count,popcorn_count,total_cents) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$code,$scr['id'],$_SESSION['user']['id'],$_SESSION['user']['name'],$_SESSION['user']['email'],$count,$custom?1:0,$water,$pop,(int)round($total*100)]);
 foreach($allocated as $seat){q('UPDATE screening_seats SET booking_id=?,office_chair=? WHERE screening_id=? AND seat_number=? AND booking_id IS NULL',[$id,in_array($seat,$officeAllocated,true)?1:0,$scr['id'],$seat]);if(q('SELECT booking_id FROM screening_seats WHERE screening_id=? AND seat_number=?',[$scr['id'],$seat])->fetchColumn()!==$id)throw new DomainException('A selected seat is no longer available.');q('INSERT INTO booking_seats VALUES(?,?,?,?)',[$id,$scr['id'],$seat,in_array($seat,$officeAllocated,true)?1:0]);q('INSERT INTO booking_passengers VALUES(?,?,?)',[$id,$seat,$by[$seat]]);}
 $pdo->commit();out(['booking'=>['code'=>$code,'customerName'=>$_SESSION['user']['name'],'customerEmail'=>$_SESSION['user']['email'],'screeningId'=>$scr['id'],'ticketCount'=>$count,'seats'=>$allocated,'passengers'=>array_map(fn($s)=>['seat'=>$s,'name'=>$by[$s]],$allocated),'officeSeats'=>$officeAllocated,'waterCount'=>$water,'popcornCount'=>$pop,'total'=>$total,'status'=>'confirmed']],201);
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof DomainException)fail($e->getMessage(),409);throw $e;}}
 if($method==='POST'&&$path==='/api/admin/login'){$r=q('SELECT * FROM admin_users WHERE username=?',[trim($b['username']??'')])->fetch();if(!$r||!password_verify($b['password']??'',$r['password_hash']))fail('Incorrect username or password.',401);session_regenerate_id(true);$_SESSION['admin']=['id'=>$r['id'],'username'=>$r['username']];out(['ok'=>true,'admin'=>['username'=>$r['username']]]);}
 if($method==='GET'&&$path==='/api/admin/me'){if(empty($_SESSION['admin']))fail('Not signed in.',401);out(['admin'=>$_SESSION['admin']]);}
 if($method==='POST'&&$path==='/api/admin/logout'){admin();unset($_SESSION['admin']);out(['ok'=>true]);}
 if($method==='GET'&&$path==='/api/admin/bookings'){
 admin();$rows=q("SELECT b.*,DATE_FORMAT(s.show_date,'%Y-%m-%d') show_date,DATE_FORMAT(s.show_time,'%H:%i') show_time,m.title movie FROM bookings b JOIN screenings s ON s.id=b.screening_id JOIN movies m ON m.id=s.movie_id ORDER BY b.created_at DESC")->fetchAll();$out=[];foreach($rows as $r){$seats=q('SELECT seat_number FROM booking_seats WHERE booking_id=?',[$r['id']])->fetchAll(PDO::FETCH_COLUMN);$office=q('SELECT seat_number FROM booking_seats WHERE booking_id=? AND office_chair=1',[$r['id']])->fetchAll(PDO::FETCH_COLUMN);$pass=q('SELECT seat_number,passenger_name AS name FROM booking_passengers WHERE booking_id=?',[$r['id']])->fetchAll();$out[]=['code'=>$r['booking_code'],'name'=>$r['customer_name'],'email'=>$r['customer_email'],'count'=>(int)$r['ticket_count'],'total'=>$r['total_cents']/100,'status'=>$r['status'],'checkedIn'=>(bool)$r['checked_in'],'checkedInAt'=>$r['checked_in_at'],'waterCount'=>(int)$r['water_count'],'popcornCount'=>(int)$r['popcorn_count'],'customSelection'=>(bool)$r['custom_selection'],'createdAt'=>$r['created_at'],'cancelledAt'=>$r['cancelled_at'],'cancelledBy'=>$r['cancelled_by'],'date'=>$r['show_date'],'time'=>$r['show_time'],'movie'=>$r['movie'],'seats'=>$seats,'officeSeats'=>$office,'passengers'=>$pass];}out(['bookings'=>$out]);}
 if($method==='POST'&&$path==='/api/admin/tickets/scan'){
 admin();$code=trim($b['code']??'');if(!preg_match('/^Dncngfrt-[A-Z0-9]{3}-[A-Z0-9]{3}-[A-Z0-9]{3}-\d{6}$/i',$code))fail('Invalid ticket code format.');
 $pdo=db();$pdo->beginTransaction();$r=q('SELECT * FROM bookings WHERE UPPER(booking_code)=UPPER(?) FOR UPDATE',[$code])->fetch();if(!$r){$pdo->rollBack();fail('Ticket code not found.',404);}if($r['status']!=='confirmed'){$pdo->rollBack();fail('This booking has been cancelled.',409);}if($r['checked_in']){$pdo->rollBack();fail('This ticket has already been scanned.',409);}
 $d=q("SELECT DATE_FORMAT(s.show_date,'%Y-%m-%d') date,DATE_FORMAT(s.show_time,'%H:%i') time,m.title movie FROM screenings s JOIN movies m ON m.id=s.movie_id WHERE s.id=?",[$r['screening_id']])->fetch();$seats=q('SELECT seat_number FROM booking_seats WHERE booking_id=?',[$r['id']])->fetchAll(PDO::FETCH_COLUMN);q('UPDATE bookings SET checked_in=1,checked_in_at=NOW() WHERE id=?',[$r['id']]);$pdo->commit();out(['ok'=>true,'ticket'=>['code'=>$r['booking_code'],'name'=>$r['customer_name'],'movie'=>$d['movie'],'date'=>$d['date'],'time'=>$d['time'],'seats'=>$seats]]);}
 if($method==='POST'&&preg_match('#^/api/admin/bookings/([^/]+)/cancel$#',$path,$m)){admin();$r=cancelBooking($m[1],null,true);$s=$r['_status']??200;unset($r['_status']);out($r,$s);}
 if($method==='POST'&&preg_match('#^/api/admin/movies$#',$path)){
 admin();$title=trim($b['title']??'');$desc=trim($b['description']??'');$duration=(int)($b['duration']??0);$poster=trim($b['poster']??'linear-gradient(135deg,#753039,#182838)');if(!$title||strlen($title)>100||!$desc||strlen($desc)>1000||$duration<1||$duration>500)fail('Movie title, description and valid duration are required.');if(!preg_match('#^(https://[^ ]+|linear-gradient\(.+\)|data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/]+=*)$#i',$poster))fail('Use an HTTPS poster image URL or upload a JPG, PNG or WebP image under 1 MB.');
 $id=uid();q('INSERT INTO movies(id,title,genre,duration,age,year,emoji,poster,description) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$title,$b['genre']??'Cits',$duration,$b['age']??'U',(int)($b['year']??date('Y')),$b['emoji']??'🎬',$poster,$desc]);addShows($id,$b['screenings']??[]);out(['id'=>$id],201);}
 if($method==='PUT'&&preg_match('#^/api/admin/movies/([^/]+)$#',$path,$m)){
 admin();$title=trim($b['title']??'');$desc=trim($b['description']??'');$duration=(int)($b['duration']??0);$poster=trim($b['poster']??'linear-gradient(135deg,#753039,#182838)');if(!$title||strlen($title)>100||!$desc||strlen($desc)>1000||$duration<1||$duration>500)fail('Movie title, description and valid duration are required.');
 q('UPDATE movies SET title=?,genre=?,duration=?,age=?,year=?,emoji=?,poster=?,description=? WHERE id=?',[$title,$b['genre']??'Cits',$duration,$b['age']??'U',(int)($b['year']??date('Y')),$b['emoji']??'🎬',$poster,$desc,$m[1]]);addShows($m[1],$b['screenings']??[]);out(['ok'=>true]);}
 if($method==='DELETE'&&preg_match('#^/api/admin/movies/([^/]+)$#',$path,$m)){admin();q('UPDATE movies SET active=0 WHERE id=?',[$m[1]]);out(['ok'=>true]);}
 if($method==='DELETE'&&preg_match('#^/api/admin/screenings/([^/]+)$#',$path,$m)){admin();if(q('SELECT id FROM bookings WHERE screening_id=? LIMIT 1',[$m[1]])->fetch())fail('This screening has booking history and cannot be removed.',409);q('DELETE FROM screenings WHERE id=?',[$m[1]]);out(['ok'=>true]);}
 if($method==='PUT'&&$path==='/api/admin/prices'){admin();foreach(['ticket','custom','office','water','popcorn'] as $k)if(isset($b[$k])){$v=(float)$b[$k];if($v<0||$v>1000)fail('Invalid price.');q('INSERT INTO cinema_settings(setting_key,value_cents) VALUES(?,?) ON DUPLICATE KEY UPDATE value_cents=VALUES(value_cents)',[$k,(int)round($v*100)]);}out(['prices'=>prices()]);}
 if($method==='POST'&&$path==='/api/admin/password'){admin();$cur=$b['currentPassword']??'';$next=$b['newPassword']??'';if(strlen($next)<12)fail('New password must be at least 12 characters.');$r=q('SELECT password_hash FROM admin_users WHERE id=?',[$_SESSION['admin']['id']])->fetch();if(!$r||!password_verify($cur,$r['password_hash']))fail('Current password is incorrect.',401);q('UPDATE admin_users SET password_hash=? WHERE id=?',[password_hash($next,PASSWORD_DEFAULT),$_SESSION['admin']['id']]);out(['ok'=>true]);}
 out(['error'=>'API route not found.'],404);
} catch(Throwable $e){error_log((string)$e);out(['error'=>'Server error. Check the PHP error log and database configuration.'],500);}
function addShows(string $movieId,$showings): void {if(!is_array($showings))fail('Screenings must be an array.');foreach($showings as $show){$date=$show['date']??'';$times=$show['times']??[];if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!is_array($times))fail('Invalid screening date.');foreach(array_unique($times) as $time){if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time))fail('Invalid screening time.');$starts="$date $time:00";if(strtotime($starts)<=time())continue;$id=uid();q("INSERT IGNORE INTO screenings(id,movie_id,show_date,show_time,starts_at,hall_name) VALUES(?,?,?,?,?,'1. zāle')",[$id,$movieId,$date,$time,$starts]);$s=q('SELECT id FROM screenings WHERE movie_id=? AND show_date=? AND show_time=?',[$movieId,$date,$time])->fetch();foreach(seatIds() as $seat)q('INSERT IGNORE INTO screening_seats(screening_id,seat_number,office_chair) VALUES(?,?,?)',[$s['id'],$seat,$seat==='CHAIR-L'?1:0]);}}}
