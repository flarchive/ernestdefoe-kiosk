<?php

/*
 * Kiosk — sell anything on a Flarum forum.
 *
 * The shape of it: the admin makes PRODUCTS, each unlocking one or more items
 * (a group, a tag, or anything another extension registers as an unlock type)
 * at one or more PRICES. Buying makes a PURCHASE, paying for it makes
 * ENTITLEMENTS, and every gate asks Access whether the member holds an item
 * right now. Providers (PayPal now) only move money and report back; the
 * ledger here is the record.
 */

use Ernestdefoe\Kiosk\Api;
use Ernestdefoe\Kiosk\Console\SweepCommand;
use Ernestdefoe\Kiosk\Content;
use Ernestdefoe\Kiosk\Extend as Kiosk;
use Ernestdefoe\Kiosk\KioskServiceProvider;
use Ernestdefoe\Kiosk\Listener\HideSecrets;
use Ernestdefoe\Kiosk\Notification\PurchaseBlueprint;
use Ernestdefoe\Kiosk\Provider\PayPal;
use Ernestdefoe\Kiosk\Provider\Stripe;
use Ernestdefoe\Kiosk\Service\Access;
use Ernestdefoe\Kiosk\Unlock\GroupUnlock;
use Ernestdefoe\Kiosk\Unlock\TagUnlock;
use Ernestdefoe\Kiosk\Service\PaidTags;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Settings\Event\Deserializing;
use Flarum\User\User;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The store, return and purchases pages are their own chunks.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/store', 'kiosk.store')
        ->route('/store/purchases', 'kiosk.purchases')
        ->route('/store/return/{number:K-[A-Za-z0-9]{10}}', 'kiosk.return'),

    // 🚨 No jsDirectory for the admin: it has no lazy chunks, and Flarum creates
    // a missing chunk folder inside vendor, which fails where vendor isn't
    // writable (as it shouldn't be). ShippedAssetsTest guards this.
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\View())->namespace('ernestdefoe-kiosk', __DIR__.'/views'),

    (new Extend\ServiceProvider())->register(KioskServiceProvider::class),

    new Kiosk\UnlockType('group', GroupUnlock::class),
    new Kiosk\UnlockType('discussion', \Ernestdefoe\Kiosk\Unlock\DiscussionUnlock::class),
    // "No ads", for any product to include. See Service\Ads.
    new Kiosk\UnlockType('ads', \Ernestdefoe\Kiosk\Unlock\AdFreeUnlock::class),

    // The admin's ads: the ones this visitor may see ride in the forum
    // payload (cached; no query), so a page never waits on a second request.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('kioskAds')
                ->get(fn ($forum, Context $context) => resolve(\Ernestdefoe\Kiosk\Service\Ads::class)->forActor($context->getActor())),
            Schema\Str::make('kioskAdFreeProduct')
                ->nullable()
                ->get(fn ($forum, Context $context) => resolve(\Ernestdefoe\Kiosk\Service\Ads::class)->offer($context->getActor())),
        ]),

    (new Extend\Event())->listen(\Flarum\Discussion\Event\Started::class, \Ernestdefoe\Kiosk\Listener\SpendPostingCredit::class),

    // Paid discussions (in a teaser tag, or sold one by one): replies are left
    // out of every post query, the first post renders as a faded excerpt, and
    // replying takes having bought it. See Service\Gate.
    (new Extend\ModelVisibility(\Flarum\Post\Post::class))->scope(\Ernestdefoe\Kiosk\Access\ScopePostVisibility::class),
    (new Extend\Formatter())->render(\Ernestdefoe\Kiosk\Formatter\TeaseContent::class),
    (new Extend\Policy())->modelPolicy(\Flarum\Discussion\Discussion::class, \Ernestdefoe\Kiosk\Access\DiscussionPolicy::class),
    (new Extend\ApiResource(\Flarum\Api\Resource\PostResource::class))
        // 🚨 A page of posts from many discussions: their tags in one query.
        ->endpoint('index', fn ($endpoint) => $endpoint->beforeSerialization(fn (Context $context, array $results) => resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->prime($results['models'], 'discussion_id')))
        ->fields(fn () => [
            Schema\Boolean::make('kioskLocked')
                ->get(fn ($post, Context $context) => (int) $post->number === 1 && $post->discussion_id
                    && resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->isLocked($context->getActor(), (int) $post->discussion_id, $post->user_id ? (int) $post->user_id : null)),
        ]),
    (new Extend\ApiResource(\Flarum\Api\Resource\DiscussionResource::class))
        ->endpoint('index', fn ($endpoint) => $endpoint->before(function (Context $context): void {
            if (trim((string) ($context->request->getQueryParams()['filter']['q'] ?? '')) !== '') {
                resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->searching = true;
            }
        })->after(function (Context $context, mixed $data) {
            resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->searching = false;

            return $data;
        }))
        ->endpoint('index', fn ($endpoint) => $endpoint->beforeSerialization(fn (Context $context, array $results) => resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->prime($results['models'], 'id')))
        ->fields(fn () => [
            Schema\Boolean::make('kioskLocked')
                // Lists load tags already: the gate uses them, saving a lookup per row.
                ->get(fn ($discussion, Context $context) => resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->isDiscussionLocked($context->getActor(), $discussion)),
            // The author's bump credits for it. Only asked of the author's own
            // discussions, from what they hold (no query).
            Schema\Integer::make('kioskBumps')
                ->visible(fn ($discussion, Context $context) => ! $context->getActor()->isGuest() && (int) $discussion->user_id === (int) $context->getActor()->id)
                ->get(function ($discussion, Context $context) {
                    $credits = resolve(\Ernestdefoe\Kiosk\Service\Credits::class);
                    if (! $credits->tags()['bump']) {
                        return 0;
                    }
                    $tags = $discussion->relationLoaded('tags')
                        ? $discussion->tags->pluck('id')->map(fn ($v) => (int) $v)->all()
                        : resolve(\Ernestdefoe\Kiosk\Service\Gate::class)->tagsOf((int) $discussion->id);

                    return $credits->bumpsFor($context->getActor(), $tags);
                }),
        ]),
    new Kiosk\Provider('paypal', PayPal::class),
    new Kiosk\Provider('stripe', Stripe::class),
    new Kiosk\Provider('btcpay', \Ernestdefoe\Kiosk\Provider\BTCPay::class),
    new Kiosk\Provider('nowpayments', \Ernestdefoe\Kiosk\Provider\NOWPayments::class),

    (new Extend\Conditional())->whenExtensionEnabled('flarum-tags', fn () => [
        new Kiosk\UnlockType('tag', TagUnlock::class),
        (new Extend\Event())->listen(\Flarum\Tags\Event\DiscussionWasTagged::class, \Ernestdefoe\Kiosk\Listener\SpendCreditOnRetag::class),
        // Paid actions in a tag: posting (credits or a pass), bumping, tipping.
        new Kiosk\UnlockType('post', \Ernestdefoe\Kiosk\Unlock\PostInTagUnlock::class),
        new Kiosk\UnlockType('bump', \Ernestdefoe\Kiosk\Unlock\BumpUnlock::class),
        new Kiosk\UnlockType('tip', \Ernestdefoe\Kiosk\Unlock\TipUnlock::class),
        (new Extend\Policy())->modelPolicy(\Flarum\Tags\Tag::class, \Ernestdefoe\Kiosk\Access\TagPolicy::class),

        // A shared link to a paid tag shows how to join instead of "not found".
        (new Extend\Frontend('forum'))
            ->removeRoute('tag')
            ->route('/t/{slug}', 'tag', Content\LockedTag::class),

        // Paid tags this visitor can't see, shown locked with a Buy button.
        (new Extend\ApiResource(ForumResource::class))
            ->fields(fn () => [
                Schema\Arr::make('kioskLockedTags')
                    ->get(fn ($forum, Context $context) => resolve(PaidTags::class)->lockedFor($context->getActor())),
                // Tags where posting is paid and this visitor can't post yet; and where
                // bumps and tips are sold. Cached lists and Access: no query.
                Schema\Arr::make('kioskPostTags')
                    ->get(fn ($forum, Context $context) => resolve(\Ernestdefoe\Kiosk\Service\Credits::class)->postLockedTags($context->getActor())),
                Schema\Arr::make('kioskTipTags')
                    ->get(fn () => resolve(\Ernestdefoe\Kiosk\Service\Credits::class)->tags()['tip']),
            ]),
    ]),

    // A buyer's permissions include what they hold; a lapsed member's do not,
    // from the second it lapses. See Access::permissionGroups.
    (new Extend\User())->permissionGroups(fn (User $user, array $groupIds) => resolve(Access::class)->permissionGroups($user, $groupIds)),

    (new Extend\Routes('api'))
        ->get('/kiosk/store', 'kiosk.store', Api\StoreController::class)
        ->post('/kiosk/checkout', 'kiosk.checkout', Api\CheckoutController::class)
        ->get('/kiosk/purchases', 'kiosk.purchases.index', Api\PurchaseController::class)
        ->get('/kiosk/purchases/{number}', 'kiosk.purchases.show', Api\PurchaseController::class)
        ->post('/kiosk/purchases/{number}/confirm', 'kiosk.purchases.confirm', Api\PurchaseController::class)
        ->post('/kiosk/purchases/{number}/cancel', 'kiosk.purchases.cancel', Api\PurchaseController::class)
        ->post('/kiosk/purchases/{number}/switch', 'kiosk.purchases.switch', Api\PurchaseController::class)
        ->post('/kiosk/purchases/{number}/seats', 'kiosk.purchases.seats.add', Api\PurchaseController::class)
        ->delete('/kiosk/purchases/{number}/seats/{userId:[0-9]+}', 'kiosk.purchases.seats.remove', Api\PurchaseController::class)
        ->post('/kiosk/coupons/check', 'kiosk.coupons.check', Api\CouponController::class)
        ->post('/kiosk/webhooks/{provider:[a-z]+}', 'kiosk.webhooks', Api\WebhookController::class)
        ->post('/kiosk/discussions/{id:[0-9]+}/bump', 'kiosk.discussions.bump', Api\ActionsController::class)
        ->get('/kiosk/discussions/{id:[0-9]+}/tips', 'kiosk.discussions.tips', Api\ActionsController::class)
        ->get('/kiosk/admin/overview', 'kiosk.admin.overview', Api\Admin\OverviewController::class)
        ->get('/kiosk/admin/products', 'kiosk.admin.products', Api\Admin\ProductsController::class)
        ->post('/kiosk/admin/products', 'kiosk.admin.products.create', Api\Admin\ProductsController::class)
        ->post('/kiosk/admin/products/order', 'kiosk.admin.products.order', Api\Admin\ProductsController::class)
        ->patch('/kiosk/admin/products/{id:[0-9]+}', 'kiosk.admin.products.update', Api\Admin\ProductsController::class)
        ->delete('/kiosk/admin/products/{id:[0-9]+}', 'kiosk.admin.products.delete', Api\Admin\ProductsController::class)
        ->get('/kiosk/admin/unlocks', 'kiosk.admin.unlocks', Api\Admin\UnlocksController::class)
        ->get('/kiosk/admin/purchases', 'kiosk.admin.purchases', Api\Admin\PurchasesController::class)
        ->post('/kiosk/admin/purchases/{id:[0-9]+}/cancel', 'kiosk.admin.purchases.cancel', Api\Admin\PurchasesController::class)
        ->post('/kiosk/admin/purchases/{id:[0-9]+}/end', 'kiosk.admin.purchases.end', Api\Admin\PurchasesController::class)
        ->post('/kiosk/admin/purchases/{id:[0-9]+}/refund', 'kiosk.admin.purchases.refund', Api\Admin\PurchasesController::class)
        ->post('/kiosk/admin/grants', 'kiosk.admin.grants', Api\Admin\PurchasesController::class)
        ->get('/kiosk/admin/webhooks', 'kiosk.admin.webhooks', Api\Admin\WebhooksController::class)
        ->post('/kiosk/admin/webhooks/{id:[0-9]+}/replay', 'kiosk.admin.webhooks.replay', Api\Admin\WebhooksController::class)
        ->get('/kiosk/admin/ads', 'kiosk.admin.ads', Api\Admin\AdsController::class)
        ->post('/kiosk/admin/ads', 'kiosk.admin.ads.create', Api\Admin\AdsController::class)
        ->patch('/kiosk/admin/ads/{id:[0-9]+}', 'kiosk.admin.ads.update', Api\Admin\AdsController::class)
        ->delete('/kiosk/admin/ads/{id:[0-9]+}', 'kiosk.admin.ads.delete', Api\Admin\AdsController::class)
        ->post('/kiosk/admin/ads/{id:[0-9]+}/image', 'kiosk.admin.ads.image', Api\Admin\AdsController::class)
        ->post('/kiosk/ads/seen', 'kiosk.ads.seen', Api\AdsController::class)
        ->post('/kiosk/ads/{id:[0-9]+}/click', 'kiosk.ads.click', Api\AdsController::class)
        ->get('/kiosk/admin/coupons', 'kiosk.admin.coupons', Api\Admin\CouponsController::class)
        ->post('/kiosk/admin/coupons', 'kiosk.admin.coupons.create', Api\Admin\CouponsController::class)
        ->delete('/kiosk/admin/coupons/{id:[0-9]+}', 'kiosk.admin.coupons.delete', Api\Admin\CouponsController::class)
        ->post('/kiosk/admin/paypal', 'kiosk.admin.paypal', Api\Admin\PayPalController::class)
        ->post('/kiosk/admin/stripe', 'kiosk.admin.stripe', Api\Admin\StripeController::class)
        ->post('/kiosk/admin/btcpay', 'kiosk.admin.btcpay', Api\Admin\CryptoController::class)
        ->post('/kiosk/admin/btcpay/disconnect', 'kiosk.admin.btcpay.disconnect', Api\Admin\CryptoController::class)
        ->post('/kiosk/admin/nowpayments', 'kiosk.admin.nowpayments', Api\Admin\CryptoController::class)
        ->post('/kiosk/admin/nowpayments/disconnect', 'kiosk.admin.nowpayments.disconnect', Api\Admin\CryptoController::class)
        ->post('/kiosk/btcpay/authorized', 'kiosk.btcpay.authorized', Api\Admin\CryptoController::class),

    // Providers cannot send a CSRF token; the signature check stands in for it.
    (new Extend\Csrf())->exemptRoute('kiosk.webhooks')->exemptRoute('kiosk.btcpay.authorized'),

    new Extend\ApiResource(Api\Resource\PurchaseResource::class),

    (new Extend\Notification())->type(PurchaseBlueprint::class, ['alert', 'email'])
        ->beforeSending(\Ernestdefoe\Kiosk\Listener\WithholdPaidPosts::class),

    (new Extend\Event())->listen(Deserializing::class, HideSecrets::class),

    (new Extend\Console())
        ->command(SweepCommand::class)
        ->command(\Ernestdefoe\Kiosk\Console\ImportFloxumCommand::class)
        ->schedule(SweepCommand::class, function (\Illuminate\Console\Scheduling\Event $event): void {
            $event->everyTenMinutes()->withoutOverlapping();
        }),

    (new Extend\Settings())
        ->default('ernestdefoe-kiosk.grace_days', 3)
        ->default('ernestdefoe-kiosk.show_nav', true)
        ->default('ernestdefoe-kiosk.ads_every_posts', 5)
        ->default('ernestdefoe-kiosk.ads_every_discussions', 10)
        ->default('ernestdefoe-kiosk.ads_label', true)
        ->serializeToForum('kioskShowNav', 'ernestdefoe-kiosk.show_nav', 'boolval')
        ->serializeToForum('kioskAdsEveryPosts', 'ernestdefoe-kiosk.ads_every_posts', 'intval')
        ->serializeToForum('kioskAdsEveryDiscussions', 'ernestdefoe-kiosk.ads_every_discussions', 'intval')
        ->serializeToForum('kioskAdsLabel', 'ernestdefoe-kiosk.ads_label', 'boolval'),
];
