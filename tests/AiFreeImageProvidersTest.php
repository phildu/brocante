<?php

use PHPUnit\Framework\TestCase;

// Connecteurs d'image gratuits (Cloudflare Workers AI, Hugging Face) : réglages, identifiant de compte, formats d'image.
final class AiFreeImageProvidersTest extends TestCase
{
    public function testDriversAreOfferedForImage(): void
    {
        $this->assertArrayHasKey('cloudflare', AI_PROVIDER_DRIVERS['image']);
        $this->assertArrayHasKey('huggingface', AI_PROVIDER_DRIVERS['image']);
        $this->assertArrayNotHasKey('cloudflare', AI_PROVIDER_DRIVERS['text']);
    }

    public function testRowKeepsCloudflareDriver(): void
    {
        $row = ai_provider_row(['id' => 'cf-gratuit', 'label' => 'Cloudflare', 'driver' => 'cloudflare', 'base_url' => 'https://api.cloudflare.com/client/v4/accounts/' . str_repeat('a', 32) . '/ai/run'], 'image');
        $this->assertSame('cloudflare', $row['driver']);
        $this->assertSame(str_repeat('a', 32), ai_provider_cloudflare_account($row));
    }

    public function testAccountIdRequiredInBaseUrl(): void
    {
        $this->assertSame('', ai_provider_cloudflare_account(['base_url' => 'https://api.cloudflare.com/client/v4/accounts/IDENTIFIANT_DE_COMPTE/ai/run']));
        $this->assertSame('', ai_provider_cloudflare_account([]));
    }

    public function testRatioSizeIsMultipleOfEight(): void
    {
        [$w, $h] = ai_provider_ratio_size('16:9', 1024);
        $this->assertSame([1024, 576], [$w, $h]);
        $this->assertSame([768, 768], ai_provider_ratio_size(null, 768));
        $this->assertSame(0, ai_provider_ratio_size('9:16', 1024)[0] % 8);
    }

    public function testImageSignatures(): void
    {
        $this->assertTrue(ai_provider_is_image("\x89PNG\r\n"));
        $this->assertTrue(ai_provider_is_image("\xFF\xD8\xFF"));
        $this->assertFalse(ai_provider_is_image('{"result":{}}'));
    }

    public function testCropToRatio(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD absent');
        $im = imagecreatetruecolor(1000, 1000);
        ob_start(); imagepng($im); $png = (string) ob_get_clean();
        $out = @getimagesizefromstring(ai_provider_crop_ratio($png, '16:9'));
        $this->assertSame(1000, $out[0]);
        $this->assertSame(563, $out[1]);
        $this->assertSame($png, ai_provider_crop_ratio($png, null));
    }

    public function testHuggingFaceDoesNotRetouchPhotos(): void
    {
        $this->assertNull(ai_provider_image(['driver' => 'huggingface', 'label' => 'HF'], __FILE__, 'test'));
    }
}
