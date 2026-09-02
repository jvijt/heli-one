<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Badge.php';
Auth::requireLogin();
$pdo=Database::connection();ensure_member_soft_delete_schema($pdo);ensure_badge_schema($pdo);
$message='';$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='upload'){
            $name=trim((string)($_POST['name']??''));
            $tagId=(int)($_POST['tag_id']??0);$tagId=$tagId>0?$tagId:null;
            if($name==='')throw new RuntimeException('Geef de template een naam.');
            if(empty($_FILES['pdf']['name'])||($_FILES['pdf']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Selecteer een PDF-template.');
            if((int)$_FILES['pdf']['size']>15*1024*1024)throw new RuntimeException('De PDF mag maximaal 15 MB groot zijn.');
            $tmp=(string)$_FILES['pdf']['tmp_name'];$head=file_get_contents($tmp,false,null,0,5);
            if($head!=='%PDF-')throw new RuntimeException('Het bestand is geen geldige PDF.');
            if($tagId){$s=$pdo->prepare('SELECT id FROM tags WHERE id=:id');$s->execute(['id'=>$tagId]);if(!$s->fetchColumn())throw new RuntimeException('De gekozen tag bestaat niet.');}
            $dir=__DIR__.'/uploads/badges';if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Uploadmap kon niet worden aangemaakt.');
            $filename='badge-template-'.bin2hex(random_bytes(10)).'.pdf';if(!move_uploaded_file($tmp,$dir.'/'.$filename))throw new RuntimeException('Template kon niet worden opgeslagen.');
            $path='/uploads/badges/'.$filename;
            if($tagId===null)$pdo->exec('UPDATE badge_templates SET is_active=0 WHERE tag_id IS NULL');
            else{$s=$pdo->prepare('UPDATE badge_templates SET is_active=0 WHERE tag_id=:tag');$s->execute(['tag'=>$tagId]);}
            $defaultLayout=json_encode([
                ['type'=>'field','field'=>'full_name','x'=>7,'y'=>33,'w'=>72,'h'=>8,'font'=>'Arial','size'=>16,'color'=>'#111111','bold'=>true,'align'=>'left'],
                ['type'=>'field','field'=>'member_number','x'=>7,'y'=>43,'w'=>40,'h'=>5,'font'=>'Arial','size'=>8,'color'=>'#333333','bold'=>false,'align'=>'left']
            ],JSON_UNESCAPED_UNICODE);
            $s=$pdo->prepare('INSERT INTO badge_templates(name,tag_id,pdf_path,layout_json,is_active) VALUES(:name,:tag,:path,:layout,1)');$s->execute(['name'=>$name,'tag'=>$tagId,'path'=>$path,'layout'=>$defaultLayout]);
            $id=(int)$pdo->lastInsertId();header('Location: /badge-designer.php?id='.$id);exit;
        }
        if($action==='toggle'){
            $id=(int)($_POST['id']??0);$active=(int)($_POST['active']??0)===1?1:0;
            $s=$pdo->prepare('SELECT * FROM badge_templates WHERE id=:id');$s->execute(['id'=>$id]);$tpl=$s->fetch();if(!$tpl)throw new RuntimeException('Template niet gevonden.');
            if($active){if($tpl['tag_id']===null)$pdo->exec('UPDATE badge_templates SET is_active=0 WHERE tag_id IS NULL');else{$q=$pdo->prepare('UPDATE badge_templates SET is_active=0 WHERE tag_id=:tag');$q->execute(['tag'=>$tpl['tag_id']]);}}
            $pdo->prepare('UPDATE badge_templates SET is_active=:a WHERE id=:id')->execute(['a'=>$active,'id'=>$id]);$message='Template-status aangepast.';
        }
        if($action==='delete'){
            $id=(int)($_POST['id']??0);$s=$pdo->prepare('SELECT pdf_path FROM badge_templates WHERE id=:id');$s->execute(['id'=>$id]);$path=(string)($s->fetchColumn()?:'');
            $pdo->prepare('DELETE FROM badge_templates WHERE id=:id')->execute(['id'=>$id]);
            if($path!==''&&str_starts_with($path,'/uploads/badges/')){@unlink(__DIR__.$path);} $message='Template verwijderd.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$tags=$pdo->query('SELECT id,name FROM tags ORDER BY name')->fetchAll();
$templates=$pdo->query('SELECT bt.*,t.name tag_name FROM badge_templates bt LEFT JOIN tags t ON t.id=bt.tag_id ORDER BY bt.tag_id IS NULL DESC,t.name,bt.updated_at DESC')->fetchAll();
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lidkaart templates - Heli One</title><style>
body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px;display:flex;justify-content:space-between}header a{color:#fff}main{max-width:1180px;margin:32px auto;padding:0 22px}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.btn{display:inline-block;padding:10px 14px;border:0;border-radius:9px;background:#111;color:#fff;text-decoration:none;cursor:pointer;font-weight:700}.secondary{background:#e7eaee;color:#111}.danger{background:#8f1d1d}.card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 18px #0000000d;margin:18px 0}.grid{display:grid;grid-template-columns:2fr 1fr 2fr auto;gap:10px;align-items:end}input,select{padding:10px;border:1px solid #d6dbe1;border-radius:8px;width:100%;box-sizing:border-box}label{display:block;font-weight:700;font-size:13px;margin-bottom:6px}.ok,.err{padding:12px 14px;border-radius:9px}.ok{background:#e9f8ee}.err{background:#feecec}.muted{color:#69707a}.tablewrap{overflow:auto}.tbl{width:100%;border-collapse:collapse}.tbl th,.tbl td{padding:12px;border-bottom:1px solid #eceff2;text-align:left}.pill{display:inline-block;padding:5px 8px;border-radius:999px;background:#eef1f4;font-size:12px}.active{background:#e7f7ed}.actions{display:flex;gap:7px;flex-wrap:wrap}.actions form{margin:0}@media(max-width:800px){.grid{grid-template-columns:1fr}}</style></head><body><header><strong>Heli One Members</strong><div><a href="/">Dashboard</a> · <a href="/logout.php">Afmelden</a></div></header><main><div class="top"><div><h1>Lidkaart templates</h1><p class="muted">Creditcardformaat 86 × 54 mm. Een tag-template krijgt voorrang op de algemene template.</p></div><a class="btn secondary" href="/">← Dashboard</a></div><?php if($message):?><p class="ok"><?=e($message)?></p><?php endif;?><?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?><div class="card"><h2>Nieuwe PDF-template</h2><form method="post" enctype="multipart/form-data" class="grid"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="upload"><div><label>Naam</label><input name="name" required placeholder="bv. Algemene lidkaart"></div><div><label>Toepassing</label><select name="tag_id"><option value="0">ALGEMEEN</option><?php foreach($tags as$t):?><option value="<?=$t['id']?>">Tag: <?=e($t['name'])?></option><?php endforeach;?></select></div><div><label>PDF achtergrond</label><input type="file" name="pdf" accept="application/pdf,.pdf" required></div><button class="btn">Upload & ontwerpen</button></form></div><div class="card"><h2>Bestaande templates</h2><div class="tablewrap"><table class="tbl"><thead><tr><th>Naam</th><th>Gekoppeld aan</th><th>Status</th><th>Gewijzigd</th><th>Acties</th></tr></thead><tbody><?php if(!$templates):?><tr><td colspan="5" class="muted">Nog geen templates.</td></tr><?php endif;?><?php foreach($templates as$t):?><tr><td><strong><?=e($t['name'])?></strong></td><td><?= $t['tag_id']===null ? '<span class="pill">ALGEMEEN</span>' : '<span class="pill">Tag: '.e($t['tag_name']).'</span>' ?></td><td><span class="pill <?=$t['is_active']?'active':''?>"><?=$t['is_active']?'Actief':'Inactief'?></span></td><td><?=e($t['updated_at'])?></td><td><div class="actions"><a class="btn" href="/badge-designer.php?id=<?=(int)$t['id']?>">Ontwerpen</a><a class="btn secondary" target="_blank" href="<?=e($t['pdf_path'])?>">PDF</a><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$t['id']?>"><input type="hidden" name="active" value="<?=$t['is_active']?0:1?>"><button class="btn secondary"><?=$t['is_active']?'Deactiveren':'Activeren'?></button></form><form method="post" onsubmit="return confirm('Template definitief verwijderen?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn danger">Verwijderen</button></form></div></td></tr><?php endforeach;?></tbody></table></div></div></main></body></html>