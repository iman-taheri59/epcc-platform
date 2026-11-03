<?php
$pdo = new PDO('pgsql:host=/tmp/epcc-pg;port=5432;dbname=epcc', 'epcc', 'epcc_dev');
$sql = "
SELECT c.relname AS tbl, a.attname AS col, format_type(a.atttypid, a.atttypmod) AS typ, a.attnotnull AS nn
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
JOIN pg_attribute a ON a.attrelid=c.oid
WHERE n.nspname='public' AND c.relkind='r' AND a.attnum>0 AND NOT a.attisdropped
ORDER BY c.relname, a.attnum";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$cols = [];
foreach ($rows as $r) { $cols[$r['tbl']][] = ['c'=>$r['col'],'t'=>$r['typ'],'n'=>$r['nn']]; }

$fksql = "
SELECT conrelid::regclass AS tbl, conname,
       (SELECT array_agg(attname ORDER BY u.ord) FROM unnest(conkey) WITH ORDINALITY AS u(att,ord) JOIN pg_attribute a ON a.attrelid=conrelid AND a.attnum=u.att) AS cols,
       confrelid::regclass AS ftbl,
       (SELECT array_agg(attname ORDER BY u.ord) FROM unnest(confkey) WITH ORDINALITY AS u(att,ord) JOIN pg_attribute a ON a.attrelid=confrelid AND a.attnum=u.att) AS fcols
FROM pg_constraint WHERE contype='f' AND connamespace='public'::regnamespace
ORDER BY 1,2";
$fks = $pdo->query($fksql)->fetchAll(PDO::FETCH_ASSOC);
file_put_contents('/tmp/schema.json', json_encode(['cols'=>$cols,'fks'=>$fks], JSON_UNESCAPED_UNICODE));
echo count($cols), " tables\n";
