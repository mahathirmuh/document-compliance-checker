<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version_number' => 1,
            'file_hash' => hash('sha256', fake()->unique()->uuid()),
            'file_size' => fake()->numberBetween(10_000, 5_000_000),
            'source_last_modified_at' => now()->subDay(),
            'detected_at' => now(),
            'is_current' => true,
        ];
    }

    public function superseded(): static
    {
        return $this->state(fn (array $attributes) => ['is_current' => false]);
    }
}
