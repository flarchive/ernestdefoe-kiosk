<?php

namespace Ernestdefoe\Kiosk\Tests\integration\api;

use Carbon\Carbon;
use Ernestdefoe\Kiosk\Tests\integration\KioskTestCase;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/** The admin's ads: who gets them, "No ads", the counts, and what the admin may save. */
class AdsTest extends KioskTestCase
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function ad(array $data = []): array
    {
        $response = $this->api('POST', '/kiosk/admin/ads', 1, $data + ['name' => 'Sponsor', 'location' => 'sidebar', 'kind' => 'html', 'html' => '<p>SPONSOR-HTML</p>']);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['data'];
    }

    /** @return list<array<string, mixed>> */
    private function adsFor(?int $userId): array
    {
        return $this->json($this->api('GET', '', $userId))['data']['attributes']['kioskAds'];
    }

    #[Test]
    public function guests_and_members_get_the_ads_for_them_and_staff_get_none(): void
    {
        $everyone = $this->ad();
        $guests = $this->ad(['name' => 'Join us', 'audience' => 'guests']);
        $members = $this->ad(['name' => 'Members', 'audience' => 'members']);

        $this->assertSame([$everyone['id'], $guests['id']], array_column($this->adsFor(null), 'id'));
        $this->assertSame([$everyone['id'], $members['id']], array_column($this->adsFor(2), 'id'));
        // Mods hold the Ad-free permission by default; admins see what members see.
        $this->assertSame([], $this->adsFor(self::STAFF));
        $this->assertCount(2, $this->adsFor(1));
        $this->assertSame('<p>SPONSOR-HTML</p>', $this->adsFor(null)[0]['html']);
        $this->assertArrayNotHasKey('audience', $this->adsFor(null)[0]);
    }

    #[Test]
    public function switched_off_scheduled_and_ended_ads_are_never_sent(): void
    {
        $this->ad(['active' => false]);
        $this->ad(['startsAt' => Carbon::now()->addDay()->toIso8601String()]);
        $this->ad(['endsAt' => Carbon::now()->subMinute()->toIso8601String()]);
        $running = $this->ad(['startsAt' => Carbon::now()->subDay()->toDateString(), 'endsAt' => Carbon::now()->addDay()->toDateString()]);

        $this->assertSame([$running['id']], array_column($this->adsFor(null), 'id'));
    }

    #[Test]
    public function buying_no_ads_hides_them_until_it_ends_and_the_store_link_appears(): void
    {
        $this->ad();
        $this->assertNull($this->json($this->api('GET', '', 2))['data']['attributes']['kioskAdFreeProduct']);

        $product = $this->product([['type' => 'ads', 'targetId' => 1]], [['kind' => 'one_off', 'amount' => '3.00', 'currency' => 'USD']], ['name' => 'Ad-free']);
        $this->assertSame('ads', $product['unlocks'][0]['type']);
        $this->assertNotEmpty($product['unlocks'][0]['label']);
        $this->assertSame($product['slug'], $this->json($this->api('GET', '', 2))['data']['attributes']['kioskAdFreeProduct']);

        $grant = $this->json($this->api('POST', '/kiosk/admin/grants', 1, ['user' => 'buyer', 'productId' => $product['id'], 'days' => 0]))['data'];
        $this->assertSame([], $this->adsFor(self::BUYER));
        $this->assertCount(1, $this->adsFor(2));

        $this->api('POST', '/kiosk/admin/purchases/'.$grant['id'].'/end', 1);
        $this->assertCount(1, $this->adsFor(self::BUYER));
    }

    #[Test]
    public function impressions_and_clicks_are_counted_for_live_ads_only(): void
    {
        $ad = $this->ad(['kind' => 'image', 'linkUrl' => 'https://example.com/shop']);
        $off = $this->ad(['active' => false]);
        $this->db()->table('kiosk_ads')->where('id', $ad['id'])->update(['image_path' => 'kiosk-ads/x.png']);
        $this->api('PATCH', '/kiosk/admin/ads/'.$ad['id'], 1, ['name' => 'Sponsor']);

        // Signed in, so the test needs no CSRF token; a guest's page sends Flarum's.
        $this->assertSame(204, $this->api('POST', '/kiosk/ads/seen', 2, ['seen' => [$ad['id'] => 3, $off['id'] => 5, 999 => 1]])->getStatusCode());
        $this->api('POST', '/kiosk/ads/seen', 2, ['seen' => [$ad['id'] => 1000]]);

        $this->assertSame(204, $this->api('POST', '/kiosk/ads/'.$ad['id'].'/click', 2)->getStatusCode());
        // The same visitor again within ten minutes, and an ad that isn't running: not counted.
        $this->api('POST', '/kiosk/ads/'.$ad['id'].'/click', 2);
        $this->api('POST', '/kiosk/ads/'.$off['id'].'/click', 2);
        // 🚨 Never counted by a GET (an <img> in a post would count every viewer).
        $this->assertSame(405, $this->api('GET', '/kiosk/ads/'.$ad['id'].'/click')->getStatusCode());
        $this->assertSame('https://example.com/shop', $this->adsFor(null)[0]['link']);

        $row = $this->db()->table('kiosk_ad_stats')->where('ad_id', $ad['id'])->first();
        $this->assertSame([13, 1], [(int) $row->impressions, (int) $row->clicks]);
        $this->assertFalse($this->db()->table('kiosk_ad_stats')->where('ad_id', $off['id'])->exists());

        $listed = $this->json($this->api('GET', '/kiosk/admin/ads', 1))['data'];
        $this->assertSame([0, 13, 1], [$listed[0]['impressions'], $listed[1]['impressions'], $listed[1]['clicks']]);
    }

    #[Test]
    public function only_admins_manage_ads_and_bad_input_is_refused(): void
    {
        $this->assertSame(403, $this->api('POST', '/kiosk/admin/ads', self::STAFF, ['name' => 'x', 'location' => 'sidebar', 'kind' => 'html', 'html' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->api('GET', '/kiosk/admin/ads', 2)->getStatusCode());

        foreach ([
            ['location' => 'moon'],
            ['kind' => 'script'],
            ['audience' => 'bots'],
            ['html' => ''],
            ['kind' => 'image', 'linkUrl' => 'javascript:alert(1)'],
            ['startsAt' => '2026-12-02', 'endsAt' => '2026-12-01'],
        ] as $bad) {
            $response = $this->api('POST', '/kiosk/admin/ads', 1, $bad + ['name' => 'Bad', 'location' => 'sidebar', 'kind' => 'html', 'html' => 'x']);
            $this->assertSame(422, $response->getStatusCode(), json_encode($bad));
        }
    }

    #[Test]
    public function an_uploaded_picture_must_really_be_an_image(): void
    {
        $ad = $this->ad(['kind' => 'image']);
        $this->assertSame([], $this->adsFor(null), 'an image ad with no picture yet is not sent');

        $draw = function (int $w, int $h, string $as): string {
            $im = imagecreatetruecolor($w, $h);
            ob_start();
            $as === 'png' ? imagepng($im) : imagejpeg($im);

            return (string) ob_get_clean();
        };
        $png = $draw(2, 3, 'png');
        $upload = fn (string $bytes, string $name) => $this->send($this->request('POST', '/api/kiosk/admin/ads/'.$ad['id'].'/image', ['authenticatedAs' => 1])
            ->withUploadedFiles(['image' => new UploadedFile((new \Laminas\Diactoros\StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name)]));

        $this->assertSame(422, $upload('<svg onload="alert(1)"/>', 'x.svg')->getStatusCode());
        $this->assertSame(422, $upload('<?php echo 1;', 'x.png')->getStatusCode());
        // A valid header with nothing decodable behind it.
        $this->assertSame(422, $upload(substr($png, 0, 33).'<?php echo 1; ?>', 'x.png')->getStatusCode());

        $ok = $upload($png, 'banner.png');
        $this->assertSame(200, $ok->getStatusCode(), (string) $ok->getBody());
        $data = $this->json($ok)['data'];
        $this->assertSame([2, 3], [$data['imageWidth'], $data['imageHeight']]);
        $this->assertMatchesRegularExpression('~/assets/kiosk-ads/[A-Za-z0-9]{24}\.png$~', $data['imageUrl']);

        $sent = $this->adsFor(null);
        $this->assertSame($data['imageUrl'], $sent[0]['image']);

        // Big pictures are sized down, keeping their shape.
        $data = $this->json($upload($draw(3000, 600, 'jpg'), 'big.jpg'))['data'];
        $this->assertSame([1456, 291], [$data['imageWidth'], $data['imageHeight']]);
        $this->assertStringEndsWith('.jpg', $data['imageUrl']);

        // Switching to code drops the picture.
        $this->assertNull($this->json($this->api('PATCH', '/kiosk/admin/ads/'.$ad['id'], 1, ['kind' => 'html', 'html' => '<b>x</b>']))['data']['imageUrl']);

        $this->api('DELETE', '/kiosk/admin/ads/'.$ad['id'], 1);
        $this->assertSame([], $this->adsFor(null));
    }
}
