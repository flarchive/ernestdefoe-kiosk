<?php

namespace Ernestdefoe\Kiosk\Seller;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Provider\BTCPay;
use Ernestdefoe\Kiosk\Provider\NOWPayments;
use Ernestdefoe\Kiosk\Provider\PayPal;
use Ernestdefoe\Kiosk\Provider\Providers;
use Ernestdefoe\Kiosk\Provider\Stripe;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * A member connects (or removes) a payout account. Each provider is as easy as
 * it is for the forum owner: paste two PayPal keys, sign up on Stripe's own
 * pages, authorize a BTCPay store, or paste NOWPayments keys.
 */
class Connect
{
    public function __construct(
        private Providers $providers,
        private Sellers $sellers,
        private UrlGenerator $url,
        private TranslatorInterface $translator,
        private ConnectionInterface $db,
    ) {
    }

    /** The member's account with a provider, in the forum's current mode for it (made if new). */
    public function account(User $seller, string $provider): Account
    {
        // Stripe hangs members' accounts off the forum's own, so that one must be connected.
        if (! in_array($provider, $this->sellers->providerKeys(), true) || ($provider === 'stripe' && ! $this->providers->get('stripe')?->isReady())) {
            throw $this->invalid('provider', 'provider_off');
        }

        $account = Account::query()->firstOrNew(['user_id' => $seller->id, 'provider' => $provider, 'mode' => $this->sellers->modeFor($provider)]);
        if (! $account->exists) {
            $account->status = Account::PENDING;
            $account->save();
        }

        return $account;
    }

    public function paypal(User $seller, string $clientId, string $secret): Account
    {
        if ($clientId === '' || $secret === '') {
            throw $this->invalid('clientId', 'paypal_keys');
        }

        $account = $this->account($seller, 'paypal');
        $account->setCredentials(['client_id' => $clientId, 'secret' => $secret]);

        /** @var PayPal $bound */
        $bound = $this->providers->forAccount($account);
        $result = $bound->client()->connect($this->webhookUrl($account), $account->mode);

        if (! $result['ok'] || empty($result['webhook_id'])) {
            throw $this->invalid('clientId', ($result['step'] ?? '') === 'credentials' ? 'paypal_rejected' : 'paypal_webhook');
        }

        $account->setCredentials(['client_id' => $clientId, 'secret' => $secret, 'webhook_id' => $result['webhook_id']]);
        $account->details = ['hint' => '…'.substr($clientId, -4)];

        return $this->connected($account);
    }

    /** Stripe's own sign-up for the member; returns where to send them. */
    public function stripe(User $seller): string
    {
        $account = $this->account($seller, 'stripe');
        $back = $this->url->to('api')->route('kiosk.selling.stripe', ['account' => $account->id]);

        /** @var Stripe $forum */
        $forum = $this->providers->get('stripe');
        $made = $forum->client()->onboard($account->externalId() ?: null, (string) $seller->email, $back, $back.'?again=1');

        if (isset($made['account']) && $made['account'] !== $account->externalId()) {
            $account->external_id = $made['account'];
            $account->save();
        }

        if (! isset($made['url'])) {
            throw $this->invalid('provider', 'stripe_connect');
        }

        return $made['url'];
    }

    /** Back from Stripe's sign-up: they can sell once Stripe lets them charge. */
    public function stripeReturned(Account $account): void
    {
        /** @var Stripe $forum */
        $forum = $this->providers->get('stripe');
        $charges = $account->externalId() !== '' ? $forum->client()->canCharge($account->externalId()) : null;

        if ($charges !== null) {
            $account->status = $charges ? Account::CONNECTED : Account::PENDING;
            $account->details = ['hint' => $account->externalId()];
            $account->save();
            $this->forgetRefs($account);
        }
    }

