<?php

namespace Tests\Unit;

use App\Domain\Forms\LocalizedContent;
use Tests\TestCase;

class LocalizedContentTest extends TestCase
{
    public function test_selected_translation_is_used_and_missing_russian_values_fall_back_to_latvian(): void
    {
        $resolver = app(LocalizedContent::class);
        $translations = ['lv' => ['label' => 'Latviski'], 'ru' => ['label' => 'По-русски']];

        $this->assertSame('По-русски', $resolver->resolve($translations, 'label', 'Base', 'ru'));
        $this->assertSame('Latviski', $resolver->resolve(['lv' => ['label' => 'Latviski']], 'label', 'Base', 'ru'));
        $this->assertSame('Latviski', $resolver->resolve(['lv' => ['label' => 'Latviski'], 'ru' => ['label' => null]], 'label', 'Base', 'ru'));
        $this->assertSame('Latviski', $resolver->resolve(['lv' => ['label' => 'Latviski'], 'ru' => ['label' => '']], 'label', 'Base', 'ru'));
        $this->assertSame('Latviski', $resolver->resolve(['lv' => ['label' => 'Latviski'], 'ru' => ['label' => " \t\n "]], 'label', 'Base', 'ru'));
    }

    public function test_base_system_fallback_and_first_available_values_follow_the_documented_order(): void
    {
        $resolver = app(LocalizedContent::class);

        $this->assertSame('Base', $resolver->resolve(['en' => ['label' => 'English']], 'label', 'Base', 'ru'));
        $this->assertSame('English', $resolver->resolve(['en' => ['label' => 'English']], 'label', null, 'ru'));
        config()->set('form_locales.fallback', 'ru');
        $this->assertSame('Russian fallback', $resolver->resolve(['ru' => ['label' => 'Russian fallback']], 'label', null, 'en'));
        $this->assertSame('ru', $resolver->sourceLocale(['ru' => ['label' => 'Russian fallback']], 'label', null, 'en'));
        config()->set('form_locales.fallback', 'unsupported');
        $this->assertSame('Русский', $resolver->resolve(['ru' => ['label' => 'Русский']], 'label', null, 'en'));
    }

    public function test_unsupported_locale_uses_default_and_null_translations_never_mask_content(): void
    {
        $resolver = app(LocalizedContent::class);
        $translations = ['lv' => ['label' => 'Latviski'], 'en' => ['label' => null], 'ru' => ['label' => null]];

        $this->assertSame('Latviski', $resolver->resolve($translations, 'label', 'Base', 'de'));
        $this->assertSame('Latviski', $resolver->resolve($translations, 'label', 'Base', 'en'));
        $this->assertNull($resolver->resolve(['en' => ['label' => null], 'ru' => ['label' => '  ']], 'label', null, 'en'));
    }
}
