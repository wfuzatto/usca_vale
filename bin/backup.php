<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
$c=appConfig()['db'];
$paths=PHP_OS_FAMILY==='Windows'?[dirname(__DIR__,3).'\mysql\bin\mysqldump.exe','C:\xampp\mysql\bin\mysqldump.exe'] : ['/usr/bin/mariadb-dump','/usr/bin/mysqldump'];
$exe=null;foreach($paths as $p) if(is_file($p)){$exe=$p;break;}
if(!$exe){fwrite(STDERR,"mysqldump/mariadb-dump não localizado. Configure caminho em bin/backup.php.\n");exit(1);}
$dir=dirname(__DIR__).'/backups';if(!is_dir($dir))mkdir($dir,0700,true);
$target=$dir.'/usca_'.gmdate('Ymd_His').'.sql';
$out=fopen($target,'wb');
$cmd=[$exe,'--host='.$c['host'],'--port='.(int)$c['port'],'--user='.$c['user'],'--single-transaction','--quick','--skip-lock-tables','--default-character-set=utf8mb4',$c['name']];
$env=array_merge($_ENV,['MYSQL_PWD'=>$c['pass']]);
$p=proc_open($cmd,[0=>['pipe','r'],1=>$out,2=>['pipe','w']],$pipes,dirname(__DIR__), $env);
if(!is_resource($p)){fclose($out);throw new RuntimeException('Não foi possível executar dump');}
fclose($pipes[0]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($p);
if($exit!==0){@unlink($target);fwrite(STDERR,$error."\n");exit(1);}
$gz=gzopen($target.'.gz','wb6');$in=fopen($target,'rb');while(!feof($in))gzwrite($gz,fread($in,1048576));fclose($in);gzclose($gz);unlink($target);
foreach(glob($dir.'/usca_*.sql.gz')?:[] as $f) if(filemtime($f)<time()-30*86400)@unlink($f);
echo 'Backup: '.$target.".gz\n";