    /** BTCPay's authorize page for the member's own store. */
    public function btcpay(User $seller, string $server): string
    {
        $server = rtrim(trim($server), '/');
        // 🚨 https only: the API key and every invoice go over it.
        if (! preg_match('~^https://[^/\s?#]+(/[^\s?#]*)?$~i', $server) || SafeHost::resolve($server) === null) {
            throw $this->invalid('url', 'btcpay_url');
        }

        $account = $this->account($seller, 'btcpay');
        $state = Str::random(40);
        // BTCPay posts back without the member's session cookie, so a
        // one-time state (kept hashed) stands in for it.
        $account->details = ['state' => hash('sha256', $state), 'expires' => time() + 900, 'server' => $server] + (array) $account->details;
        $account->save();

        /** @var BTCPay $forum */
        $forum = $this->providers->get('btcpay');

        return $forum->authorizeUrl($server, $this->url->to('api')->route('kiosk.selling.btcpay', ['account' => $account->id]).'?state='.$state);
    }

    /** @param list<string> $permissions */
    public function btcpayAuthorized(Account $account, string $state, string $apiKey, array $permissions): bool
    {
        $details = (array) $account->details;
        $expected = (string) ($details['state'] ?? '');

        if ($expected === '' || ! hash_equals($expected, hash('sha256', $state)) || (int) ($details['expires'] ?? 0) < time()) {
            return false;
        }

        $server = (string) $details['server'];
        $account->details = ['hint' => parse_url($server, PHP_URL_HOST)];
        $account->save();

        /** @var BTCPay $bound */
        $bound = $this->providers->forAccount($account);
        $ok = $bound->connect($server, $apiKey, $permissions, $this->webhookUrl($account));
        $this->forgetRefs($account);

        return $ok;
    }

    public function nowpayments(User $seller, string $apiKey, string $ipnSecret): Account
    {
        if ($apiKey === '' || $ipnSecret === '') {
            throw $this->invalid('apiKey', 'nowpayments_keys');
        }

        $account = $this->account($seller, 'nowpayments');
        $account->setCredentials(['api_secret' => $apiKey, 'ipn_secret' => $ipnSecret]);

        /** @var NOWPayments $bound */
        $bound = $this->providers->forAccount($account);
        if (! $bound->check($account->mode)) {
            throw $this->invalid('apiKey', 'nowpayments_rejected');
        }

        $account->details = ['hint' => '…'.substr($apiKey, -4)];

        return $this->connected($account);
    }

    /** Remove a payout account. Their listings stop selling through it; buyers keep what they bought. */
    public function remove(User $seller, string $provider): void
    {
        $accounts = Account::query()->where('user_id', $seller->id)->where('provider', $provider)->get();

        // Live subscriptions renew through this account: keep it until they end,
        // or their buyers couldn't cancel and nobody could refund.
        if (\Ernestdefoe\Kiosk\Model\Purchase::query()->whereIn('seller_account_id', $accounts->pluck('id'))
            ->where('kind', \Ernestdefoe\Kiosk\Model\Price::RECURRING)
            ->whereIn('status', [\Ernestdefoe\Kiosk\Model\Purchase::ACTIVE, \Ernestdefoe\Kiosk\Model\Purchase::CANCELED])->exists()) {
            throw $this->invalid('provider', 'has_subscriptions');
        }

        foreach ($accounts as $account) {
            $this->forgetRefs($account);
            $account->delete();
        }
    }

    public function webhookUrl(Account $account): string
    {
        return $this->url->to('api')->route('kiosk.webhooks.seller', ['provider' => $account->provider, 'account' => $account->id]);
    }

    private function connected(Account $account): Account
    {
        $account->status = Account::CONNECTED;
        $account->updated_at = Carbon::now();
        $account->save();
        // New keys may be a different app or account: plans made on the old one don't exist here.
        $this->forgetRefs($account);

        return $account;
    }

    private function forgetRefs(Account $account): void
    {
        $this->db->table('kiosk_price_refs')->whereIn('provider', array_map(fn ($base) => $base.'@'.$account->id, ['paypal', 'paypal_notrial', 'stripe']))->delete();
    }

    private function invalid(string $field, string $reason): ValidationException
    {
        return new ValidationException([$field => $this->translator->trans('ernestdefoe-kiosk.forum.selling.errors.'.$reason)]);
    }
}
