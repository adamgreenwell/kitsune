// @ts-check
const { php, literal } = require('./storefront-fixture');

/** Synthetic authoritative proof for browser presentation only. No credential, transport or provider call. */
function confirmTestOrder(reference, refundState = 'owed') {
    return JSON.parse(php(`$order=DB::table('commerce_orders')->where('reference',${literal(reference)})->firstOrFail();`
        + '$site=Kitsune\\Core\\Models\\Site::withoutScopeBecause("Exact paid browser fixture site",fn($q)=>$q->whereKey($order->site_id)->firstOrFail());'
        + 'app(Kitsune\\Core\\Tenancy\\Context::class)->setSite($site);auth()->forgetUser();'
        + '$record=app(Kitsune\\Commerce\\Orders\\OrderReaderData::class)->record(DB::connection(),$order);$reference=bin2hex(random_bytes(16));$now=Carbon\\CarbonImmutable::now("UTC")->startOfSecond();$expires=$now->addSeconds(1860);'
        + '$payload=Kitsune\\Commerce\\Payments\\CheckoutAttempts::payload($record,$reference,$expires->timestamp,"https://fixture-return.test");'
        + "DB::table('commerce_checkout_attempts')->insert(['org_id'=>$site->org_id,'site_id'=>$site->id,'order_id'=>$order->id,'reference'=>$reference,'active_order_id'=>null,"
        + "'mode'=>'test','currency'=>$order->currency,'account_id'=>'acct_paidBrowserFixture','api_version'=>Kitsune\\Commerce\\Payments\\StripeCheckout::API_VERSION,'credential_witness'=>str_repeat('a',64),'state'=>'completed',"
        + "'request_payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'request_hash'=>Kitsune\\Commerce\\Payments\\CheckoutAttempts::digest($payload),'order_hash'=>Kitsune\\Commerce\\Payments\\CheckoutReview::orderHash($record),"
        + "'session_id'=>'cs_test_'.$reference,'checkout_url'=>null,'reviewed_at'=>$now->format('Y-m-d H:i:s'),'expires_at'=>$expires->format('Y-m-d H:i:s'),"
        + "'lease_token'=>null,'lease_until'=>null,'created_at'=>$now->format('Y-m-d H:i:s'),'updated_at'=>$now->format('Y-m-d H:i:s')]);"
        + "$attempt=DB::table('commerce_checkout_attempts')->where('reference',$reference)->firstOrFail();$amount=(string)$order->quoted_total_minor;"
        + "$proof=['id'=>$attempt->session_id,'test'=>true,'currency'=>$order->currency,'amountTotal'=>$amount,'clientReference'=>$order->reference,'attemptReference'=>$attempt->reference,'orderReference'=>$order->reference,"
        + "'expiresAt'=>$expires->timestamp,'paymentStatus'=>'paid','status'=>'complete','url'=>null,'paymentIntent'=>'pi_'.$reference,'payment'=>['intentId'=>'pi_'.$reference,'chargeId'=>'ch_'.$reference,'currency'=>$order->currency,'amount'=>$amount,'received'=>$amount,'captured'=>$amount,'refunded'=>'0','disputed'=>false,'test'=>true]];"
        + 'app(Kitsune\\Commerce\\Orders\\OrderLocks::class)->run(fn()=>app(Kitsune\\Commerce\\Payments\\PaidOrders::class)->confirm(DB::connection(),$order,$attempt,$proof));'
        + `$refund=DB::table('commerce_refund_attempts')->where('order_id',$order->id)->first();if($refund!==null&&${literal(refundState)}!=='owed'){DB::table('commerce_refund_attempts')->where('id',$refund->id)->update(['state'=>${literal(refundState)},'refund_id'=>'re_'.$refund->reference,'first_submitted_at'=>$now->format('Y-m-d H:i:s')]);}`
        + 'echo json_encode(["attempt"=>$attempt->reference,"refund"=>$refund?->reference,"session"=>$attempt->session_id,"charge"=>"ch_".$reference,"intent"=>"pi_".$reference]);'));
}

module.exports = { confirmTestOrder };
