// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');

// Throwaway fixtures in Playwright's fresh database only. No real application or provider is contacted.
const HOLDER = { email: 'download-list@kitsune.test', password: 'correct-horse-battery-staple' };
const EMPTY = { email: 'download-list-empty@kitsune.test', password: 'correct-horse-battery-staple' };
const PREFIX = '/downloads-list';
const NESTED = '/downloads-list/nested';
const RTL = '/downloads-list-rtl';
const PDF = '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n';
const literal = (value) => `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;

function artisan(...args) {
    return execFileSync('php', ['artisan', ...args], { cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8' }).trim();
}

function seedDownloadList() {
    const code = [
        "$org=Kitsune\\Core\\Models\\Org::query()->firstOrCreate(['slug'=>'reader-download-list'],['name'=>'Download list fixture']);",
        '$context=app(Kitsune\\Core\\Tenancy\\Context::class);$context->forget()->setOrg($org);',
        "$type=Kitsune\\Core\\Models\\EntryType::query()->firstOrCreate(['org_id'=>$org->id,'handle'=>'download-list-fixture'],['name'=>'Download list fixture','plural_name'=>'Download list fixtures','is_media'=>true]);",
        `$holder=App\\Models\\Reader::findByEmail(${literal(HOLDER.email)})??App\\Models\\Reader::createReader(${literal(HOLDER.email)},Illuminate\\Support\\Facades\\Hash::make(${literal(HOLDER.password)}),now());`,
        `$empty=App\\Models\\Reader::findByEmail(${literal(EMPTY.email)})??App\\Models\\Reader::createReader(${literal(EMPTY.email)},Illuminate\\Support\\Facades\\Hash::make(${literal(EMPTY.password)}),now());`,
        `foreach([['downloads-list',${literal(PREFIX)},'en'],['downloads-list-nested',${literal(NESTED)},'en'],['downloads-list-rtl',${literal(RTL)},'ar']] as [$slug,$prefix,$locale]){`,
        "$site=Kitsune\\Core\\Models\\Site::query()->firstOrCreate(['slug'=>$slug],['org_id'=>$org->id,'handle'=>$slug,'name'=>'Download list fixture','base_url'=>$prefix,'locale'=>$locale,'settings'=>['reader_accounts'=>'sign-in']]);$context->setSite($site);",
        "app(Kitsune\\Core\\Entitlements\\EntitlementWriter::class)->grant($holder->id,'fixture.downloads','e2e.download-list:1',null);",
        "for($i=1;$i<=25;$i++){foreach([true,false] as $held){$title=$held?sprintf('Fixture file %02d',$i):'Hidden private offer '.$i;",
        "$id=DB::table('entries')->where('org_id',$org->id)->where('site_id',$site->id)->where('entry_type_id',$type->id)->where('title',$title)->value('id');if($id!==null){continue;}",
        "$id=DB::table('entries')->insertGetId(['org_id'=>$org->id,'site_id'=>$site->id,'entry_type_id'=>$type->id,'type_handle'=>$type->handle,'title'=>$title,'created_at'=>'2000-01-01 00:00:00','updated_at'=>'2000-01-01 00:00:00']);",
        "DB::table('media_files')->insert(['entry_id'=>$id,'disk'=>'kitsune-private','path'=>'download-list-fixture/'.$id.'.pdf','mime'=>'application/pdf','size_bytes'=>100,'checksum'=>str_repeat('a',64),'visibility'=>'private','reader_entitlement'=>$held?'fixture.downloads':'fixture.unheld','created_at'=>'2000-01-01 00:00:00']);",
        `if($held&&$i===1){$bytes=base64_decode(${literal('JVBERi0xLjQKMSAwIG9iajw8Pj5lbmRvYmoKdHJhaWxlcjw8Pj4KJSVFT0YK')});Illuminate\\Support\\Facades\\Storage::disk('kitsune-private')->put('download-list-fixture/'.$id.'.pdf',$bytes);DB::table('media_files')->where('entry_id',$id)->update(['size_bytes'=>strlen($bytes),'checksum'=>hash('sha256',$bytes)]);}`,
        '}}}',
        "echo 'download-list fixture ready';",
    ].join(' ');
    const result = artisan('tinker', '--execute', code);
    if (result !== 'download-list fixture ready') throw new Error(`Download list fixture failed: ${result}`);
}

module.exports = { HOLDER, EMPTY, PREFIX, NESTED, RTL, PDF, artisan, seedDownloadList };
