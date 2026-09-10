<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
Auth::requireLogin();
$pdo=Database::connection();
Auth::ensureUserSchema();
ensure_member_soft_delete_schema($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS member_activation_codes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,member_id INT UNSIGNED NOT NULL,code_hash CHAR(64) NOT NULL UNIQUE,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_by_user_id INT UNSIGNED NULL,INDEX idx_mac_member(member_id),INDEX idx_mac_expiry(expires_at),CONSTRAINT fk_mac_member FOREIGN KEY(member_id) REFERENCES members(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$tags=$pdo->query('SELECT t.id,t.name,COUNT(mt.member_id) cnt FROM tags t LEFT JOIN member_tags mt ON mt.tag_id=t.id GROUP BY t.id,t.name ORDER BY t.name')->fetchAll();
$rows=[];$tagName='';$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $tag=(int)($_POST['tag_id']??0);
        $q=$pdo->prepare('SELECT t.name,m.id,m.first_name,m.last_name,m.weight_kg,m.member_number,u.id user_id FROM member_tags mt JOIN tags t ON t.id=mt.tag_id JOIN members m ON m.id=mt.member_id LEFT JOIN users u ON u.member_id=m.id WHERE mt.tag_id=:tag AND m.deleted_at IS NULL ORDER BY m.last_name,m.first_name');
        $q->execute(['tag'=>$tag]);
        $members=$q->fetchAll();
        if(!$members)throw new RuntimeException('Geen leden gevonden voor deze tag.');
        $tagName=$members[0]['name'];
        $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        foreach($members as$m){
            if($m['user_id']){$rows[]=$m+['code'=>'Reeds account'];continue;}
            $raw='';
            for($i=0;$i<8;$i++)$raw.=$alphabet[random_int(0,strlen($alphabet)-1)];
            $code=substr($raw,0,4).'-'.substr($raw,4);
            $pdo->prepare('UPDATE member_activation_codes SET expires_at=NOW() WHERE member_id=:id AND used_at IS NULL')->execute(['id'=>$m['id']]);
            $pdo->prepare('INSERT INTO member_activation_codes(member_id,code_hash,expires_at,created_by_user_id) VALUES(:id,:h,DATE_ADD(NOW(),INTERVAL 30 DAY),:uid)')->execute(['id'=>$m['id'],'h'=>hash('sha256',$raw),'uid'=>(int)($_SESSION['user_id']??0)?:null]);
            $rows[]=$m+['code'=>$code];
        }
    }catch(Throwable $e){$err=$e->getMessage();}
}
$exportRows=array_map(static fn(array $r):array=>[
    'Naam'=>(string)($r['last_name']??''),
    'Voornaam'=>(string)($r['first_name']??''),
    'Gewicht (kg)'=>(string)($r['weight_kg']??''),
    'Lidnummer'=>(string)($r['member_number']??''),
    'Activatiecode'=>(string)($r['code']??''),
],$rows);
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activatiecodes | Heli One</title><style>
body{font-family:Arial,sans-serif;background:#f5f6f8;margin:0;color:#171717}header{background:#111;color:#fff;padding:18px 28px}main{max-width:1100px;margin:30px auto;padding:0 20px}.card{background:#fff;padding:22px;border-radius:14px;margin:16px 0;box-shadow:0 4px 18px #0000000d}.btn{display:inline-block;padding:10px 14px;border:0;border-radius:8px;background:#111;color:#fff;font-weight:700;cursor:pointer;text-decoration:none}.secondary{background:#e7eaee;color:#111}.exportbar{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}select{padding:10px;min-width:320px;border:1px solid #d4d9df;border-radius:8px}table{width:100%;border-collapse:collapse;background:#fff}th,td{padding:10px;border-bottom:1px solid #ddd;text-align:left}th{background:#f7f8fa}.code{font-family:monospace;font-size:18px;font-weight:700;letter-spacing:.5px}.err{background:#feecec;padding:12px;border-radius:8px}.note{color:#667085;font-size:14px}@media print{header,.noprint{display:none!important}body{background:#fff}main{margin:0;max-width:none;padding:0}.card{padding:0;box-shadow:none}table{font-size:12px}h1{font-size:20px}}
</style></head><body><header>Heli One Members</header><main><h1>Activatiecodes per tag</h1><div class="card noprint"><p>Genereer voor alle leden zonder account onder één tag een persoonlijke eenmalige code. Nieuwe codes vervangen eerdere ongebruikte codes en zijn 30 dagen geldig.</p><?php if($err):?><p class="err"><?=e($err)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><select name="tag_id" required><option value="">Kies tag...</option><?php foreach($tags as$t):?><option value="<?=(int)$t['id']?>"><?=e($t['name'])?> (<?=(int)$t['cnt']?>)</option><?php endforeach;?></select> <button class="btn">Codes genereren</button></form></div><?php if($rows):?><div class="exportbar noprint"><button class="btn secondary" type="button" onclick="window.print()">Afdrukken</button><button class="btn" type="button" id="pdfBtn">Download PDF</button><button class="btn" type="button" id="excelBtn">Download Excel</button></div><div class="card" id="exportCard"><h2><?=e($tagName)?></h2><p>Activatie via: <strong>members.heli-one.be/activate.php</strong></p><p class="note">Deze codes zijn eenmalig bruikbaar en 30 dagen geldig. Bewaar of exporteer deze lijst nu: de codes worden om veiligheidsredenen niet leesbaar opgeslagen.</p><table id="codesTable"><thead><tr><th>Naam</th><th>Voornaam</th><th>Gewicht</th><th>Lidnr.</th><th>Activatiecode</th></tr></thead><tbody><?php foreach($rows as$r):?><tr><td><?=e($r['last_name'])?></td><td><?=e($r['first_name'])?></td><td><?=e((string)($r['weight_kg']??''))?></td><td><?=e($r['member_number']??'')?></td><td class="code"><?=e($r['code'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></main><?php if($rows):?><script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.4/jspdf.plugin.autotable.min.js"></script><script>
const exportRows=<?=json_encode($exportRows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const tagName=<?=json_encode($tagName,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
function safeFilename(v){return String(v||'activatiecodes').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-zA-Z0-9_-]+/g,'-').replace(/^-+|-+$/g,'').toLowerCase()||'activatiecodes'}
document.getElementById('excelBtn').addEventListener('click',()=>{
    if(!window.XLSX){alert('Excel-module kon niet worden geladen. Vernieuw de pagina en probeer opnieuw.');return;}
    const rows=[['Heli One - Activatiecodes'],['Tag',tagName],['Aanmelden via','members.heli-one.be/activate.php'],[],['Naam','Voornaam','Gewicht (kg)','Lidnummer','Activatiecode']];
    exportRows.forEach(r=>rows.push([r['Naam'],r['Voornaam'],r['Gewicht (kg)'],r['Lidnummer'],r['Activatiecode']]));
    const ws=XLSX.utils.aoa_to_sheet(rows);
    ws['!cols']=[{wch:24},{wch:20},{wch:14},{wch:15},{wch:20}];
    const wb=XLSX.utils.book_new();XLSX.utils.book_append_sheet(wb,ws,'Activatiecodes');
    XLSX.writeFile(wb,'heli-one-activatiecodes-'+safeFilename(tagName)+'.xlsx');
});
document.getElementById('pdfBtn').addEventListener('click',()=>{
    try{
        const {jsPDF}=window.jspdf;if(!jsPDF)throw new Error('PDF module ontbreekt');
        const doc=new jsPDF({orientation:'landscape',unit:'mm',format:'a4'});
        doc.setFontSize(18);doc.text('Heli One - Activatiecodes',14,16);
        doc.setFontSize(11);doc.text('Tag: '+tagName,14,24);doc.text('Aanmelden via: members.heli-one.be/activate.php',14,30);doc.text('Codes zijn eenmalig bruikbaar en 30 dagen geldig.',14,36);
        doc.autoTable({startY:42,head:[['Naam','Voornaam','Gewicht (kg)','Lidnummer','Activatiecode']],body:exportRows.map(r=>[r['Naam'],r['Voornaam'],r['Gewicht (kg)'],r['Lidnummer'],r['Activatiecode']]),styles:{fontSize:10,cellPadding:2.5},headStyles:{fontStyle:'bold'},columnStyles:{4:{fontStyle:'bold',fontSize:12}}});
        doc.save('heli-one-activatiecodes-'+safeFilename(tagName)+'.pdf');
    }catch(e){console.error(e);alert('PDF kon niet worden gemaakt. Vernieuw de pagina en probeer opnieuw.');}
});
</script><?php endif;?></body></html>