<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
function history_db(): ?PDO {
 static $pdo=false; if($pdo instanceof PDO)return $pdo; if($pdo===null)return null;
 try{$dir=__DIR__.'/data';if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Unable to create history data directory');
  $pdo=new PDO('sqlite:'.$dir.'/monitor.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
  $pdo->exec('PRAGMA busy_timeout=5000');$pdo->exec('PRAGMA journal_mode=WAL');$pdo->exec('PRAGMA synchronous=NORMAL');
  $pdo->exec('CREATE TABLE IF NOT EXISTS observations (id INTEGER PRIMARY KEY AUTOINCREMENT,ts INTEGER NOT NULL,reflector TEXT NOT NULL,type TEXT NOT NULL,online INTEGER NOT NULL,response_ms INTEGER,users INTEGER NOT NULL DEFAULT 0,modules INTEGER NOT NULL DEFAULT 0,peers INTEGER NOT NULL DEFAULT 0)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_obs_ref_ts ON observations(reflector,ts)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT,ts INTEGER NOT NULL,reflector TEXT NOT NULL,event_type TEXT NOT NULL,message TEXT NOT NULL)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts)');return $pdo;
 }catch(Throwable $e){error_log('DSTAR history DB disabled: '.$e->getMessage());$pdo=null;return null;}
}
function history_record(array $statuses): void {
 $dir=__DIR__.'/data';if(!is_dir($dir))@mkdir($dir,0775,true);$lock=@fopen($dir.'/history-write.lock','c');
 if(!$lock){error_log('DSTAR history write skipped: cannot open lock file');return;}if(!@flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return;}
 try{$db=history_db();if(!$db)return;$now=time();$latest=$db->query('SELECT MAX(ts) FROM observations')->fetchColumn();
  if($latest!==false&&$latest!==null&&($now-(int)$latest)<HISTORY_SAMPLE_SECONDS)return;
  $db->beginTransaction();try{$lastQ=$db->prepare('SELECT online FROM observations WHERE reflector=? ORDER BY ts DESC,id DESC LIMIT 1');$ins=$db->prepare('INSERT INTO observations(ts,reflector,type,online,response_ms,users,modules,peers) VALUES(?,?,?,?,?,?,?,?)');$evt=$db->prepare('INSERT INTO events(ts,reflector,event_type,message) VALUES(?,?,?,?)');
   foreach($statuses as $s){$lastQ->execute([$s['name']]);$prev=$lastQ->fetchColumn();$online=!empty($s['online'])?1:0;$ins->execute([$now,$s['name'],$s['type'],$online,$s['response_ms']??null,count($s['users']??[]),count($s['modules']??[]),count($s['peers']??[])]);if($prev!==false&&(int)$prev!==$online)$evt->execute([$now,$s['name'],$online?'recovery':'outage',$online?'Service recovered':'Service became unavailable']);}
   $db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('DSTAR history write: '.$e->getMessage());return;}
  if(random_int(1,50)===1){try{$cut=$now-(HISTORY_RETENTION_DAYS*86400);$db->prepare('DELETE FROM observations WHERE ts < ?')->execute([$cut]);$db->prepare('DELETE FROM events WHERE ts < ?')->execute([$cut]);}catch(Throwable $e){error_log('DSTAR history cleanup: '.$e->getMessage());}}
 }catch(Throwable $e){error_log('DSTAR history write skipped: '.$e->getMessage());}finally{@flock($lock,LOCK_UN);fclose($lock);}
}
function history_payload(int $hours=24): array {$hours=max(1,min($hours,24*HISTORY_RETENTION_DAYS));try{$db=history_db();if(!$db)return ['enabled'=>false,'hours'=>$hours,'reflectors'=>[],'events'=>[]];$since=time()-$hours*3600;$q=$db->prepare('SELECT reflector,COUNT(*) samples,SUM(online) online_samples,ROUND(AVG(response_ms),0) avg_response_ms,MAX(users) max_users FROM observations WHERE ts>=? GROUP BY reflector ORDER BY reflector');$q->execute([$since]);$rows=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['availability_pct']=$r['samples']?round(100*(int)$r['online_samples']/(int)$r['samples'],2):null;$rows[]=$r;}$e=$db->prepare('SELECT ts,reflector,event_type,message FROM events WHERE ts>=? ORDER BY ts DESC LIMIT 100');$e->execute([$since]);return ['enabled'=>true,'hours'=>$hours,'reflectors'=>$rows,'events'=>$e->fetchAll(PDO::FETCH_ASSOC)];}catch(Throwable $e){error_log('DSTAR history read: '.$e->getMessage());return ['enabled'=>false,'hours'=>$hours,'reflectors'=>[],'events'=>[],'error'=>'History temporarily unavailable'];}}
