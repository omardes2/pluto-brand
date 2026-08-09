<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Store\Models\StoreBanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@pluto-brand.com')->first();
    }

    public function test_admin_index_loads(): void
    {
        $this->actingAs($this->admin())->get('/admin/banners')
            ->assertOk()->assertSee(__('شرائح السلايدر'));
    }

    public function test_admin_can_create_banner_with_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/admin/banners', [
            'title' => 'تشكيلة الصيف',
            'subtitle' => 'خصومات حتى ٥٠٪',
            'button_label' => 'تسوّق الآن',
            'button_url' => '/shop',
            'sort_order' => 1,
            'is_active' => 1,
            'image' => UploadedFile::fake()->image('banner.jpg', 1600, 600),
        ])->assertRedirect(route('admin.banners.index'));

        $banner = StoreBanner::where('title', 'تشكيلة الصيف')->first();
        $this->assertNotNull($banner);
        $this->assertNotNull($banner->image);
        Storage::disk('public')->assertExists($banner->image);
        $this->assertTrue($banner->is_active);
    }

    public function test_update_replaces_image(): void
    {
        Storage::fake('public');
        $banner = StoreBanner::factory()->create(['image' => 'banners/old.jpg']);
        Storage::disk('public')->put('banners/old.jpg', 'x');

        $this->actingAs($this->admin())->put('/admin/banners/'.$banner->uuid, [
            'title' => 'محدّث',
            'sort_order' => 0,
            'is_active' => 1,
            'image' => UploadedFile::fake()->image('new.jpg', 1600, 600),
        ])->assertRedirect();

        $fresh = $banner->fresh();
        $this->assertNotSame('banners/old.jpg', $fresh->image);
        Storage::disk('public')->assertMissing('banners/old.jpg');
        Storage::disk('public')->assertExists($fresh->image);
    }

    public function test_toggle_and_delete(): void
    {
        $banner = StoreBanner::factory()->create(['is_active' => true]);

        $this->actingAs($this->admin())->post('/admin/banners/'.$banner->uuid.'/toggle')->assertRedirect();
        $this->assertFalse($banner->fresh()->is_active);

        $this->actingAs($this->admin())->delete('/admin/banners/'.$banner->uuid)->assertRedirect();
        $this->assertSoftDeleted('store_banners', ['id' => $banner->id]);
    }

    public function test_storefront_home_shows_active_banner(): void
    {
        Cache::forget('storefront:banners');
        StoreBanner::factory()->create([
            'image' => 'banners/summer.jpg',
            'title' => 'عرض الشريحة الأول',
            'button_label' => 'تسوّق',
            'button_url' => '/shop',
            'is_active' => true,
        ]);

        $this->get('/')->assertOk()
            ->assertSee('banners/summer.jpg', false)
            ->assertSee('عرض الشريحة الأول');
    }

    public function test_storefront_hides_inactive_banner_and_falls_back(): void
    {
        Cache::forget('storefront:banners');
        StoreBanner::factory()->inactive()->create([
            'image' => 'banners/hidden.jpg',
            'title' => 'شريحة مخفية',
        ]);

        $res = $this->get('/')->assertOk();
        $res->assertDontSee('banners/hidden.jpg', false);
        $res->assertDontSee('شريحة مخفية');
        // البطل الافتراضي يظهر عند غياب شرائح مفعّلة.
        $res->assertSee(__('storefront.tagline'));
    }

    public function test_banner_cache_invalidated_on_save(): void
    {
        Cache::forget('storefront:banners');
        // يملأ الكاش (فارغ).
        $this->get('/')->assertOk();

        StoreBanner::factory()->create(['image' => 'banners/fresh.jpg', 'title' => 'ظهر فورًا', 'is_active' => true]);

        // بعد الحفظ يجب أن يبطُل الكاش وتظهر الشريحة دون انتظار.
        $this->get('/')->assertOk()->assertSee('banners/fresh.jpg', false);
    }
}
