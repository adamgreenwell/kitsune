// @ts-check
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const literal = (value) => `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
function php(code) {
    try {
        return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
            cwd: path.join(__dirname, '..', 'skeleton'), encoding: 'utf8',
        }).trim();
    } catch (error) {
        throw new Error(`Blueprint fixture command failed: ${String(error.stdout || error.stderr || error.message)}`);
    }
}

// Dedicated orgs in global-setup's disposable SQLite database; reuse already authenticated test accounts.
function seedBlueprintAdmin() {
    const previous = JSON.parse(php("$ids=Kitsune\\Core\\Models\\Org::query()->whereIn('slug',['blueprint-admin','blueprint-blocked','blueprint-stale','blueprint-rtl'])->pluck('id');"
        + "$roles=DB::table('roles')->whereIn('org_id',$ids)->where('is_owner',true)->pluck('id');"
        + "echo json_encode(['orgs'=>$ids->count(),'sites'=>DB::table('sites')->whereIn('org_id',$ids)->count(),'owners'=>DB::table('role_user')->whereIn('role_id',$roles)->count(),'members'=>DB::table('org_user')->whereIn('org_id',$ids)->count()]);"));
    if (previous.orgs !== 0) {
        // Playwright replaces a failed worker. Reuse a complete fixture; never repair a partial seed silently.
        if (previous.orgs === 4 && previous.sites === 4 && previous.owners === 8 && previous.members === 12) return;
        throw new Error(`Blueprint fixture is incomplete: ${JSON.stringify(previous)}`);
    }
    php("if(!app()->environment(['local','testing'])||DB::connection()->getDriverName()!=='sqlite'){throw new RuntimeException('Blueprint browser fixture requires the local SQLite test app.');}"
        + "foreach(['blueprint-admin','blueprint-blocked','blueprint-stale','blueprint-rtl'] as $slug){"
        + "$org=Kitsune\\Core\\Models\\Org::create(['slug'=>$slug,'name'=>'Blueprint review '.$slug]);"
        + '$ctx=app(Kitsune\\Core\\Tenancy\\Context::class);$ctx->setOrg($org);'
        + "$role=Kitsune\\Core\\Models\\Role::create(['handle'=>'owner','name'=>'Owner','is_owner'=>true]);"
        + "$site=Kitsune\\Core\\Models\\Site::create(['handle'=>'main','slug'=>$slug,'name'=>'Blueprint review','base_url'=>'/'.$slug,'locale'=>'en']);"
        + "foreach(['alpha@kitsune.test','alpha-rtl@kitsune.test','copyeditor@kitsune.test'] as $email){"
        + "$user=App\\Models\\User::query()->withoutGlobalScopes()->where('email',$email)->firstOrFail();"
        + '$user->orgs()->syncWithoutDetaching([$org->id]);$user->sites()->syncWithoutDetaching([$site->id]);'
        + "if($email!=='copyeditor@kitsune.test'){$role->assignTo($user->id);}}"
        + "if(in_array($slug,['blueprint-blocked','blueprint-stale'],true)){Kitsune\\Core\\Blueprints\\BlueprintApplier::apply(new Kitsune\\Core\\Blueprints\\FirstParty\\BlogBlueprint);}"
        + "if($slug==='blueprint-blocked'){$ctx->setSite($site);$type=Kitsune\\Core\\Models\\EntryType::query()->where('org_id',$org->id)->where('handle','post')->firstOrFail();"
        + "Kitsune\\Core\\Models\\Entry::create(['entry_type_id'=>$type->id,'title'=>'Retained blueprint browser post']);}"
        + '}');
}

function atOrg(slug) {
    return `$org=Kitsune\\Core\\Models\\Org::query()->where('slug',${literal(slug)})->firstOrFail();`
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setOrg($org);';
}

function state(slug) {
    return JSON.parse(php(atOrg(slug)
        + "$facts=[];foreach(['blueprints','entry_types','field_storage','roles','entries','entry_revisions','audit_log'] as $table){"
        + "$rows=DB::table($table)->where('org_id',$org->id)->orderBy('id')->get();$facts[$table]=['count'=>$rows->count(),'hash'=>hash('sha256',serialize($rows->all()))];}"
        + "echo json_encode($facts,JSON_THROW_ON_ERROR);"));
}

function reverseUnused(slug, handle) {
    php(atOrg(slug) + `Kitsune\\Core\\Blueprints\\BlueprintApplier::reverse(${literal(handle)});`);
}

module.exports = { seedBlueprintAdmin, state, reverseUnused };
