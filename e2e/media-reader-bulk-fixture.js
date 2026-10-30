// @ts-check
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { randomUUID } = require('node:crypto');

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const literal = (value) => `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], {
    cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8',
}).trim();

// This spec owns both sites and every returned entry id in the disposable browser database. No prefix-wide deletes.
function seed(count, mixed = false) {
    const slug = `bulk-offer-${randomUUID().slice(0, 8)}`;
    const code = [
        "$g=Kitsune\\Core\\Models\\Site::withoutScopeBecause('browser fixture',fn($q)=>$q->where('slug','golfdom')->firstOrFail());",
        '$c=app(Kitsune\\Core\\Tenancy\\Context::class);$c->forget()->setOrg(Kitsune\\Core\\Models\\Org::query()->findOrFail($g->org_id));',
        "$type=Kitsune\\Core\\Models\\EntryType::query()->whereNull('org_id')->where('handle','image')->firstOrFail();",
        `$slug=${literal(slug)};$sites=[];$entries=[];`,
        "foreach([$slug,$slug.'-control'] as $s){$sites[]=Kitsune\\Core\\Models\\Site::create(['handle'=>$s,'slug'=>$s,'name'=>'Bulk offer fixture','base_url'=>'/'.$s,'locale'=>'en']);}",
        "$owner=App\\Models\\User::query()->where('email','alpha@kitsune.test')->firstOrFail();foreach($sites as $site){$owner->admitToSite($site);}auth()->setUser($owner);",
        `$source=tempnam(sys_get_temp_dir(),'kitsune-reader-bulk-');file_put_contents($source,base64_decode('${PNG}'));`,
        `try{for($i=0;$i<${count + 1};$i++){`,
        `$control=$i===${count};$c->setSite($sites[$control?1:0]);$public=${mixed ? '$i===2' : 'false'};$shared=${mixed ? '$i===3' : 'false'};`,
        "$title=$slug.($control?' control':sprintf(' file %02d',$i+1));",
        "$entry=Kitsune\\Core\\Media\\MediaLibrary::store($source,'probe.png',$type,$public?'public':'private',$title,!$shared);",
        (mixed ? "if($i===0){Kitsune\\Core\\Media\\MediaVisibility::offer($entry,'course.previous');}" : ''),
        '$entries[]=(int)$entry->id;}}finally{unlink($source);}',
        "echo json_encode(['org'=>(int)$g->org_id,'sites'=>array_map(fn($s)=>(int)$s->id,$sites),'slug'=>$slug,'entries'=>$entries]);",
    ].join(' ');
    return JSON.parse(tinker(code));
}

function state(fixture) {
    const ids = JSON.stringify(fixture.entries);
    return JSON.parse(tinker(`$ids=${ids};$out=[];foreach($ids as $id){`
        + "$entry=DB::table('entries')->where('id',$id)->first();$m=DB::table('media_files')->where('entry_id',$id)->first();"
        + "if($entry===null||$m===null){throw new RuntimeException('Owned browser fixture is missing');}"
        + "$bytes=Illuminate\\Support\\Facades\\Storage::disk($m->disk)->get($m->path);"
        + "$out[]=['id'=>(int)$id,'site'=>$entry->site_id===null?null:(int)$entry->site_id,'offer'=>$m->reader_entitlement,'visibility'=>$m->visibility,'checksum'=>$m->checksum,'actual_checksum'=>hash('sha256',$bytes),'audits'=>DB::table('audit_log')->where('target_type',Kitsune\\Core\\Models\\Entry::class)->where('target_id',(string)$id)->whereIn('action',['media.offered_to_readers','media.reader_offer_changed'])->orderBy('id')->pluck('action')->all()];}echo json_encode($out);"));
}

function remove(fixture) {
    tinker(`$ids=${JSON.stringify(fixture.entries)};$sites=${JSON.stringify(fixture.sites)};`
        + `$c=app(Kitsune\\Core\\Tenancy\\Context::class);$c->forget()->setOrg(Kitsune\\Core\\Models\\Org::query()->findOrFail(${fixture.org}));`
        + "foreach($ids as $id){$entry=Kitsune\\Core\\Models\\Entry::withoutScopeBecause('removing owned browser fixture',fn($q)=>$q->withTrashed()->findOrFail($id));"
        + `if((int)$entry->org_id!==${fixture.org}||($entry->site_id!==null&&!in_array((int)$entry->site_id,$sites,true))){throw new RuntimeException('Foreign fixture scope');}`
        + `$c->setSite(null)->setOrg(Kitsune\\Core\\Models\\Org::query()->findOrFail(${fixture.org}));$entry->forceDelete();}foreach($sites as $id){Kitsune\\Core\\Models\\Site::query()->findOrFail($id)->delete();}`);
}

module.exports = { seed, state, remove };
